<?php
/**
 * Plugin Name: Souss Sondages
 * Description: Sondages premium pour Souss Actualités : options avec photos, résultats animés, anti double vote, partage social. Affichage automatique sous la photo et le titre de l'article.
 * Version: 1.0.1
 * Author: Souss Actualités
 * Text Domain: souss-sondages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSOND_VERSION', '1.0.1' );
define( 'SSOND_URL', plugin_dir_url( __FILE__ ) );
define( 'SSOND_TABLE', 'ssond_votes' );

/* -------------------------------------------------------------------------
 * Installation : table des votes (1 ligne par votant et par sondage)
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'ssond_install' );
function ssond_install() {
	global $wpdb;
	$table   = $wpdb->prefix . SSOND_TABLE;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			poll_id BIGINT UNSIGNED NOT NULL,
			option_key VARCHAR(20) NOT NULL,
			voter CHAR(64) NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY poll_voter (poll_id, voter),
			KEY poll_id (poll_id)
		) $charset;"
	);
	if ( ! get_option( 'ssond_salt' ) ) {
		update_option( 'ssond_salt', wp_generate_password( 32, true, true ), false );
	}
}

/* -------------------------------------------------------------------------
 * Type de contenu « Sondage »
 * ---------------------------------------------------------------------- */
