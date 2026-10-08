<?php
/**
 * Plugin Name: SA Sitemap sans cache
 * Description: Desactive le cache du sitemap Rank Math. Les fichiers de cache ne pouvaient plus etre effaces sur le serveur, le sitemap restait fige au 29/09/2026.
 */
add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );
