const Parser = require('rss-parser');
const fs = require('fs');
const path = require('path');
const { makeEventsApi } = require('./lib/eventsApi');
const { makeTelegramClient } = require('./lib/telegram');
const { loadSyndicated, saveSyndicated, publishSyndicatedArticle } = require('./syndication-publisher');

// Veille de sources externes : lit engine/sources.json (categorie local/national/
// international, activee ou non) et transforme chaque nouvel article en EVENEMENT
// "detected"/"unverified" via l'API sa-events/v1 - jamais un flash, jamais un
// article. Le classement importance/confiance reste a "to_watch"/"unverified" par
// defaut : c'est au redacteur en chef (ou a l'agent, sur sa demande explicite)
// de le faire evoluer depuis /wp-admin ou Telegram. Voir docs/newsroom/verification-procedures.md.
//
// SYNDICATION : si la source a "publish_to_sa: true" dans sources.json, chaque
// nouvel article est aussi publie automatiquement sur souss-actualites.com avec
// attribution du journal source + photo originale (via souss-bot/v1/syndicate,
// meme X-Bot-Secret que le reste de l'API — aucun Application Password requis).

const WP_BASE_URL = process.env.WP_BASE_URL || 'https://souss-actualites.com';
const BOT_API_SECRET = process.env.BOT_API_SECRET;
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const ADMIN_TELEGRAM_ID = process.env.ADMIN_TELEGRAM_ID ? String(process.env.ADMIN_TELEGRAM_ID) : null;
const CHECK_INTERVAL_MS = parseInt(process.env.SOURCE_CHECK_INTERVAL_MS || '600000', 10); // 10 minutes
const IMPORTANT_CHECK_INTERVAL_MS = parseInt(process.env.IMPORTANT_CHECK_INTERVAL_MS || '60000', 10); // 1 minute
const SOURCES_FILE = process.env.SOURCES_CONFIG_FILE || path.join(__dirname, 'sources.json');
const SEEN_DIR = path.join(__dirname, '.seen-sources');

const parser = new Parser({ timeout: 15000 }); // 15s max par source — evite le blocage si une source est hors ligne
const telegram = TELEGRAM_BOT_TOKEN ? makeTelegramClient(TELEGRAM_BOT_TOKEN) : null;
const eventsApi = BOT_API_SECRET ? makeEventsApi(WP_BASE_URL, BOT_API_SECRET) : null;

// Ensemble des event_keys deja syndicques (persist sur disque)
let syndicated = loadSyndicated();

function loadSources() {
    let raw;
    try {
        raw = fs.readFileSync(SOURCES_FILE, 'utf8');
    } catch (err) {
        console.error(`Impossible de lire ${SOURCES_FILE}:`, err.message);
        return [];
    }
    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
        console.error(`sources.json invalide (JSON malforme):`, err.message);
        return [];
    }
}

function seenFilePath(sourceName) {
    const safe = sourceName.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    return path.join(SEEN_DIR, `${safe}.json`);
}

function loadSeen(sourceName) {
    try {
        return new Set(JSON.parse(fs.readFileSync(seenFilePath(sourceName), 'utf8')));
    } catch (err) {
        return new Set();
    }
}

function saveSeen(sourceName, seen) {
    try {
        fs.mkdirSync(SEEN_DIR, { recursive: true });
        fs.writeFileSync(seenFilePath(sourceName), JSON.stringify(Array.from(seen).slice(-1000)));
    } catch (err) {
        console.error(`Echec sauvegarde seen pour ${sourceName}:`, err.message);
    }
}

async function notifyAdmin(text) {
    if (!telegram || !ADMIN_TELEGRAM_ID) return;
    try {
        await telegram.sendMessage(ADMIN_TELEGRAM_ID, text);
    } catch (err) {
        console.error('Echec notification admin:', err.message);
    }
}

