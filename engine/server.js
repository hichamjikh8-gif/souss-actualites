const express = require('express');
const fs = require('fs');
const path = require('path');
const { makeTelegramClient } = require('./lib/telegram');
const { makeWpBotApi } = require('./lib/wpBotApi');
const { makeAgent } = require('./lib/agent');

const PORT = process.env.PORT || 3000;
const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const ADMIN_TELEGRAM_ID = process.env.ADMIN_TELEGRAM_ID ? String(process.env.ADMIN_TELEGRAM_ID) : null;
const WEBHOOK_URL = process.env.WEBHOOK_URL;
const WP_BASE_URL = process.env.WP_BASE_URL || 'https://souss-actualites.com';
const BOT_API_SECRET = process.env.BOT_API_SECRET;
const ANTHROPIC_API_KEY = process.env.ANTHROPIC_API_KEY;
const FAL_KEY = process.env.FAL_KEY;
const UPTIME_CHECK_INTERVAL_MS = parseInt(process.env.UPTIME_CHECK_INTERVAL_MS || '300000', 10);
const MODERATION_CHECK_INTERVAL_MS = parseInt(process.env.MODERATION_CHECK_INTERVAL_MS || '300000', 10);
const KNOWN_COMMANDS = ['/start', '/help', '/stats', '/drafts', '/comments', '/publish', '/new'];

const SEEN_COMMENTS_FILE = path.join(__dirname, 'seen-comments.json');

const app = express();
app.use(express.json());

const telegram = TELEGRAM_BOT_TOKEN ? makeTelegramClient(TELEGRAM_BOT_TOKEN) : null;
const wpApi = BOT_API_SECRET ? makeWpBotApi(WP_BASE_URL, BOT_API_SECRET) : null;
const agent =
    ANTHROPIC_API_KEY && telegram && wpApi
        ? makeAgent({ anthropicApiKey: ANTHROPIC_API_KEY, telegram, wpApi, falKey: FAL_KEY })
        : null;

function loadSeenComments() {
    try {
        return new Set(JSON.parse(fs.readFileSync(SEEN_COMMENTS_FILE, 'utf8')));
    } catch (err) {
        return new Set();
    }
}

function saveSeenComments(seen) {
    fs.writeFileSync(SEEN_COMMENTS_FILE, JSON.stringify(Array.from(seen).slice(-500)));
}

async function alertAdmin(text) {
    if (!telegram || !ADMIN_TELEGRAM_ID) return;
    try {
        await telegram.sendMessage(ADMIN_TELEGRAM_ID, text);
    } catch (err) {
        console.error('Echec envoi alerte admin:', err.message);
    }
}

function isAuthorized(update) {
    const chatId = update && update.message && update.message.chat && update.message.chat.id;
    return Boolean(ADMIN_TELEGRAM_ID) && chatId !== undefined && String(chatId) === ADMIN_TELEGRAM_ID;
}

function formatDrafts(drafts) {
    if (!drafts.length) return 'Aucun brouillon en attente.';
    return drafts.map((d) => `#${d.id} — ${d.title} (${d.date})`).join('\n');
}

function formatStats(stats) {
    const recent = stats.recent.map((p) => `• ${p.title}`).join('\n');
    return [
        `Articles publiés : ${stats.published}`,
        `Brouillons : ${stats.drafts}`,
        `Commentaires en attente : ${stats.comments_pending}`,
        '',
        'Derniers articles publiés :',
        recent || '(aucun)',
    ].join('\n');
}

function formatPendingComments(comments) {
    if (!comments.length) return 'Aucun commentaire en attente.';
    return comments
        .map((c) => `#${c.id} — ${c.author} sur "${c.post_title}"\n${c.excerpt}`)
        .join('\n\n');
}

const HELP_TEXT = [
    'Podés hablarme en lenguaje natural (buscar noticias, escribir un artículo, generar una imagen, publicar) o usar estos comandos rápidos :',
    '/stats — statistiques du site',
    '/drafts — liste des brouillons',
    '/publish <id> — publier un brouillon',
    "/new <titre> | <contenu> — créer un nouveau brouillon",
    '/comments — commentaires en attente de modération',
    '/help — cette aide',
].join('\n');

