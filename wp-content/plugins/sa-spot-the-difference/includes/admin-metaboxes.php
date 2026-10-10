<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGTD_MIN_DISTANCE_PERCENT', 3.0 ); // distance mini entre deux différences, en % de la diagonale de l'image.

add_action( 'add_meta_boxes', 'sgtd_add_meta_boxes' );
add_action( 'save_post_sgtd_game', 'sgtd_save_meta_boxes' );
add_action( 'admin_enqueue_scripts', 'sgtd_admin_enqueue_assets' );

function sgtd_add_meta_boxes() {
	add_meta_box(
		'sgtd_images',
		__( 'Images du jeu (originale et modifiée)', 'sa-spot-the-difference' ),
		'sgtd_render_images_metabox',
		'sgtd_game',
		'normal',
		'high'
	);

	add_meta_box(
		'sgtd_differences',
		__( 'Différences à trouver', 'sa-spot-the-difference' ),
		'sgtd_render_differences_metabox',
		'sgtd_game',
		'normal',
		'high'
	);
}

function sgtd_render_images_metabox( $post ) {
	wp_nonce_field( 'sgtd_save_meta', 'sgtd_meta_nonce' );

	$image_a = absint( get_post_meta( $post->ID, '_sgtd_image_a', true ) );
	$image_b = absint( get_post_meta( $post->ID, '_sgtd_image_b', true ) );
	?>
	<p><?php esc_html_e( 'Choisissez deux photos ou illustrations avec le même cadrage : l\'image A (originale) et l\'image B (avec les différences). Les repères de différences se placent sur l\'image B.', 'sa-spot-the-difference' ); ?></p>
	<div class="sgtd-image-pickers">
		<div class="sgtd-image-picker" data-role="a">
			<h4><?php esc_html_e( 'Image A — originale', 'sa-spot-the-difference' ); ?></h4>
			<div class="sgtd-image-preview">
				<?php if ( $image_a ) { echo wp_get_attachment_image( $image_a, 'medium' ); } ?>
			</div>
			<input type="hidden" name="sgtd_image_a" class="sgtd-image-input" value="<?php echo esc_attr( $image_a ); ?>" />
			<button type="button" class="button sgtd-choose-image"><?php esc_html_e( 'Choisir l\'image A', 'sa-spot-the-difference' ); ?></button>
			<button type="button" class="button-link sgtd-remove-image" <?php echo $image_a ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Retirer', 'sa-spot-the-difference' ); ?></button>
		</div>
		<div class="sgtd-image-picker" data-role="b">
			<h4><?php esc_html_e( 'Image B — avec les différences', 'sa-spot-the-difference' ); ?></h4>
			<div class="sgtd-image-preview">
				<?php if ( $image_b ) { echo wp_get_attachment_image( $image_b, 'medium' ); } ?>
			</div>
			<input type="hidden" name="sgtd_image_b" class="sgtd-image-input" value="<?php echo esc_attr( $image_b ); ?>" />
			<button type="button" class="button sgtd-choose-image"><?php esc_html_e( 'Choisir l\'image B', 'sa-spot-the-difference' ); ?></button>
			<button type="button" class="button-link sgtd-remove-image" <?php echo $image_b ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Retirer', 'sa-spot-the-difference' ); ?></button>
		</div>
	</div>
	<?php
}

function sgtd_render_differences_metabox( $post ) {
	$image_b       = absint( get_post_meta( $post->ID, '_sgtd_image_b', true ) );
	$differences   = sgtd_get_differences( $post->ID );
	$differences_json = wp_json_encode( $differences );
	?>
	<p>
		<?php esc_html_e( 'Cliquez sur l\'image ci-dessous pour placer une différence (5, 10, 15 ou autant que vous voulez). Cliquez sur un repère existant pour le retirer.', 'sa-spot-the-difference' ); ?>
	</p>
	<?php if ( ! $image_b ) : ?>
		<p class="sgtd-warning"><?php esc_html_e( 'Choisissez d\'abord l\'image B ci-dessus, puis enregistrez le brouillon pour pouvoir y placer les différences.', 'sa-spot-the-difference' ); ?></p>
	<?php else : ?>
		<div class="sgtd-editor-wrap">
			<div class="sgtd-editor-image" data-image-id="<?php echo esc_attr( $image_b ); ?>">
				<?php echo wp_get_attachment_image( $image_b, 'large', false, array( 'class' => 'sgtd-editor-img', 'draggable' => 'false' ) ); ?>
				<div class="sgtd-marker-layer"></div>
			</div>
			<p class="sgtd-counter"><?php esc_html_e( 'Différences placées :', 'sa-spot-the-difference' ); ?> <strong class="sgtd-count">0</strong></p>
			<p class="sgtd-feedback" aria-live="polite"></p>
		</div>
	<?php endif; ?>
	<textarea name="sgtd_differences" id="sgtd_differences_data" style="display:none"><?php echo esc_textarea( $differences_json ); ?></textarea>
	<?php
}

