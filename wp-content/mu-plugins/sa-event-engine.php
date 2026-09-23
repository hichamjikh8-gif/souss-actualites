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

	if ( $version < 3 ) {
		sa_event_create_tables_v3( $charset );
		update_option( 'sa_event_engine_db_version', 3 );
		$version = 3;
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

/**
 * Ajoute les colonnes utilisees par sa_event_mark_syndicated() ci-dessous.
 */
function sa_event_create_tables_v3( $charset ) {
	global $wpdb;
	$events = sa_event_table_events();
	$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$events} LIKE 'syndicated_post_id'" );
	if ( empty( $existing ) ) {
		$wpdb->query( "ALTER TABLE {$events} ADD COLUMN syndicated_post_id BIGINT(20) NULL DEFAULT NULL, ADD COLUMN syndicated_at DATETIME NULL DEFAULT NULL" );
	}
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

/**
 * Marque durablement (en base, pas seulement dans le cache local de
 * engine/.seen-sources/ qui peut etre perdu au redemarrage du conteneur
 * Railway) qu'un evenement a deja ete syndique - empeche une republication
 * de l'article si la meme URL/le meme evenement est revu plus tard.
 */
function sa_event_mark_syndicated( $event_id, $post_id ) {
	global $wpdb;
	return $wpdb->update( sa_event_table_events(),
		array( 'syndicated_post_id' => (int) $post_id, 'syndicated_at' => current_time( 'mysql' ) ),
		array( 'id' => (int) $event_id ),
		array( '%d', '%s' ), array( '%d' )
	);
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

/**
 * Heuristique de similarite (recouvrement de mots, coefficient de Jaccard) -
 * PAS une similarite semantique reelle. Sert a suggerer des doublons
 * probables (meme evenement rapporte par des sources differentes, donc des
 * URLs differentes) pour que le redacteur en chef ou l'agent decide -
 * jamais une fusion automatique. Fonctionne sur titres francais ou arabes
 * (les deux langues des sources suivies).
 */
function sa_event_stopwords() {
	static $stopwords = null;
	if ( null === $stopwords ) {
		$stopwords = array_flip( array(
			// francais
			'les', 'des', 'une', 'un', 'de', 'du', 'le', 'la', 'et', 'en', 'au', 'aux', 'pour',
			'dans', 'sur', 'par', 'avec', 'ce', 'cette', 'ces', 'est', 'sont', 'plus', 'moins',
			'apres', 'avant', 'entre', 'son', 'sa', 'ses', 'leur', 'leurs', 'qui', 'que', 'quoi',
			// arabe (mots-outils frequents)
			'في', 'من', 'على', 'إلى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'أن',
			'إن', 'لا', 'ما', 'لم', 'قد', 'كان', 'كانت', 'بعد', 'قبل', 'بين', 'حول', 'كل',
		) );
	}
	return $stopwords;
}

function sa_event_tokenize( $title ) {
	$title = mb_strtolower( (string) $title, 'UTF-8' );
	$title = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $title );
	$words = preg_split( '/\s+/u', trim( (string) $title ) );
	$stopwords = sa_event_stopwords();
	$tokens = array();
	foreach ( (array) $words as $w ) {
		$w = trim( $w );
		if ( '' === $w || mb_strlen( $w, 'UTF-8' ) < 3 || isset( $stopwords[ $w ] ) ) {
			continue;
		}
		$tokens[ $w ] = true;
	}
	return array_keys( $tokens );
}

function sa_event_title_similarity( $title_a, $title_b ) {
	$tokens_a = sa_event_tokenize( $title_a );
	$tokens_b = sa_event_tokenize( $title_b );
	if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
		return 0.0;
	}
	$set_a = array_flip( $tokens_a );
	$set_b = array_flip( $tokens_b );
	$intersection_count = count( array_intersect_key( $set_a, $set_b ) );
	$union_count = count( $set_a ) + count( $set_b ) - $intersection_count;
	return $union_count > 0 ? $intersection_count / $union_count : 0.0;
}

const SA_EVENT_SIMILARITY_THRESHOLD = 0.35;

/**
 * Renvoie les evenements ouverts (hors $exclude_event_id) tries par
 * similarite de titre avec $title, au-dessus du seuil.
 */
function sa_event_find_similar_open( $title, $exclude_event_id = 0, $limit = 5 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, event_key, title, status, importance, category, last_updated_at
		 FROM " . sa_event_table_events() . "
		 WHERE merged_into IS NULL AND status NOT IN ('archived','rejected') AND id != %d
		 ORDER BY last_updated_at DESC LIMIT 100",
		(int) $exclude_event_id
	) );
	$scored = array();
	foreach ( $rows as $r ) {
		$score = sa_event_title_similarity( $title, $r->title );
		if ( $score >= SA_EVENT_SIMILARITY_THRESHOLD ) {
			$scored[] = array(
				'event_key'       => $r->event_key,
				'title'           => $r->title,
				'status'          => $r->status,
				'importance'      => $r->importance,
				'category'        => $r->category,
				'last_updated_at' => $r->last_updated_at,
				'similarity'      => round( $score, 3 ),
			);
		}
	}
	usort( $scored, function ( $a, $b ) {
		return $b['similarity'] <=> $a['similarity'];
	} );
	return array_slice( $scored, 0, $limit );
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
 * N'envoie RIEN automatiquement : c'est une preparation. La publication reelle
 * n'a lieu que si le redacteur en chef clique explicitement "Publier
 * maintenant" (sa_event_social_publish_to_channel ci-dessous), et seulement
 * si les identifiants du canal sont configures (sinon le bouton n'apparait
 * meme pas). Sans configuration : copier-coller manuel, comme avant.
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

function sa_event_social_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM " . sa_event_table_social() . " WHERE id = %d", (int) $id
	) );
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

function sa_event_social_mark_published( $id ) {
	global $wpdb;
	return $wpdb->update( sa_event_table_social(),
		array( 'status' => 'posted' ),
		array( 'id' => (int) $id ),
		array( '%s' ), array( '%d' )
	);
}

/**
 * Un canal est "configure" si ses identifiants existent. Determine si le
 * bouton "Publier maintenant" doit apparaitre pour ce canal - tant que
 * Hicham n'a pas ajoute les identifiants dans WORDPRESS_CONFIG_EXTRA, aucun
 * bouton n'apparait et rien ne peut partir automatiquement.
 */
function sa_event_social_channel_configured( $channel ) {
	if ( 'facebook' === $channel ) {
		return defined( 'FACEBOOK_PAGE_ID' ) && FACEBOOK_PAGE_ID !== '' && defined( 'FACEBOOK_PAGE_ACCESS_TOKEN' ) && FACEBOOK_PAGE_ACCESS_TOKEN !== '';
	}
	if ( 'x' === $channel ) {
		return defined( 'X_CONSUMER_KEY' ) && X_CONSUMER_KEY !== ''
			&& defined( 'X_CONSUMER_SECRET' ) && X_CONSUMER_SECRET !== ''
			&& defined( 'X_ACCESS_TOKEN' ) && X_ACCESS_TOKEN !== ''
			&& defined( 'X_ACCESS_TOKEN_SECRET' ) && X_ACCESS_TOKEN_SECRET !== '';
	}
	return false; // telegram et newsletter restent volontairement manuels pour l'instant.
}

/**
 * Encodage pourcentage conforme RFC 3986 exige par OAuth 1.0a. rawurlencode()
 * de PHP suit deja RFC 3986 (contrairement a l'equivalent JS encodeURIComponent,
 * qui laisse passer !*'() non encodes - voir la version Node de cette meme
 * fonction dans engine/rss-watcher.js, qui doit compenser cet ecart).
 */
function sa_event_oauth1_percent_encode( $str ) {
	return rawurlencode( (string) $str );
}

/**
 * Construit l'en-tete Authorization OAuth 1.0a (HMAC-SHA1) pour une requete
 * POST sans parametres de requete/formulaire (le corps est du JSON, donc hors
 * signature). Algorithme identique a celui utilise cote Node
 * (engine/rss-watcher.js), verifie contre l'exemple officiel de la RFC 5849
 * (section 1.2) le 2026-09-23.
 */
function sa_event_build_oauth1_header( $method, $url ) {
	$oauth_params = array(
		'oauth_consumer_key'     => X_CONSUMER_KEY,
		'oauth_nonce'            => wp_generate_password( 32, false ),
		'oauth_signature_method' => 'HMAC-SHA1',
		'oauth_timestamp'        => (string) time(),
		'oauth_token'            => X_ACCESS_TOKEN,
		'oauth_version'          => '1.0',
	);

	$pairs = array();
	ksort( $oauth_params );
	foreach ( $oauth_params as $key => $value ) {
		$pairs[] = sa_event_oauth1_percent_encode( $key ) . '=' . sa_event_oauth1_percent_encode( $value );
	}
	$param_string = implode( '&', $pairs );

	$base_string = strtoupper( $method ) . '&' . sa_event_oauth1_percent_encode( $url ) . '&' . sa_event_oauth1_percent_encode( $param_string );
	$signing_key = sa_event_oauth1_percent_encode( X_CONSUMER_SECRET ) . '&' . sa_event_oauth1_percent_encode( X_ACCESS_TOKEN_SECRET );
	$signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

	$header_params            = $oauth_params;
	$header_params['oauth_signature'] = $signature;
	ksort( $header_params );

	$header_pairs = array();
	foreach ( $header_params as $key => $value ) {
		$header_pairs[] = sa_event_oauth1_percent_encode( $key ) . '="' . sa_event_oauth1_percent_encode( $value ) . '"';
	}
	return 'OAuth ' . implode( ', ', $header_pairs );
}

/**
 * Publie reellement sur Facebook (Graph API - /{page-id}/feed) ou X (API v2 -
 * /2/tweets, signe en OAuth 1.0a "user context" - c'est le type de cles
 * generees pour le compte @hiz_hisham le 2026-08-01, pas un Bearer OAuth2).
 * NON TESTE contre de vrais identifiants au moment de l'ecriture - suit la
 * documentation officielle de chaque API, mais Hicham doit verifier avec un
 * premier post a faible enjeu avant de compter dessus.
 * Retourne true en cas de succes, ou une chaine d'erreur explicite sinon.
 */
function sa_event_social_publish_to_channel( $channel, $content, $article_url = '' ) {
	if ( ! sa_event_social_channel_configured( $channel ) ) {
		return "Canal '{$channel}' non configure (identifiants manquants).";
	}

	if ( 'facebook' === $channel ) {
		$body = array(
			'message'      => $content,
			'access_token' => FACEBOOK_PAGE_ACCESS_TOKEN,
		);
		if ( $article_url ) {
			$body['link'] = $article_url;
		}
		$response = wp_remote_post( 'https://graph.facebook.com/v19.0/' . rawurlencode( FACEBOOK_PAGE_ID ) . '/feed', array(
			'timeout' => 15,
			'body'    => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return 'Erreur reseau Facebook : ' . $response->get_error_message();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return 'Erreur API Facebook (HTTP ' . $code . ') : ' . wp_remote_retrieve_body( $response );
		}
		return true;
	}

	if ( 'x' === $channel ) {
		$x_url    = 'https://api.twitter.com/2/tweets';
		$response = wp_remote_post( $x_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => sa_event_build_oauth1_header( 'POST', $x_url ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'text' => $content ) ),
		) );
		if ( is_wp_error( $response ) ) {
			return 'Erreur reseau X : ' . $response->get_error_message();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return 'Erreur API X (HTTP ' . $code . ') : ' . wp_remote_retrieve_body( $response );
		}
		return true;
	}

	return "Canal '{$channel}' non pris en charge pour la publication automatique.";
}

/**
 * Fusionne $source_key dans $target_key : toutes les sources sont
 * reattachees, $source_key est marque merged_into (jamais supprime).
 * Retourne WP_Error en cas de probleme, sinon true.
 */
function sa_event_merge( $source_key, $target_key, $actor ) {
	global $wpdb;
	if ( $source_key === $target_key ) {
		return new WP_Error( 'bad_request', 'Un evenement ne peut pas fusionner avec lui-meme.', array( 'status' => 400 ) );
	}
	$source = sa_event_get_by_key( $source_key );
	$target = sa_event_get_by_key( $target_key );
	if ( ! $source || ! $target ) {
		return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
	}

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

	return true;
}

/**
 * Cree le brouillon d'article WordPress associe a un evenement. Le brouillon
 * n'est JAMAIS publie automatiquement : le redacteur en chef doit le valider
 * et le publier lui-meme (via /wp-admin ou la commande /publish du bot).
 */
/**
 * Maillage interne simple pour le SEO : cherche des articles deja publies
 * dont le titre partage des mots significatifs avec $title (memes tokens
 * que la deduplication d'evenements). Retourne au plus $limit posts.
 */
function sa_event_related_published_posts( $title, $limit = 3 ) {
	$tokens = sa_event_tokenize( $title );
	if ( empty( $tokens ) ) {
		return array();
	}
	// WP_Query 's' fait une recherche OR-ish sur le texte ; on lui donne les
	// tokens les plus significatifs (les plus longs, souvent les plus rares).
	usort( $tokens, function ( $a, $b ) {
		return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
	} );
	$search = implode( ' ', array_slice( $tokens, 0, 6 ) );

	$query = new WP_Query( array(
		's'              => $search,
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => 'relevance',
	) );

	$related = array();
	foreach ( $query->posts as $p ) {
		$related[] = array( 'title' => get_the_title( $p ), 'url' => get_permalink( $p ) );
	}
	return $related;
}

/* ------------------------- IMAGE A LA UNE (Pexels/Unsplash) ----------------
 * Recherche une photo libre de droits liee au sujet d'un evenement et
 * l'attache comme image a la une du brouillon. Desactive tant qu'aucune des
 * deux constantes (PEXELS_API_KEY, UNSPLASH_ACCESS_KEY) n'est definie dans
 * WORDPRESS_CONFIG_EXTRA - Pexels est tente en premier (inscription plus
 * simple), Unsplash en repli. Recherche avec le titre de l'evenement tel
 * quel : marche bien pour les noms propres (Agadir, Maroc...), moins bien
 * pour un titre entierement en arabe traduit litteralement - limite connue,
 * pas de traduction automatique ici (pas d'appel IA dans ce mu-plugin).
 * ---------------------------------------------------------------------------
 */

/**
 * Accepte la cle soit comme constante PHP (define() dans WORDPRESS_CONFIG_EXTRA,
 * comme SOUSS_BOT_SECRET), soit comme variable d'environnement brute du
 * conteneur (getenv()) - selon la maniere dont Hicham l'a ajoutee dans
 * Railway. Retourne null si absente des deux.
 */
function sa_event_stock_photo_key( $name ) {
	if ( defined( $name ) && '' !== constant( $name ) ) {
		return constant( $name );
	}
	$env = getenv( $name );
	return ( false !== $env && '' !== $env ) ? $env : null;
}

function sa_event_stock_photo_configured() {
	return null !== sa_event_stock_photo_key( 'PEXELS_API_KEY' )
		|| null !== sa_event_stock_photo_key( 'UNSPLASH_ACCESS_KEY' );
}

function sa_event_stock_photo_query( $title ) {
	$tokens = sa_event_tokenize( $title );
	usort( $tokens, function ( $a, $b ) {
		return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
	} );
	return implode( ' ', array_slice( $tokens, 0, 4 ) );
}

function sa_event_search_pexels( $query, $api_key ) {
	$response = wp_remote_get(
		'https://api.pexels.com/v1/search?' . http_build_query( array(
			'query'       => $query,
			'per_page'    => 1,
			'orientation' => 'landscape',
		) ),
		array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => $api_key ),
		)
	);
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['photos'][0] ) ) {
		return null;
	}
	$p = $data['photos'][0];
	return array(
		'image_url'              => isset( $p['src']['large2x'] ) ? $p['src']['large2x'] : ( $p['src']['large'] ?? null ),
		'credit_name'            => $p['photographer'] ?? 'Pexels',
		'source_label'           => 'Pexels',
		'download_tracking_url'  => null,
	);
}

function sa_event_search_unsplash( $query, $access_key ) {
	$response = wp_remote_get(
		'https://api.unsplash.com/search/photos?' . http_build_query( array(
			'query'       => $query,
			'per_page'    => 1,
			'orientation' => 'landscape',
		) ),
		array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Client-ID ' . $access_key ),
		)
	);
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['results'][0] ) ) {
		return null;
	}
	$p = $data['results'][0];
	return array(
		'image_url'             => $p['urls']['regular'] ?? null,
		'credit_name'           => $p['user']['name'] ?? 'Unsplash',
		'source_label'          => 'Unsplash',
		// Guideline officielle Unsplash : signaler le telechargement quand une
		// image est reellement utilisee (pas seulement affichee en recherche).
		'download_tracking_url' => $p['links']['download_location'] ?? null,
	);
}

function sa_event_search_stock_photo( $title ) {
	$query = sa_event_stock_photo_query( $title );
	if ( '' === $query ) {
		return null;
	}
	$pexels_key = sa_event_stock_photo_key( 'PEXELS_API_KEY' );
	error_log( sprintf(
		'[sa-event-engine] recherche photo pour "%s" (requete: "%s") - PEXELS_API_KEY trouvee: %s',
		$title, $query, $pexels_key ? 'oui' : 'non'
	) );
	if ( $pexels_key ) {
		$photo = sa_event_search_pexels( $query, $pexels_key );
		error_log( '[sa-event-engine] Pexels : ' . ( $photo ? 'photo trouvee (' . $photo['credit_name'] . ')' : 'aucun resultat / echec' ) );
		if ( $photo && ! empty( $photo['image_url'] ) ) {
			return $photo;
		}
	}
	$unsplash_key = sa_event_stock_photo_key( 'UNSPLASH_ACCESS_KEY' );
	if ( $unsplash_key ) {
		$photo = sa_event_search_unsplash( $query, $unsplash_key );
		if ( $photo && ! empty( $photo['image_url'] ) ) {
			return $photo;
		}
	}
	return null;
}

/**
 * Telecharge la photo trouvee et l'attache comme image a la une du post.
 * Retourne true en cas de succes, false sinon (jamais fatal : l'article
 * reste un brouillon valide meme sans image).
 */
