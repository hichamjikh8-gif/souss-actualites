<?php
/**
 * Plugin Name: Souss Actualites - Performance
 * Description: Allegements cibles du poids des pages, sans toucher au theme ni a la base.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le CSS personnalise des styles globaux contient une regle .custom-logo-link avec un
 * PNG de ~120 Ko encode en base64, annulee plus loin par background-image: none !important.
 * Elle pesait pres de la moitie du CSS inline de chaque page. On retire seulement cette
 * declaration a l'affichage (le reste de la regle et le reglage enregistre restent intacts).
 */
function sa_perf_strip_dead_logo_css( &$node, &$changed ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	foreach ( $node as $key => &$value ) {
		if ( 'css' === $key && is_string( $value ) && false !== strpos( $value, 'data:image/' ) ) {
			$clean = preg_replace( '~background-image\s*:\s*url\(\s*[\'"]?data:image/[^)]*\)\s*;?~i', '', $value );
			if ( is_string( $clean ) && $clean !== $value ) {
				$value   = $clean;
				$changed = true;
			}
		} elseif ( is_array( $value ) ) {
			sa_perf_strip_dead_logo_css( $value, $changed );
		}
	}
	unset( $value );
}

add_filter( 'wp_theme_json_data_user', function ( $theme_json ) {
	if ( ! is_object( $theme_json ) || ! method_exists( $theme_json, 'get_data' ) || ! class_exists( 'WP_Theme_JSON_Data' ) ) {
		return $theme_json;
	}
	$data = $theme_json->get_data();
	if ( ! is_array( $data ) ) {
		return $theme_json;
	}
	$changed = false;
	sa_perf_strip_dead_logo_css( $data, $changed );
	return $changed ? new WP_Theme_JSON_Data( $data, 'custom' ) : $theme_json;
} );

// Dans un theme de blocs, le « CSS additionnel » du Customizer est ajoute au meme bloc
// global-styles-inline-css : c'est la que se trouve cette regle sur le site en ligne.
add_filter( 'wp_get_custom_css', function ( $css ) {
	if ( ! is_string( $css ) || false === strpos( $css, 'data:image/' ) ) {
		return $css;
	}
	$clean = preg_replace( '~background-image\s*:\s*url\(\s*[\'"]?data:image/[^)]*\)\s*;?~i', '', $css );
	return is_string( $clean ) ? $clean : $css;
} );
