<?php
/**
 * 301 redirect: the default WordPress placeholder privacy policy page
 * (/politique-de-confidentialite/) to the real, maintained one
 * (/politique-de-confidentialite-2/), avoiding duplicate content in Google.
 */

add_action('template_redirect', function () {
    if (!is_page('politique-de-confidentialite')) {
        return;
    }

    wp_redirect(home_url('/politique-de-confidentialite-2/'), 301);
    exit;
});
