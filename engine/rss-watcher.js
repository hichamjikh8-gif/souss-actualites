const Parser = require('rss-parser');
const fs = require('fs');
const path = require('path');

const parser = new Parser();

// Configuration via variables d'environnement (definies dans Railway, jamais en dur ici)
const FEED_URL = process.env.RSS_FEED_URL || 'https://souss-actualites.com/feed/';
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const TELEGRAM_CHAT_ID = process.env.TELEGRAM_CHAT_ID || '@soussactualites_bot';
const ADMIN_TELEGRAM_ID = process.env.ADMIN_TELEGRAM_ID;
const CHECK_INTERVAL_MS = parseInt(process.env.CHECK_INTERVAL_MS || '300000', 10); // 5 minutes
const FACEBOOK_PAGE_ID = process.env.FACEBOOK_PAGE_ID;
const FACEBOOK_PAGE_ACCESS_TOKEN = process.env.FACEBOOK_PAGE_ACCESS_TOKEN;
const X_BEARER_TOKEN = process.env.X_BEARER_TOKEN;

const SEEN_FILE = path.join(__dirname, 'seen.json');

function loadSeen() {
    try {
          return new Set(JSON.parse(fs.readFileSync(SEEN_FILE, 'utf8')));
    } catch (err) {
          return new Set();
    }
}

function saveSeen(seen) {
    const arr = Array.from(seen).slice(-500);
    fs.writeFileSync(SEEN_FILE, JSON.stringify(arr));
}

function extractImage(item) {
    const html = item['content:encoded'] || item.content || '';
    const match = html.match(/<img[^>]+src="([^">]+)"/);
    if (match) return match[1];
    if (item.enclosure && item.enclosure.url) return item.enclosure.url;
    return null;
}

async function sendTelegramMessage(item) {
    const text = `${item.title}\n${item.link}`;
    const imageUrl = extractImage(item);
    const base = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;

  const endpoint = imageUrl ? `${base}/sendPhoto` : `${base}/sendMessage`;
    const body = imageUrl
      ? { chat_id: TELEGRAM_CHAT_ID, photo: imageUrl, caption: text }
          : { chat_id: TELEGRAM_CHAT_ID, text };

  const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
  });

  const data = await response.json();
    if (!data.ok) {
          throw new Error(`Erreur API Telegram: ${JSON.stringify(data)}`);
    }
}

async function alertAdmin(text) {
    if (!TELEGRAM_BOT_TOKEN || !ADMIN_TELEGRAM_ID) return;
    try {
        await fetch(`https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chat_id: ADMIN_TELEGRAM_ID, text }),
        });
    } catch (err) {
        console.error('Echec envoi alerte admin:', err.message);
    }
}

// Publie l'article sur la Page Facebook via l'API Graph.
// Ne fait rien si les variables FACEBOOK_PAGE_ID / FACEBOOK_PAGE_ACCESS_TOKEN sont absentes
// (degradation gracieuse : le worker continue de fonctionner sans Facebook).
// Facebook extrait automatiquement le titre, l'image og:image et la description
// depuis l'URL — pas besoin de les envoyer manuellement.
async function sendFacebookPost(item) {
    if (!FACEBOOK_PAGE_ID || !FACEBOOK_PAGE_ACCESS_TOKEN) return null;

    const url = `https://graph.facebook.com/v20.0/${FACEBOOK_PAGE_ID}/feed`;
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            message: item.title,
            link: item.link,
            access_token: FACEBOOK_PAGE_ACCESS_TOKEN,
        }),
    });

    const data = await response.json();
    if (data.error) {
        throw new Error(`Erreur API Facebook: ${JSON.stringify(data.error)}`);
    }
    return data.id; // post_id retourne par Facebook
}

// Publie l'article sur X (Twitter) via l'API v2 POST /2/tweets.
// Ne fait rien si X_BEARER_TOKEN est absent (meme degradation gracieuse que
// Facebook ci-dessus). NB : X exige un token OAuth avec le droit tweet.write
// (pas un bearer app-only en lecture seule) - meme mecanisme que celui deja
// utilise cote PHP pour le bouton "Publier maintenant" (sa-event-engine.php).
async function sendXPost(item) {
    if (!X_BEARER_TOKEN) return null;

    // X compte tout lien comme 23 caracteres (raccourci automatique via t.co)
    // quelle que soit sa longueur reelle : on reserve cette place pour le
    // lien + un saut de ligne, et on tronque le titre si besoin.
    const LINK_RESERVED = 23;
    const maxTitleLength = 280 - LINK_RESERVED - 1;
    const title = item.title.length > maxTitleLength
        ? `${item.title.slice(0, maxTitleLength - 1)}…`
        : item.title;
    const text = `${title}\n${item.link}`;

    const response = await fetch('https://api.twitter.com/2/tweets', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${X_BEARER_TOKEN}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ text }),
    });

    const data = await response.json();
    if (!response.ok || data.errors) {
        throw new Error(`Erreur API X: ${JSON.stringify(data.errors || data)}`);
    }
    return data.data && data.data.id;
}

async function checkFeed() {
    if (!TELEGRAM_BOT_TOKEN) {
        console.error('TELEGRAM_BOT_TOKEN manquant. Ajoutez cette variable d\'environnement dans Railway.');
        return;
    }

    const seen = loadSeen();

    try {
        const feed = await parser.parseURL(FEED_URL);
        const newItems = feed.items.filter((item) => item.link && !seen.has(item.link)).reverse();

        for (const item of newItems) {
            // Telegram
            try {
                await sendTelegramMessage(item);
                console.log(`Telegram OK: ${item.title}`);
            } catch (sendErr) {
                console.error(`Echec Telegram pour "${item.title}":`, sendErr.message);
                await alertAdmin(`⚠️ Échec Telegram pour "${item.title}" : ${sendErr.message}`);
            }

            // Facebook (independant de Telegram — un echec ne bloque pas l'autre)
            try {
                const fbId = await sendFacebookPost(item);
                if (fbId) console.log(`Facebook OK: ${item.title} (post ${fbId})`);
            } catch (fbErr) {
                console.error(`Echec Facebook pour "${item.title}":`, fbErr.message);
                await alertAdmin(`⚠️ Échec Facebook pour "${item.title}" : ${fbErr.message}`);
            }

            // X / Twitter (independant des deux autres)
            try {
                const xId = await sendXPost(item);
                if (xId) console.log(`X OK: ${item.title} (post ${xId})`);
            } catch (xErr) {
                console.error(`Echec X pour "${item.title}":`, xErr.message);
                await alertAdmin(`⚠️ Échec X pour "${item.title}" : ${xErr.message}`);
            }

            seen.add(item.link);
        }

        if (newItems.length > 0) {
            saveSeen(seen);
        } else {
            console.log('Aucun nouvel article a envoyer.');
        }
    } catch (err) {
        console.error('Erreur lors de la lecture du flux RSS:', err.message);
    }
}

console.log('Demarrage du worker Souss Actualites (moteur envoi automatique Telegram + Facebook + X)');
console.log(`Flux surveille: ${FEED_URL}`);
console.log(`Frequence: toutes les ${CHECK_INTERVAL_MS / 1000} secondes`);

checkFeed();
setInterval(checkFeed, CHECK_INTERVAL_MS);
