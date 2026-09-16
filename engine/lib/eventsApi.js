function makeEventsApi(baseUrl, secret) {
    async function request(pathname, options = {}) {
        const response = await fetch(`${baseUrl}/wp-json/sa-events/v1${pathname}`, {
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
            const err = new Error(`Erreur API Events (${pathname}): ${message}`);
            err.eventKey = data && data.data && data.data.event_key;
            throw err;
        }
        return data;
    }

    return {
        list: (params = {}) => {
            const qs = new URLSearchParams(params).toString();
            return request(`/list${qs ? `?${qs}` : ''}`);
        },
        get: (eventKey) => request(`/get/${encodeURIComponent(eventKey)}`),
        findSimilar: (url) => request('/find-similar', { method: 'POST', body: JSON.stringify({ url }) }),
        create: (payload) => request('/create', { method: 'POST', body: JSON.stringify(payload) }),
        addSource: (payload) => request('/add-source', { method: 'POST', body: JSON.stringify(payload) }),
        update: (payload) => request('/update', { method: 'POST', body: JSON.stringify(payload) }),
        merge: (sourceEventKey, targetEventKey, actor) =>
            request('/merge', {
                method: 'POST',
                body: JSON.stringify({ source_event_key: sourceEventKey, target_event_key: targetEventKey, actor }),
            }),
        linkFlash: (eventKey, flashId, actor) =>
            request('/link-flash', { method: 'POST', body: JSON.stringify({ event_key: eventKey, flash_id: flashId, actor }) }),
        promoteToArticle: (eventKey, actor) =>
            request('/promote-to-article', { method: 'POST', body: JSON.stringify({ event_key: eventKey, actor }) }),
        prepareSocialPost: (eventKey, channel, content, actor) =>
            request('/social-pack', {
                method: 'POST',
                body: JSON.stringify({ event_key: eventKey, channel, content, actor }),
            }),
    };
}

module.exports = { makeEventsApi };
