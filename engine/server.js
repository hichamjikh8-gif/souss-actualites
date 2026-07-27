const express = require('express');

const app = express();
const PORT = process.env.PORT || 3000;

// Ce petit service web sert uniquement de "health check" pour Railway.
// Le travail d'envoi automatique vers Telegram est fait par le worker (rss-watcher.js).

app.get('/', (req, res) => {
  res.send('Souss Actualites - moteur envoi automatique - service web actif');
  });

  app.get('/health', (req, res) => {
    res.json({ status: 'ok', service: 'web' });
    });

    app.listen(PORT, () => {
      console.log(`Service web demarre sur le port ${PORT}`);
      });
      
