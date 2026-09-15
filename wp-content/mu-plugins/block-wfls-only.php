<?php
/**
 * Plugin Name: Disable Wordfence (emergency)
 * Description: Forces ALL Wordfence plugins inactive so admin can log in without 2FA or IP blocks.
 */
add_filter('option_active_plugins', function($plugins) {
     return array_values(array_filter((array)$plugins, function($plugin) {
              return strpos($plugin, 'wordfence') === false;
     }));
});