function sa_event_attach_featured_image( $post_id, $photo ) {
	if ( empty( $photo['image_url'] ) ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $photo['image_url'], 15 );
	if ( is_wp_error( $tmp ) ) {
		error_log( '[sa-event-engine] echec telechargement image : ' . $tmp->get_error_message() );
		return false;
	}

	$file_array = array(
		'name'     => 'sa-event-photo-' . sanitize_title( $photo['source_label'] ) . '-' . time() . '.jpg',
		'tmp_name' => $tmp,
	);

	$attach_id = media_handle_sideload( $file_array, $post_id, null, array(
		'post_excerpt' => sprintf( 'Photo : %s / %s', $photo['credit_name'], $photo['source_label'] ),
	) );

	if ( is_wp_error( $attach_id ) ) {
		@unlink( $tmp );
		error_log( '[sa-event-engine] echec attachement image : ' . $attach_id->get_error_message() );
		return false;
	}

	update_post_meta( $attach_id, '_sa_stock_photo_credit', sanitize_text_field( $photo['credit_name'] . ' / ' . $photo['source_label'] ) );
	set_post_thumbnail( $post_id, $attach_id );
	error_log( sprintf( '[sa-event-engine] image a la une attachee (post #%d, attachment #%d, %s)', $post_id, $attach_id, $photo['source_label'] ) );

	$unsplash_key = sa_event_stock_photo_key( 'UNSPLASH_ACCESS_KEY' );
	if ( ! empty( $photo['download_tracking_url'] ) && $unsplash_key ) {
		wp_remote_get( $photo['download_tracking_url'], array(
			'timeout' => 10,
			'headers' => array( 'Authorization' => 'Client-ID ' . $unsplash_key ),
		) );
	}

	return true;
}

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

	// Maillage interne (SEO) : suggere des articles deja publies sur des
	// sujets proches, pour que le redacteur en chef les garde ou les retire
	// avant publication - jamais insere sans relecture puisque le brouillon
	// reste un brouillon.
	$related = sa_event_related_published_posts( $event->title, 3 );
	if ( $related ) {
		$body .= "<h2>À lire aussi</h2>\n<ul>\n";
		foreach ( $related as $r ) {
			$body .= '<li><a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['title'] ) . '</a></li>' . "\n";
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

	// Image a la une (Pexels/Unsplash) : ne bloque jamais la creation du
	// brouillon si la recherche/le telechargement echoue.
	$image_found = false;
	$photo       = sa_event_search_stock_photo( $event->title );
	if ( $photo ) {
		$image_found = sa_event_attach_featured_image( $post_id, $photo );
	}

	global $wpdb;
	$wpdb->update( sa_event_table_events(), array(
		'article_post_id' => $post_id,
		'status'           => 'editor_review',
		'last_updated_at'  => current_time( 'mysql' ),
	), array( 'id' => $event->id ), array( '%d', '%s', '%s' ), array( '%d' ) );

	sa_event_log( $event->id, $actor, 'article_draft_created', array(
		'post_id'     => $post_id,
		'image_found' => $image_found,
	) );

	return array(
		'event_key'   => $event_key,
		'post_id'     => $post_id,
		'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
		'image_found' => $image_found,
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
			'syndicated_post_id' => isset( $row->syndicated_post_id ) && $row->syndicated_post_id ? (int) $row->syndicated_post_id : null,
			'syndicated_at'     => isset( $row->syndicated_at ) ? $row->syndicated_at : null,
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
	// Deduplication deterministe (URL deja vue) +, si un titre est fourni,
	// des candidats scores par recouvrement de mots (heuristique, pas une
	// vraie similarite semantique - voir sa_event_title_similarity). Le choix
	// final (creer / enrichir / fusionner) reste a l'agent ou au redacteur.
	register_rest_route( 'sa-events/v1', '/find-similar', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$url   = (string) $request->get_param( 'url' );
			$title = (string) $request->get_param( 'title' );

			$duplicate_of = $url ? sa_event_find_by_url( $url ) : null;

			if ( '' !== trim( $title ) ) {
				$candidates = sa_event_find_similar_open( $title, 0, 10 );
			} else {
				$rows = $wpdb->get_results(
					"SELECT event_key, title, status, importance, category, last_updated_at
					 FROM " . sa_event_table_events() . "
					 WHERE merged_into IS NULL AND status NOT IN ('archived','rejected')
					 ORDER BY last_updated_at DESC LIMIT 30"
				);
				$candidates = array_map( function ( $r ) {
					return array(
						'event_key'       => $r->event_key,
						'title'           => $r->title,
						'status'          => $r->status,
						'importance'      => $r->importance,
						'category'        => $r->category,
						'last_updated_at' => $r->last_updated_at,
						'similarity'      => null,
					);
				}, $rows );
			}

			return array(
				'duplicate_url_of' => $duplicate_of,
				'open_events'      => $candidates,
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
						'event'     => $shape_event( sa_event_get_by_key( $existing ), false ),
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
			$source_key = (string) $request->get_param( 'source_event_key' );
			$target_key = (string) $request->get_param( 'target_event_key' );
			$actor      = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'editor' ) );

			$result = sa_event_merge( $source_key, $target_key, $actor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

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

	// POST /mark-syndicated { event_key, post_id, actor? }
	// Enregistre en base (durable, contrairement au cache local de la veille)
	// qu'un article syndique a deja ete publie pour cet evenement, pour que
	// l'article ne soit jamais republie meme si le conteneur "sources" perd
	// son cache local (engine/.seen-sources/) - voir sa_event_mark_syndicated().
	register_rest_route( 'sa-events/v1', '/mark-syndicated', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( WP_REST_Request $request ) use ( $shape_event ) {
			$event_key = (string) $request->get_param( 'event_key' );
			$post_id   = (int) $request->get_param( 'post_id' );
			$row       = sa_event_get_by_key( $event_key );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Evenement introuvable.', array( 'status' => 404 ) );
			}
			if ( ! $post_id ) {
				return new WP_Error( 'bad_request', 'post_id requis.', array( 'status' => 400 ) );
			}
			$actor = sanitize_text_field( (string) ( $request->get_param( 'actor' ) ?: 'agent' ) );
			sa_event_mark_syndicated( $row->id, $post_id );
			sa_event_log( $row->id, $actor, 'syndicated', array( 'post_id' => $post_id ) );

			return $shape_event( sa_event_get_by_key( $event_key ), false );
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
	} elseif ( 'set_importance' === $action && $event_key ) {
		$importance = isset( $_POST['sa_event_importance'] ) ? sanitize_key( wp_unslash( $_POST['sa_event_importance'] ) ) : '';
		if ( in_array( $importance, sa_event_allowed_importance(), true ) ) {
			$row = sa_event_get_by_key( $event_key );
			if ( $row ) {
				global $wpdb;
				$wpdb->update( sa_event_table_events(),
					array( 'importance' => $importance, 'last_updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $row->id ), array( '%s', '%s' ), array( '%d' )
				);
				// C'est ICI, sur une action manuelle du redacteur en chef, que
				// l'evenement peut devenir "important" - jamais automatique.
				// La veille (source-watcher.js) verifie sa-events/v1/list?importance=important
				// a intervalle rapproche et envoie l'alerte Telegram a ce moment-la.
				sa_event_log( $row->id, $actor, 'updated', array( 'importance' => $importance ) );
			}
		}
	} elseif ( 'promote' === $action && $event_key ) {
		sa_event_promote_to_article( $event_key, $actor );
	} elseif ( 'merge_similar' === $action && $event_key && isset( $_POST['sa_similar_key'] ) ) {
		$similar_key = sanitize_text_field( wp_unslash( $_POST['sa_similar_key'] ) );
		sa_event_merge( $similar_key, $event_key, $actor );
	} elseif ( 'mark_social_posted' === $action && isset( $_POST['sa_social_id'] ) ) {
		sa_event_social_mark_posted( (int) $_POST['sa_social_id'] );
	} elseif ( 'publish_social' === $action && isset( $_POST['sa_social_id'] ) ) {
		$sp = sa_event_social_get( (int) $_POST['sa_social_id'] );
		if ( $sp ) {
			$article_url = '';
			$event_row   = sa_event_get_by_key( $event_key );
			if ( $event_row && $event_row->article_post_id ) {
				$article_url = get_permalink( (int) $event_row->article_post_id );
			}
			$result = sa_event_social_publish_to_channel( $sp->channel, $sp->content, $article_url );
			if ( true === $result ) {
				sa_event_social_mark_published( $sp->id );
				sa_event_log( $sp->event_id, $actor, 'social_published', array( 'channel' => $sp->channel ) );
			} else {
				set_transient( 'sa_event_social_error_' . get_current_user_id(), (string) $result, 60 );
			}
		}
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

				<?php $similar = sa_event_find_similar_open( $focused_event->title, $focused_event->id, 5 ); ?>
				<?php if ( $similar ) : ?>
					<h3>Doublons possibles</h3>
					<p><small>Suggestion automatique par recouvrement de mots dans le titre (pas fiable a 100% - verifiez avant de fusionner). Fusionner rattache toutes les sources de l'evenement suggere a celui-ci ; il n'est jamais supprime, juste marque comme fusionne.</small></p>
					<ul>
						<?php foreach ( $similar as $cand ) : ?>
							<li style="margin-bottom:6px;">
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'sa-events', 'event' => $cand['event_key'] ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $cand['event_key'] ); ?></a>
								— <?php echo esc_html( $cand['title'] ); ?>
								<small>(similarite <?php echo esc_html( round( $cand['similarity'] * 100 ) ); ?>%)</small>
								<form method="post" style="display:inline;margin-left:8px;">
									<?php wp_nonce_field( 'sa_event_admin', 'sa_event_nonce' ); ?>
									<input type="hidden" name="sa_event_action" value="merge_similar" />
									<input type="hidden" name="sa_event_key" value="<?php echo esc_attr( $focused_event->event_key ); ?>" />
									<input type="hidden" name="sa_similar_key" value="<?php echo esc_attr( $cand['event_key'] ); ?>" />
									<?php submit_button( 'Fusionner ici', 'small', 'submit', false ); ?>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h3>Publications sociales preparees</h3>
				<p><small>Preparation par defaut : rien ne part automatiquement. "Publier maintenant" n'apparait que pour les canaux dont les identifiants sont configures (Facebook/X) ; sinon, copier-coller manuel.</small></p>
				<?php
				$social_error = get_transient( 'sa_event_social_error_' . get_current_user_id() );
				if ( $social_error ) {
					delete_transient( 'sa_event_social_error_' . get_current_user_id() );
					echo '<div class="notice notice-error"><p>' . esc_html( $social_error ) . '</p></div>';
				}
				?>
				<?php $social_posts = sa_event_social_list( $focused_event->id ); ?>
				<?php if ( ! $social_posts ) : ?>
					<p>Aucune publication preparee pour le moment.</p>
				<?php else : ?>
					<ul>
						<?php foreach ( $social_posts as $sp ) : ?>
							<?php
							$status_label = 'draft' === $sp->status ? 'brouillon' : ( 'posted' === $sp->status ? 'publie (auto)' : 'publie (manuel)' );
							?>
							<li style="margin-bottom:8px;">
								<?php echo sa_event_badge( $sp->channel ); ?>
								<?php echo sa_event_badge( $status_label ); ?>
								<br /><?php echo nl2br( esc_html( $sp->content ) ); ?>
								<?php if ( 'draft' === $sp->status ) : ?>
									<?php if ( sa_event_social_channel_configured( $sp->channel ) ) : ?>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'sa_event_admin', 'sa_event_nonce' ); ?>
											<input type="hidden" name="sa_event_action" value="publish_social" />
											<input type="hidden" name="sa_event_key" value="<?php echo esc_attr( $focused_event->event_key ); ?>" />
											<input type="hidden" name="sa_social_id" value="<?php echo esc_attr( $sp->id ); ?>" />
											<?php submit_button( 'Publier maintenant', 'primary', 'submit', false ); ?>
										</form>
									<?php endif; ?>
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
					<input type="hidden" name="sa_event_action" value="set_importance" />
					<input type="hidden" name="sa_event_key" value="<?php echo esc_attr( $focused_event->event_key ); ?>" />
					<?php if ( 'important' === $focused_event->importance ) : ?>
						<input type="hidden" name="sa_event_importance" value="to_watch" />
						<?php submit_button( 'Retirer le marqueur "important"', 'secondary', 'submit', false ); ?>
						<span style="margin-left:8px;color:#a80000;font-weight:600;">⚠️ Marque important — alerte Telegram envoyee</span>
					<?php else : ?>
						<input type="hidden" name="sa_event_importance" value="important" />
						<?php submit_button( '⚠️ Marquer comme important (envoie une alerte Telegram)', 'primary', 'submit', false ); ?>
					<?php endif; ?>
				</form>

				<form method="post" style="margin-top:8px;">
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

/* ------------------ EXPIRATION DES ARTICLES SYNDIQUES (8H) -----------------
 * Sur demande du redacteur en chef (2026-09-23) : un article syndique
 * automatiquement (voir engine/syndication-publisher.js, meta
 * _syndication_source) ne doit rester en ligne que 8 heures, puis etre
 * supprime DEFINITIVEMENT (pas seulement mis a la corbeille) pour ne jamais
 * reapparaitre. Ne touche jamais un article sans cette meta : les articles
 * ecrits/publies manuellement par le redacteur en chef, ou publies depuis un
 * brouillon prepare par l'Event Engine, ne l'ont pas et restent intacts.
 * La non-republication ulterieure du meme article est assuree cote veille par
 * sa_event_mark_syndicated() (colonne durable en base), pas par cette purge.
 * ---------------------------------------------------------------------------
 */

const SA_SYNDICATION_LIFETIME_HOURS = 2;

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['sa_fifteen_minutes'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Toutes les 15 minutes (Souss Actualites)' ),
	);
	return $schedules;
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'sa_syndication_expire_event' ) ) {
		wp_schedule_event( time(), 'sa_fifteen_minutes', 'sa_syndication_expire_event' );
	}
} );