add_action( 'init', 'ssond_register_cpt' );
function ssond_register_cpt() {
	register_post_type(
		'ssond_poll',
		array(
			'labels'       => array(
				'name'               => 'Sondages',
				'singular_name'      => 'Sondage',
				'add_new'            => 'Nouveau sondage',
				'add_new_item'       => 'Créer un sondage',
				'edit_item'          => 'Modifier le sondage',
				'all_items'          => 'Tous les sondages',
				'search_items'       => 'Rechercher un sondage',
				'not_found'          => 'Aucun sondage',
				'not_found_in_trash' => 'Aucun sondage dans la corbeille',
				'menu_name'          => 'Sondages',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-chart-bar',
			'menu_position'=> 25,
			'supports'     => array( 'title' ),
			'show_in_rest' => false,
		)
	);
}

add_filter( 'enter_title_here', function ( $t, $post ) {
	return ( 'ssond_poll' === $post->post_type ) ? 'Question du sondage (ex : Qui sera Ballon d’Or africain 2026 ?)' : $t;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Données d'un sondage
 * ---------------------------------------------------------------------- */
function ssond_get_options( $poll_id ) {
	$opts = get_post_meta( $poll_id, '_ssond_options', true );
	return is_array( $opts ) ? $opts : array();
}

function ssond_is_closed( $poll_id ) {
	if ( get_post_meta( $poll_id, '_ssond_closed', true ) ) {
		return true;
	}
	$end = get_post_meta( $poll_id, '_ssond_end', true );
	return $end && strtotime( $end . ' 23:59:59' ) < current_time( 'timestamp' );
}

function ssond_get_results( $poll_id ) {
	global $wpdb;
	$table = $wpdb->prefix . SSOND_TABLE;
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_key, COUNT(*) c FROM $table WHERE poll_id = %d GROUP BY option_key", $poll_id ) );
	$counts = array();
	foreach ( ssond_get_options( $poll_id ) as $o ) {
		$counts[ $o['key'] ] = 0;
	}
	foreach ( $rows as $r ) {
		if ( isset( $counts[ $r->option_key ] ) ) {
			$counts[ $r->option_key ] = (int) $r->c;
		}
	}
	return $counts;
}

function ssond_voter_hash() {
	$ip = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? trim( explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )[0] ) : ( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
	return hash( 'sha256', $ip . '|' . $ua . '|' . get_option( 'ssond_salt', 'souss' ) );
}

/* -------------------------------------------------------------------------
 * Administration : éditeur d'options du sondage
 * ---------------------------------------------------------------------- */
add_action( 'add_meta_boxes', 'ssond_meta_boxes' );
function ssond_meta_boxes() {
	add_meta_box( 'ssond_options', 'Réponses du sondage', 'ssond_options_box', 'ssond_poll', 'normal', 'high' );
	add_meta_box( 'ssond_settings', 'Réglages', 'ssond_settings_box', 'ssond_poll', 'side' );
	add_meta_box( 'ssond_results', 'Résultats en direct', 'ssond_results_box', 'ssond_poll', 'side' );
	add_meta_box( 'ssond_post_poll', 'Sondage de l’article', 'ssond_post_box', 'post', 'side', 'high' );
}

function ssond_options_box( $post ) {
	wp_nonce_field( 'ssond_save', 'ssond_nonce' );
	$opts = ssond_get_options( $post->ID );
	if ( empty( $opts ) ) {
		$opts = array( array( 'key' => '', 'label' => '', 'image' => 0 ), array( 'key' => '', 'label' => '', 'image' => 0 ) );
	}
	echo '<p class="description">Ajoutez autant de réponses que vous voulez. La photo est facultative (idéale pour des joueurs, des personnalités…).</p>';
	echo '<div id="ssond-rows">';
	foreach ( $opts as $o ) {
		ssond_option_row( $o );
	}
	echo '</div>';
	echo '<p><button type="button" class="button button-primary" id="ssond-add">+ Ajouter une réponse</button></p>';
	echo '<script type="text/html" id="tmpl-ssond-row">';
	ssond_option_row( array( 'key' => '', 'label' => '', 'image' => 0 ) );
	echo '</script>';
}

function ssond_option_row( $o ) {
	$img = ! empty( $o['image'] ) ? wp_get_attachment_image_url( (int) $o['image'], 'thumbnail' ) : '';
	?>
	<div class="ssond-row">
		<div class="ssond-thumb" style="<?php echo $img ? 'background-image:url(' . esc_url( $img ) . ')' : ''; ?>"><?php echo $img ? '' : '<span class="dashicons dashicons-format-image"></span>'; ?></div>
		<div class="ssond-fields">
			<input type="text" name="ssond_label[]" value="<?php echo esc_attr( $o['label'] ); ?>" placeholder="Réponse (ex : Achraf Hakimi)" class="widefat">
			<input type="hidden" name="ssond_key[]" value="<?php echo esc_attr( $o['key'] ); ?>">
			<input type="hidden" name="ssond_image[]" value="<?php echo (int) $o['image']; ?>" class="ssond-image-id">
			<button type="button" class="button ssond-pick">Choisir une photo</button>
			<button type="button" class="button-link ssond-unpick">Retirer la photo</button>
		</div>
		<button type="button" class="ssond-remove" title="Supprimer cette réponse">&times;</button>
	</div>
	<?php
}

function ssond_settings_box( $post ) {
	$closed = get_post_meta( $post->ID, '_ssond_closed', true );
	$end    = get_post_meta( $post->ID, '_ssond_end', true );
	$before = get_post_meta( $post->ID, '_ssond_show_before', true );
	?>
	<p><label><input type="checkbox" name="ssond_closed" value="1" <?php checked( $closed ); ?>> Sondage clôturé (affiche seulement les résultats)</label></p>
	<p><label>Date de fin (facultatif)<br><input type="date" name="ssond_end" value="<?php echo esc_attr( $end ); ?>"></label></p>
	<p><label><input type="checkbox" name="ssond_show_before" value="1" <?php checked( $before ); ?>> Afficher les résultats avant le vote</label></p>
	<p class="description">Code court : <code>[sondage id="<?php echo (int) $post->ID; ?>"]</code></p>
	<?php
}

function ssond_results_box( $post ) {
	$counts = ssond_get_results( $post->ID );
	$total  = array_sum( $counts );
	if ( ! $total ) {
		echo '<p>Aucun vote pour le moment.</p>';
		return;
	}
	foreach ( ssond_get_options( $post->ID ) as $o ) {
		$c = $counts[ $o['key'] ] ?? 0;
		printf( '<p><strong>%s</strong><br>%d vote(s) — %s %%</p>', esc_html( $o['label'] ), $c, esc_html( round( $c * 100 / $total ) ) );
	}
	printf( '<p><strong>Total : %d vote(s)</strong></p>', $total );
}

function ssond_post_box( $post ) {
	wp_nonce_field( 'ssond_post_save', 'ssond_post_nonce' );
	$current = (int) get_post_meta( $post->ID, '_ssond_poll_id', true );
	$polls   = get_posts( array( 'post_type' => 'ssond_poll', 'post_status' => 'publish', 'numberposts' => 200 ) );
	echo '<p><select name="ssond_poll_id" style="width:100%"><option value="0">— Aucun sondage —</option>';
	foreach ( $polls as $p ) {
		printf( '<option value="%d" %s>%s</option>', $p->ID, selected( $current, $p->ID, false ), esc_html( $p->post_title ) );
	}
	echo '</select></p><p class="description">Le sondage s’affiche sous la photo et le titre de l’article. <a href="' . esc_url( admin_url( 'post-new.php?post_type=ssond_poll' ) ) . '" target="_blank">Créer un sondage</a></p>';
}

add_action( 'save_post_ssond_poll', 'ssond_save_poll' );
function ssond_save_poll( $post_id ) {
	if ( ! isset( $_POST['ssond_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ssond_nonce'] ) ), 'ssond_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$labels = isset( $_POST['ssond_label'] ) ? (array) wp_unslash( $_POST['ssond_label'] ) : array();
	$keys   = isset( $_POST['ssond_key'] ) ? (array) wp_unslash( $_POST['ssond_key'] ) : array();
	$images = isset( $_POST['ssond_image'] ) ? (array) wp_unslash( $_POST['ssond_image'] ) : array();
	$opts   = array();
	foreach ( $labels as $i => $label ) {
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			continue;
		}
		$key = isset( $keys[ $i ] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $keys[ $i ] ) ) : '';
		if ( '' === $key ) {
			$key = strtolower( wp_generate_password( 8, false, false ) );
		}
		$opts[] = array( 'key' => $key, 'label' => $label, 'image' => isset( $images[ $i ] ) ? absint( $images[ $i ] ) : 0 );
	}
	update_post_meta( $post_id, '_ssond_options', $opts );
	update_post_meta( $post_id, '_ssond_closed', empty( $_POST['ssond_closed'] ) ? '' : '1' );
	update_post_meta( $post_id, '_ssond_show_before', empty( $_POST['ssond_show_before'] ) ? '' : '1' );
	$end = isset( $_POST['ssond_end'] ) ? sanitize_text_field( wp_unslash( $_POST['ssond_end'] ) ) : '';
	update_post_meta( $post_id, '_ssond_end', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ? $end : '' );
}

add_action( 'save_post_post', 'ssond_save_post_poll' );
function ssond_save_post_poll( $post_id ) {
	if ( ! isset( $_POST['ssond_post_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ssond_post_nonce'] ) ), 'ssond_post_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$id = isset( $_POST['ssond_poll_id'] ) ? absint( $_POST['ssond_poll_id'] ) : 0;
	if ( $id ) {
		update_post_meta( $post_id, '_ssond_poll_id', $id );
	} else {
		delete_post_meta( $post_id, '_ssond_poll_id' );
	}
}

add_action( 'admin_enqueue_scripts', 'ssond_admin_assets' );
function ssond_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || 'ssond_poll' !== $screen->post_type ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'ssond-admin', SSOND_URL . 'admin.css', array(), SSOND_VERSION );
	wp_enqueue_script( 'ssond-admin', SSOND_URL . 'admin.js', array( 'jquery' ), SSOND_VERSION, true );
}

