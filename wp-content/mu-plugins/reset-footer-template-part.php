<?php
/**
 * Le template part "footer" avait ete personnalise manuellement (Site Editor),
 * ce qui empechait le fichier parts/footer.html du theme enfant de s'appliquer.
 * Ce correctif met cette personnalisation a la corbeille (reversible, ne
 * touche a rien d'autre : header/widgets/plugins intacts) pour que le nouveau
 * design du footer, gere par le theme enfant, prenne effet partout.
 * Ne s'execute qu'une seule fois (garde via une option).
 */

add_action('init', function () {
    if (get_option('souss_footer_template_part_reset_v1')) {
        return;
    }

    $custom_footer = get_page_by_path('footer', OBJECT, 'wp_template_part');

    if ($custom_footer && $custom_footer->post_status === 'publish') {
        wp_trash_post($custom_footer->ID);
    }

    update_option('souss_footer_template_part_reset_v1', 1);

    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
});
