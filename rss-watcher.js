const Parser = require('rss-parser');
const fs = require('fs');
const path = require('path');

const parser = new Parser();

// Configuration via variables d'environnement (definies dans Railway, jamais en dur ici)
const FEED_URL = process.env.RSS_FEED_URL || 'https://souss-actualites.com/feed/';
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const TELEGRAM_CHAT_ID = process.env.TELEGRAM_CHAT_ID || '@soussactualites_bot';
const CHECK_INTERVAL_MS = parseInt(process.env.CHECK_INTERVAL_MS || '300000', 10); // 5 minutes

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
              try {
                        await sendTelegramMessage(item);
                        seen.add(item.link);
                        console.log(`Article envoye: ${item.title}`);
              } catch (sendErr) {
                        console.error(`Echec envoi pour "${item.title}":`, sendErr.message);
              }
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

console.log('Demarrage du worker Souss Actualites (moteur envoi automatique Telegram)');
console.log(`Flux surveille: ${FEED_URL}`);
console.log(`Frequence: toutes les ${CHECK_INTERVAL_MS / 1000} secondes`);

checkFeed();
setInterval(checkFeed, CHECK_INTERVAL_MS);
