<?php
/**
 * Plugin Name: Souss Actualites - Vidéos Dailymotion automatiques
 * Description: Toutes les heures, lit la liste publique des vidéos de la
 *              chaîne Dailymotion de Souss Actualités. Quand une nouvelle
 *              vidéo porte le même titre qu'un article publié, elle est
 *              insérée sous la photo de l'article (bloc Embed Dailymotion,
 *              lecteur adapté au mobile). Fonctionne comme le module YouTube.
 *              Aucune clé nécessaire : l'API publique suffit pour lire la liste.
 * Version:     1.0.0
 * Author:      Souss Actualites
 *
 * Sécurité : en cas de problème (API injoignable, réponse invalide…) le
 * plugin ne fait rien. Page de contrôle : Outils → Vidéos Dailymotion auto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SA_DM_USER', 'x6p981e' );
define( 'SA_DM_CRON_HOOK', 'sa_dm_autoembed_check' );
define( 'SA_DM_OPT_DONE', 'sa_dm_autoembed_done' );
define( 'SA_DM_OPT_LOG', 'sa_dm_autoembed_log' );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( SA_DM_CRON_HOOK ) ) {
		wp_schedule_event( time() + 600, 'hourly', SA_DM_CRON_HOOK );
	}
} );
add_action( SA_DM_CRON_HOOK, 'sa_dm_autoembed_run' );

function sa_dm_log( $msg ) {
	$log = get_option( SA_DM_OPT_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, current_time( 'Y-m-d H:i' ) . ' — ' . $msg );
	update_option( SA_DM_OPT_LOG, array_slice( $log, 0, 20 ), false );
}

// Même normalisation que le module YouTube, pour que les deux comparent les titres pareil.
function sa_dm_norm( $s ) {
	$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = preg_replace( '/#\S+/u', ' ', $s );
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
	return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
}

function sa_dm_fetch_videos() {
	$url  = 'https://api.dailymotion.com/user/' . SA_DM_USER . '/videos?fields=id,title,created_time,aspect_ratio&sort=recent&limit=50';
	$resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		sa_dm_log( 'API Dailymotion injoignable.' );
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $data ) || empty( $data['list'] ) || ! is_array( $data['list'] ) ) {
		return array();
	}
	$videos = array();
	foreach ( $data['list'] as $v ) {
		$id = isset( $v['id'] ) ? (string) $v['id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9]{5,20}$/', $id ) ) {
			continue;
		}
		$ratio    = isset( $v['aspect_ratio'] ) ? (float) $v['aspect_ratio'] : 0;
		$videos[] = array(
			'id'        => $id,
			'vertical'  => ( $ratio > 0 && $ratio < 1 ),
			'title'     => isset( $v['title'] ) ? (string) $v['title'] : '',
			'published' => isset( $v['created_time'] ) ? (int) $v['created_time'] : 0,
		);
	}
	return $videos;
}

function sa_dm_embed_block( $id, $vertical ) {
	$u      = 'https://www.dailymotion.com/video/' . $id;
	$aspect = $vertical ? 'wp-embed-aspect-9-16' : 'wp-embed-aspect-16-9';
	return '<!-- wp:embed {"url":"' . $u . '","type":"video","providerNameSlug":"dailymotion","responsive":true,"className":"' . $aspect . ' wp-has-aspect-ratio"} -->' . "\n"
		. '<figure class="wp-block-embed is-type-video is-provider-dailymotion wp-block-embed-dailymotion ' . $aspect . ' wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n"
		. $u . "\n"
		. '</div></figure>' . "\n"
		. '<!-- /wp:embed -->';
}

function sa_dm_autoembed_run() {
	$videos = sa_dm_fetch_videos();
	if ( empty( $videos ) ) {
		return;
	}
	$done = get_option( SA_DM_OPT_DONE, array() );
	if ( ! is_array( $done ) ) {
		$done = array();
	}

	$posts = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => 'publish',
		'posts_per_page'   => 300,
		'date_query'       => array( array( 'after' => '30 days ago' ) ),
		'suppress_filters' => true,
	) );
	$by_title = array();
	foreach ( $posts as $p ) {
		$n = sa_dm_norm( $p->post_title );
		if ( strlen( $n ) >= 15 && ! isset( $by_title[ $n ] ) ) {
			$by_title[ $n ] = $p;
		}
	}

	foreach ( $videos as $v ) {
		if ( in_array( $v['id'], $done, true ) ) {
			continue;
		}
		if ( $v['published'] && $v['published'] < time() - 30 * DAY_IN_SECONDS ) {
			continue;
		}
		$n = sa_dm_norm( $v['title'] );
		if ( strlen( $n ) < 15 || ! isset( $by_title[ $n ] ) ) {
			continue;
		}
		$post    = $by_title[ $n ];
		$content = (string) $post->post_content;

		// Une seule vidéo par article : s'il y en a déjà une (Dailymotion ou YouTube), on ne touche à rien.
		if ( false !== strpos( $content, $v['id'] ) || false !== strpos( $content, 'wp-block-embed-dailymotion' ) || false !== strpos( $content, 'wp-block-embed-youtube' ) ) {
			$done[] = $v['id'];
			continue;
		}

		$block = sa_dm_embed_block( $v['id'], $v['vertical'] );
		$close = '<!-- /wp:image -->';
		$trim  = ltrim( $content );
		if ( 0 === strpos( $trim, '<!-- wp:image' ) && false !== ( $pos = strpos( $content, $close ) ) ) {
			$at      = $pos + strlen( $close );
			$content = substr( $content, 0, $at ) . "\n\n" . $block . "\n\n" . ltrim( substr( $content, $at ) );
		} else {
			$content = $block . "\n\n" . $content;
		}

		kses_remove_filters();
		$res = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $content ),
		), true );
		kses_init();

		if ( is_wp_error( $res ) ) {
			sa_dm_log( 'Erreur sur « ' . $post->post_title . ' » : ' . $res->get_error_message() );
			continue;
		}
		$done[] = $v['id'];
		sa_dm_log( 'Vidéo ' . $v['id'] . ' ajoutée à « ' . $post->post_title . ' ».' );
	}

	update_option( SA_DM_OPT_DONE, array_slice( array_values( array_unique( $done ) ), -300 ), false );
}

add_action( 'admin_menu', function () {
	add_management_page( 'Vidéos Dailymotion auto', 'Vidéos Dailymotion auto', 'manage_options', 'sa-dm-auto', 'sa_dm_admin_page' );
} );

add_action( 'admin_post_sa_dm_check_now', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Accès refusé.' );
	}
	check_admin_referer( 'sa_dm_check_now' );
	sa_dm_autoembed_run();
	sa_dm_log( 'Vérification manuelle effectuée.' );
	wp_safe_redirect( admin_url( 'tools.php?page=sa-dm-auto' ) );
	exit;
} );

function sa_dm_admin_page() {
	$log  = get_option( SA_DM_OPT_LOG, array() );
	$next = wp_next_scheduled( SA_DM_CRON_HOOK );
	echo '<div class="wrap"><h1>Vidéos Dailymotion automatiques</h1>';
	echo '<p>Toutes les heures, chaque nouvelle vidéo de votre chaîne Dailymotion qui porte <strong>le même titre</strong> qu\'un article est ajoutée sous la photo de cet article. Si l\'article a déjà une vidéo (Dailymotion ou YouTube), rien n\'est ajouté.</p>';
	echo '<p>Prochaine vérification : ' . ( $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : '—' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="sa_dm_check_now">';
	wp_nonce_field( 'sa_dm_check_now' );
	submit_button( 'Vérifier maintenant', 'primary', 'submit', false );
	echo '</form><h2>Journal</h2><ul>';
	if ( empty( $log ) ) {
		echo '<li>Rien pour le moment.</li>';
	} else {
		foreach ( (array) $log as $l ) {
			echo '<li>' . esc_html( $l ) . '</li>';
		}
	}
	echo '</ul></div>';
}
