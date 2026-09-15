<?php
/**
 * Plugin Name: Google Site Verification
 * Description: Ajoute la balise meta de validation Google Search Console dans le head.
 * Version:     1.0.0
 * Author:      Souss Actualités
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
    $verification_code = 'MJBDiqGZDBDsP0ku0WCfw07GUYLFybYSrcx_iOreSKI';

    if ( empty( $verification_code ) || 'PLACEHOLDER' === $verification_code ) {
        return;
    }

    printf(
        '<meta name="google-site-verification" content="%s" />' . "\n",
        esc_attr( $verification_code )
    );
} );