/* Colonne « Votes » dans la liste des sondages */
add_filter( 'manage_ssond_poll_posts_columns', function ( $c ) {
	$c['ssond_votes'] = 'Votes';
	$c['ssond_code']  = 'Code court';
	return $c;
} );
add_action( 'manage_ssond_poll_posts_custom_column', function ( $col, $id ) {
	if ( 'ssond_votes' === $col ) {
		echo (int) array_sum( ssond_get_results( $id ) );
	}
	if ( 'ssond_code' === $col ) {
		echo '<code>[sondage id="' . (int) $id . '"]</code>';
	}
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Front : rendu du sondage
 * ---------------------------------------------------------------------- */
function ssond_render( $poll_id, $share_url = '' ) {
	$poll = get_post( $poll_id );
	if ( ! $poll || 'ssond_poll' !== $poll->post_type || 'publish' !== $poll->post_status ) {
		return '';
	}
	$opts = ssond_get_options( $poll_id );
	if ( count( $opts ) < 2 ) {
		return '';
	}
	wp_enqueue_style( 'ssond', SSOND_URL . 'sondage.css', array(), SSOND_VERSION );
	wp_enqueue_script( 'ssond', SSOND_URL . 'sondage.js', array(), SSOND_VERSION, true );
	wp_localize_script( 'ssond', 'SSOND', array( 'api' => esc_url_raw( rest_url( 'souss-sondages/v1/' ) ) ) );

	$has_img = false;
	foreach ( $opts as $o ) {
		if ( ! empty( $o['image'] ) ) {
			$has_img = true;
		}
	}
	$share_url = $share_url ? $share_url : get_permalink();
	$closed    = ssond_is_closed( $poll_id );
	$before    = (bool) get_post_meta( $poll_id, '_ssond_show_before', true );

	ob_start();
	?>
	<section class="ssond <?php echo $has_img ? 'ssond--media' : 'ssond--text'; ?>" id="sondage-<?php echo (int) $poll_id; ?>" data-poll="<?php echo (int) $poll_id; ?>" data-closed="<?php echo $closed ? '1' : '0'; ?>" data-before="<?php echo $before ? '1' : '0'; ?>" aria-live="polite">
		<header class="ssond__head">
			<span class="ssond__kicker"><span class="ssond__dot"></span><?php echo $closed ? 'Sondage clôturé' : 'Sondage'; ?></span>
			<h3 class="ssond__question"><?php echo esc_html( $poll->post_title ); ?></h3>
		</header>
		<ul class="ssond__list" role="list">
			<?php foreach ( $opts as $o ) :
				$img = ! empty( $o['image'] ) ? wp_get_attachment_image_url( (int) $o['image'], 'medium' ) : '';
				?>
				<li class="ssond__opt">
					<button type="button" class="ssond__btn" data-key="<?php echo esc_attr( $o['key'] ); ?>" aria-label="Voter pour <?php echo esc_attr( $o['label'] ); ?>">
						<?php if ( $img ) : ?>
							<span class="ssond__img"><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $o['label'] ); ?>" loading="lazy" decoding="async"></span>
						<?php endif; ?>
						<span class="ssond__body">
							<span class="ssond__row">
								<span class="ssond__label"><?php echo esc_html( $o['label'] ); ?></span>
								<span class="ssond__pct" aria-hidden="true"></span>
							</span>
							<span class="ssond__bar"><span class="ssond__fill"></span></span>
						</span>
						<span class="ssond__check" aria-hidden="true">✓</span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<footer class="ssond__foot">
			<span class="ssond__total"><?php echo $closed ? '' : 'Touchez une réponse pour voter'; ?></span>
			<span class="ssond__share">
				<a class="ssond__sh ssond__sh--wa" target="_blank" rel="noopener" href="https://wa.me/?text=<?php echo rawurlencode( $poll->post_title . ' — Votez : ' . $share_url . '#sondage-' . $poll_id ); ?>" aria-label="Partager sur WhatsApp"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.6 14.1c-.2.7-1.4 1.3-2 1.4-.5.1-1.2.1-1.9-.1-.4-.1-1-.3-1.7-.6-3-1.3-4.9-4.3-5-4.5-.2-.2-1.2-1.6-1.2-3s.8-2.2 1-2.5c.3-.3.6-.4.8-.4h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.5.3.1.1.1.7-.1 1.3z"/></svg></a>
				<a class="ssond__sh ssond__sh--fb" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>" aria-label="Partager sur Facebook"><svg viewBox="0 0 24 24"><path d="M14 8h3V4h-3c-2.8 0-4 1.7-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.7-.6z"/></svg></a>
				<a class="ssond__sh ssond__sh--x" target="_blank" rel="noopener" href="https://x.com/intent/post?text=<?php echo rawurlencode( $poll->post_title . ' — Votez !' ); ?>&url=<?php echo rawurlencode( $share_url ); ?>" aria-label="Partager sur X"><svg viewBox="0 0 24 24"><path d="M17.7 3h3.2l-7 8 8.2 10h-6.4l-5-6.2L4.9 21H1.7l7.5-8.6L1.4 3h6.6l4.5 5.7L17.7 3zm-1.1 16h1.8L7.4 4.9H5.5L16.6 19z"/></svg></a>
				<button type="button" class="ssond__sh ssond__sh--link" data-url="<?php echo esc_url( $share_url . '#sondage-' . $poll_id ); ?>" aria-label="Copier le lien"><svg viewBox="0 0 24 24"><path d="M10.6 13.4a1 1 0 0 0 1.4 0l4-4a3 3 0 0 0-4.2-4.2l-1.5 1.5 1.4 1.4 1.5-1.5a1 1 0 0 1 1.4 1.4l-4 4a1 1 0 0 0 0 1.4zm2.8-2.8a1 1 0 0 0-1.4 0l-4 4a3 3 0 0 0 4.2 4.2l1.5-1.5-1.4-1.4-1.5 1.5a1 1 0 0 1-1.4-1.4l4-4a1 1 0 0 0 0-1.4z"/></svg></button>
			</span>
		</footer>
	</section>
	<?php
	return ob_get_clean();
}

add_shortcode( 'sondage', function ( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts );
	return ssond_render( absint( $atts['id'] ) );
} );

