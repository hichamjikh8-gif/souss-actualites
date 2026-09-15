<?php
/**
 * Plugin Name: Disable Wordfence (emergency)
 * Description: Forces Wordfence inactive so it cannot block logins.
 */
add_filter('option_active_plugins', function($plugins) {
 return array_values(array_filter((array)$plugins, function($plugin) {
 return strpos($plugin, 'wordfence') === false;
 }));
});
