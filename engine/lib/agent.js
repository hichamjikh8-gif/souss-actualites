const Anthropic = require('@anthropic-ai/sdk');

// Configuracion via variables de entorno (nunca en duro aqui)
const MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-5';
const MAX_TOKENS = parseInt(process.env.CLAUDE_MAX_TOKENS || '8000', 10);
const FAL_MODEL = process.env.FAL_MODEL || 'fal-ai/flux/schnell';
const MAX_HISTORY_MESSAGES = 40;
const MAX_TOOL_ITERATIONS = 8;

const SYSTEM_PROMPT = `Sos el asistente editorial de Souss Actualités, periodico digital de Agadir y el Souss-Massa (Marruecos). Conversas en espanol con Hicham, el fundador, por Telegram.

Podes:
- Buscar noticias y contexto actual con la herramienta de busqueda web.
- Redactar articulos periodisticos completos en frances (idioma del periodico), con: titulo SEO, meta descripcion (150-160 caracteres), 3-5 keywords, y cuerpo del articulo en HTML simple (<p>, <h2>, <strong>) de al menos 400 palabras, tono de prensa regional profesional.
- Generar una imagen ilustrativa con generar_imagen cuando te lo pidan para un articulo.
- Publicar directamente en el sitio con publicar_articulo, SOLO cuando Hicham lo pida explicitamente ("publicalo", "publica esto", "publica el articulo", etc). Nunca publiques por iniciativa propia, ni aunque el articulo ya este completo y listo.

Trabajas por etapas dentro de la misma conversacion (buscar -> redactar -> imagen opcional -> publicar), recordando lo que se dijo antes. Respondele siempre a Hicham en espanol, aunque el articulo publicado quede en frances.`;

function extractText(content) {
    const text = content
        .filter((b) => b.type === 'text')
        .map((b) => b.text)
        .join('\n\n');
    return text || '(sin respuesta de texto)';
}

async function generarImagenFal(prompt, falKey) {
    const response = await fetch(`https://fal.run/${FAL_MODEL}`, {
        method: 'POST',
        headers: {
            Authorization: `Key ${falKey}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ prompt, image_size: 'landscape_16_9' }),
    });

    if (!response.ok) {
        const text = await response.text().catch(() => '');
        throw new Error(`fal.ai HTTP ${response.status}: ${text.slice(0, 200)}`);
    }

    const data = await response.json();
    const url = data && data.images && data.images[0] && data.images[0].url;
    if (!url) {
        throw new Error('fal.ai no devolvio ninguna imagen en la respuesta.');
    }
    return url;
}

const TOOLS = [
    { type: 'web_search_20260209', name: 'web_search', max_uses: 5 },
    {
        name: 'generar_imagen',
        description:
            'Genera una imagen a partir de una descripcion en texto y la envia por Telegram al usuario. Usar cuando pidan una imagen o ilustracion para un articulo.',
        input_schema: {
            type: 'object',
            properties: {
                prompt: {
                    type: 'string',
                    description:
                        'Descripcion visual detallada de la imagen a generar, en ingles para mejor calidad (estilo, escena, ambiente, composicion).',
                },
            },
            required: ['prompt'],
        },
    },
    {
        name: 'publicar_articulo',
        description:
            'Crea y PUBLICA de inmediato un articulo en souss-actualites.com (queda visible al publico al instante). Usar unicamente cuando Hicham pida explicitamente publicar.',
        input_schema: {
            type: 'object',
            properties: {
                titulo: { type: 'string', description: 'Titulo del articulo (SEO, en frances).' },
                contenido_html: {
                    type: 'string',
                    description: 'Cuerpo completo del articulo en HTML simple (<p>, <h2>, <strong>), en frances.',
                },
                imagen_url: {
                    type: 'string',
                    description: 'URL de una imagen ya generada en esta conversacion, para incluir al inicio del articulo (opcional).',
                },
            },
            required: ['titulo', 'contenido_html'],
        },
    },
];

function makeAgent({ anthropicApiKey, telegram, wpApi, falKey }) {
    const client = new Anthropic({ apiKey: anthropicApiKey });
    const conversations = new Map(); // chatId -> Anthropic.MessageParam[]

    function getHistory(chatId) {
        return conversations.get(chatId) || [];
    }

    function setHistory(chatId, messages) {
        const trimmed =
            messages.length > MAX_HISTORY_MESSAGES
                ? messages.slice(messages.length - MAX_HISTORY_MESSAGES)
                : messages;
        conversations.set(chatId, trimmed);
    }

    async function executeTool(chatId, block) {
        if (block.name === 'generar_imagen') {
            if (!falKey) {
                return JSON.stringify({ ok: false, error: 'FAL_KEY no configurada en el servidor.' });
            }
            const prompt = block.input.prompt;
            const imageUrl = await generarImagenFal(prompt, falKey);
            await telegram.sendPhoto(chatId, imageUrl, prompt.slice(0, 200));
            return JSON.stringify({
                ok: true,
                imagen_url: imageUrl,
                nota: 'Imagen ya enviada por Telegram al usuario.',
            });
        }

        if (block.name === 'publicar_articulo') {
            const { titulo, contenido_html, imagen_url } = block.input;
            const fullContent = imagen_url
                ? `<img src="${imagen_url}" alt="${String(titulo).replace(/"/g, '')}" />\n${contenido_html}`
                : contenido_html;
            const draft = await wpApi.createDraft(titulo, fullContent);
            const published = await wpApi.publish(draft.id);
            return JSON.stringify({ ok: true, id: published.id, url: published.url });
        }

        return JSON.stringify({ ok: false, error: `Herramienta desconocida: ${block.name}` });
    }

    async function handleMessage(chatId, userText) {
        const history = getHistory(chatId);
        history.push({ role: 'user', content: userText });

        let iterations = 0;
        while (iterations++ < MAX_TOOL_ITERATIONS) {
            const response = await client.messages.create({
                model: MODEL,
                max_tokens: MAX_TOKENS,
                system: SYSTEM_PROMPT,
                tools: TOOLS,
                messages: history,
            });

            history.push({ role: 'assistant', content: response.content });

            if (response.stop_reason === 'refusal') {
                setHistory(chatId, history);
                return 'No puedo ayudar con esa solicitud.';
            }

            if (response.stop_reason === 'pause_turn') {
                continue; // busqueda web server-side sin terminar; seguimos el turno
            }

            const toolUseBlocks = response.content.filter((b) => b.type === 'tool_use');

            if (toolUseBlocks.length === 0) {
                setHistory(chatId, history);
                return extractText(response.content);
            }

            const toolResults = [];
            for (const block of toolUseBlocks) {
                try {
                    const result = await executeTool(chatId, block);
                    toolResults.push({ type: 'tool_result', tool_use_id: block.id, content: result });
                } catch (err) {
                    toolResults.push({
                        type: 'tool_result',
                        tool_use_id: block.id,
                        content: `Error: ${err.message}`,
                        is_error: true,
                    });
                }
            }
            history.push({ role: 'user', content: toolResults });
        }

        setHistory(chatId, history);
        return 'Se alcanzo el limite de pasos para esta solicitud. ¿Podes reformularla o dividirla en partes?';
    }

    return { handleMessage };
}

module.exports = { makeAgent };
