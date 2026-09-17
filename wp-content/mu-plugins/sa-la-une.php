<?php
/**
 * Plugin Name: Souss Actualites – La Une
 * Description: (1) Tout article publie dans Actualites apparait automatiquement
 *              dans "L'Actu du jour" (La Une) sans action manuelle.
 *              (2) Supprime la section commentaires sur les articles de L'Actu du jour
 *              et affiche le resume IA a la place.
 *              (3) Sur la page de la categorie L'Actu du jour : affiche l'extrait
 *              integral (resume IA de 5 lignes) et ajoute un lien
 *              "Lire l'article complet".
 * Version:     1.1.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Identifiant WordPress de la categorie "L'Actu du jour" (slug : lactu-du-jour). */
define( 'SA_LACTU_DU_JOUR_TERM_ID', 16 );

/* ---------------------------------------------------------------------------
 * 1. AUTO-SYNC : Actualites → L'Actu du jour
 *
 * Priorite 20 (apres sa-event-engine.php a priorite 10) pour que le resume IA
 * soit deja genere dans post_excerpt avant que l'article atterrisse dans La Une.
 * Le meta _sa_la_une_synced empeche un double-ajout si l'article est re-sauve.
 * --------------------------------------------------------------------------- */
add_action( 'transition_post_status', 'sa_la_une_auto_sync', 20, 3 );

function sa_la_une_auto_sync( $new_status, $old_status, $post ) {
    if ( 'publish' !== $new_status || 'publish' === $old_status ) {
        return;
    }
    if ( ! $post || 'post' !== $post->post_type ) {
        return;
    }
    if ( ! has_category( 'actualites', $post ) ) {
        return;
    }
    // Ne syncroniser qu'une seule fois (evite les doublons sur mise a jour).
    if ( get_post_meta( $post->ID, '_sa_la_une_synced', true ) ) {
        return;
    }
    wp_set_post_terms( $post->ID, array( SA_LACTU_DU_JOUR_TERM_ID ), 'category', true );
    update_post_meta( $post->ID, '_sa_la_une_synced', 1 );
}

/* ---------------------------------------------------------------------------
 * 2. COMMENTAIRES – Niveau 1 : fermeture via filtre WordPress natif (belt)
 *
 * Correctif v1.0.1 : les themes FSE appellent souvent comments_open() sans
 * argument — $post_id vaut alors 0. On revient au post global dans ce cas.
 * --------------------------------------------------------------------------- */
add_filter( 'comments_open', 'sa_la_une_close_comments', 10, 2 );
add_filter( 'pings_open',    'sa_la_une_close_comments', 10, 2 );

function sa_la_une_close_comments( $open, $post_id ) {
    if ( ! $post_id ) {
        global $post;
        $post_id = $post ? $post->ID : 0;
    }
    if ( $post_id && has_category( 'lactu-du-jour', (int) $post_id ) ) {
        return false;
    }
    return $open;
}

/* ---------------------------------------------------------------------------
 * 2b. COMMENTAIRES – Niveau 2 : remplacement du bloc FSE par le resume IA
 *
 * Les themes block (FSE) utilisent le bloc core/comments pour afficher le
 * formulaire. On intercepte son rendu et on le remplace par le resume IA
 * stocke dans post_excerpt. Double protection avec le filtre ci-dessus.
 * --------------------------------------------------------------------------- */
add_filter( 'render_block', 'sa_la_une_replace_comments_block', 10, 2 );

function sa_la_une_replace_comments_block( $block_content, $block ) {
    // On ne touche qu'au bloc core/comments sur les articles individuels.
    if ( 'core/comments' !== $block['blockName'] ) {
        return $block_content;
    }
    if ( ! is_singular( 'post' ) ) {
        return $block_content;
    }
    global $post;
    $post_id = $post ? $post->ID : get_the_ID();
    if ( ! $post_id || ! has_category( 'lactu-du-jour', (int) $post_id ) ) {
        return $block_content;
    }
    $the_post = get_post( $post_id );
    if ( ! $the_post ) {
        return '';
    }
    $raw = trim( $the_post->post_excerpt );
    // Pas de resume stocke : on supprime simplement le bloc.
    if ( '' === $raw ) {
        return '';
    }
    return '<div class="sa-resume-article">'
        . wpautop( esc_html( $raw ) )
        . '</div>';
}

/* ---------------------------------------------------------------------------
 * 3. ARCHIVE L'ACTU DU JOUR : Extrait integral + lien "Lire l'article complet"
 *
 * Sur la page de la categorie lactu-du-jour, on remplace l'extrait tronque du
 * theme par le post_excerpt brut (resume IA de 5 lignes stocke par
 * sa-event-engine.php). On utilise $post->post_excerpt directement — jamais
 * get_the_excerpt() — pour eviter une recursion infinie dans le filtre.
 * --------------------------------------------------------------------------- */
add_filter( 'the_excerpt', 'sa_la_une_excerpt_with_link', 10 );

function sa_la_une_excerpt_with_link( $excerpt ) {
    if ( ! is_category( 'lactu-du-jour' ) ) {
        return $excerpt;
    }
    global $post;
    if ( ! $post ) {
        return $excerpt;
    }
    $raw = trim( $post->post_excerpt );
    if ( '' !== $raw ) {
        // wpautop ajoute les balises <p> ; esc_html protege le contenu.
        $excerpt = wpautop( esc_html( $raw ) );
    }
    $excerpt .= sprintf(
        '<p class="sa-lire-plus"><a href="%s">%s</a></p>',
        esc_url( get_permalink( $post->ID ) ),
        esc_html__( "Lire l\u2019article complet", 'souss-actualites' )
    );
    return $excerpt;
}

// Longueur d'extrait elevee sur L'Actu du jour pour laisser passer le texte complet.
add_filter( 'excerpt_length', function ( $length ) {
    return is_category( 'lactu-du-jour' ) ? 200 : $length;
}, 10 );
