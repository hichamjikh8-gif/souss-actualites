<?php
/**
 * Plugin Name: SA Auteurs et schema
 * Description: 1) Affiche la biographie de l'auteur sur sa page auteur. 2) Ajoute la biographie dans le schema Rank Math (Person). 3) Retire les doublons NewsArticle / Open Graph (Rank Math reste la seule source).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1) Biographie sous le titre de la page auteur.
add_filter( 'render_block', function ( $html, $block ) {
	if ( ! is_author() || 'core/query-title' !== ( $block['blockName'] ?? '' ) ) {
		return $html;
	}
	$bio = get_the_author_meta( 'description', (int) get_queried_object_id() );
	if ( ! $bio ) {
		return $html;
	}
	return $html . '<p class="sa-author-bio" style="max-width:720px;margin:12px 0 24px;font-size:1.05em;line-height:1.6;">' . esc_html( $bio ) . '</p>';
}, 10, 2 );

// 2) Biographie dans le schema Person de Rank Math.
add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	if ( is_author() ) {
		$uid = (int) get_queried_object_id();
	} elseif ( is_singular( 'post' ) ) {
		$uid = (int) get_post_field( 'post_author', get_queried_object_id() );
	} else {
		return $data;
	}
	$bio = $uid ? get_the_author_meta( 'description', $uid ) : '';
	if ( ! $bio ) {
		return $data;
	}
	foreach ( $data as $key => $entity ) {
		if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
			continue;
		}
		$types = (array) $entity['@type'];
		if ( in_array( 'Person', $types, true ) ) {
			$data[ $key ]['description'] = $bio;
		}
	}
	return $data;
}, 99 );

// 3) Retire les deux anciens blocs NewsArticle + og: en double
//    (mu-plugin schema-news-article.php et functions.php du theme enfant).
add_action( 'wp_head', function () {
	global $wp_filter;
	if ( empty( $wp_filter['wp_head'] ) || ! is_object( $wp_filter['wp_head'] ) ) {
		return;
	}
	foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( ! ( $cb['function'] instanceof Closure ) ) {
				continue;
			}
			try {
				$file = wp_normalize_path( ( new ReflectionFunction( $cb['function'] ) )->getFileName() );
			} catch ( Throwable $e ) {
				continue;
			}
			if ( substr( $file, -strlen( 'mu-plugins/schema-news-article.php' ) ) === 'mu-plugins/schema-news-article.php'
				|| substr( $file, -strlen( 'themes/extendable-child/functions.php' ) ) === 'themes/extendable-child/functions.php' ) {
				remove_action( 'wp_head', $cb['function'], $priority );
			}
		}
	}
}, -1000 );
