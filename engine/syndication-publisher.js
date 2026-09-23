'use strict';
/**
 * SYNDICATION PUBLISHER — Souss Actualités
 * =========================================
 * Publie automatiquement les articles des sources externes (Agadir24, Hespress,
 * La Nouvelle Tribune, Aujourd'hui le Maroc...) directement sur souss-actualites.com,
 * avec attribution claire (nom du journal + lien) et photo de l'article original.
 *
 * Authentification : X-Bot-Secret (BOT_API_SECRET) — meme mecanisme que les
 * autres endpoints de l'API newsroom. Aucun Application Password WP requis.
 *
 * Endpoint utilise : POST /wp-json/souss-bot/v1/syndicate
 * Defini dans : wp-content/mu-plugins/telegram-bot-api.php
 */

const fs = require('fs');
const path = require('path');

const SEEN_DIR = path.join(__dirname, '.seen-sources');
const SYNDICATED_KEY = '_syndicated-articles';

// Categories WordPress
const WP_CATEGORY_MAP = {
    local:         [3],  // Actualites
    national:      [3],  // Actualites
    international: [6],  // International
};

// Mots-cles "flash mondial" -> force categorie International
const BREAKING_KEYWORDS = [
    'عاجل', 'عاجل:', 'breaking', 'flash', 'urgent', 'بيان',
    'زلزال', 'انفجار', 'هجوم', 'اغتيال', 'حرب', 'غارة',
];

// ─── Utilitaires ─────────────────────────────────────────────────────────────

function loadSyndicated() {
    const p = path.join(SEEN_DIR, `${SYNDICATED_KEY}.json`);
    try {
        return new Set(JSON.parse(fs.readFileSync(p, 'utf8')));
    } catch (_) {
        return new Set();
    }
}

function saveSyndicated(set) {
    try {
        fs.mkdirSync(SEEN_DIR, { recursive: true });
        const p = path.join(SEEN_DIR, `${SYNDICATED_KEY}.json`);
        fs.writeFileSync(p, JSON.stringify(Array.from(set).slice(-3000)));
    } catch (err) {
        console.error('[syndicator] Echec sauvegarde syndicated:', err.message);
    }
}

/**
 * Extrait l'URL de l'image og:image depuis une page web.
 */