add_action( 'sa_syndication_expire_event', 'sa_syndication_expire_old_articles' );

/**
 * Purge forcee, une seule fois, au premier chargement suivant ce deploiement
 * (2026-09-23, passage de la duree de vie de 8h a 2h sur demande du redacteur
 * en chef) : sans ca, le lot d'articles publies entre 2h et 8h avant le
 * passage en production resterait en ligne jusqu'au prochain passage du cron
 * (15 min). Version v2 : la premiere version de cette purge forcee (limitee a
 * 50 articles, les plus recents en premier par defaut) laissait des articles
 * Agadir24 vieux de plusieurs jours orphelins - voir sa_syndication_expire_old_articles()
 * ci-dessous pour le correctif (tri du plus vieux au plus recent + $limit=-1
 * ici). Le drapeau en option evite toute re-execution ulterieure ; le cron
 * recurrent reprend le relais normalement ensuite avec sa limite de 50.
 */
add_action( 'init', function () {
	if ( ! get_option( 'sa_syndication_force_cleanup_2026_09_23_v2' ) ) {
		// -1 (illimite) plutot que la limite par defaut de 50 : ce passage
		// unique doit rattraper tout l'historique syndique perime d'un coup
		// (constate en prod le 2026-09-23 : des articles Agadir24 vieux de
		// plusieurs jours survivaient encore, le tri par defaut de WP_Query
		// traitant les plus RECENTS en premier, jamais les plus vieux, tant
		// qu'un gros lot recent existait - voir aussi le tri explicite
		// ci-dessous, du plus vieux au plus recent, corrige pour de bon).
		sa_syndication_expire_old_articles( -1 );
		update_option( 'sa_syndication_force_cleanup_2026_09_23_v2', 1 );
	}
	if ( ! get_option( 'sa_syndication_force_cleanup_2026_09_23_v3' ) ) {
		// v2 s'appuie sur la meta _syndication_source, mais les tout premiers
		// articles syndiques (ex. Agadir24 du 2026-09-17, avant l'ajout de
		// l'image a la une automatique) n'ont jamais eu cette meta - constate
		// en prod : ils n'ont pas non plus de featured_media, signe qu'ils
		// viennent d'une version plus ancienne de l'endpoint /syndicate.
		// sa_syndication_expire_legacy_undated_articles() les repere par leur
		// contenu (classe CSS syndication-attribution) au lieu de la meta.
		sa_syndication_expire_legacy_undated_articles();
		update_option( 'sa_syndication_force_cleanup_2026_09_23_v3', 1 );
	}
	// Suppression definitive demandee par le redacteur en chef le 2026-09-23 :
	// article #11394 (bandeau meteo Barlamane touche par la fuite CSS/JS
	// corrigee le meme jour, voir sanitizeSyndicatedHtml() cote Node). Action
	// ponctuelle sur un article precis, pas une regle generale - le drapeau
	// evite toute re-execution.
	if ( ! get_option( 'sa_delete_post_11394_2026_09_23' ) ) {
		$thumbnail_id = get_post_thumbnail_id( 11394 );
		$deleted      = wp_delete_post( 11394, true );
		if ( $deleted && $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}
		update_option( 'sa_delete_post_11394_2026_09_23', 1 );
	}
} );

