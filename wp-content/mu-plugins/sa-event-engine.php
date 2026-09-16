<?php
/**
 * Plugin Name: Souss Actualites - Event Engine (Newsroom)
 * Description: Modele d'evenement persistant pour la newsroom : une information
 *              importante devient un EVENEMENT (EVENT-AAAA-NNNNN) qui regroupe
 *              plusieurs sources, une chronologie et un niveau de confiance.
 *              Plusieurs sources qui parlent du meme sujet enrichissent
 *              l'evenement existant au lieu de creer des doublons. Un evenement
 *              peut ensuite produire un flash Breaking News (bandeau) et/ou un
 *              brouillon d'article WordPress - jamais une publication automatique.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------- CONSTANTES ------------------------------- */

function sa_event_allowed_statuses() {
	return array(
		'detected',       // information captee, pas encore travaillee
		'researching',    // en cours de recherche / verification
		'verified',       // faits confirmes par au moins une source fiable
		'breaking',       // un flash Breaking News lui est associe
		'monitoring',     // sous surveillance, en attente de developpements
		'editor_review',  // un brouillon d'article a ete prepare, attend le redacteur en chef
		'published',      // un article WordPress a ete publie pour cet evenement
		'updated',        // evenement publie puis mis a jour depuis
		'archived',       // clos, plus d'evolution attendue
		'rejected',       // ecarte par le redacteur en chef (pas pertinent / non confirme)
	);
}

function sa_event_allowed_importance() {
	return array( 'important', 'to_watch', 'secondary', 'not_relevant' );
}

function sa_event_allowed_confidence() {
	return array( 'unverified', 'partial', 'confirmed', 'contradictory' );
}

function sa_event_allowed_category() {
	return array( 'local', 'national', 'international' );
}

function sa_event_allowed_channels() {
	return array( 'facebook', 'x', 'telegram', 'newsletter' );
}

/* -------------------------------- TABLES ---------------------------------- */

function sa_event_table_events() {
	global $wpdb;
	return $wpdb->prefix . 'sa_events';
}

function sa_event_table_sources() {
	global $wpdb;
	return $wpdb->prefix . 'sa_event_sources';
}

function sa_event_table_log() {
	global $wpdb;
	return $wpdb->prefix . 'sa_event_log';
}

function sa_event_table_social() {
	global $wpdb;
	return $wpdb->prefix . 'sa_event_social_posts';
}

function sa_event_maybe_create_tables() {
	global $wpdb;
	$version = (int) get_option( 'sa_event_engine_db_version' );
	$charset = $wpdb->get_charset_collate();

	if ( $version < 1 ) {
		sa_event_create_tables_v1( $charset );
		update_option( 'sa_event_engine_db_version', 1 );
		$version = 1;
	}

	if ( $version < 2 ) {
		sa_event_create_tables_v2( $charset );
		update_option( 'sa_event_engine_db_version', 2 );
		$version = 2;
	}
}

function sa_event_create_tables_v1( $charset ) {
	global $wpdb;
	$events  = sa_event_table_events();
	$sources = sa_event_table_sources();
	$log     = sa_event_table_log();

	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$events} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			event_key VARCHAR(40) NOT NULL,
			title TEXT NOT NULL,
			summary TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'detected',
			importance VARCHAR(20) NOT NULL DEFAULT 'to_watch',
			confidence VARCHAR(20) NOT NULL DEFAULT 'unverified',
			category VARCHAR(20) NOT NULL DEFAULT 'local',
			breaking_flash_id BIGINT(20) NULL,
			article_post_id BIGINT(20) NULL,
			merged_into BIGINT(20) NULL,
			created_by VARCHAR(100) NOT NULL DEFAULT 'system',
			first_detected_at DATETIME NOT NULL,
			last_updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_key (event_key)
		) {$charset}"
	);

	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$sources} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) NOT NULL,
			url VARCHAR(500) NOT NULL DEFAULT '',
			url_hash CHAR(64) NOT NULL DEFAULT '',
			source_name VARCHAR(200) NOT NULL DEFAULT '',
			title TEXT NULL,
			excerpt TEXT NULL,
			added_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id),
			KEY url_hash (url_hash)
		) {$charset}"
	);

	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$log} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) NOT NULL,
			actor VARCHAR(100) NOT NULL DEFAULT 'system',
			action VARCHAR(50) NOT NULL,
			detail TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) {$charset}"
	);

}

