<?php
/**
 * Plugin Name: Emergency Fix - DELETE AFTER USE
 * Description: Disable Wordfence temporarily to restore admin access
 */

// Remove Wordfence from active plugins before it loads
add_filter('option_active_plugins', function($plugins) {
      return array_values(array_filter($plugins, function($p) {
                return strpos($p, 'wordfence') === false;
      }));
}, 0);

// Clear Wordfence lockouts from DB
add_action('init', function() {
      global $wpdb;
      $wpdb->query("DELETE FROM {$wpdb->prefix}wfLockedOut");
      $wpdb->query("DELETE FROM {$wpdb->prefix}wfBlockedIPLog WHERE blockedTime > UNIX_TIMESTAMP() - 86400");
}, 1);
