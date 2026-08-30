const TELEGRAM_API = 'https://api.telegram.org';

function makeTelegramClient(botToken) {
    const base = `${TELEGRAM_API}/bot${botToken}`;

    async function call(method, body) {
        const response = await fetch(`${base}/${method}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (!data.ok) {
            throw new Error(`Erreur API Telegram (${method}): ${JSON.stringify(data)}`);
        }
        return data.result;
    }

    return {
        sendMessage: (chatId, text, extra = {}) => call('sendMessage', { chat_id: chatId, text, ...extra }),
        sendPhoto: (chatId, photo, caption, extra = {}) => call('sendPhoto', { chat_id: chatId, photo, caption, ...extra }),
        setWebhook: (url) => call('setWebhook', { url }),
    };
}

module.exports = { makeTelegramClient };