// Mots-cles Maroc / sport marocain (arabe + francais + variantes latines des
// noms de joueurs les plus couverts). Utilise pour n'autoriser la syndication
// automatique d'un article "sport" ou "international" que s'il concerne
// reellement le Maroc - la charte editoriale (voir docs/newsroom) l'exige
// explicitement : "le sport uniquement lorsqu'il concerne des sportifs
// marocains... les grandes depeches sportives mondiales uniquement lorsqu'il
// s'agit d'une veritable information majeure" (ce dernier cas reste un choix
// humain : on ne le laisse pas passer automatiquement, voir plus bas).
const MOROCCO_KEYWORDS = [
    // pays / lieux
    'maroc', 'marocain', 'marocaine', 'morocco', 'moroccan', 'المغرب', 'مغربي', 'مغربية',
    'souss', 'agadir', 'massa', 'rabat', 'casablanca', 'marrakech', 'fes', 'fès', 'tanger', 'oujda',
    // equipe nationale / clubs
    'lions de l’atlas', 'lions de l\'atlas', 'أسود الأطلس', 'الأسود',
    'wydad', 'الوداد', 'raja', 'الرجاء', 'botola', 'البطولة', 'far rabat', 'الجيش الملكي',
    'rsb', 'ittihad tanger', 'الاتحاد', 'mas fes', 'المغرب الفاسي',
    // joueurs marocains les plus couverts a l'etranger (variantes latines + arabes)
    'hakimi', 'حكيمي', 'ziyech', 'زياش', 'en-nesyri', 'ennesyri', 'النصيري', 'ounahi', 'أوناحي',
    'mazraoui', 'مزراوي', 'saiss', 'الصايس', 'amrabat', 'أمرابط', 'boufal', 'بوفال', 'aguerd', 'أكرض',
    'diaz', // Brahim Diaz (international marocain depuis 2023)
    'regragui', 'الركراكي',
];

function isMoroccoRelevant(title, excerpt) {
    const text = `${title} ${excerpt}`.toLowerCase();
    return MOROCCO_KEYWORDS.some((kw) => text.includes(kw.toLowerCase()));
}

// Detecte un article reserve aux abonnes de la source (paywall) : le titre
// porte souvent un cadenas 🔒, et le corps scrape n'est alors que le message
// d'abonnement, pas un vrai contenu - inutile et trompeur a republier tel
// quel. Trouve en prod le 2026-09-17 (Aujourd'hui le Maroc, "Journal
// electronique du Vendredi..." avec 🔒 dans le titre).
const PAYWALL_PATTERNS = [
    /🔒/,
    /r[ée]serv[ée]e?\s+(à|a|aux)\s+(nos\s+)?abonn[ée]s/i,
    /d[ée]couvrir\s+nos\s+offres\s+d.abonnement/i,
    /d[ée]j[àa]\s+abonn[ée]/i,
];

function isPaywalled(title, excerpt) {
    const text = `${title} ${excerpt}`;
    return PAYWALL_PATTERNS.some((re) => re.test(text));
}

// Mots-cles Agadir / Souss-Massa (arabe + francais + variantes latines des
// communes/villes de la region). Sur demande du redacteur en chef
// (2026-09-23) : le radar peut desormais utiliser des sources nationales et
// etrangeres (Hespress, Barlamane, Alyaoum24, Atalayar...), mais leur
// syndication automatique doit se limiter aux articles qui parlent
// reellement d'Agadir/Souss-Massa - on ne veut pas que ces sources publient
// leur actualite generale marocaine sur le site. Ne s'applique pas aux
// sources deja intrinsequement locales (category "local", aujourd'hui
// seulement Agadir24) : tout leur contenu concerne deja la region par
// nature, voir leur note dans sources.json.
const AGADIR_SOUSS_KEYWORDS = [
    // ville / region
    'agadir', 'souss-massa', 'souss massa', 'souss', 'massa',
    'أكادير', 'اكادير', 'سوس ماسة', 'سوس',
    // communes et villes de la region (les plus citees dans la presse)
    'inezgane', 'إنزكان', 'انزكان',
    'ait melloul', 'aït melloul', 'آيت ملول', 'ايت ملول',
    'taroudant', 'تارودانت',
    'tiznit', 'تزنيت',
    'chtouka ait baha', 'chtouka-ait baha', 'اشتوكة آيت باها', 'اشتوكة',
    'sidi ifni', 'سيدي إفني', 'سيدي افني',
    'tafraout', 'تافراوت',
    'biougra', 'بيوكرة',
    'oulad teima', 'أولاد تايمة', 'اولاد تايمة',
    'taghazout', 'تاغازوت',
    'aourir', 'أورير',
    'imouzzer', 'إموزار',
    'aoulouz', 'أولوز',
];