function sa_event_create_tables_v2( $charset ) {
	global $wpdb;
	$social = sa_event_table_social();

	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$social} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			event_id BIGINT(20) NOT NULL,
			channel VARCHAR(20) NOT NULL,
			content TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_by VARCHAR(100) NOT NULL DEFAULT 'agent',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) {$charset}"
	);
}
add_action( 'init', 'sa_event_maybe_create_tables' );

/* ------------------------------- HELPERS ----------------------------------- */

function sa_event_log( $event_id, $actor, $action, $detail = array() ) {
	global $wpdb;
	$wpdb->insert( sa_event_table_log(), array(
		'event_id'   => (int) $event_id,
		'actor'      => sanitize_text_field( (string) $actor ),
		'action'     => sanitize_key( $action ),
		'detail'     => wp_json_encode( $detail ),
		'created_at' => current_time( 'mysql' ),
	), array( '%d', '%s', '%s', '%s', '%s' ) );
}

function sa_event_touch( $event_id ) {
	global $wpdb;
	$wpdb->update( sa_event_table_events(),
		array( 'last_updated_at' => current_time( 'mysql' ) ),
		array( 'id' => (int) $event_id ),
		array( '%s' ), array( '%d' )
	);
}

function sa_event_next_key() {
	global $wpdb;
	$year  = current_time( 'Y' );
	$table = sa_event_table_events();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE event_key LIKE %s",
		'EVENT-' . $year . '-%'
	) );
	// Boucle courte pour eviter une collision improbable si deux creations
	// simultanees tombent sur le meme numero (pas de verrou distribue ici).
	for ( $i = $count + 1; $i < $count + 50; $i++ ) {
		$candidate = sprintf( 'EVENT-%s-%05d', $year, $i );
		$exists    = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE event_key = %s", $candidate
		) );
		if ( ! $exists ) {
			return $candidate;
		}
	}
	return 'EVENT-' . $year . '-' . uniqid();
}

function sa_event_get_by_key( $event_key ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . sa_event_table_events() . " WHERE event_key = %s",
		$event_key
	) );
	return $row ?: null;
}

function sa_event_get_sources( $event_id, $limit = 50 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . sa_event_table_sources() . " WHERE event_id = %d ORDER BY added_at ASC LIMIT %d",
		(int) $event_id, (int) $limit
	) );
}

function sa_event_get_log( $event_id, $limit = 50 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . sa_event_table_log() . " WHERE event_id = %d ORDER BY created_at DESC LIMIT %d",
		(int) $event_id, (int) $limit
	) );
}

/**
 * Verifie si une URL est deja rattachee a un evenement (deduplication stricte).
 * Retourne l'event_key existant, ou null.
 */
function sa_event_find_by_url( $url ) {
	global $wpdb;
	$hash = hash( 'sha256', trim( $url ) );
	$row  = $wpdb->get_row( $wpdb->prepare(
		"SELECT e.event_key FROM " . sa_event_table_sources() . " s
		 INNER JOIN " . sa_event_table_events() . " e ON e.id = s.event_id
		 WHERE s.url_hash = %s ORDER BY s.added_at ASC LIMIT 1",
		$hash
	) );
	return $row ? $row->event_key : null;
}

function sa_event_add_source_row( $event_id, $url, $source_name, $title, $excerpt ) {
	global $wpdb;
	$wpdb->insert( sa_event_table_sources(), array(
		'event_id'    => (int) $event_id,
		'url'         => esc_url_raw( (string) $url ),
		'url_hash'    => $url ? hash( 'sha256', trim( $url ) ) : '',
		'source_name' => sanitize_text_field( (string) $source_name ),
		'title'       => sanitize_text_field( (string) $title ),
		'excerpt'     => wp_kses_post( (string) $excerpt ),
		'added_at'    => current_time( 'mysql' ),
	), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	return (int) $wpdb->insert_id;
}

/**
 * Enregistre un texte pret pour un canal social (Facebook/X/Telegram/newsletter).
 * N'envoie jamais rien : c'est une preparation, la publication reelle sur le
 * reseau est manuelle (aucune API Facebook/X n'est connectee a ce projet).
 */
function sa_event_social_add( $event_id, $channel, $content, $created_by ) {
	global $wpdb;
	$wpdb->insert( sa_event_table_social(), array(
		'event_id'   => (int) $event_id,
		'channel'    => sanitize_key( $channel ),
		'content'    => wp_kses_post( (string) $content ),
		'status'     => 'draft',
		'created_by' => sanitize_text_field( (string) $created_by ),
		'created_at' => current_time( 'mysql' ),
	), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
	return (int) $wpdb->insert_id;
}

function sa_event_social_list( $event_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM " . sa_event_table_social() . " WHERE event_id = %d ORDER BY created_at DESC",
		(int) $event_id
	) );
}