function sa_syndication_expire_old_articles( $limit = 50 ) {
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - SA_SYNDICATION_LIFETIME_HOURS * HOUR_IN_SECONDS );

	$query = new WP_Query( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => 'date',
		'order'          => 'ASC', // les plus vieux d'abord : garantit qu'un gros lot recent ne fait jamais patienter indefiniment les plus anciens.
		'meta_key'       => '_syndication_source',
		'date_query'     => array(
			array( 'column' => 'post_date_gmt', 'before' => $cutoff, 'inclusive' => true ),
		),
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	foreach ( $query->posts as $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$deleted      = wp_delete_post( $post_id, true );
		if ( $deleted && $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}
		error_log( sprintf(
			'[sa-syndication-expire] article syndique #%d supprime definitivement (age > %dh)',
			$post_id, SA_SYNDICATION_LIFETIME_HOURS
		) );
	}
}

/**
 * Purge ponctuelle (voir le drapeau v3 ci-dessus) des articles syndiques
 * heritage sans meta _syndication_source, reperes par leur contenu plutot
 * que par la meta. N'est jamais appelee par le cron recurrent : desormais
 * tout nouvel article syndique recoit systematiquement cette meta (voir
 * telegram-bot-api.php), donc ce repli par contenu n'a plus de raison d'etre
 * au-dela de ce rattrapage initial.
 */
function sa_syndication_expire_legacy_undated_articles() {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - SA_SYNDICATION_LIFETIME_HOURS * HOUR_IN_SECONDS );

	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status = 'publish'
		 AND post_content LIKE %s
		 AND post_date_gmt < %s",
		'%class="syndication-attribution%',
		$cutoff
	) );

	foreach ( $post_ids as $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$deleted      = wp_delete_post( $post_id, true );
		if ( $deleted && $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}
		error_log( sprintf(
			'[sa-syndication-expire] article syndique heritage #%d supprime definitivement (sans meta _syndication_source)',
			$post_id
		) );
	}
}

/* --------------------- RESUME AUTOMATIQUE "A LA UNE" -----------------------
 * Quand un article est publie dans la categorie Actualites (que ce soit via
 * la syndication automatique ou une publication manuelle depuis /wp-admin),
 * genere un resume fidele d'environ 5 lignes avec l'API Claude et le stocke
 * comme extrait WordPress standard (post_excerpt) - les blocs "Extrait de
 * l'article" (core/post-excerpt) deja utilises sur le site l'affichent alors
 * automatiquement, sans modification du theme. Desactive tant que
 * ANTHROPIC_API_KEY n'est pas disponible (constante WORDPRESS_CONFIG_EXTRA ou
 * variable d'environnement, meme mecanisme que PEXELS_API_KEY).
 * ---------------------------------------------------------------------------
 */

/**
 * Accepte une cle/secret soit comme constante PHP (define()), soit comme
 * variable d'environnement du conteneur (getenv()). Generalisation de
 * sa_event_stock_photo_key() pour d'autres cles (ANTHROPIC_API_KEY...) sans
 * toucher a cette fonction deja verifiee en production.
 */
function sa_event_secret( $name ) {
	if ( defined( $name ) && '' !== constant( $name ) ) {
		return constant( $name );
	}
	$env = getenv( $name );
	return ( false !== $env && '' !== $env ) ? $env : null;
}

