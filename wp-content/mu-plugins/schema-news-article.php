<?php
/**
 * Plugin Name: Schema.org NewsArticle
 * Description: Injecte automatiquement les balises schema.org NewsArticle dans le head des articles.
 * Version:     1.0.0
 * Author:      Souss Actualités
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
    if ( ! is_single() ) {
        return;
    }

    global $post;

    $title       = get_the_title( $post );
    $author      = get_the_author_meta( 'display_name', $post->post_author );
    $date        = get_the_date( 'c', $post );
    $permalink   = get_permalink( $post );
    $description = wp_trim_words( get_the_excerpt( $post ), 30, '...' );

    $image_id  = get_post_thumbnail_id( $post );
    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
    $image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : $title;

    $schema = array(
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => $title,
        'description'   => $description,
        'datePublished' => $date,
        'author'        => array(
            '@type' => 'Person',
            'name'  => $author,
        ),
        'publisher'     => array(
            '@type' => 'Organization',
            'name'  => 'Souss Actualités',
            'url'   => home_url( '/' ),
        ),
        'mainEntityOfPage' => array(
            '@type' => 'WebPage',
            '@id'   => $permalink,
        ),
        'url' => $permalink,
    );

    if ( $image_url ) {
        $schema['image'] = array(
            '@type'  => 'ImageObject',
            'url'    => $image_url,
            'width'  => (int) wp_get_attachment_metadata( $image_id )['width'],
            'height' => (int) wp_get_attachment_metadata( $image_id )['height'],
        );
    }

    $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

    if ( $json ) {
        printf( "\n" . '<script type="application/ld+json">%s</script>' . "\n", $json );
    }
});
