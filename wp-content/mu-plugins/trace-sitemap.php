<?php
// Sitemap fix: hook parse_query priority 2 (after Rank Math priority 1)
// Directly generate sitemap XML via Rank Math Generator when sitemap query var present
add_action('parse_query', function($query) {
      if (!$query->is_main_query()) return;
      $sitemap_var = get_query_var('sitemap');
      $uri = $_SERVER['REQUEST_URI'] ?? '';
      if (strpos($uri, 'sitemap') === false) return;
      if ($sitemap_var) {
                $gen = new \RankMath\Sitemap\Generator();
                $output = $gen->get_output($sitemap_var, (int) get_query_var('sitemap_n'));
                if (!empty($output)) {
                              while (ob_get_level()) ob_end_clean();
                              header('Content-Type: text/xml; charset=UTF-8');
                              header('X-Sitemap-Source: mu-plugin-direct');
                              echo $output;
                              exit;
                }
      }
}, 2);
