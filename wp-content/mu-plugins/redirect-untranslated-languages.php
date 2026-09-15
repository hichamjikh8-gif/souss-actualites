<?php
/**
 * Plugin Name: Redirect Untranslated Languages
 * Description: Redirige /ar/ y /es/ a la portada con 301 permanente, sin aviso.
 * Version: 1.0
 */

add_action( 'init', function () {
      $uri = $_SERVER['REQUEST_URI'] ?? '/';
      if ( preg_match( '#^/(ar|es)(/|$|\?)#', $uri ) ) {
                wp_redirect( home_url( '/' ), 301 );
                exit;
      }
} );