function isAgadirSoussRelevant(title, text) {
    const haystack = `${title} ${text}`.toLowerCase();
    return AGADIR_SOUSS_KEYWORDS.some((kw) => haystack.includes(kw.toLowerCase()));
}

/**
 * Traite un article. Retourne true si l'article a ete gere (evenement cree,
 * ou URL deja connue) et false en cas d'echec transitoire.
 */
async function processItem(source, item) {
    const url = item.link;
    if (!url) return true;

    const excerpt = (item.contentSnippet || item.content || '').toString().slice(0, 500);
    const title = (item.title || '').toString().trim().slice(0, 500);
    if (!title) return true;

    let eventKey = null;
    // Vrai si l'evenement (nouveau ou deja connu par son URL) porte deja une
    // marque de syndication en base (sa_events.syndicated_post_id) - source de
    // verite durable, contrairement au Set local `syndicated` qui peut etre
    // perdu si le conteneur redemarre sans le cache engine/.seen-sources/.
    let alreadySyndicated = false;

    try {
        let similarWarning = '';
        try {
            const similar = await eventsApi.findSimilar(url, title);
            const best = (similar.open_events || [])[0];
            if (best && best.similarity >= 0.5) {
                similarWarning = ` (ressemble a ${best.event_key} : "${best.title}", similarite ${Math.round(best.similarity * 100)}%)`;
            }
        } catch (simErr) {
            // Non bloquant
        }

        const created = await eventsApi.create({
            title,
            category: source.category,
            importance: 'to_watch',
            confidence: 'unverified',
            created_by: `watcher:${source.name}`,
            source: {
                url,
                source_name: source.name,
                title,
                excerpt,
            },
        });
        eventKey = created.event_key;
        alreadySyndicated = Boolean(created.syndicated_post_id);
        console.log(`[${source.name}] Nouvel evenement ${eventKey} : ${title}${similarWarning}`);
    } catch (err) {
        if (err.eventKey) {
            eventKey = err.eventKey; // URL deja connue → on connait quand meme l'event_key
            alreadySyndicated = Boolean(err.event && err.event.syndicated_post_id);
            console.log(`[${source.name}] URL deja suivie (evenement ${eventKey}), ignoree.`);
        } else {
            console.error(`[${source.name}] Echec creation evenement pour "${title}" (sera retente) :`, err.message);
            return false;
        }
    }

    // ── Syndication automatique ──────────────────────────────────────────────
    // Si la source est marquee publish_to_sa et que cet article n'a pas encore
    // ete publie sur Souss Actualites, on le publie maintenant - SAUF pour les
    // categories sport/international si l'article ne concerne pas directement
    // le Maroc (charte editoriale). Dans ce cas l'evenement reste cree
    // (to_watch) mais n'est pas publie tout seul : Hicham ou l'agent decide.
    const needsMoroccoCheck = source.category === 'sport' || source.category === 'international';
    const moroccoOk = !needsMoroccoCheck || isMoroccoRelevant(title, excerpt);
    if (!moroccoOk) {
        console.log(`[${source.name}] Syndication ignoree (hors perimetre Maroc) : ${title}`);
    }

    // Filtre region Agadir/Souss-Massa : ne s'applique qu'aux sources non
    // intrinsequement locales (tout sauf category "local", aujourd'hui
    // Agadir24 seulement) - voir AGADIR_SOUSS_KEYWORDS ci-dessus. On verifie
    // sur le texte le plus complet disponible (content:encoded quand la
    // source le fournit, sinon l'extrait court) pour ne pas rater un article
    // qui ne mentionne la region que dans son corps, pas son titre/resume.
    const isLocalSource = source.category === 'local';
    const textForRegionCheck = (item['content:encoded'] || excerpt || '').toString();
    const regionOk = isLocalSource || isAgadirSoussRelevant(title, textForRegionCheck);
    if (!regionOk) {
        console.log(`[${source.name}] Syndication ignoree (hors perimetre Agadir/Souss-Massa) : ${title}`);
    }

    const paywalled = isPaywalled(title, excerpt);
    if (paywalled) {
        console.log(`[${source.name}] Syndication ignoree (contenu reserve aux abonnes) : ${title}`);
    }
    if (regionOk && moroccoOk && !paywalled && source.publish_to_sa && BOT_API_SECRET && eventKey && !syndicated.has(eventKey) && !alreadySyndicated) {
        try {
            const result = await publishSyndicatedArticle(source, item, eventKey, WP_BASE_URL, BOT_API_SECRET);
            if (result) {
                syndicated.add(eventKey);
                saveSyndicated(syndicated);
                // Marque durable en base : meme si engine/.seen-sources/ est perdu
                // (redemarrage du conteneur), cet article ne sera plus republie -
                // voir sa_event_mark_syndicated() et la purge 8h dans sa-event-engine.php.
                await eventsApi.markSyndicated(eventKey, result.post_id, `watcher:${source.name}`).catch((err) => {
                    console.error(`[${source.name}] Echec marquage syndicated en base pour ${eventKey}:`, err.message);
                });
                // Notification Telegram admin (optionnel - non bloquant)
                await notifyAdmin(
                    `📰 Syndication : "${title}"\nSource : ${source.name}\n🔗 ${result.post_url}`
                ).catch(() => {});
            }
        } catch (err) {
            // Echec de syndication : non bloquant, l'evenement est quand meme marque vu
            console.error(`[syndicator] Echec publication "${title}" (${source.name}):`, err.message);
        }
    }

    return true;
}

