<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'manage_sgtd_game_posts_columns', 'sgtd_add_list_columns' );
add_action( 'manage_sgtd_game_posts_custom_column', 'sgtd_render_list_column', 10, 2 );

function sgtd_add_list_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['sgtd_thumbnail']   = __( 'Image B', 'sa-spot-the-difference' );
			$new['sgtd_differences'] = __( 'Différences', 'sa-spot-the-difference' );
		}
	}
	return $new;
}

function sgtd_render_list_column( $column, $post_id ) {
	if ( 'sgtd_thumbnail' === $column ) {
		$image_b = absint( get_post_meta( $post_id, '_sgtd_image_b', true ) );
		if ( $image_b ) {
			echo wp_get_attachment_image( $image_b, array( 60, 60 ) );
		} else {
			echo '&#8212;';
		}
	}

	if ( 'sgtd_differences' === $column ) {
		$count = count( sgtd_get_differences( $post_id ) );
		echo esc_html( $count );
	}
}