function sa_event_social_mark_posted( $id ) {
	global $wpdb;
	return $wpdb->update( sa_event_table_social(),
		array( 'status' => 'posted_manually' ),
		array( 'id' => (int) $id ),
		array( '%s' ), array( '%d' )
	);
}

/**
 * Cree le brouillon d'article WordPress associe a un evenement. Le brouillon
 * n'est JAMAIS publie automatiquement : le redacteur en chef doit le valider
 * et le publier lui-meme (via /wp-admin ou la commande /publish du bot).
 */
function sa_event_promote_to_article( $event_key, $actor ) {
	$event = sa_event_get_by_key( $event_key );
	if ( ! $event ) {
		return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
	}
	if ( $event->article_post_id ) {
		return new WP_Error( 'already_promoted', 'Un brouillon existe deja pour cet evenement.', array( 'status' => 409 ) );
	}

	$sources = sa_event_get_sources( $event->id );
	$body    = '';
	if ( $event->summary ) {
		$body .= '<p>' . esc_html( $event->summary ) . '</p>' . "\n";
	}
	if ( $sources ) {
		$body .= "<h2>Sources</h2>\n<ul>\n";
		foreach ( $sources as $s ) {
			$label = $s->source_name ? $s->source_name : $s->url;
			if ( $s->url ) {
				$body .= '<li><a href="' . esc_url( $s->url ) . '">' . esc_html( $label ) . '</a></li>' . "\n";
			} else {
				$body .= '<li>' . esc_html( $label ) . '</li>' . "\n";
			}
		}
		$body .= "</ul>\n";
	}

	$author = get_user_by( 'login', 'lahcen' );
	$post_id = wp_insert_post( array(
		'post_title'   => wp_strip_all_tags( $event->title ),
		'post_content' => $body,
		'post_status'  => 'draft',
		'post_author'  => $author ? $author->ID : 1,
	), true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	global $wpdb;
	$wpdb->update( sa_event_table_events(), array(
		'article_post_id' => $post_id,
		'status'           => 'editor_review',
		'last_updated_at'  => current_time( 'mysql' ),
	), array( 'id' => $event->id ), array( '%d', '%s', '%s' ), array( '%d' ) );

	sa_event_log( $event->id, $actor, 'article_draft_created', array( 'post_id' => $post_id ) );

	return array(
		'event_key' => $event_key,
		'post_id'   => $post_id,
		'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
	);
}

/* --------------------------------- API -------------------------------------
 * Meme mecanisme d'authentification que souss-bot/v1 et sa-flash/v1 :
 * en-tete X-Bot-Secret compare a la constante SOUSS_BOT_SECRET (definie via
 * WORDPRESS_CONFIG_EXTRA sur Railway, jamais en dur ici).
 * ---------------------------------------------------------------------------
 */

add_action( 'rest_api_init', function () {
	$permission = function ( WP_REST_Request $request ) {
		if ( ! defined( 'SOUSS_BOT_SECRET' ) || SOUSS_BOT_SECRET === '' ) {
			return false;
		}
		$provided = $request->get_header( 'x-bot-secret' );
		return is_string( $provided ) && hash_equals( SOUSS_BOT_SECRET, $provided );
	};

	$shape_event = function ( $row, $with_details = false ) {
		if ( ! $row ) {
			return null;
		}
		$out = array(
			'event_key'         => $row->event_key,
			'title'             => $row->title,
			'summary'           => $row->summary,
			'status'            => $row->status,
			'importance'        => $row->importance,
			'confidence'        => $row->confidence,
			'category'          => $row->category,
			'breaking_flash_id' => $row->breaking_flash_id ? (int) $row->breaking_flash_id : null,
			'article_post_id'   => $row->article_post_id ? (int) $row->article_post_id : null,
			'article_url'       => $row->article_post_id ? get_permalink( (int) $row->article_post_id ) : null,
			'merged_into'       => $row->merged_into ? (int) $row->merged_into : null,
			'created_by'        => $row->created_by,
			'first_detected_at' => $row->first_detected_at,
			'last_updated_at'   => $row->last_updated_at,
		);
		if ( $with_details ) {
			$out['sources'] = array_map( function ( $s ) {
				return array(
					'url'         => $s->url,
					'source_name' => $s->source_name,
					'title'       => $s->title,
					'excerpt'     => $s->excerpt,
					'added_at'    => $s->added_at,
				);
			}, sa_event_get_sources( $row->id ) );
			$out['log'] = array_map( function ( $l ) {
				return array(
					'actor'      => $l->actor,
					'action'     => $l->action,
					'detail'     => json_decode( $l->detail, true ),
					'created_at' => $l->created_at,
				);
			}, sa_event_get_log( $row->id ) );
			$out['social_posts'] = array_map( function ( $p ) {
				return array(
					'id'         => (int) $p->id,
					'channel'    => $p->channel,
					'content'    => $p->content,
					'status'     => $p->status,
					'created_by' => $p->created_by,
					'created_at' => $p->created_at,
				);
			}, sa_event_social_list( $row->id ) );
		}
		return $out;
	};

	// GET /list?status=&importance=&limit=
	register_rest_route( 'sa-events/v1', '/list', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			global $wpdb;
			$limit  = min( 100, max( 1, (int) $request->get_param( 'limit' ) ?: 30 ) );
			$where  = array( '1=1' );
			$params = array();

			$status = $request->get_param( 'status' );
			if ( $status && in_array( $status, sa_event_allowed_statuses(), true ) ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			$importance = $request->get_param( 'importance' );
			if ( $importance && in_array( $importance, sa_event_allowed_importance(), true ) ) {
				$where[]  = 'importance = %s';
				$params[] = $importance;
			}
			if ( ! $request->get_param( 'include_merged' ) ) {
				$where[] = 'merged_into IS NULL';
			}

			$sql = "SELECT * FROM " . sa_event_table_events() . " WHERE " . implode( ' AND ', $where )
				. " ORDER BY last_updated_at DESC LIMIT %d";
			$params[] = $limit;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			return array_map( function ( $r ) use ( $shape_event ) {
				return $shape_event( $r, false );
			}, $rows );
		},
	) );

	// GET /get/{event_key}
	register_rest_route( 'sa-events/v1', '/get/(?P<key>[A-Z0-9-]+)', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			$row = sa_event_get_by_key( $request->get_param( 'key' ) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}
			return $shape_event( $row, true );
		},
	) );

	// POST /find-similar { url?, title? }
	// Deduplication deterministe (URL deja vue) + liste des evenements ouverts
	// recents pour que l'agent ou le redacteur juge la similarite semantique.
	register_rest_route( 'sa-events/v1', '/find-similar', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$url = (string) $request->get_param( 'url' );

			$duplicate_of = $url ? sa_event_find_by_url( $url ) : null;

			$rows = $wpdb->get_results(
				"SELECT event_key, title, status, importance, category, last_updated_at
				 FROM " . sa_event_table_events() . "
				 WHERE merged_into IS NULL AND status NOT IN ('archived','rejected')
				 ORDER BY last_updated_at DESC LIMIT 30"
			);

			return array(
				'duplicate_url_of' => $duplicate_of,
				'open_events'      => $rows,
			);
		},
	) );

	// POST /create { title, category, importance?, confidence?, summary?, created_by?, source:{url,source_name,title,excerpt} }
	register_rest_route( 'sa-events/v1', '/create', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			global $wpdb;
			$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
			if ( '' === $title ) {
				return new WP_Error( 'bad_request', 'Titre requis.', array( 'status' => 400 ) );
			}

			$category   = (string) $request->get_param( 'category' );
			$importance = (string) $request->get_param( 'importance' );
			$confidence = (string) $request->get_param( 'confidence' );
			$category   = in_array( $category, sa_event_allowed_category(), true ) ? $category : 'local';
			$importance = in_array( $importance, sa_event_allowed_importance(), true ) ? $importance : 'to_watch';
			$confidence = in_array( $confidence, sa_event_allowed_confidence(), true ) ? $confidence : 'unverified';
			$created_by = sanitize_text_field( (string) ( $request->get_param( 'created_by' ) ?: 'agent' ) );

			$source = $request->get_param( 'source' );
			if ( is_array( $source ) && ! empty( $source['url'] ) ) {
				$existing = sa_event_find_by_url( $source['url'] );
				if ( $existing ) {
					return new WP_Error( 'duplicate', "Cette URL est deja rattachee a l'evenement {$existing}.", array(
						'status'    => 409,
						'event_key' => $existing,
					) );
				}
			}

			$now = current_time( 'mysql' );
			$key = sa_event_next_key();

			$wpdb->insert( sa_event_table_events(), array(
				'event_key'         => $key,
				'title'             => $title,
				'summary'           => sanitize_textarea_field( (string) $request->get_param( 'summary' ) ),
				'status'            => 'detected',
				'importance'        => $importance,
				'confidence'        => $confidence,
				'category'          => $category,
				'created_by'        => $created_by,
				'first_detected_at' => $now,
				'last_updated_at'   => $now,
			), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

			$event_id = (int) $wpdb->insert_id;

			if ( is_array( $source ) && ! empty( $source['url'] ) ) {
				sa_event_add_source_row( $event_id, $source['url'], $source['source_name'] ?? '', $source['title'] ?? '', $source['excerpt'] ?? '' );
			}

			sa_event_log( $event_id, $created_by, 'created', array( 'title' => $title ) );

			$row = sa_event_get_by_key( $key );
			return $shape_event( $row, true );
		},
	) );

	// POST /add-source { event_key, url, source_name?, title?, excerpt?, actor? }
	register_rest_route( 'sa-events/v1', '/add-source', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			$event_key = (string) $request->get_param( 'event_key' );
			$url       = (string) $request->get_param( 'url' );
			$row       = sa_event_get_by_key( $event_key );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}
			if ( '' === $url ) {
				return new WP_Error( 'bad_request', 'URL requise.', array( 'status' => 400 ) );
			}

			$existing = sa_event_find_by_url( $url );
			if ( $existing && $existing !== $event_key ) {
				return new WP_Error( 'duplicate', "Cette URL est deja rattachee a l'evenement {$existing}.", array(
					'status'    => 409,
					'event_key' => $existing,
				) );
			}
			if ( $existing === $event_key ) {
				return $shape_event( sa_event_get_by_key( $event_key ), true );
			}

			$actor = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );
			sa_event_add_source_row( $row->id, $url, $request->get_param( 'source_name' ), $request->get_param( 'title' ), $request->get_param( 'excerpt' ) );
			sa_event_touch( $row->id );
			sa_event_log( $row->id, $actor, 'source_added', array( 'url' => $url ) );

			return $shape_event( sa_event_get_by_key( $event_key ), true );
		},
	) );

	// POST /update { event_key, status?, importance?, confidence?, summary?, actor?, note? }
	register_rest_route( 'sa-events/v1', '/update', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			global $wpdb;
			$event_key = (string) $request->get_param( 'event_key' );
			$row       = sa_event_get_by_key( $event_key );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}

			$actor   = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );
			$fields  = array();
			$formats = array();
			$changed = array();

			$status = $request->get_param( 'status' );
			if ( null !== $status ) {
				if ( ! in_array( $status, sa_event_allowed_statuses(), true ) ) {
					return new WP_Error( 'bad_request', 'Statut invalide.', array( 'status' => 400 ) );
				}
				$fields['status'] = $status;
				$formats[]         = '%s';
				$changed['status'] = $status;
			}
			$importance = $request->get_param( 'importance' );
			if ( null !== $importance ) {
				if ( ! in_array( $importance, sa_event_allowed_importance(), true ) ) {
					return new WP_Error( 'bad_request', 'Importance invalide.', array( 'status' => 400 ) );
				}
				$fields['importance'] = $importance;
				$formats[]             = '%s';
				$changed['importance'] = $importance;
			}
			$confidence = $request->get_param( 'confidence' );
			if ( null !== $confidence ) {
				if ( ! in_array( $confidence, sa_event_allowed_confidence(), true ) ) {
					return new WP_Error( 'bad_request', 'Confiance invalide.', array( 'status' => 400 ) );
				}
				$fields['confidence'] = $confidence;
				$formats[]             = '%s';
				$changed['confidence'] = $confidence;
			}
			$summary = $request->get_param( 'summary' );
			if ( null !== $summary ) {
				$fields['summary'] = sanitize_textarea_field( (string) $summary );
				$formats[]          = '%s';
				$changed['summary'] = true;
			}

			if ( empty( $fields ) ) {
				return new WP_Error( 'bad_request', 'Aucun champ a mettre a jour.', array( 'status' => 400 ) );
			}

			$fields['last_updated_at'] = current_time( 'mysql' );
			$formats[]                  = '%s';

			$wpdb->update( sa_event_table_events(), $fields, array( 'id' => $row->id ), $formats, array( '%d' ) );

			$note = $request->get_param( 'note' );
			$changed['note'] = $note ? sanitize_text_field( (string) $note ) : null;
			sa_event_log( $row->id, $actor, 'updated', $changed );

			return $shape_event( sa_event_get_by_key( $event_key ), true );
		},
	) );

	// POST /merge { source_event_key, target_event_key, actor? }
	// Fusionne un doublon dans l'evenement principal : les sources sont
	// reattachees, l'evenement source est marque merged_into (jamais supprime).
	register_rest_route( 'sa-events/v1', '/merge', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			global $wpdb;
			$source_key = (string) $request->get_param( 'source_event_key' );
			$target_key = (string) $request->get_param( 'target_event_key' );
			if ( $source_key === $target_key ) {
				return new WP_Error( 'bad_request', 'Un evenement ne peut pas fusionner avec lui-meme.', array( 'status' => 400 ) );
			}
			$source = sa_event_get_by_key( $source_key );
			$target = sa_event_get_by_key( $target_key );
			if ( ! $source || ! $target ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}

			$actor = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'editor' ) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE " . sa_event_table_sources() . " SET event_id = %d WHERE event_id = %d",
				$target->id, $source->id
			) );
			$wpdb->update( sa_event_table_events(),
				array( 'merged_into' => $target->id, 'last_updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $source->id ), array( '%d', '%s' ), array( '%d' )
			);
			sa_event_touch( $target->id );

			sa_event_log( $source->id, $actor, 'merged_into', array( 'target' => $target_key ) );
			sa_event_log( $target->id, $actor, 'merge_received', array( 'source' => $source_key ) );

			return $shape_event( sa_event_get_by_key( $target_key ), true );
		},
	) );

	// POST /link-flash { event_key, flash_id, actor? }
	// Relie un flash Breaking News (table sa_breaking_news) a l'evenement.
	register_rest_route( 'sa-events/v1', '/link-flash', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			global $wpdb;
			$event_key = (string) $request->get_param( 'event_key' );
			$flash_id  = (int) $request->get_param( 'flash_id' );
			$row       = sa_event_get_by_key( $event_key );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}
			$actor = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );

			$wpdb->update( sa_event_table_events(), array(
				'breaking_flash_id' => $flash_id,
				'status'            => 'breaking',
				'last_updated_at'   => current_time( 'mysql' ),
			), array( 'id' => $row->id ), array( '%d', '%s', '%s' ), array( '%d' ) );

			sa_event_log( $row->id, $actor, 'flash_linked', array( 'flash_id' => $flash_id ) );

			return $shape_event( sa_event_get_by_key( $event_key ), true );
		},
	) );

	// POST /promote-to-article { event_key, actor? }
	// Prepare un BROUILLON d'article (jamais publie automatiquement).
	register_rest_route( 'sa-events/v1', '/promote-to-article', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$event_key = (string) $request->get_param( 'event_key' );
			$actor     = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );
			return sa_event_promote_to_article( $event_key, $actor );
		},
	) );

	// POST /social-pack { event_key, channel, content, actor? }
	// Enregistre un texte pret pour un canal (facebook/x/telegram/newsletter).
	// Ne publie jamais rien sur le reseau : preparation seulement, voir
	// docs/newsroom/social-guidelines.md.
	register_rest_route( 'sa-events/v1', '/social-pack', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			$event_key = (string) $request->get_param( 'event_key' );
			$channel   = (string) $request->get_param( 'channel' );
			$content   = (string) $request->get_param( 'content' );
			$row       = sa_event_get_by_key( $event_key );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}
			if ( ! in_array( $channel, sa_event_allowed_channels(), true ) ) {
				return new WP_Error( 'bad_request', 'Canal invalide.', array( 'status' => 400 ) );
			}
			if ( '' === trim( $content ) ) {
				return new WP_Error( 'bad_request', 'Contenu vide.', array( 'status' => 400 ) );
			}
			$actor = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );
			sa_event_social_add( $row->id, $channel, $content, $actor );
			sa_event_log( $row->id, $actor, 'social_prepared', array( 'channel' => $channel ) );

			return $shape_event( sa_event_get_by_key( $event_key ), true );
		},
	) );

	// POST /social-mark-posted { social_post_id, actor? }
	// Marque manuellement un texte prepare comme publie ailleurs (aucun envoi
	// automatique n'existe : c'est Hicham qui l'a colle sur le reseau).
	register_rest_route( 'sa-events/v1', '/social-mark-posted', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request->get_param( 'social_post_id' );
			if ( ! $id || ! sa_event_social_mark_posted( $id ) ) {
				return new WP_Error( 'not_found', 'Publication preparee introuvable.', array( 'status' => 404 ) );
			}
			return array( 'id' => $id, 'status' => 'posted_manually' );
		},
	) );
} );

