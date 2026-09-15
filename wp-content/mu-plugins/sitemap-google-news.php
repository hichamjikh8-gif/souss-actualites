<?php
/**
 * Plugin Name: Sitemap Google News
 * Description: Génère un sitemap Google News à /sitemap-news.xml avec les 50 derniers articles publiés dans les 48 dernières heures.
 * Version:     1.0.0
 * Author:      Souss Actualités
 *
 * @package SoussActualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SA_NEWS_SITEMAP_MAX_ITEMS', 50 );
define( 'SA_NEWS_SITEMAP_FRESH_HOURS', 48 );

add_action( 'init', function () {
    add_rewrite_rule( '^sitemap-news\.xml$', 'index.php?sa_sitemap_news=1', 'top' );
}, 1 );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'sa_sitemap_news';
    return $vars;
}, 1 );

add_filter( 'redirect_canonical', function ( $redirect ) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( strpos( $uri, 'sitemap-news.xml' ) !== false ) {
        return false;
    }
    return $redirect;
}, 1 );

add_action( 'template_redirect', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( ! preg_match( '#^/?sitemap-news\.xml$#', trim( parse_url( $uri, PHP_URL_PATH ) ) ) ) {
        return;
    }

    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => SA_NEWS_SITEMAP_MAX_ITEMS,
        'no_found_rows'  => true,
        'orderby'        => 'post_date',
        'order'          => 'DESC',
        'date_query'     => array(
            array(
                'after'     => SA_NEWS_SITEMAP_FRESH_HOURS . ' hours ago',
                'column'    => 'post_date',
                'inclusive' => true,
            ),
        ),
    ) );

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    header( 'Content-Type: application/xml; charset=UTF-8' );
    header( 'X-Sitemap-Source: mu-plugin-google-news' );

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    echo '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

    foreach ( $posts as $post ) {
        setup_postdata( $post );

        $publication_date = get_post_datetime( $post, 'date' )->format( 'Y-m-d\TH:i:sP' );

        echo "  <url>\n";
        echo '    <loc>' . esc_url( get_permalink( $post ) ) . "</loc>\n";
        echo "    <news:news>\n";
        echo "      <news:publication>\n";
        echo "        <news:name>Souss Actualités</news:name>\n";
        echo "        <news:language>fr</news:language>\n";
        echo "      </news:publication>\n";
        echo '      <news:publication_date>' . esc_html( $publication_date ) . "</news:publication_date>\n";
        echo '      <news:title>' . esc_html( get_the_title( $post ) ) . "</news:title>\n";
        echo "    </news:news>\n";
        echo "  </url>\n";
    }

    wp_reset_postdata();

    echo '</urlset>' . "\n";
    exit;
}, 20 );