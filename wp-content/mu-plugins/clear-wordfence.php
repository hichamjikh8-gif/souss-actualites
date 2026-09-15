<?php
/**
 * Plugin Name: Clear Wordfence Lockout (one-time)
 * Description: Deletes Wordfence lockout data once, then never runs again.
 */
add_action('init', function() {
 if (get_option('souss_wf_cleared')) return;
 global $wpdb;
 $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wf\_%'");
 add_option('souss_wf_cleared', time());
 error_log('[clear-wordfence] Cleared Wordfence lockout data from wp_options.');
});
