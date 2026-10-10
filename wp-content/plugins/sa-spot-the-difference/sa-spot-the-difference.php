<?php
/**
 * Plugin Name: Trouvez les différences — Souss Actualités
 * Description: Jeu interactif "Trouvez les différences" (images avant/après) publiable dans les articles via un bloc Gutenberg ou un shortcode.
 * Version: 1.0.0
 * Author: Souss Actualités
 * Text Domain: sa-spot-the-difference
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGTD_PLUGIN_FILE', __FILE__ );
define( 'SGTD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SGTD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SGTD_VERSION', '1.0.0' );

require_once SGTD_PLUGIN_DIR . 'includes/post-type.php';
require_once SGTD_PLUGIN_DIR . 'includes/admin-metaboxes.php';
require_once SGTD_PLUGIN_DIR . 'includes/admin-list-columns.php';
require_once SGTD_PLUGIN_DIR . 'includes/block.php';
require_once SGTD_PLUGIN_DIR . 'includes/frontend.php';

register_activation_hook( __FILE__, 'sgtd_activate' );

function sgtd_activate() {
	sgtd_register_post_type();
	sgtd_register_taxonomy();
	sgtd_seed_default_regions();
	flush_rewrite_rules();
}
