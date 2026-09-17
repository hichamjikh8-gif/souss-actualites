<?php
/**
 * Plugin Name: Souss Actualites - WF Enable App Passwords
 * Description: Autorise les mots de passe d'application WordPress
 *              malgre le blocage par defaut de Wordfence.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Wordfence bloque les mots de passe d'application par defaut.
// Ce filtre les reautorie pour que l'API REST puisse s'authentifier.
add_filter( 'wp_is_application_passwords_available', '__return_true' );
