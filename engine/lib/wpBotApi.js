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

function makeFlashApi(baseUrl, secret) {
    async function request(pathname, options = {}) {
        const response = await fetch(`${baseUrl}/wp-json/sa-flash/v1${pathname}`, {
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
            throw new Error(`Erreur API Flash (${pathname}): ${message}`);
        }
        return data;
    }

    return {
        list: () => request('/list'),
        create: (title, url) => request('/new', { method: 'POST', body: JSON.stringify({ title, url: url || '' }) }),
        publish: (id) => request('/publish', { method: 'POST', body: JSON.stringify({ id }) }),
        unpublish: (id) => request('/unpublish', { method: 'POST', body: JSON.stringify({ id }) }),
        trashPost: (id) => request('/trash-post', { method: 'POST', body: JSON.stringify({ id }) }),
    };
}

module.exports = { makeWpBotApi, makeFlashApi };
