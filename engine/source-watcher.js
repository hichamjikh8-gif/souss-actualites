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

const parser = new Parser();
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
        console.log(`[${source.name}] Nouvel evenement ${eventKey} : ${title}${similarWarning}`);
    } catch (err) {
        if (err.eventKey) {
            eventKey = err.eventKey; // URL deja connue → on connait quand meme l'event_key
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
    const paywalled = isPaywalled(title, excerpt);
    if (paywalled) {
        console.log(`[${source.name}] Syndication ignoree (contenu reserve aux abonnes) : ${title}`);
    }
    if (moroccoOk && !paywalled && source.publish_to_sa && BOT_API_SECRET && eventKey && !syndicated.has(eventKey)) {
        try {
            const result = await publishSyndicatedArticle(source, item, eventKey, WP_BASE_URL, BOT_API_SECRET);
            if (result) {
                syndicated.add(eventKey);
                saveSyndicated(syndicated);
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

// Retourne true si le texte semble etre en arabe, francais ou espagnol.
// Utilise quand source.filter_languages est true pour ignorer les articles
// en anglais (ou toute autre langue hors perimetre editorial).
function isEditorialLanguage(text) {
    if (!text) return true; // pas de titre = on laisse passer, sera rejete plus loin
    // Arabe : plage Unicode U+0600-U+06FF
    if (/[؀-ۿ]/.test(text)) return true;
    // Espagnol specifique : n tilde, ponctuation inversee
    if (/[ñÑ¡¿]/.test(text)) return true;
    // Francais/Espagnol : diacritiques latins communs aux deux langues
    if (/[À-ÿ]/.test(text)) return true;
    // Mots grammaticaux francais courants (sans accent)
    if (/\b(le|la|les|de|du|des|un|une|au|aux|et|est|pour|dans|sur|avec|par|qui|que|se|en|il|elle|ils|elles|nous|vous|ce|cette|ces|son|sa|ses|leur|leurs|mais|ou|donc|or|ni|car)\b/i.test(text)) return true;
    // Mots grammaticaux espagnols courants (sans accent)
    if (/\b(el|los|las|del|una|con|por|para|como|pero|mas|sin|sobre|entre|cuando|tambien|hay|puede|han|fue|ser|los)\b/i.test(text)) return true;
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