async function fetchOgImage(articleUrl) {
    try {
        const res = await fetch(articleUrl, {
            headers: { 'User-Agent': 'SoussActualites-Syndicator/1.0' },
            signal: AbortSignal.timeout(10000),
        });
        if (!res.ok) return null;
        const html = await res.text();

        const ogMatch = html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']+)["']/i)
            || html.match(/<meta[^>]+content=["']([^"']+)["'][^>]+property=["']og:image["']/i);
        if (ogMatch && ogMatch[1]) return ogMatch[1].trim();

        const twMatch = html.match(/<meta[^>]+name=["']twitter:image["'][^>]+content=["']([^"']+)["']/i)
            || html.match(/<meta[^>]+content=["']([^"']+)["'][^>]+name=["']twitter:image["']/i);
        if (twMatch && twMatch[1]) return twMatch[1].trim();

        return null;
    } catch (_) {
        return null;
    }
}

function escapeHtml(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Construit le contenu HTML Gutenberg : corps de l'article + separateur +
 * attribution. L'attribution respecte la langue reelle de l'article (bug
 * corrige le 2026-09-17 : le texte etait fige en arabe meme pour les sources
 * francophones comme Foot Mercato ou La Nouvelle Tribune).
 *
 * `isFullHtml` distingue deux cas :
 * - true  : `body` est le HTML complet de l'article (depuis content:encoded,
 *   voir publishSyndicatedArticle) - insere tel quel, WordPress le traite
 *   comme un bloc "freeform" classique (comme du contenu redige a l'ancienne
 *   editeur, sans blocs).
 * - false : `body` est l'extrait court en texte brut (comportement d'origine,
 *   utilise quand la source ne fournit pas content:encoded, ex. Agadir24) -
 *   echappe et enveloppe dans un bloc wp:paragraph comme avant.
 */
function buildPostContent(body, title, sourceName, sourceUrl, isFullHtml) {
    const bodyBlock = isFullHtml
        ? body
        : [
            '<!-- wp:paragraph -->',
            `<p>${escapeHtml(body)}</p>`,
            '<!-- /wp:paragraph -->',
        ].join('\n');

    // Plage Unicode arabe U+0600-U+06FF ; sinon on suppose francais (les deux
    // seules langues editoriales du site, voir docs/newsroom/editorial-charter.md).
    const isArabic = /[؀-ۿ]/.test(`${title} ${body}`);

    const attribution = isArabic
        ? `📰 <strong>المصدر :</strong> <a href="${sourceUrl}" target="_blank" rel="noopener noreferrer">${sourceName}</a>`
        : `📰 <strong>Source :</strong> <a href="${sourceUrl}" target="_blank" rel="noopener noreferrer">${sourceName}</a>`;
    const readMore = isArabic
        ? `<a href="${sourceUrl}" target="_blank" rel="noopener noreferrer">← اقرأ المقال كاملاً على موقع ${sourceName}</a>`
        : `<a href="${sourceUrl}" target="_blank" rel="noopener noreferrer">← Lire l'article complet sur ${sourceName}</a>`;

    return [
        bodyBlock,
        '',
        '<!-- wp:separator {"className":"is-style-wide"} -->',
        '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/>',
        '<!-- /wp:separator -->',
        '',
        '<!-- wp:paragraph {"className":"syndication-attribution"} -->',
        `<p class="syndication-attribution">${attribution}</p>`,
        '<!-- /wp:paragraph -->',
        '',
        '<!-- wp:paragraph {"className":"syndication-readmore"} -->',
        `<p class="syndication-readmore">${readMore}</p>`,
        '<!-- /wp:paragraph -->',
    ].join('\n');
}

/**
 * Determine les categories WP et si c'est un breaking news.
 */
function resolveCategories(sourceCategory, title, excerpt) {
    const text = `${title} ${excerpt}`.toLowerCase();
    const isBreaking = BREAKING_KEYWORDS.some((kw) => text.includes(kw.toLowerCase()));

    if (isBreaking || sourceCategory === 'international') {
        return { categories: [6], isBreaking: true };
    }
    return {
        categories: WP_CATEGORY_MAP[sourceCategory] || [3],
        isBreaking: false,
    };
}

// ─── Fonction principale ──────────────────────────────────────────────────────

/**
 * Publie un article sur WordPress avec attribution source.
 * Appelle /souss-bot/v1/syndicate (X-Bot-Secret, aucun Application Password requis).
 */
async function publishSyndicatedArticle(source, item, eventKey, wpBaseUrl, botSecret) {
    const title = (item.title || '').trim();
    const sourceUrl = item.link;
    const sourceName = source.name;

    if (!title || !sourceUrl) return null;

    // Extrait court en texte brut, utilise uniquement pour la detection des
    // mots-cles "breaking" ci-dessous - jamais republie tel quel.
    const shortExcerpt = (item.contentSnippet || item.content || '').toString().slice(0, 600).trim();

    // content:encoded (RSS 2.0) contient l'article complet chez certaines
    // sources (verifie le 2026-09-23 : La Nouvelle Tribune, Aujourd'hui le
    // Maroc). rss-parser le garde sous cette cle brute sans le fusionner dans
    // item.content, qui lui ne reprend que le <description> court - d'ou le
    // besoin d'aller le chercher explicitement. Quand absent (ex: Agadir24,
    // dont le flux ne fournit qu'un resume tronque), on retombe sur l'extrait
    // court comme avant : aucune regression pour ces sources-la.
    const fullContentHtml = (item['content:encoded'] || '').toString().trim();
    const hasFullContent = fullContentHtml.length > 0;
    const body = hasFullContent ? fullContentHtml : shortExcerpt;

    const { categories, isBreaking } = resolveCategories(source.category, title, shortExcerpt);

    // Recuperer l'URL og:image (le telechargement vers WP media est fait cote PHP)
    let imageUrl = null;
    try {
        imageUrl = await fetchOgImage(sourceUrl);
    } catch (_) {}

    const content = buildPostContent(body, title, sourceName, sourceUrl, hasFullContent);

    const payload = {
        title,
        content,
        source_name: sourceName,
        source_url: sourceUrl,
        event_key: eventKey,
        category_ids: categories,
        ...(imageUrl ? { image_url: imageUrl } : {}),
    };

    const res = await fetch(`${wpBaseUrl}/wp-json/souss-bot/v1/syndicate`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Bot-Secret': botSecret,
        },
        body: JSON.stringify(payload),
        signal: AbortSignal.timeout(30000),
    });

    let data = null;
    try { data = await res.json(); } catch (_) {}

    if (!res.ok) {
        const msg = data && data.message ? data.message : `HTTP ${res.status}`;
        throw new Error(`syndicate endpoint: ${msg}`);
    }

    console.log(`[syndicator] Article publie : "${title}" (post #${data.id}) — ${data.post_url}`);
    if (isBreaking) {
        console.log(`[syndicator] Breaking news detecte : ${title}`);
    }

    return { post_id: data.id, post_url: data.post_url };
}

module.exports = {
    loadSyndicated,
    saveSyndicated,
    publishSyndicatedArticle,
};
