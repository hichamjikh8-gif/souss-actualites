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
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'" ) === $table) {
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'loginSecurity:disableApplicationPasswords'");
        $wpdb->query("UPDATE {$table} SET val = '0' WHERE name = 'disableApplicationPasswords'");
    }
}, 1);

// Methode 4 : supprimer les hooks Wordfence qui masquent la section Application Passwords sur la page profil
add_action('wp_loaded', function() {
    global $wp_filter;
    $hooks = ['show_user_profile', 'edit_user_profile', 'personal_options'];
    foreach ($hooks as $hook) {
        if (empty($wp_filter[$hook])) continue;
        foreach ($wp_filter[$hook]->callbacks as $priority => $cbs) {
            foreach ($cbs as $id => $cb) {
                $fn = $cb['function'];
                $is_wf = false;
                if (is_array($fn) && is_object($fn[0])) {
                    $cls = get_class($fn[0]);
                    $is_wf = (stripos($cls, 'wf') !== false || stripos($cls, 'wordfence') !== false);
                } elseif ($fn instanceof Closure) {
                    try {
                        $r = new ReflectionFunction($fn);
                        $f = $r->getFileName();
                        $is_wf = $f && (stripos($f, 'wordfence') !== false || stripos($f, '/wfls') !== false || stripos($f, 'wf-') !== false);
                    } catch (Exception $e) {}
                } elseif (is_string($fn)) {
                    $is_wf = (stripos($fn, 'wfls') !== false || stripos($fn, 'wordfence') !== false);
                }
                if ($is_wf) {
                    unset($wp_filter[$hook]->callbacks[$priority][$id]);
                }
            }
        }
    }
}, 999);
