<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'sgtd_register_block' );
add_action( 'enqueue_block_editor_assets', 'sgtd_localize_block_editor_games' );
add_shortcode( 'sa_spot_diff', 'sgtd_shortcode' );

function sgtd_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'sgtd-block-editor',
		SGTD_PLUGIN_URL . 'assets/block/editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
		SGTD_VERSION,
		true
	);

	register_block_type(
		'sa/spot-the-difference',
		array(
			'title'           => __( 'Trouvez les différences', 'sa-spot-the-difference' ),
			'description'     => __( 'Insère un jeu "Trouvez les différences" publié.', 'sa-spot-the-difference' ),
			'category'        => 'widgets',
			'icon'            => 'admin-customizer',
			'attributes'      => array(
				'gameId' => array(
					'type'    => 'number',
					'default' => 0,
				),
			),
			'editor_script'   => 'sgtd-block-editor',
			'render_callback' => 'sgtd_render_block',
		)
	);
}

/**
 * N'interroge la liste des jeux publiés que quand l'éditeur de blocs est réellement chargé
 * (pas sur le front, pas sur les autres écrans d'admin) — pas de nouvelle route REST publique nécessaire.
 */
function sgtd_localize_block_editor_games() {
	$games = get_posts(
		array(
			'post_type'      => 'sgtd_game',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$choices = array();
	foreach ( $games as $game ) {
		$choices[] = array(
			'id'    => $game->ID,
			'title' => get_the_title( $game ),
		);
	}

	wp_localize_script( 'sgtd-block-editor', 'sgtdBlockData', array( 'games' => $choices ) );
}

function sgtd_render_block( $attributes ) {
	$game_id = isset( $attributes['gameId'] ) ? absint( $attributes['gameId'] ) : 0;
	return sgtd_render_game_markup( $game_id );
}

function sgtd_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sa_spot_diff' );
	return sgtd_render_game_markup( absint( $atts['id'] ) );
}
