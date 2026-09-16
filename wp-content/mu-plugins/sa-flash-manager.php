<?php
/**
 * Plugin Name: Souss Actualites - Gestion des Flashes Breaking News
 * Description: Stocke les breaking news comme simples titres (flashes) dans une
 *              table dediee, sans creer d'articles WordPress consultables.
 *              Fournit une page d'administration et une API REST pour le bot.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sa_flash_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'sa_breaking_news';
}

function sa_flash_maybe_create_table() {
	global $wpdb;
	$table   = sa_flash_table_name();
	$version = (int) get_option( 'sa_flash_db_version' );
	if ( $version >= 1 && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		return;
	}
	$charset = $wpdb->get_charset_collate();
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			title TEXT NOT NULL,
			url VARCHAR(500) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'publish',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset}"
	);
	update_option( 'sa_flash_db_version', 1 );
}
add_action( 'init', 'sa_flash_maybe_create_table' );

function sa_flash_fetch( $limit = 8 ) {
	global $wpdb;
	if ( ! get_option( 'sa_flash_db_version' ) ) {
		sa_flash_maybe_create_table();
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, title, url, status, created_at FROM " . sa_flash_table_name() . " WHERE status = 'publish' ORDER BY created_at DESC, id DESC LIMIT %d",
		(int) $limit
	) );
	return is_array( $rows ) ? $rows : array();
}

function sa_flash_add( $title, $url = '' ) {
	global $wpdb;
	$title = trim( wp_strip_all_tags( $title ) );
	$url   = trim( esc_url_raw( $url ) );
	if ( '' === $title ) {
		return 0;
	}
	$wpdb->insert( sa_flash_table_name(), array(
		'title'      => $title,
		'url'        => $url,
		'status'     => 'publish',
		'created_at' => current_time( 'mysql' ),
	), array( '%s', '%s', '%s', '%s' ) );
	return (int) $wpdb->insert_id;
}

function sa_flash_set_status( $id, $status ) {
	global $wpdb;
	return $wpdb->update( sa_flash_table_name(), array( 'status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
}

function sa_flash_delete( $id ) {
	global $wpdb;
	return $wpdb->delete( sa_flash_table_name(), array( 'id' => (int) $id ), array( '%d' ) );
}

/* ---------------------------------- ADMIN ---------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page(
		'Flashes Breaking News',
		'Flashes Breaking News',
		'manage_options',
		'sa-flashes',
		'sa_flash_admin_page'
	);
} );

function sa_flash_admin_handle_post() {
	if ( ! isset( $_POST['sa_flash_action'] ) || ! check_admin_referer( 'sa_flash_admin', 'sa_flash_nonce' ) ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['sa_flash_action'] ) );
	if ( 'add' === $action ) {
		$title = isset( $_POST['sa_flash_title'] ) ? wp_unslash( $_POST['sa_flash_title'] ) : '';
		$url   = isset( $_POST['sa_flash_url'] ) ? wp_unslash( $_POST['sa_flash_url'] ) : '';
		if ( '' !== trim( wp_strip_all_tags( $title ) ) ) {
			sa_flash_add( $title, $url );
		}
	} elseif ( 'delete' === $action && isset( $_POST['sa_flash_id'] ) ) {
		sa_flash_delete( (int) $_POST['sa_flash_id'] );
	} elseif ( in_array( $action, array( 'publish', 'unpublish' ), true ) && isset( $_POST['sa_flash_id'] ) ) {
		sa_flash_set_status( (int) $_POST['sa_flash_id'], 'publish' === $action ? 'publish' : 'draft' );
	}
	wp_safe_redirect( add_query_arg( 'page', 'sa-flashes', admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_init', 'sa_flash_admin_handle_post' );

function sa_flash_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$flashes = sa_flash_fetch( 30 );
	$nonce   = wp_create_nonce( 'sa_flash_admin' );
	$table   = sa_flash_table_name();
	?>
	<div class="wrap">
		<h1>Flashes Breaking News</h1>
		<p>Ces titres sont affichés UNIQUEMENT dans le bandeau défilant en haut du site. Aucun article n'est créé sur le site.</p>

		<h2>Ajouter un flash</h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'sa_flash_admin', 'sa_flash_nonce' ); ?>
			<input type="hidden" name="sa_flash_action" value="add" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sa_flash_title">Titre du flash</label></th>
					<td><input name="sa_flash_title" id="sa_flash_title" type="text" class="regular-text" maxlength="200" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sa_flash_url">Lien (optionnel)</label></th>
					<td><input name="sa_flash_url" id="sa_flash_url" type="url" class="regular-text" placeholder="https://..." /></td>
				</tr>
			</table>
			<?php submit_button( 'Ajouter le flash' ); ?>
		</form>

		<h2>Flashes existants</h2>
		<?php if ( ! $flashes ) : ?>
			<p>Aucun flash pour le moment.</p>
		<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>ID</th>
					<th>Titre</th>
					<th>Date</th>
					<th>Statut</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $flashes as $flash ) : ?>
				<tr>
					<td><?php echo esc_html( $flash->id ); ?></td>
					<td>
						<?php echo esc_html( $flash->title ); ?>
						<?php if ( $flash->url ) : ?>
							<a href="<?php echo esc_url( $flash->url ); ?>" target="_blank" rel="noopener">↗</a>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $flash->created_at ); ?></td>
					<td><?php echo 'publish' === $flash->status ? 'Publié' : 'Brouillon'; ?></td>
					<td>
						<form method="post" action="" style="display:inline;">
							<?php wp_nonce_field( 'sa_flash_admin', 'sa_flash_nonce' ); ?>
							<input type="hidden" name="sa_flash_id" value="<?php echo esc_attr( $flash->id ); ?>" />
							<?php if ( 'publish' === $flash->status ) : ?>
								<input type="hidden" name="sa_flash_action" value="unpublish" />
								<button class="button">Mettre en brouillon</button>
							<?php else : ?>
								<input type="hidden" name="sa_flash_action" value="publish" />
								<button class="button button-primary">Publier</button>
							<?php endif; ?>
						</form>
						<form method="post" action="" style="display:inline;">
							<?php wp_nonce_field( 'sa_flash_admin', 'sa_flash_nonce' ); ?>
							<input type="hidden" name="sa_flash_id" value="<?php echo esc_attr( $flash->id ); ?>" />
							<input type="hidden" name="sa_flash_action" value="delete" />
							<button class="button" onclick="return confirm('Supprimer ce flash ?');">Supprimer</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}

/* ----------------------------------- API ----------------------------------- */

