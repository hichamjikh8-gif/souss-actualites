<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'sgtd_register_post_type' );
add_action( 'init', 'sgtd_register_taxonomy' );

function sgtd_register_post_type() {
	register_post_type(
		'sgtd_game',
		array(
			'labels'       => array(
				'name'               => __( 'Jeux — Trouvez les différences', 'sa-spot-the-difference' ),
				'singular_name'      => __( 'Jeu', 'sa-spot-the-difference' ),
				'add_new'            => __( 'Ajouter un jeu', 'sa-spot-the-difference' ),
				'add_new_item'       => __( 'Ajouter un nouveau jeu', 'sa-spot-the-difference' ),
				'edit_item'          => __( 'Modifier le jeu', 'sa-spot-the-difference' ),
				'new_item'           => __( 'Nouveau jeu', 'sa-spot-the-difference' ),
				'view_item'          => __( 'Voir le jeu', 'sa-spot-the-difference' ),
				'search_items'       => __( 'Rechercher des jeux', 'sa-spot-the-difference' ),
				'not_found'          => __( 'Aucun jeu trouvé', 'sa-spot-the-difference' ),
				'all_items'          => __( 'Tous les jeux', 'sa-spot-the-difference' ),
				'menu_name'          => __( 'Jeux', 'sa-spot-the-difference' ),
			),
			'public'       => true,
			'show_ui'      => true,
			'show_in_menu' => true,
			'menu_icon'    => 'dashicons-admin-customizer',
			'menu_position'=> 25,
			'show_in_rest' => false,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'rewrite'      => array( 'slug' => 'jeu' ),
			'capability_type' => 'post',
		)
	);
}

function sgtd_register_taxonomy() {
	register_taxonomy(
		'sgtd_region',
		'sgtd_game',
		array(
			'labels'            => array(
				'name'          => __( 'Régions / Villes', 'sa-spot-the-difference' ),
				'singular_name' => __( 'Région / Ville', 'sa-spot-the-difference' ),
				'search_items'  => __( 'Rechercher', 'sa-spot-the-difference' ),
				'all_items'     => __( 'Toutes les régions', 'sa-spot-the-difference' ),
				'edit_item'     => __( 'Modifier', 'sa-spot-the-difference' ),
				'add_new_item'  => __( 'Ajouter une région/ville', 'sa-spot-the-difference' ),
				'menu_name'     => __( 'Régions / Villes', 'sa-spot-the-difference' ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'rewrite'           => array( 'slug' => 'jeu-region' ),
		)
	);
}

/**
 * Termes de départ demandés dans le cahier des charges — n'écrase jamais un terme déjà créé/renommé par le rédacteur.
 */
function sgtd_seed_default_regions() {
	$defaults = array(
		__( 'Agadir', 'sa-spot-the-difference' ),
		__( 'Souss-Massa', 'sa-spot-the-difference' ),
		__( 'Taroudant', 'sa-spot-the-difference' ),
		__( 'Marrakech', 'sa-spot-the-difference' ),
		__( 'Essaouira', 'sa-spot-the-difference' ),
		__( 'Casablanca', 'sa-spot-the-difference' ),
		__( 'Rabat', 'sa-spot-the-difference' ),
		__( 'Fès', 'sa-spot-the-difference' ),
		__( 'Tanger', 'sa-spot-the-difference' ),
		__( 'Culture marocaine', 'sa-spot-the-difference' ),
		__( 'Gastronomie', 'sa-spot-the-difference' ),
		__( 'Monuments', 'sa-spot-the-difference' ),
		__( 'Paysages', 'sa-spot-the-difference' ),
		__( 'Traditions', 'sa-spot-the-difference' ),
	);

	foreach ( $defaults as $term_name ) {
		if ( ! term_exists( $term_name, 'sgtd_region' ) ) {
			wp_insert_term( $term_name, 'sgtd_region' );
		}
	}
}
