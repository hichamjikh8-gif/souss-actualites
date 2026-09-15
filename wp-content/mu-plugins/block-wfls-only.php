<?php
/**
 * Plugin Name: Block Wordfence Login Security Only (emergency)
 * Description: Blocks ONLY the Wordfence Login Security plugin so admin can log in without 2FA, while keeping main Wordfence active.
 */
add_filter('option_active_plugins', function($plugins) {
 return array_values(array_filter((array)$plugins, function($plugin) {
 // Block ONLY wordfence-login-security, NOT the main wordfence plugin
 return strpos($plugin, 'wordfence-login-security') === false;
 }));
});