/* Affichage automatique en haut du contenu (= juste sous la photo et le titre) */
add_filter( 'the_content', 'ssond_auto_insert', 20 );
function ssond_auto_insert( $content ) {
	if ( is_admin() || ! is_singular( 'post' ) || (int) get_queried_object_id() !== (int) get_the_ID() ) {
		return $content;
	}
	$poll_id = (int) get_post_meta( get_the_ID(), '_ssond_poll_id', true );
	if ( ! $poll_id || false !== strpos( $content, 'class="ssond ' ) ) {
		return $content;
	}
	$html = ssond_render( $poll_id, get_permalink() );
	// Si l'article commence par sa photo (bloc image), le sondage se place juste après celle-ci.
	if ( preg_match( '/^\s*<figure\b[^>]*wp-block-image[^>]*>.*?<\/figure>/is', $content, $m ) ) {
		return $m[0] . $html . substr( $content, strlen( $m[0] ) );
	}
	return $html . $content;
}

/* -------------------------------------------------------------------------
 * API : état + vote
 * ---------------------------------------------------------------------- */
add_action( 'rest_api_init', function () {
	register_rest_route( 'souss-sondages/v1', '/poll/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'ssond_api_state',
	) );
	register_rest_route( 'souss-sondages/v1', '/poll/(?P<id>\d+)/vote', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ssond_api_vote',
	) );
} );

