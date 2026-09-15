<?php
/**
 * Plugin Name: Disable Wordfence 2FA (emergency)
 * Description: Disables Wordfence 2FA requirement so admin can log in and reconfigure.
 */
// Delete all Wordfence Login Security data (runs once)
add_action('init', function() {
 if (get_option('souss_2fa_reset_v2')) return;
 global $wpdb;
 // Delete user 2FA secrets
 $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wfls-%'");
 // Delete all wfls options including settings
 $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wfls_%'");
 // Write new settings with 2FA completely disabled
 $settings = array(
 'enable_2fa_for_all' => '0',
 'require_2fa_by_role' => array(),
 'allow_trusted_devices' => '1',
 'grace_period_enabled' => '0',
 );
 update_option('wfls_settings', $settings);
 add_option('souss_2fa_reset_v2', time());
 error_log('[reset-2fa] Wordfence 2FA fully disabled.');
});
// Also filter out 2FA requirement on every request as a failsafe
add_filter('wfls_authenticate_user', function($user, $username, $password) {
 return $user;
}, 999, 3);
