const Parser = require('rss-parser');
const fs = require('fs');
const path = require('path');
const { makeEventsApi } = require('./lib/eventsApi');
const { makeTelegramClient } = require('./lib/telegram');

// Veille de sources externes : lit engine/sources.json (categorie local/national/
// international, activee ou non) et transforme chaque nouvel article en EVENEMENT
// "detected"/"unverified" via l'API sa-events/v1 - jamais un flash, jamais un
// article. Le classement importance/confiance reste a "to_watch"/"unverified" par
// defaut : c'est au redacteur en chef (ou a l'agent, sur sa demande explicite)
// de le faire evoluer depuis /wp-admin ou Telegram. Voir docs/newsroom/verification-procedures.md.

const WP_BASE_URL = process.env.WP_BASE_URL || 'https://souss-actualites.com';
const BOT_API_SECRET = process.env.BOT_API_SECRET;
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const ADMIN_TELEGRAM_ID = process.env.ADMIN_TELEGRAM_ID ? String(process.env.ADMIN_TELEGRAM_ID) : null;
const CHECK_INTERVAL_MS = parseInt(process.env.SOURCE_CHECK_INTERVAL_MS || '600000', 10); // 10 minutes
const SOURCES_FILE = process.env.SOURCES_CONFIG_FILE || path.join(__dirname, 'sources.json');
const SEEN_DIR = path.join(__dirname, '.seen-sources');

const parser = new Parser();
const telegram = TELEGRAM_BOT_TOKEN ? makeTelegramClient(TELEGRAM_BOT_TOKEN) : null;
const eventsApi = BOT_API_SECRET ? makeEventsApi(WP_BASE_URL, BOT_API_SECRET) : null;

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

async function processItem(source, item) {
    const url = item.link;
    if (!url) return;

    const excerpt = (item.contentSnippet || item.content || '').toString().slice(0, 500);
    const title = (item.title || '').toString().trim().slice(0, 500);
    if (!title) return;

    try {
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
        console.log(`[${source.name}] Nouvel evenement ${created.event_key} : ${title}`);
        await notifyAdmin(`🔎 Nouvel evenement detecte (${source.category})\n${created.event_key} — ${title}\nSource : ${source.name}\n${url}`);
    } catch (err) {
        if (err.eventKey) {
            // URL deja connue : deja rattachee a un evenement existant, rien a faire.
            console.log(`[${source.name}] URL deja suivie (evenement ${err.eventKey}), ignoree.`);
            return;
        }
        console.error(`[${source.name}] Echec creation evenement pour "${title}":`, err.message);
    }
}

async function checkSource(source) {
    if (!source.url || !source.name) {
        console.error('Source mal configuree (name/url manquant), ignoree:', source);
        return;
    }
    const seen = loadSeen(source.name);
    try {
        const feed = await parser.parseURL(source.url);
        const newItems = feed.items.filter((item) => item.link && !seen.has(item.link)).reverse();

        for (const item of newItems) {
            await processItem(source, item);
            seen.add(item.link);
        }

        if (newItems.length > 0) {
            saveSeen(source.name, seen);
            console.log(`[${source.name}] ${newItems.length} nouvel(le)(s) article(s) traite(s).`);
        } else {
            console.log(`[${source.name}] Aucun nouvel article.`);
        }
    } catch (err) {
        console.error(`[${source.name}] Erreur lors de la lecture du flux:`, err.message);
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
    console.log('Demarrage de la veille de sources externes (Souss Actualites)');
    console.log(`Sources activees : ${active.length ? active.join(', ') : '(aucune)'}`);
    console.log(`Frequence : toutes les ${CHECK_INTERVAL_MS / 1000} secondes`);

    tick();
    setInterval(tick, CHECK_INTERVAL_MS);
}

main();
