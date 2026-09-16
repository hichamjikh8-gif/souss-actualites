const { JWT } = require('google-auth-library');

// Client Google Search Console (lecture seule) via un compte de service.
// GOOGLE_SERVICE_ACCOUNT_JSON contient la cle JSON complete du compte de
// service (jamais en dur ici, uniquement en variable d'environnement
// Railway). Le compte de service doit avoir ete ajoute comme utilisateur
// (au moins "Restreint") sur la propriete Search Console correspondante -
// sinon l'API repond une erreur de permission, pas une erreur d'auth.

function makeSearchConsoleClient(serviceAccountJson, siteUrl) {
    let jwtClient = null;

    function getClient() {
        if (!jwtClient) {
            const credentials = JSON.parse(serviceAccountJson);
            jwtClient = new JWT({
                email: credentials.client_email,
                key: credentials.private_key,
                scopes: ['https://www.googleapis.com/auth/webmasters.readonly'],
            });
        }
        return jwtClient;
    }

    async function query({ startDate, endDate, dimensions = [], rowLimit = 10 }) {
        const client = getClient();
        const { token } = await client.getAccessToken();
        const endpoint = `https://searchconsole.googleapis.com/webmasters/v3/sites/${encodeURIComponent(siteUrl)}/searchAnalytics/query`;
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ startDate, endDate, dimensions, rowLimit }),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            throw new Error(`Erreur Search Console API (HTTP ${response.status}): ${JSON.stringify(data)}`);
        }
        return (data && data.rows) || [];
    }

    // Search Console a generalement 2-3 jours de retard sur les donnees les
    // plus recentes ; on decale la fenetre pour eviter des jours vides.
    async function getSummary(days = 28) {
        const end = new Date();
        end.setDate(end.getDate() - 3);
        const start = new Date(end);
        start.setDate(start.getDate() - days);
        const fmt = (d) => d.toISOString().slice(0, 10);
        const startDate = fmt(start);
        const endDate = fmt(end);

        const [totalsRow] = await query({ startDate, endDate, dimensions: [], rowLimit: 1 });
        const topQueries = await query({ startDate, endDate, dimensions: ['query'], rowLimit: 5 });
        const topPages = await query({ startDate, endDate, dimensions: ['page'], rowLimit: 5 });

        return {
            period: `${startDate} — ${endDate}`,
            totals: totalsRow || null,
            topQueries,
            topPages,
        };
    }

    return { query, getSummary };
}

module.exports = { makeSearchConsoleClient };
