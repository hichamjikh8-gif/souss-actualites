<?php
/**
 * Plugin Name: Remove WFLS from DB (emergency)
 * Description: Removes Wordfence Login Security from active_plugins in the DB so 2FA never runs.
 */

// 1. Clear Wordfence IP blocks from custom tables (run before WF loads)
global $wpdb;
if ( isset( $wpdb ) ) {
        $p = $wpdb->prefix;
        @$wpdb->query( "DELETE FROM `{$p}wfblocks7`" );
        @$wpdb->query( "DELETE FROM `{$p}wfblockediplog`" );
        @$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_wfls_%'" );
        @$wpdb->query( "DELETE FROM `{$wpdb->usermeta}` WHERE `meta_key` LIKE 'wfls%'" );
}

// 2. Remove Wordfence Login Security from active_plugins IN THE DATABASE (permanent)
//    This runs before WF loads, and the DB change survives reboots.
add_action( 'muplugins_loaded', function() {
        $active = get_option( 'active_plugins', array() );
        $clean  = array_values( array_filter( (array) $active, function( $p ) {
                    return strpos( $p, 'wordfence-login-security' ) === false;
        } ) );
        if ( $clean !== (array) $active ) {
                    update_option( 'active_plugins', $clean );
        }
}, 1 );

// 3. Also filter at runtime so it never loads even if DB update is delayed
add_filter( 'option_active_plugins', function( $plugins ) {
        return array_values( array_filter( (array) $plugins, function( $p ) {
                    return strpos( $p, 'wordfence-login-security' ) === false;
        } ) );
} );
