<?php
/**
 * Plugin Name: WF Enable Application Passwords (Temp)
 * Description: Reactive les Application Passwords bloques par Wordfence. SUPPRIMER APRES USAGE.
 */

// Methode 1 : forcer le filtre WordPress (priorite 999 surclasse Wordfence)
add_filter('wp_is_application_passwords_available', '__return_true', 999);
add_filter('wp_is_application_passwords_available_for_user', '__return_true', 999);

// Methode 2 : option wfls_options
add_action('init', function() {
    $wfls = get_option('wfls_options', []);
    if (!is_array($wfls)) { $wfls = maybe_unserialize($wfls); }
    if (!is_array($wfls)) { $wfls = []; }
    $wfls['disableApplicationPasswords'] = false;
    update_option('wfls_options', $wfls);

    // Methode 3 : table wp_wfconfig
    global $wpdb;
    $table = $wpdb->prefix . 'wfconfig';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'loginSecurity:disableApplicationPasswords'");
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'disableApplicationPasswords'");
    }
}, 1);
