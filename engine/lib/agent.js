const Anthropic = require('@anthropic-ai/sdk');

// Configuracion via variables de entorno (nunca en duro aqui)
const MODEL = process.env.CLAUDE_MODEL || 'claude-sonnet-5';
const MAX_TOKENS = parseInt(process.env.CLAUDE_MAX_TOKENS || '8000', 10);
const FAL_MODEL = process.env.FAL_MODEL || 'fal-ai/flux/schnell';
const MAX_HISTORY_MESSAGES = 40;
const MAX_TOOL_ITERATIONS = 8;

const SYSTEM_PROMPT = `Sos el asistente editorial de Souss Actualités, periodico digital de Agadir y el Souss-Massa (Marruecos). Conversas en espanol con Hicham, el fundador, por Telegram.

Razonas en EVENTOS, no en articulos sueltos: cuando varias fuentes hablan de lo mismo, es UN solo evento que se enriquece, no varios duplicados. Antes de crear un evento nuevo, busca si ya existe uno similar con buscar_eventos_similares. Si existe, agrega la fuente nueva con agregar_fuente_evento en vez de crear otro. Un evento importante NO se convierte automaticamente en articulo: eso lo decide Hicham (boton "preparar brouillon" en /wp-admin, o vos si el te lo pide explicitamente con preparar_articulo, que solo crea un BROUILLON, nunca publica).

Ademas de esta conversacion, un servicio separado (source-watcher.js) vigila fuentes externas (RSS) y crea automaticamente eventos "detected"/"unverified" cuando encuentra algo nuevo - vas a ver eventos que vos no creaste. Si Hicham te pide revisar lo que detecto la veille, usa buscar_eventos_similares o pedile la lista para detectar duplicados (mismo hecho, URLs distintas) y fusionarlos con fusionar_eventos.

Podes:
- Buscar noticias y contexto actual con la herramienta de busqueda web.
- Registrar lo que encuentres como eventos: crear_evento para algo nuevo, agregar_fuente_evento para enriquecer uno existente, actualizar_evento para cambiar su estado/confianza/resumen a medida que llega mas informacion.
- Redactar articulos periodisticos completos en frances (idioma del periodico), con: titulo SEO, meta descripcion (150-160 caracteres), 3-5 keywords, y cuerpo del articulo en HTML simple (<p>, <h2>, <strong>) de al menos 400 palabras, tono de prensa regional profesional.
- Generar una imagen ilustrativa con generar_imagen cuando te lo pidan para un articulo.
- Publicar un flash de breaking news con publicar_flash: SOLO el titular (max 8 palabras, en frances), que se muestra unicamente en la banda roja de BREAKING NEWS de la portada. NO se crea ninguna pagina de articulo en el sitio. Usalo solo cuando Hicham lo pida explicitamente ("publicalo", "publica esto", "publica el flash", etc), o como parte de la tarea automatica de breaking news. Si el flash corresponde a un evento que ya registraste, pasa su event_key para que quede vinculado.
- Preparar (nunca publicar) un brouillon de articulo a partir de un evento con preparar_articulo, solo cuando Hicham lo pida explicitamente.
- Preparar (nunca publicar) un texto para Facebook, X, Telegram o newsletter con preparar_publicacion_social, solo cuando Hicham lo pida explicitamente: no hay ninguna API de red social conectada, el texto queda guardado para que el lo use manualmente.

Nunca inventes hechos ni trates una sola fuente como automaticamente verdadera: marca la confianza del evento como "unverified" o "partial" hasta tener confirmacion, y "contradictory" si las fuentes se contradicen. Trabajas por etapas dentro de la misma conversacion (buscar -> registrar evento -> redactar -> imagen opcional -> publicar), recordando lo que se dijo antes. Respondele siempre a Hicham en espanol, aunque el articulo publicado quede en frances.`;

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
        name: 'publicar_flash',
        description:
            'Publica un flash de breaking news en souss-actualites.com: se muestra SOLO como titular en la banda roja BREAKING NEWS de la portada. No crea ninguna pagina de articulo ni contenido leible en el sitio.',
        input_schema: {
            type: 'object',
            properties: {
                titulo: { type: 'string', description: 'Titulo del flash en frances, 8 palabras maximo, factual y directo.' },
                event_key: {
                    type: 'string',
                    description: 'Opcional. Si este flash corresponde a un evento ya registrado (EVENT-AAAA-NNNNN), pasa su clave para vincularlo.',
                },
            },
            required: ['titulo'],
        },
    },
    {
        name: 'buscar_eventos_similares',
        description:
            'Antes de crear un evento nuevo, verifica si ya existe uno sobre el mismo tema. Devuelve si la URL ya esta registrada (duplicado exacto) y, si pasas un titulo, una lista de eventos abiertos ordenada por similitud (score 0-1, heuristica por palabras en comun - no es semantica real, uses tu propio criterio ademas del score).',
        input_schema: {
            type: 'object',
            properties: {
                url: { type: 'string', description: 'URL de la fuente que estas evaluando (si la tenes).' },
                titulo: { type: 'string', description: 'Titulo para comparar por similitud con los eventos abiertos (recomendado).' },
            },
        },
    },
    {
        name: 'crear_evento',
        description:
            'Crea un evento nuevo en la newsroom (usar solo si buscar_eventos_similares no encontro nada equivalente). Un evento agrupa varias fuentes sobre el mismo hecho.',
        input_schema: {
            type: 'object',
            properties: {
                titulo: { type: 'string', description: 'Titulo factual y neutro del evento, en frances.' },
                categoria: { type: 'string', enum: ['local', 'national', 'international'] },
                importancia: { type: 'string', enum: ['important', 'to_watch', 'secondary', 'not_relevant'] },
                confianza: { type: 'string', enum: ['unverified', 'partial', 'confirmed', 'contradictory'] },
                resumen: { type: 'string', description: 'Resumen breve de los hechos confirmados hasta ahora.' },
                fuente_url: { type: 'string' },
                fuente_nombre: { type: 'string', description: 'Nombre del medio/fuente (ej: Reuters, MAP, source locale).' },
                fuente_titulo: { type: 'string', description: 'Titulo del articulo fuente.' },
            },
            required: ['titulo'],
        },
    },
    {
        name: 'agregar_fuente_evento',
        description: 'Agrega una fuente nueva a un evento ya existente (en vez de crear un duplicado).',
        input_schema: {
            type: 'object',
            properties: {
                event_key: { type: 'string', description: 'Clave del evento, formato EVENT-AAAA-NNNNN.' },
                url: { type: 'string' },
                fuente_nombre: { type: 'string' },
                titulo: { type: 'string' },
                extracto: { type: 'string' },
            },
            required: ['event_key', 'url'],
        },
    },
    {
        name: 'actualizar_evento',
        description: 'Actualiza el estado, la importancia, la confianza o el resumen de un evento existente a medida que llega nueva informacion.',
        input_schema: {
            type: 'object',
            properties: {
                event_key: { type: 'string' },
                estado: {
                    type: 'string',
                    enum: ['detected', 'researching', 'verified', 'breaking', 'monitoring', 'editor_review', 'published', 'updated', 'archived', 'rejected'],
                },
                confianza: { type: 'string', enum: ['unverified', 'partial', 'confirmed', 'contradictory'] },
                resumen: { type: 'string' },
                nota: { type: 'string', description: 'Nota breve explicando el cambio (para la trazabilidad).' },
            },
            required: ['event_key'],
        },
    },
    {
        name: 'preparar_articulo',
        description:
            'Prepara un BROUILLON de articulo WordPress a partir de un evento (titulo + resumen + lista de fuentes). NO lo publica: Hicham debe revisarlo y publicarlo el mismo. Usar solo cuando lo pida explicitamente.',
        input_schema: {
            type: 'object',
            properties: {
                event_key: { type: 'string' },
            },
            required: ['event_key'],
        },
    },
    {
        name: 'fusionar_eventos',
        description:
            'Fusiona dos eventos que resultan ser el mismo hecho (ej: la veille detecto el mismo evento dos veces desde fuentes distintas con URLs diferentes). El evento origen queda marcado como fusionado (nunca se borra) y todas sus fuentes pasan al evento destino.',
        input_schema: {
            type: 'object',
            properties: {
                event_key_origen: { type: 'string', description: 'Evento duplicado que desaparece (se fusiona).' },
                event_key_destino: { type: 'string', description: 'Evento principal que conserva el historial.' },
            },
            required: ['event_key_origen', 'event_key_destino'],
        },
    },
    {
        name: 'preparar_publicacion_social',
        description:
            'Redacta y guarda un texto listo para un canal (facebook, x, telegram o newsletter) a partir de un evento. NO publica nada en el canal real: no hay ninguna API de Facebook/X conectada, es solo una preparacion que Hicham copia y pega manualmente. Usar solo cuando lo pida explicitamente.',
        input_schema: {
            type: 'object',
            properties: {
                event_key: { type: 'string' },
                canal: { type: 'string', enum: ['facebook', 'x', 'telegram', 'newsletter'] },
                texto: { type: 'string', description: 'Texto redactado en frances, con el formato adecuado al canal.' },
            },
            required: ['event_key', 'canal', 'texto'],
        },
    },
];