function ssond_state( $poll_id, $voted_key = null ) {
	global $wpdb;
	if ( null === $voted_key ) {
		$table     = $wpdb->prefix . SSOND_TABLE;
		$voted_key = $wpdb->get_var( $wpdb->prepare( "SELECT option_key FROM $table WHERE poll_id = %d AND voter = %s", $poll_id, ssond_voter_hash() ) );
	}
	$counts = ssond_get_results( $poll_id );
	return array(
		'counts' => $counts,
		'total'  => array_sum( $counts ),
		'voted'  => $voted_key ? $voted_key : null,
		'closed' => ssond_is_closed( $poll_id ),
	);
}

function ssond_valid_poll( $poll_id ) {
	$p = get_post( $poll_id );
	return $p && 'ssond_poll' === $p->post_type && 'publish' === $p->post_status;
}

function ssond_api_state( WP_REST_Request $req ) {
	$id = absint( $req['id'] );
	if ( ! ssond_valid_poll( $id ) ) {
		return new WP_Error( 'ssond_404', 'Sondage introuvable', array( 'status' => 404 ) );
	}
	$res = rest_ensure_response( ssond_state( $id ) );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

function ssond_api_vote( WP_REST_Request $req ) {
	global $wpdb;
	$id  = absint( $req['id'] );
	$key = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $req->get_param( 'option' ) ) );
	if ( ! ssond_valid_poll( $id ) ) {
		return new WP_Error( 'ssond_404', 'Sondage introuvable', array( 'status' => 404 ) );
	}
	if ( ssond_is_closed( $id ) ) {
		return new WP_Error( 'ssond_closed', 'Ce sondage est clôturé.', array( 'status' => 403 ) );
	}
	$valid = wp_list_pluck( ssond_get_options( $id ), 'key' );
	if ( ! in_array( $key, $valid, true ) ) {
		return new WP_Error( 'ssond_bad', 'Réponse invalide.', array( 'status' => 400 ) );
	}
	$table = $wpdb->prefix . SSOND_TABLE;
	$voter = ssond_voter_hash();
	$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (poll_id, option_key, voter, created) VALUES (%d, %s, %s, %s)", $id, $key, $voter, current_time( 'mysql' ) ) );
	$res = rest_ensure_response( ssond_state( $id ) );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/* Lien « Réglages » dans la liste des extensions */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=ssond_poll' ) ) . '">Sondages</a>' );
	return $links;
} );