// Retourne true si le texte semble etre en arabe, francais, espagnol,
// anglais, allemand ou italien. Utilise quand source.filter_languages est
// true pour ignorer les articles hors de ce perimetre editorial.
//
// Elargi le 2026-09-23 sur demande du redacteur en chef : la diaspora
// marocaine originaire du Souss est nombreuse hors des pays francophones/
// hispanophones (Allemagne, Italie, Royaume-Uni/Etats-Unis...), donc une
// source en anglais/allemand/italien qui parlerait d'Agadir/Souss-Massa ne
// doit plus etre rejetee d'office par ce filtre. Recherche menee le meme
// jour : aucun media dedie identifie dans ces trois langues pour l'instant
// (voir echanges avec le redacteur en chef) - ce changement prepare le
// terrain pour le jour ou une source valide sera trouvee, sans qu'il faille
// retoucher ce fichier a nouveau.
function isEditorialLanguage(text) {
    if (!text) return true; // pas de titre = on laisse passer, sera rejete plus loin
    // Arabe : plage Unicode U+0600-U+06FF
    if (/[؀-ۿ]/.test(text)) return true;
    // Espagnol specifique : n tilde, ponctuation inversee
    if (/[ñÑ¡¿]/.test(text)) return true;
    // Diacritiques latins communs au francais/espagnol/allemand/italien
    // (inclut aussi les umlauts allemands ä/ö/ü/ß et les voyelles accentuees
    // italiennes à/è/ì/ò/ù, qui tombent dans cette meme plage Unicode).
    if (/[À-ÿ]/.test(text)) return true;
    // Mots grammaticaux francais courants (sans accent)
    if (/\b(le|la|les|de|du|des|un|une|au|aux|et|est|pour|dans|sur|avec|par|qui|que|se|en|il|elle|ils|elles|nous|vous|ce|cette|ces|son|sa|ses|leur|leurs|mais|ou|donc|or|ni|car)\b/i.test(text)) return true;
    // Mots grammaticaux espagnols courants (sans accent)
    if (/\b(el|los|las|del|una|con|por|para|como|pero|mas|sin|sobre|entre|cuando|tambien|hay|puede|han|fue|ser|los)\b/i.test(text)) return true;
    // Mots grammaticaux anglais courants - l'anglais n'a pas de diacritiques,
    // donc ce repli lexical est le seul signal disponible.
    if (/\b(the|and|of|to|in|is|for|on|with|that|by|at|from|as|it|this|be|are|was|were|has|have|an|but|not|we|you|they|his|her|their)\b/i.test(text)) return true;
    // Mots grammaticaux allemands courants (sans umlaut, en repli si le titre n'en contient pas)
    if (/\b(der|die|das|und|ist|für|mit|auf|von|zu|nicht|ein|eine|den|dem|des|sich|auch|nach|bei|wird|werden|wurde|sind)\b/i.test(text)) return true;
    // Mots grammaticaux italiens courants (sans accent, en repli si le titre n'en contient pas)
    if (/\b(il|lo|la|gli|le|di|che|non|per|con|su|come|anche|dove|questo|questa|sono|hanno|delle|degli|della)\b/i.test(text)) return true;
    return false;
}

