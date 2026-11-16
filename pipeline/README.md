# Pipeline vídeo Souss Actualités

Del artículo al vídeo vertical (1080×1920) listo para publicar.

1. `bash setup.sh`: instala Kokoro TTS y su modelo (voz francesa `ff_siwis`).
2. Prepara una carpeta de trabajo con:
   - `script.txt`: una frase por línea. Formato `texto hablado|texto mostrado` cuando la pronunciación y el subtítulo difieran (cifras, «Mohamède six|Mohammed VI»).
   - `imgs/*.jpg`: fotos reales, horizontales, de licencia libre (Wikimedia Commons).
   - `config.json`: `workdir`, `images` (orden), `kicker`, `title`, `byline`, `credits`, `out`.
3. `bash make_video.sh /ruta/carpeta`: voz, música original, montaje y MP4 final.

Publicación: este MP4 se sube a la rama `videos` de este repositorio. YouTube Studio puede leerlo desde `raw.githubusercontent.com`; TikTok lo bloquea y necesita que se elija el archivo.
