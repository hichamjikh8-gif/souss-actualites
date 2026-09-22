<?php
/**
 * Plugin Name: TEMP - List WordPress Users
 * Description: Temporary diagnostic tool. Outputs a JSON list of all WP
 *              users (ID, user_login, display_name) when accessed via
 *              ?list_wp_users=1. Self-deletes after being triggered once.
 *              DELETE THIS FILE after use.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook in early, before most of WordPress has loaded, so this works even
 * on a broken/locked-down site. We only act on the specific query param
 * to avoid interfering with normal requests.
 */
add_action( 'muplugins_loaded', function () {

    if ( empty( $_GET['list_wp_users'] ) ) {
        return;
    }

    // Load just enough of WordPress to query users.
    if ( ! function_exists( 'get_users' ) ) {
        require_once ABSPATH . WPINC . '/pluggable.php';
    }

    add_action( 'plugins_loaded', function () {

        if ( empty( $_GET['list_wp_users'] ) ) {
            return;
        }

        $users = get_users( array(
            'fields' => array( 'ID', 'user_login', 'display_name' ),
        ) );

        $data = array();
        foreach ( $users as $user ) {
            $data[] = array(
                'ID'            => (int) $user->ID,
                'user_login'    => $user->user_login,
                'display_name'  => $user->display_name,
            );
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: application/json; charset=UTF-8' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT );

        // Self-delete: this is a one-time diagnostic tool, so remove the
        // plugin file immediately after it has produced output once.
        $plugin_file = __FILE__;
        register_shutdown_function( function () use ( $plugin_file ) {
            if ( file_exists( $plugin_file ) ) {
                @unlink( $plugin_file );
            }
        } );

        exit;
    }, 0 );
}, 0 );