async function checkSource(source) {
    if (!source.url || !source.name) {
        console.error('Source mal configuree (name/url manquant), ignoree:', source);
        return;
    }
    const seen = loadSeen(source.name);
    try {
        const feed = await parser.parseURL(source.url);
        let newItems = feed.items.filter((item) => item.link && !seen.has(item.link)).reverse();

        // Filtre langue : si la source demande filter_languages, on ignore les articles
        // dont le titre ne ressemble ni au francais ni a l'arabe.
        if (source.filter_languages) {
            const before = newItems.length;
            newItems = newItems.filter((item) => {
                const ok = isEditorialLanguage((item.title || '').toString());
                if (!ok) {
                    console.log(`[${source.name}] Article ignore (langue hors perimetre) : "${(item.title || '').toString().slice(0, 80)}"`);
                    // On marque quand meme l'URL comme vue pour ne pas la retraiter
                    seen.add(item.link);
                }
                return ok;
            });
            if (newItems.length < before) {
                saveSeen(source.name, seen); // persiste les URLs ignorees
            }
        }

        let handledCount = 0;
        for (const item of newItems) {
            const handled = await processItem(source, item);
            if (handled) {
                seen.add(item.link);
                handledCount++;
            }
        }

        if (handledCount > 0) {
            saveSeen(source.name, seen);
        }
        if (newItems.length > 0) {
            console.log(`[${source.name}] ${handledCount}/${newItems.length} nouvel(le)(s) article(s) traite(s)${handledCount < newItems.length ? ' (le reste sera retente au prochain cycle)' : ''}.`);
        } else {
            console.log(`[${source.name}] Aucun nouvel article.`);
        }
    } catch (err) {
        console.error(`[${source.name}] Erreur lors de la lecture du flux:`, err.message);
    }
}

const IMPORTANT_NOTIFIED_KEY = '_notified-important';

async function notifyImportantEvents() {
    if (!telegram || !ADMIN_TELEGRAM_ID) return;
    const notified = loadSeen(IMPORTANT_NOTIFIED_KEY);
    try {
        const events = await eventsApi.list({ importance: 'important', limit: 50 });
        const fresh = events.filter((e) => !notified.has(e.event_key));
        for (const e of fresh) {
            await notifyAdmin(`⚠️ Evenement important : ${e.title}\n${e.event_key} — statut ${e.status}, confiance ${e.confidence}\nhttps://souss-actualites.com/wp-admin/options-general.php?page=sa-events&event=${e.event_key}`);
            notified.add(e.event_key);
        }
        if (fresh.length) saveSeen(IMPORTANT_NOTIFIED_KEY, notified);
    } catch (err) {
        console.error('Echec verification des evenements importants:', err.message);
    }
}

async function tick() {
    const sources = loadSources().filter((s) => s.enabled);
    if (!sources.length) {
        console.log('Aucune source activee dans sources.json.');
        return;
    }
    for (const source of sources) {
        await checkSource(source);
    }
}

function main() {
    if (!eventsApi) {
        console.error('BOT_API_SECRET manquant : impossible de creer des evenements. Arret.');
        process.exit(1);
    }
    const sources = loadSources();
    const active = sources.filter((s) => s.enabled).map((s) => `${s.name} (${s.category})`);
    const syndicable = sources.filter((s) => s.enabled && s.publish_to_sa).map((s) => s.name);

    console.log('Demarrage de la veille de sources externes (Souss Actualites)');
    console.log(`Sources activees : ${active.length ? active.join(', ') : '(aucune)'}`);
    console.log(`Frequence flux RSS : toutes les ${CHECK_INTERVAL_MS / 1000} secondes`);
    console.log(`Frequence verification "important" : toutes les ${IMPORTANT_CHECK_INTERVAL_MS / 1000} secondes`);

    if (BOT_API_SECRET && syndicable.length) {
        console.log(`Syndication automatique active pour : ${syndicable.join(', ')}`);
    } else if (!BOT_API_SECRET) {
        console.log('Syndication desactivee (BOT_API_SECRET manquant).');
    }

    tick();
    setInterval(tick, CHECK_INTERVAL_MS);

    notifyImportantEvents();
    setInterval(notifyImportantEvents, IMPORTANT_CHECK_INTERVAL_MS);
}

main();
