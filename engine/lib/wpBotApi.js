function makeWpBotApi(baseUrl, secret) {
    async function request(pathname, options = {}) {
        const response = await fetch(`${baseUrl}/wp-json/souss-bot/v1${pathname}`, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                'X-Bot-Secret': secret,
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            const message = data && data.message ? data.message : `HTTP ${response.status}`;
            throw new Error(`Erreur API WordPress (${pathname}): ${message}`);
        }
        return data;
    }

    return {
        getDrafts: () => request('/drafts'),
        getStats: () => request('/stats'),
        getPendingComments: () => request('/comments-pending'),
        publish: (id) => request('/publish', { method: 'POST', body: JSON.stringify({ id }) }),
        createDraft: (title, content) => request('/new', { method: 'POST', body: JSON.stringify({ title, content }) }),
    };
}

module.exports = { makeWpBotApi };