function makeAgent({ anthropicApiKey, telegram, wpApi, flashApi, eventsApi, falKey }) {
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

        if (block.name === 'publicar_flash') {
            if (!flashApi) {
                return JSON.stringify({ ok: false, error: 'API de flashes no configurada en el servidor (BOT_API_SECRET).' });
            }
            const titulo = String(block.input.titulo || '').trim();
            if (!titulo) {
                return JSON.stringify({ ok: false, error: 'Titulo de flash vacio.' });
            }
            const created = await flashApi.create(titulo, '');
            const published = await flashApi.publish(created.id);
            const eventKey = block.input.event_key ? String(block.input.event_key).trim() : '';
            if (eventKey && eventsApi) {
                try {
                    await eventsApi.linkFlash(eventKey, published.id, 'agent');
                } catch (err) {
                    console.error('Echec liaison flash/evenement:', err.message);
                }
            }
            return JSON.stringify({ ok: true, id: published.id, titulo, event_key: eventKey || null });
        }

        if (block.name === 'buscar_eventos_similares') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const result = await eventsApi.findSimilar(block.input.url || '', block.input.titulo || '');
            return JSON.stringify({ ok: true, ...result });
        }

        if (block.name === 'crear_evento') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const input = block.input;
            const payload = {
                title: input.titulo,
                category: input.categoria,
                importance: input.importancia,
                confidence: input.confianza,
                summary: input.resumen,
                created_by: 'agent',
            };
            if (input.fuente_url) {
                payload.source = {
                    url: input.fuente_url,
                    source_name: input.fuente_nombre || '',
                    title: input.fuente_titulo || '',
                };
            }
            try {
                const event = await eventsApi.create(payload);
                return JSON.stringify({ ok: true, event });
            } catch (err) {
                return JSON.stringify({ ok: false, error: err.message, event_key_existant: err.eventKey || null });
            }
        }

        if (block.name === 'agregar_fuente_evento') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const input = block.input;
            try {
                const event = await eventsApi.addSource({
                    event_key: input.event_key,
                    url: input.url,
                    source_name: input.fuente_nombre || '',
                    title: input.titulo || '',
                    excerpt: input.extracto || '',
                    actor: 'agent',
                });
                return JSON.stringify({ ok: true, event });
            } catch (err) {
                return JSON.stringify({ ok: false, error: err.message, event_key_existant: err.eventKey || null });
            }
        }

        if (block.name === 'actualizar_evento') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const input = block.input;
            const event = await eventsApi.update({
                event_key: input.event_key,
                status: input.estado,
                confidence: input.confianza,
                summary: input.resumen,
                note: input.nota,
                actor: 'agent',
            });
            return JSON.stringify({ ok: true, event });
        }

        if (block.name === 'preparar_articulo') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const result = await eventsApi.promoteToArticle(block.input.event_key, 'agent');
            return JSON.stringify({ ok: true, ...result, nota: 'Brouillon cree, en attente de validation par Hicham.' });
        }

        if (block.name === 'fusionar_eventos') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const event = await eventsApi.merge(block.input.event_key_origen, block.input.event_key_destino, 'agent');
            return JSON.stringify({ ok: true, event });
        }

        if (block.name === 'preparar_publicacion_social') {
            if (!eventsApi) {
                return JSON.stringify({ ok: false, error: 'API de eventos no configurada en el servidor.' });
            }
            const input = block.input;
            const event = await eventsApi.prepareSocialPost(input.event_key, input.canal, input.texto, 'agent');
            return JSON.stringify({ ok: true, event, nota: 'Guardado como brouillon, nada fue publicado en el canal real.' });
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