async function handleCommand(chatId, text) {
    const trimmed = text.trim();
    const [cmdRaw] = trimmed.split(/\s+/);
    const cmd = cmdRaw.toLowerCase();
    const argText = text.slice(cmdRaw.length).trim();
    const isSlashCommand = trimmed.startsWith('/');

    if (isSlashCommand && !KNOWN_COMMANDS.includes(cmd)) {
        await telegram.sendMessage(chatId, `Commande inconnue.\n\n${HELP_TEXT}`);
        return;
    }

    if (!isSlashCommand) {
        if (!agent) {
            await telegram.sendMessage(
                chatId,
                "El asistente conversacional no está configurado todavía (falta ANTHROPIC_API_KEY, o BOT_API_SECRET/TELEGRAM_BOT_TOKEN)."
            );
            return;
        }
        try {
            const reply = await agent.handleMessage(chatId, text);
            await telegram.sendMessage(chatId, reply);
        } catch (err) {
            console.error('Erreur agent conversationnel:', err.message);
            await telegram.sendMessage(chatId, `Error: ${err.message}`);
        }
        return;
    }

    if (!wpApi) {
        await telegram.sendMessage(chatId, "Le bot n'est pas relié à l'API du site (BOT_API_SECRET manquant).");
        return;
    }

    try {
        if (cmd === '/start' || cmd === '/help') {
            await telegram.sendMessage(chatId, HELP_TEXT);
        } else if (cmd === '/stats') {
            const stats = await wpApi.getStats();
            await telegram.sendMessage(chatId, formatStats(stats));
        } else if (cmd === '/drafts') {
            const drafts = await wpApi.getDrafts();
            await telegram.sendMessage(chatId, formatDrafts(drafts));
        } else if (cmd === '/comments') {
            const comments = await wpApi.getPendingComments();
            await telegram.sendMessage(chatId, formatPendingComments(comments));
        } else if (cmd === '/publish') {
            const id = parseInt(argText, 10);
            if (!id) {
                await telegram.sendMessage(chatId, 'Utilisation : /publish <id>. Voir /drafts pour les id disponibles.');
                return;
            }
            const result = await wpApi.publish(id);
            await telegram.sendMessage(chatId, `Publié : ${result.title}\n${result.url}`);
        } else if (cmd === '/new') {
            const [titlePart, ...contentParts] = argText.split('|');
            const title = (titlePart || '').trim();
            const content = contentParts.join('|').trim();
            if (!title || !content) {
                await telegram.sendMessage(chatId, "Utilisation : /new Titre de l'article | Contenu de l'article");
                return;
            }
            const result = await wpApi.createDraft(title, content);
            await telegram.sendMessage(chatId, `Brouillon créé : #${result.id} — ${result.title}\nPublier avec /publish ${result.id}`);
        } else {
            await telegram.sendMessage(chatId, `Commande inconnue.\n\n${HELP_TEXT}`);
        }
    } catch (err) {
        console.error('Erreur commande bot:', err.message);
        await telegram.sendMessage(chatId, `Erreur : ${err.message}`);
    }
}

app.get('/', (req, res) => {
    res.send('Souss Actualites - moteur envoi automatique - service web actif');
});

app.get('/health', (req, res) => {
    res.json({ status: 'ok', service: 'web' });
});

app.post('/telegram-webhook', async (req, res) => {
    res.sendStatus(200); // repondre vite: Telegram retente si on tarde
    const update = req.body;
    const message = update && update.message;
    if (!message || !message.text) return;

    if (!isAuthorized(update)) {
        console.log(`Commande ignorée d'un utilisateur non autorisé (chat ${message.chat && message.chat.id}).`);
        return;
    }

    await handleCommand(message.chat.id, message.text);
});

let siteWasDown = false;

async function checkUptime() {
    try {
        const response = await fetch(WP_BASE_URL, { method: 'GET', signal: AbortSignal.timeout(15000) });
        if (!response.ok) {
            throw new Error(`Statut HTTP ${response.status}`);
        }
        if (siteWasDown) {
            await alertAdmin(`✅ Le site ${WP_BASE_URL} est de nouveau accessible.`);
            siteWasDown = false;
        }
    } catch (err) {
        if (!siteWasDown) {
            await alertAdmin(`🚨 Le site ${WP_BASE_URL} semble inaccessible : ${err.message}`);
            siteWasDown = true;
        }
    }
}

async function checkPendingComments() {
    if (!wpApi) return;
    try {
        const comments = await wpApi.getPendingComments();
        const seen = loadSeenComments();
        const fresh = comments.filter((c) => !seen.has(String(c.id)));
        for (const c of fresh) {
            await alertAdmin(`💬 Nouveau commentaire à modérer sur "${c.post_title}"\n${c.author} : ${c.excerpt}`);
            seen.add(String(c.id));
        }
        if (fresh.length) saveSeenComments(seen);
    } catch (err) {
        console.error('Erreur verification commentaires:', err.message);
    }
}

async function registerWebhook() {
    if (!telegram || !WEBHOOK_URL) {
        console.log('Webhook Telegram non enregistré (TELEGRAM_BOT_TOKEN ou WEBHOOK_URL manquant).');
        return;
    }
    try {
        await telegram.setWebhook(WEBHOOK_URL);
        console.log(`Webhook Telegram enregistré : ${WEBHOOK_URL}`);
    } catch (err) {
        console.error('Echec enregistrement webhook Telegram:', err.message);
    }
}

app.listen(PORT, () => {
    console.log(`Service web demarre sur le port ${PORT}`);
    registerWebhook();
    if (ADMIN_TELEGRAM_ID) {
        setInterval(checkUptime, UPTIME_CHECK_INTERVAL_MS);
        setInterval(checkPendingComments, MODERATION_CHECK_INTERVAL_MS);
    } else {
        console.log('ADMIN_TELEGRAM_ID manquant: commandes et alertes désactivées.');
    }
});