function sa_une_generate_summary( $post_id ) {
	$api_key = sa_event_secret( 'ANTHROPIC_API_KEY' );
	if ( ! $api_key ) {
		return;
	}
	if ( get_post_meta( $post_id, '_sa_une_summary_done', true ) ) {
		return; // deja fait - evite de regenerer a chaque modification mineure
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	$title   = wp_strip_all_tags( $post->post_title );
	$content = wp_strip_all_tags( $post->post_content );
	// Coupe le bloc d'attribution de syndication (📰 Source: ... / المصدر :
	// ...) pour que le resume parle du SUJET de l'article, pas du lien source.
	$content = preg_replace( '/📰.*$/us', '', $content );
	$content = trim( mb_substr( $content, 0, 4000, 'UTF-8' ) );

	if ( '' === $content ) {
		return;
	}

	$model = sa_event_secret( 'CLAUDE_MODEL' ) ?: 'claude-sonnet-5';
	$prompt = "Voici un article de presse. Redige un resume fidele d'environ 5 lignes (pas plus), dans LA MEME LANGUE que l'article, sans inventer d'information, qui donne immediatement l'essentiel au lecteur. Reponds uniquement avec le texte du resume, sans titre ni guillemets.\n\nTitre : {$title}\n\nArticle :\n{$content}";

	$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
		'timeout' => 30,
		'headers' => array(
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		),
		'body' => wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => 400,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		) ),
	) );

	if ( is_wp_error( $response ) ) {
		error_log( '[sa-une-summary] echec appel Claude (post #' . $post_id . ') : ' . $response->get_error_message() );
		return;
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( '[sa-une-summary] erreur API Claude (post #' . $post_id . ', HTTP ' . $code . ') : ' . wp_remote_retrieve_body( $response ) );
		return;
	}

	$data    = json_decode( wp_remote_retrieve_body( $response ), true );
	$summary = '';
	if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
		foreach ( $data['content'] as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$summary .= $block['text'];
			}
		}
	}
	$summary = trim( $summary );

	if ( '' === $summary ) {
		error_log( '[sa-une-summary] reponse Claude vide (post #' . $post_id . ')' );
		return;
	}

	// wp_update_post() re-declenche transition_post_status, mais avec
	// old_status == new_status == 'publish' cette fois : le garde-fou dans le
	// hook (voir plus bas) empeche toute boucle.
	wp_update_post( array(
		'ID'           => $post_id,
		'post_excerpt' => $summary,
	) );
	update_post_meta( $post_id, '_sa_une_summary_done', 1 );
	update_post_meta( $post_id, '_sa_une_summary_generated_at', current_time( 'mysql' ) );

	error_log( '[sa-une-summary] resume genere (post #' . $post_id . ', ' . mb_strlen( $summary, 'UTF-8' ) . ' caracteres)' );
}

add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return; // uniquement au moment reel de la publication, jamais en boucle
	}
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}
	if ( ! sa_une_is_comment_free_category( $post->ID ) ) {
		return; // perimetre : Actualites + International (etendu le 2026-09-17)
	}
	sa_une_generate_summary( $post->ID );
}, 10, 3 );

/* --------------------- COMMENTAIRES -> RESUME (Actualites) -----------------
 * Sur demande du redacteur en chef : plus de formulaire "Laisser un
 * commentaire" sur les articles de la categorie Actualites - le resume IA
 * (post_excerpt, deja genere ci-dessus) s'affiche a la place. Scope sur la
 * categorie "actualites" directement (deja verifiee fiable dans le hook
 * ci-dessus) plutot que sur une synchronisation vers une categorie
 * secondaire - independant de toute autre implementation eventuelle basee
 * sur une autre categorie (ex: "L'Actu du jour") : les deux peuvent coexister
 * sans conflit, chacune ne fait rien si l'autre a deja retire le bloc.
 * --------------------------------------------------------------------------- */
// Categories concernees par le retrait du formulaire de commentaires - au
// depart uniquement "actualites", etendu a "international" le 2026-09-17
// (les depeches internationales n'etaient pas couvertes).
function sa_une_is_comment_free_category( $post_id ) {
	return has_category( 'actualites', $post_id ) || has_category( 'international', $post_id );
}

add_filter( 'comments_open', 'sa_une_close_comments_on_actualites', 10, 2 );
add_filter( 'pings_open', 'sa_une_close_comments_on_actualites', 10, 2 );

function sa_une_close_comments_on_actualites( $open, $post_id ) {
	if ( ! $post_id ) {
		global $post;
		$post_id = $post ? $post->ID : 0;
	}
	if ( $post_id && sa_une_is_comment_free_category( (int) $post_id ) ) {
		return false;
	}
	return $open;
}

add_filter( 'render_block', 'sa_une_replace_comments_block', 10, 2 );

function sa_une_replace_comments_block( $block_content, $block ) {
	if ( 'core/comments' !== ( isset( $block['blockName'] ) ? $block['blockName'] : '' ) ) {
		return $block_content;
	}
	if ( ! is_singular( 'post' ) ) {
		return $block_content;
	}
	$post_id = get_the_ID();
	if ( ! $post_id || ! sa_une_is_comment_free_category( (int) $post_id ) ) {
		return $block_content;
	}
	$the_post = get_post( $post_id );
	if ( ! $the_post ) {
		return '';
	}
	$summary = trim( (string) $the_post->post_excerpt );
	if ( '' === $summary ) {
		return ''; // pas de resume genere pour cet article : on retire juste le bloc
	}
	return '<div class="sa-resume-article">' . wpautop( esc_html( $summary ) ) . '</div>';
}
