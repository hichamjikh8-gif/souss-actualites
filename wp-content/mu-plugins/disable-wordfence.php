<?php
/**
 * Plugin Name: Disable Wordfence (permanent)
 * Description: Permanently removes Wordfence from active plugins and blocks
 *              it from executing, even if this mu-plugin is later removed.
 */

// Runtime failsafe: strip Wordfence from the active plugins list on every
// request, in case the database entry ever gets restored.
add_filter('option_active_plugins', function($plugins) {
    return array_values(array_filter((array)$plugins, function($plugin) {
        return strpos($plugin, 'wordfence') === false;
    }));
});

// One-time permanent cleanup: remove Wordfence from the active_plugins
// database entry so it doesn't re-activate on plugin refresh or updates.
add_action('init', function() {
    if (get_option('souss_wf_deactivated')) {
        return;
    }

    $active_plugins = get_option('active_plugins', array());

    $filtered_plugins = array_values(array_filter((array)$active_plugins, function($plugin) {
        return strpos($plugin, 'wordfence') === false;
    }));

    if ($filtered_plugins !== $active_plugins) {
        update_option('active_plugins', $filtered_plugins);
    }

    update_option('souss_wf_deactivated', time());

    error_log('[disable-wordfence] Permanently removed Wordfence from active_plugins in wp_options.');
});
