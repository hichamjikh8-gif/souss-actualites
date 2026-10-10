<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendu partagé par le bloc Gutenberg et le shortcode [sa_spot_diff id="123"].
 * Charge les assets front uniquement au moment où un jeu est réellement affiché sur la page.
 */
function sgtd_render_game_markup( $game_id ) {
	$game = $game_id ? get_post( $game_id ) : null;

	// Un visiteur anonyme ne voit jamais un jeu non publié ; un utilisateur connecté qui peut éditer
	// du contenu peut prévisualiser un jeu encore en brouillon (même logique que l'aperçu d'un article).
	$status_allowed = $game && ( 'publish' === $game->post_status || current_user_can( 'edit_posts' ) );

	if ( ! $game || 'sgtd_game' !== $game->post_type || ! $status_allowed ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return '<p class="sgtd-admin-notice">' . esc_html__( 'Choisissez un jeu "Trouvez les différences" dans le panneau du bloc.', 'sa-spot-the-difference' ) . '</p>';
		}
		return '';
	}

	$image_a = absint( get_post_meta( $game->ID, '_sgtd_image_a', true ) );
	$image_b = absint( get_post_meta( $game->ID, '_sgtd_image_b', true ) );
	$differences = sgtd_get_differences( $game->ID );

	if ( ! $image_a || ! $image_b || empty( $differences ) ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return '<p class="sgtd-admin-notice">' . esc_html__( 'Ce jeu n\'a pas encore ses deux images et ses différences — complétez-le avant de le publier dans un article.', 'sa-spot-the-difference' ) . '</p>';
		}
		return '';
	}

	sgtd_enqueue_frontend_assets();

	$instance_id = 'sgtd-' . $game->ID . '-' . wp_unique_id();

	$image_a_src = wp_get_attachment_image_url( $image_a, 'large' );
	$image_b_src = wp_get_attachment_image_url( $image_b, 'large' );

	$payload = array(
		'total'       => count( $differences ),
		'differences' => $differences,
	);

	ob_start();
	?>
	<div class="sgtd-game" id="<?php echo esc_attr( $instance_id ); ?>" data-total="<?php echo esc_attr( count( $differences ) ); ?>">
		<script type="application/json" class="sgtd-game-data"><?php echo sgtd_safe_inline_json( $payload ); ?></script>

		<h3 class="sgtd-title"><?php echo esc_html( get_the_title( $game ) ); ?></h3>
		<?php if ( has_excerpt( $game ) || $game->post_content ) : ?>
			<p class="sgtd-description"><?php echo esc_html( wp_strip_all_tags( $game->post_excerpt ? $game->post_excerpt : $game->post_content ) ); ?></p>
		<?php endif; ?>

		<div class="sgtd-status-bar">
			<p class="sgtd-counter"><?php esc_html_e( 'Différences trouvées :', 'sa-spot-the-difference' ); ?> <span class="sgtd-found">0</span>/<span class="sgtd-total"><?php echo esc_html( count( $differences ) ); ?></span></p>
			<label class="sgtd-hint-toggle">
				<input type="checkbox" class="sgtd-hint-checkbox" />
				<?php esc_html_e( 'Afficher un indice', 'sa-spot-the-difference' ); ?>
			</label>
		</div>

		<p class="sgtd-feedback" aria-live="polite"></p>

		<div class="sgtd-board">
			<div class="sgtd-image-wrap" data-side="a">
				<img src="<?php echo esc_url( $image_a_src ); ?>" alt="<?php echo esc_attr( get_the_title( $game ) . ' — ' . __( 'original', 'sa-spot-the-difference' ) ); ?>" class="sgtd-img" draggable="false" />
				<div class="sgtd-marker-layer"></div>
			</div>
			<div class="sgtd-image-wrap" data-side="b">
				<img src="<?php echo esc_url( $image_b_src ); ?>" alt="<?php echo esc_attr( get_the_title( $game ) . ' — ' . __( 'modifiée', 'sa-spot-the-difference' ) ); ?>" class="sgtd-img" draggable="false" />
				<div class="sgtd-marker-layer"></div>
			</div>
		</div>

		<div class="sgtd-victory" hidden>
			<p><?php esc_html_e( 'Bravo, vous avez trouvé toutes les différences !', 'sa-spot-the-difference' ); ?></p>
		</div>

		<button type="button" class="sgtd-replay button"><?php esc_html_e( 'Rejouer', 'sa-spot-the-difference' ); ?></button>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * JSON sûr à insérer dans une balise <script type="application/json"> : échappe les séquences
 * qui permettraient de sortir de la balise (données = coordonnées et titre du jeu, pas d'entrée utilisateur libre).
 */
function sgtd_safe_inline_json( $data ) {
	return str_replace( '</script', '<\/script', wp_json_encode( $data ) );
}

function sgtd_enqueue_frontend_assets() {
	$css_file = SGTD_PLUGIN_DIR . 'assets/frontend/game.css';
	$js_file  = SGTD_PLUGIN_DIR . 'assets/frontend/game.js';

	wp_enqueue_style( 'sgtd-game', SGTD_PLUGIN_URL . 'assets/frontend/game.css', array(), file_exists( $css_file ) ? filemtime( $css_file ) : SGTD_VERSION );
	wp_enqueue_script( 'sgtd-game', SGTD_PLUGIN_URL . 'assets/frontend/game.js', array(), file_exists( $js_file ) ? filemtime( $js_file ) : SGTD_VERSION, true );

	wp_localize_script(
		'sgtd-game',
		'sgtdGameI18n',
		array(
			'correct'    => __( 'Bravo, bien vu !', 'sa-spot-the-difference' ),
			'incorrect'  => __( 'Pas tout à fait — essayez encore.', 'sa-spot-the-difference' ),
			'alreadyFound' => __( 'Déjà trouvée.', 'sa-spot-the-difference' ),
		)
	);
}
