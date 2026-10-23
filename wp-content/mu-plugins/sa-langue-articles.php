<?php
/**
 * Plugin Name: Souss Actualites - Langue des articles
 * Description: Declare l'arabe (Maroc) sur les articles rediges en arabe. Le site est
 *              configure en francais : sans ce correctif, Google recevait « fr-FR » pour
 *              des textes arabes et les montrait peu aux lecteurs marocains qui cherchent
 *              en arabe.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Meme regle que le sitemap Google News (sitemap-google-news.php) : titre en arabe = article arabe.
function sa_lang_post_is_arabic( $post ) {
	$post = get_post( $post );
	return $post && 'post' === $post->post_type && (bool) preg_match( '/\p{Arabic}/u', $post->post_title );
}

function sa_lang_current_is_arabic() {
	return is_singular( 'post' ) && sa_lang_post_is_arabic( get_queried_object_id() );
}

// <html lang="ar-MA">. Pas de dir="rtl" global : il retournerait l'en-tete et le menu du
// site ; le texte de l'article est deja affiche de droite a gauche par le theme.
add_filter( 'language_attributes', function ( $output ) {
	if ( ! sa_lang_current_is_arabic() ) {
		return $output;
	}
	return preg_match( '/lang="[^"]*"/', $output )
		? preg_replace( '/lang="[^"]*"/', 'lang="ar-MA"', $output )
		: trim( $output . ' lang="ar-MA"' );
}, 20 );

add_filter( 'rank_math/opengraph/facebook/og_locale', function ( $locale ) {
	return sa_lang_current_is_arabic() ? 'ar_MA' : $locale;
}, 20 );

// Schema Rank Math : la page et l'article sont en arabe (le site, lui, reste en francais).
add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( ! is_array( $data ) || ! sa_lang_current_is_arabic() ) {
		return $data;
	}
	$types = array( 'Article', 'NewsArticle', 'BlogPosting', 'WebPage' );
	foreach ( $data as $key => $entity ) {
		if ( ! is_array( $entity ) || ! isset( $entity['@type'] ) ) {
			continue;
		}
		$entity_types = (array) $entity['@type'];
		if ( array_intersect( $types, $entity_types ) ) {
			$data[ $key ]['inLanguage'] = 'ar-MA';
		}
	}
	return $data;
}, PHP_INT_MAX ); // Rank Math complete l'article apres la priorite 99 : on passe en dernier.
