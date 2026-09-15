<?php
/**
 * Plugin Name: Disable Wordfence + Clear 2FA (emergency)
 * Description: Blocks ALL Wordfence plugins AND wipes 2FA secrets so the admin can set up a fresh QR code.
 */

// 1. Block ALL Wordfence plugins from loading (no 2FA, no IP blocks)
add_filter('option_active_plugins', function($plugins) {
         return array_values(array_filter((array)$plugins, function($plugin) {
                      return strpos($plugin, 'wordfence') === false;
         }));
});

// 2. On every request, wipe the Wordfence 2FA secrets and IP blocks from the DB
// This runs once and then disables itself via a transient so it only fires when needed
add_action('init', function() {
         if (get_transient('wf_emergency_cleaned')) return;
         global $wpdb;
         // Delete all wfls user-meta for ALL users
               $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wfls%'");
         // Delete Wordfence IP block options
               $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wf_%'");
         $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wordfence%'");
         // Mark as done for 1 hour
               set_transient('wf_emergency_cleaned', 1, HOUR_IN_SECONDS);
         error_log('[emergency] Wordfence 2FA secrets and IP blocks cleared.');
}, 1);
