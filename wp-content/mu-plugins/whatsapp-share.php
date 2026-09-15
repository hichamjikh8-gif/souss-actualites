<?php
/**
 * Plugin Name: Souss Actualites - Partage WhatsApp
 * Description: Ajoute un bouton vert "Partager sur WhatsApp" sous le titre de
 *              chaque article, avec un lien wa.me contenant le titre et l'URL
 *              de l'article.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}

	$wa_css = '
.sa-whatsapp-wrap {
	margin: 14px 0 18px;
}
.sa-whatsapp-btn {
	display: inline-flex;
	align-items: center;
	gap: 9px;
	background: #25d366;
	color: #ffffff;
	text-decoration: none;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	font-size: 15px;
	font-weight: 600;
	padding: 10px 18px;
	border-radius: 40px;
	transition: background-color 0.2s ease;
}
.sa-whatsapp-btn:hover {
	background: #1eb858;
	color: #ffffff;
}
.sa-whatsapp-btn svg {
	width: 19px;
	height: 19px;
	fill: #ffffff;
	flex: 0 0 auto;
}
';
	echo '<style id="sa-whatsapp-css">' . $wa_css . '</style>' . "\n";
} );

add_filter( 'the_title', function ( $title, $post_id ) {
	if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() ) {
		return $title;
	}

	global $post;
	if ( ! $post || $post->ID !== $post_id || post_password_required( $post ) ) {
		return $title;
	}

	return $title . sa_whatsapp_button( $post );
}, 20, 2 );

/**
 * Construit le bouton de partage WhatsApp (wa.me) avec le titre et l'URL.
 */
function sa_whatsapp_button( $post ) {
	$title = get_the_title( $post );
	$url   = get_permalink( $post );

	// Format attendu par WhatsApp: texte partage suivi de l'URL.
	$text = $title . ' ' . $url;
	$href = 'https://wa.me/?text=' . rawurlencode( $text );

	$icon = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

	return '<div class="sa-whatsapp-wrap"><a class="sa-whatsapp-btn" href="' . esc_url( $href ) . '" target="_blank" rel="noopener nofollow">' . $icon . '<span>Partager sur WhatsApp</span></a></div>';
}