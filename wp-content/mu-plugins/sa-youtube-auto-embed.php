<?php
/**
 * Plugin Name: Souss Actualites - Vidéos YouTube automatiques
 * Description: Toutes les heures, lit le flux public de la chaîne YouTube de
 *              Souss Actualités. Quand une nouvelle vidéo porte le même titre
 *              qu'un article publié, la vidéo est insérée sous la photo de
 *              l'article (bloc Embed YouTube). La mise à jour de l'article
 *              déclenche ensuite l'indexation Google habituelle (plugin
 *              Indexation instantanée). Aucun coût : pas d'API, pas de clé.
 * Version:     1.0.0
 * Author:      Souss Actualites
 *
 * Sécurité : tout est entouré de vérifications ; en cas de problème
 * (flux injoignable, XML invalide...) le plugin ne fait rien.
 * Page de contrôle : Outils → Vidéos YouTube auto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SA_YT_CHANNEL_ID', 'UCiuPF8_U1DLILNWwzf1cyvA' );
define( 'SA_YT_CRON_HOOK', 'sa_yt_autoembed_check' );
define( 'SA_YT_OPT_DONE', 'sa_yt_autoembed_done' );
define( 'SA_YT_OPT_LOG', 'sa_yt_autoembed_log' );

/* ---------- Planification (toutes les heures) ---------- */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( SA_YT_CRON_HOOK ) ) {
		wp_schedule_event( time() + 300, 'hourly', SA_YT_CRON_HOOK );
	}
} );
add_action( SA_YT_CRON_HOOK, 'sa_yt_autoembed_run' );

/* ---------- Journal (20 dernières lignes) ---------- */
function sa_yt_log( $msg ) {
	$log = get_option( SA_YT_OPT_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, current_time( 'Y-m-d H:i' ) . ' — ' . $msg );
	update_option( SA_YT_OPT_LOG, array_slice( $log, 0, 20 ), false );
}

/* ---------- Normalisation des titres pour la comparaison ---------- */
function sa_yt_norm( $s ) {
	$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = preg_replace( '/#\S+/u', ' ', $s );              // hashtags
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );   // ponctuation
	return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
}

/* ---------- Lecture du flux YouTube ---------- */
function sa_yt_fetch_videos() {
	$url  = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . SA_YT_CHANNEL_ID;
	$resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		sa_yt_log( 'Flux YouTube injoignable.' );
		return array();
	}
	$body = wp_remote_retrieve_body( $resp );
	if ( ! function_exists( 'simplexml_load_string' ) || '' === $body ) {
		return array();
	}
	$prev = libxml_use_internal_errors( true );
	$xml  = simplexml_load_string( $body );
	libxml_use_internal_errors( $prev );
	if ( false === $xml ) {
		sa_yt_log( 'Flux YouTube illisible.' );
		return array();
	}
	$videos = array();
	foreach ( $xml->entry as $entry ) {
		$yt = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
		$id = trim( (string) $yt->videoId );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{6,20}$/', $id ) ) {
			continue;
		}
		$href = '';
		foreach ( $entry->link as $l ) {
			if ( 'alternate' === (string) $l['rel'] ) {
				$href = (string) $l['href'];
			}
		}
		$videos[] = array(
			'id'        => $id,
			'short'     => ( false !== strpos( $href, '/shorts/' ) ),
			'title'     => (string) $entry->title,
			'published' => strtotime( (string) $entry->published ),
		);
	}
	return $videos;
}

/* ---------- Bloc Embed (identique à celui de l'éditeur) ---------- */
// Short (vertical 9:16) ou vidéo classique (horizontale 16:9), d'après le lien du flux.
function sa_yt_embed_block( $id, $short = true ) {
	$u      = $short ? 'https://www.youtube.com/shorts/' . $id : 'https://www.youtube.com/watch?v=' . $id;
	$aspect = $short ? 'wp-embed-aspect-9-16' : 'wp-embed-aspect-16-9';
	return '<!-- wp:embed {"url":"' . $u . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"' . $aspect . ' wp-has-aspect-ratio"} -->' . "\n"
		. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube ' . $aspect . ' wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n"
		. $u . "\n"
		. '</div></figure>' . "\n"
		. '<!-- /wp:embed -->';
}