add_action( 'rest_api_init', function () {
	$permission = function ( WP_REST_Request $request ) {
		if ( ! defined( 'SOUSS_BOT_SECRET' ) || SOUSS_BOT_SECRET === '' ) {
			return false;
		}
		$provided = $request->get_header( 'x-bot-secret' );
		return is_string( $provided ) && hash_equals( SOUSS_BOT_SECRET, $provided );
	};

	register_rest_route( 'sa-flash/v1', '/list', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function () {
			return sa_flash_fetch( 30 );
		},
	) );

	register_rest_route( 'sa-flash/v1', '/new', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			$url   = esc_url_raw( (string) $request->get_param( 'url' ) );
			if ( '' === $title ) {
				return new WP_Error( 'bad_request', 'Titre requis.', array( 'status' => 400 ) );
			}
			$id = sa_flash_add( $title, $url );
			if ( ! $id ) {
				return new WP_Error( 'insert_failed', 'Echec de l\'insertion.', array( 'status' => 500 ) );
			}
			return array( 'id' => $id, 'title' => $title, 'status' => 'publish' );
		},
	) );

	register_rest_route( 'sa-flash/v1', '/publish', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request->get_param( 'id' );
			if ( ! $id || ! sa_flash_set_status( $id, 'publish' ) ) {
				return new WP_Error( 'not_found', 'Flash introuvable.', array( 'status' => 404 ) );
			}
			return array( 'id' => $id, 'status' => 'publish' );
		},
	) );

	register_rest_route( 'sa-flash/v1', '/unpublish', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request->get_param( 'id' );
			if ( ! $id || ! sa_flash_set_status( $id, 'draft' ) ) {
				return new WP_Error( 'not_found', 'Flash introuvable.', array( 'status' => 404 ) );
			}
			return array( 'id' => $id, 'status' => 'draft' );
		},
	) );

	register_rest_route( 'sa-flash/v1', '/delete', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request->get_param( 'id' );
			if ( ! $id || ! sa_flash_delete( $id ) ) {
				return new WP_Error( 'not_found', 'Flash introuvable.', array( 'status' => 404 ) );
			}
			return array( 'id' => $id, 'deleted' => true );
		},
	) );

	register_rest_route( 'sa-flash/v1', '/trash-post', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id   = (int) $request->get_param( 'id' );
			$post = get_post( $id );
			if ( ! $post || 'post' !== $post->post_type ) {
				return new WP_Error( 'not_found', 'Article introuvable.', array( 'status' => 404 ) );
			}
			$result = wp_update_post( array( 'ID' => $id, 'post_status' => 'trash' ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return array( 'id' => $id, 'trashed' => true );
		},
	) );
} );