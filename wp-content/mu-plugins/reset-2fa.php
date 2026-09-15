<?php
/**
 * Plugin Name: Reset Wordfence 2FA (one-time)
 * Description: Removes Wordfence 2FA data for the admin user so they can set it up fresh.
 */
add_action('init', function() {
 if (get_option('souss_2fa_reset_done')) return;
 global $wpdb;
 // Delete all Wordfence Login Security 2FA data for all users
 $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wfls-%'");
 $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wfls-%'");
 add_option('souss_2fa_reset_done', time());
 error_log('[reset-2fa] Wordfence 2FA data cleared for all users.');
});