/* ---------- Traitement principal ---------- */
function sa_yt_autoembed_run() {
	$videos = sa_yt_fetch_videos();
	if ( empty( $videos ) ) {
		return;
	}
	$done = get_option( SA_YT_OPT_DONE, array() );
	if ( ! is_array( $done ) ) {
		$done = array();
	}

	// Articles publiés des 30 derniers jours.
	$posts = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => 'publish',
		'posts_per_page'   => 300,
		'date_query'       => array( array( 'after' => '30 days ago' ) ),
		'suppress_filters' => true,
	) );
	$by_title = array();
	foreach ( $posts as $p ) {
		$n = sa_yt_norm( $p->post_title );
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
		$n = sa_yt_norm( $v['title'] );
		if ( strlen( $n ) < 15 || ! isset( $by_title[ $n ] ) ) {
			continue; // pas d'article avec exactement ce titre : on réessaiera plus tard
		}
		$post    = $by_title[ $n ];
		$content = (string) $post->post_content;

		// Déjà une vidéo YouTube dans l'article : on ne touche à rien.
		if ( false !== strpos( $content, $v['id'] ) || false !== strpos( $content, 'wp-block-embed-youtube' ) || false !== strpos( $content, 'wp-block-embed-dailymotion' ) ) {
			$done[] = $v['id'];
			continue;
		}

		$block = sa_yt_embed_block( $v['id'], $v['short'] );
		$close = '<!-- /wp:image -->';
		$trim  = ltrim( $content );
		if ( 0 === strpos( $trim, '<!-- wp:image' ) && false !== ( $pos = strpos( $content, $close ) ) ) {
			$at      = $pos + strlen( $close );
			$content = substr( $content, 0, $at ) . "\n\n" . $block . "\n\n" . ltrim( substr( $content, $at ) );
		} else {
			$content = $block . "\n\n" . $content;
		}

		// Pas de filtrage kses (sinon le contenu HTML de l'article pourrait être abîmé en tâche planifiée).
		kses_remove_filters();
		$res = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( $content ),
		), true );
		kses_init();

		if ( is_wp_error( $res ) ) {
			sa_yt_log( 'Erreur sur « ' . $post->post_title . ' » : ' . $res->get_error_message() );
			continue;
		}
		$done[] = $v['id'];
		sa_yt_log( 'Vidéo ' . $v['id'] . ' ajoutée à « ' . $post->post_title . ' ».' );
	}

	update_option( SA_YT_OPT_DONE, array_slice( array_values( array_unique( $done ) ), -300 ), false );
}

/* ---------- Page Outils → Vidéos YouTube auto ---------- */
add_action( 'admin_menu', function () {
	add_management_page( 'Vidéos YouTube auto', 'Vidéos YouTube auto', 'manage_options', 'sa-yt-auto', 'sa_yt_admin_page' );
} );

add_action( 'admin_post_sa_yt_check_now', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Accès refusé.' );
	}
	check_admin_referer( 'sa_yt_check_now' );
	sa_yt_autoembed_run();
	sa_yt_log( 'Vérification manuelle effectuée.' );
	wp_safe_redirect( admin_url( 'tools.php?page=sa-yt-auto' ) );
	exit;
} );

function sa_yt_admin_page() {
	$log  = get_option( SA_YT_OPT_LOG, array() );
	$next = wp_next_scheduled( SA_YT_CRON_HOOK );
	echo '<div class="wrap"><h1>Vidéos YouTube automatiques</h1>';
	echo '<p>Toutes les heures, chaque nouvelle vidéo de votre chaîne YouTube qui porte <strong>le même titre</strong> qu\'un article est ajoutée sous la photo de cet article. Google est ensuite prévenu automatiquement.</p>';
	echo '<p>Prochaine vérification : ' . ( $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : '—' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="sa_yt_check_now">';
	wp_nonce_field( 'sa_yt_check_now' );
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
