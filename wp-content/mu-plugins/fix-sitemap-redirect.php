<?php
/**
 * Fix: Block WordPress canonical redirect for Rank Math sitemap URLs.
 * Without this, WP redirects sitemap_index.xml -> homepage.
 */

// 1. Register 'sitemap' and 'xsl' as public query vars early
add_filter('query_vars', function($vars) {
      $vars[] = 'sitemap';
      $vars[] = 'sitemap_n';
      $vars[] = 'xsl';
      return $vars;
}, 1);

// 2. Block canonical redirect when sitemap query var is present
add_filter('redirect_canonical', function($redirect) {
      // Check both the parsed query var and raw $_GET
               if (get_query_var('sitemap') || get_query_var('xsl') || !empty($_GET['sitemap']) || !empty($_GET['xsl'])) {
                         return false;
               }
      return $redirect;
}, 1);

// 3. Also ensure Rank Math sitemap rewrite rule exists
add_action('init', function() {
      add_rewrite_rule('sitemap_index\.xml$', 'index.php?sitemap=1', 'top');
      add_rewrite_rule('([^/]+)-sitemap([0-9]+)?\.xml$', 'index.php?sitemap=$matches[1]&sitemap_n=$matches[2]', 'top');
}, 1);