/**
 * Lit et normalise les différences enregistrées (toujours un tableau de {x,y} en %, 0-100).
 */
function sgtd_get_differences( $post_id ) {
	$raw = get_post_meta( $post_id, '_sgtd_differences', true );
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	return sgtd_sanitize_differences( $decoded );
}

/**
 * Valide chaque point (0-100%) et rejette silencieusement les points trop proches d'un point déjà accepté,
 * pour que la règle anti-rapprochement s'applique même si le JS a été contourné.
 */
function sgtd_sanitize_differences( $points ) {
	$clean = array();
	foreach ( $points as $point ) {
		if ( ! is_array( $point ) || ! isset( $point['x'], $point['y'] ) ) {
			continue;
		}
		$x = max( 0, min( 100, (float) $point['x'] ) );
		$y = max( 0, min( 100, (float) $point['y'] ) );

		$too_close = false;
		foreach ( $clean as $existing ) {
			$distance = sqrt( pow( $x - $existing['x'], 2 ) + pow( $y - $existing['y'], 2 ) );
			if ( $distance < SGTD_MIN_DISTANCE_PERCENT ) {
				$too_close = true;
				break;
			}
		}

		if ( ! $too_close ) {
			$clean[] = array( 'x' => round( $x, 2 ), 'y' => round( $y, 2 ) );
		}
	}
	return $clean;
}

function sgtd_save_meta_boxes( $post_id ) {
	if ( ! isset( $_POST['sgtd_meta_nonce'] ) || ! wp_verify_nonce( $_POST['sgtd_meta_nonce'], 'sgtd_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['sgtd_image_a'] ) ) {
		update_post_meta( $post_id, '_sgtd_image_a', absint( $_POST['sgtd_image_a'] ) );
	}
	if ( isset( $_POST['sgtd_image_b'] ) ) {
		update_post_meta( $post_id, '_sgtd_image_b', absint( $_POST['sgtd_image_b'] ) );
	}

	if ( isset( $_POST['sgtd_differences'] ) ) {
		$decoded = json_decode( wp_unslash( $_POST['sgtd_differences'] ), true );
		$clean   = is_array( $decoded ) ? sgtd_sanitize_differences( $decoded ) : array();
		update_post_meta( $post_id, '_sgtd_differences', wp_json_encode( $clean ) );
	}
}

function sgtd_admin_enqueue_assets( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || 'sgtd_game' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_media();

	$css_file = SGTD_PLUGIN_DIR . 'assets/admin/admin-editor.css';
	$js_file  = SGTD_PLUGIN_DIR . 'assets/admin/admin-editor.js';

	wp_enqueue_style( 'sgtd-admin-editor', SGTD_PLUGIN_URL . 'assets/admin/admin-editor.css', array(), file_exists( $css_file ) ? filemtime( $css_file ) : SGTD_VERSION );
	wp_enqueue_script( 'sgtd-admin-editor', SGTD_PLUGIN_URL . 'assets/admin/admin-editor.js', array( 'jquery' ), file_exists( $js_file ) ? filemtime( $js_file ) : SGTD_VERSION, true );

	wp_localize_script(
		'sgtd-admin-editor',
		'sgtdAdminData',
		array(
			'minDistance' => SGTD_MIN_DISTANCE_PERCENT,
			'i18n'        => array(
				'tooClose' => __( 'Trop proche d\'une autre différence — éloignez un peu le point.', 'sa-spot-the-difference' ),
				'chooseA'  => __( 'Choisir l\'image A', 'sa-spot-the-difference' ),
				'chooseB'  => __( 'Choisir l\'image B', 'sa-spot-the-difference' ),
			),
		)
	);
}