/* -------------------------- ADMIN (controle humain) ------------------------
 * Tableau de bord en lecture pour le redacteur en chef : statut, sources,
 * niveau de confiance, chronologie de chaque evenement. Les seules actions
 * possibles depuis cette page sont des changements de statut manuels et la
 * preparation d'un brouillon d'article - jamais une publication.
 * ---------------------------------------------------------------------------
 */

add_action( 'admin_menu', function () {
	add_options_page(
		'Evenements (Newsroom)',
		'Evenements (Newsroom)',
		'manage_options',
		'sa-events',
		'sa_event_admin_page'
	);
} );

function sa_event_admin_handle_post() {
	if ( ! isset( $_POST['sa_event_action'] ) || ! check_admin_referer( 'sa_event_admin', 'sa_event_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$action    = sanitize_key( wp_unslash( $_POST['sa_event_action'] ) );
	$event_key = isset( $_POST['sa_event_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sa_event_key'] ) ) : '';
	$actor     = 'editor:' . wp_get_current_user()->user_login;

	if ( 'set_status' === $action && $event_key ) {
		$status = isset( $_POST['sa_event_status'] ) ? sanitize_key( wp_unslash( $_POST['sa_event_status'] ) ) : '';
		if ( in_array( $status, sa_event_allowed_statuses(), true ) ) {
			$row = sa_event_get_by_key( $event_key );
			if ( $row ) {
				global $wpdb;
				$wpdb->update( sa_event_table_events(),
					array( 'status' => $status, 'last_updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $row->id ), array( '%s', '%s' ), array( '%d' )
				);
				sa_event_log( $row->id, $actor, 'updated', array( 'status' => $status ) );
			}
		}
	} elseif ( 'promote' === $action && $event_key ) {
		sa_event_promote_to_article( $event_key, $actor );
	} elseif ( 'mark_social_posted' === $action && isset( $_POST['sa_social_id'] ) ) {
		sa_event_social_mark_posted( (int) $_POST['sa_social_id'] );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'sa-events', 'event' => $event_key ), admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_init', 'sa_event_admin_handle_post' );

function sa_event_badge( $value ) {
	return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#eee;font-size:12px;">' . esc_html( $value ) . '</span>';
}

function sa_event_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$nonce         = wp_create_nonce( 'sa_event_admin' );
	$focus_key     = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
	$focused_event = $focus_key ? sa_event_get_by_key( $focus_key ) : null;

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT * FROM " . sa_event_table_events() . " WHERE merged_into IS NULL ORDER BY last_updated_at DESC LIMIT 50"
	);
	?>
	<div class="wrap">
		<h1>Evenements (Newsroom)</h1>
		<p>Une information importante devient un evenement qui regroupe ses sources. Elle ne devient un article que si vous le decidez.</p>

		<?php if ( $focused_event ) : ?>
			<div class="card" style="max-width:800px;padding:16px;margin-bottom:20px;">
				<h2><?php echo esc_html( $focused_event->event_key ); ?> — <?php echo esc_html( $focused_event->title ); ?></h2>
				<p>
					<?php echo sa_event_badge( $focused_event->status ); ?>
					<?php echo sa_event_badge( $focused_event->importance ); ?>
					<?php echo sa_event_badge( $focused_event->confidence ); ?>
					<?php echo sa_event_badge( $focused_event->category ); ?>
				</p>
				<?php if ( $focused_event->summary ) : ?>
					<p><?php echo esc_html( $focused_event->summary ); ?></p>
				<?php endif; ?>
				<p><em>Detecte le <?php echo esc_html( $focused_event->first_detected_at ); ?> — mis a jour le <?php echo esc_html( $focused_event->last_updated_at ); ?></em></p>

				<?php if ( $focused_event->article_post_id ) : ?>
					<p><strong>Brouillon d'article :</strong> <a href="<?php echo esc_url( get_edit_post_link( $focused_event->article_post_id ) ); ?>">Ouvrir dans l'editeur</a></p>
				<?php endif; ?>

				<h3>Sources</h3>
				<ul>
					<?php foreach ( sa_event_get_sources( $focused_event->id ) as $s ) : ?>
						<li>
							<?php if ( $s->url ) : ?>
								<a href="<?php echo esc_url( $s->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s->source_name ?: $s->url ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $s->source_name ?: '(source sans URL)' ); ?>
							<?php endif; ?>
							<?php if ( $s->title ) : ?> — <?php echo esc_html( $s->title ); ?><?php endif; ?>
							<br /><small><?php echo esc_html( $s->added_at ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>

				<h3>Publications sociales preparees</h3>
				<p><small>Preparation seulement : aucun envoi automatique. A copier-coller manuellement sur le reseau concerne.</small></p>
				<?php $social_posts = sa_event_social_list( $focused_event->id ); ?>
				<?php if ( ! $social_posts ) : ?>
					<p>Aucune publication preparee pour le moment.</p>
				<?php else : ?>
					<ul>
						<?php foreach ( $social_posts as $sp ) : ?>
							<li style="margin-bottom:8px;">
								<?php echo sa_event_badge( $sp->channel ); ?>
								<?php echo sa_event_badge( 'posted_manually' === $sp->status ? 'publie (manuel)' : 'brouillon' ); ?>
								<br /><?php echo nl2br( esc_html( $sp->content ) ); ?>
								<?php if ( 'draft' === $sp->status ) : ?>
									<form method="post" style="display:inline;">
										<?php wp_nonce_field( 'sa_event_admin', 'sa_event_nonce' ); ?>
										<input type="hidden" name="sa_event_action" value="mark_social_posted" />
										<input type="hidden" name="sa_social_id" value="<?php echo esc_attr( $sp->id ); ?>" />
										<?php submit_button( 'Marquer comme publie manuellement', 'small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h3>Chronologie</h3>
				<ul>
					<?php foreach ( sa_event_get_log( $focused_event->id, 30 ) as $l ) : ?>
						<li><small><?php echo esc_html( $l->created_at ); ?></small> — <strong><?php echo esc_html( $l->actor ); ?></strong> : <?php echo esc_html( $l->action ); ?></li>
					<?php endforeach; ?>
				</ul>

				<form method="post" style="margin-top:12px;">
					<?php wp_nonce_field( 'sa_event_admin', 'sa_event_nonce' ); ?>
					<input type="hidden" name="sa_event_action" value="set_status" />
					<input type="hidden" name="sa_event_key" value="<?php echo esc_attr( $focused_event->event_key ); ?>" />
					<select name="sa_event_status">
						<?php foreach ( sa_event_allowed_statuses() as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $focused_event->status, $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( 'Changer le statut', 'secondary', 'submit', false ); ?>
				</form>

				<?php if ( ! $focused_event->article_post_id ) : ?>
					<form method="post" style="margin-top:8px;">
						<?php wp_nonce_field( 'sa_event_admin', 'sa_event_nonce' ); ?>
						<input type="hidden" name="sa_event_action" value="promote" />
						<input type="hidden" name="sa_event_key" value="<?php echo esc_attr( $focused_event->event_key ); ?>" />
						<?php submit_button( "Preparer un brouillon d'article (ne publie rien)", 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>

				<p style="margin-top:12px;"><a href="<?php echo esc_url( remove_query_arg( 'event' ) ); ?>">&larr; Retour a la liste</a></p>
			</div>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Cle</th>
					<th>Titre</th>
					<th>Statut</th>
					<th>Importance</th>
					<th>Confiance</th>
					<th>Maj</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6">Aucun evenement pour le moment.</td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'sa-events', 'event' => $row->event_key ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $row->event_key ); ?></a></td>
					<td><?php echo esc_html( $row->title ); ?></td>
					<td><?php echo sa_event_badge( $row->status ); ?></td>
					<td><?php echo sa_event_badge( $row->importance ); ?></td>
					<td><?php echo sa_event_badge( $row->confidence ); ?></td>
					<td><?php echo esc_html( $row->last_updated_at ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
