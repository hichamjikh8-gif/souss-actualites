<?php
/**
 * Plugin Name: Clear WF IP Blocks (emergency)
 * Description: Wipes Wordfence IP blocks from all tables at load time, before WF can read them.
 */

// Runs at mu-plugin load time — before any regular plugin (including Wordfence)
global $wpdb;
if ( isset( $wpdb ) ) {
    $p = $wpdb->prefix;
    // Wordfence custom IP-block tables
    @$wpdb->query( "DELETE FROM `{$p}wfblocks7`" );
    @$wpdb->query( "DELETE FROM `{$p}wfblockediplog`" );
    // Wordfence Login Security brute-force transients in wp_options
    @$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_wfls_%'" );
    @$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_timeout_wfls_%'" );
    // Also wipe wfls 2FA user-meta so QR setup appears fresh
    @$wpdb->query( "DELETE FROM `{$wpdb->usermeta}` WHERE `meta_key` LIKE 'wfls%'" );
    // Clear the emergency-cleaned transient so the cleanup above fires again if needed
    @$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` = '_transient_wf_emergency_cleaned'" );
}
