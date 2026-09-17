<?php
/**
 * Plugin Name: WF Enable Application Passwords (Temp)
 * Description: Reactive les Application Passwords bloques par Wordfence. SUPPRIMER APRES USAGE.
 */
add_action('init', function() {
    $wfls = get_option('wfls_options', []);
    if (!is_array($wfls)) $wfls = [];
    $wfls['disableApplicationPasswords'] = false;
    update_option('wfls_options', $wfls);

    global $wpdb;
    $table = $wpdb->prefix . 'wfconfig';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'loginSecurity:disableApplicationPasswords'");
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'disableApplicationPasswords'");
    }
}, 1);
