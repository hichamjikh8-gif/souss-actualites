<?php
/**
 * Souss Studio — résumé et accroche des vidéos par Claude, avec la clé ANTHROPIC_API_KEY déjà configurée sur le site.
 *
 * Le Studio (GitHub Actions) n'a pas sa propre clé : il appelle POST /wp-json/sst/v1/ia {"job": "<id>"}.
 * Pourquoi c'est sûr sans secret partagé : le site ne lit jamais le texte envoyé par l'appelant. Il relit lui-même
 * jobs/<id>/job.json sur la branche « videos » (seuls les comptes autorisés peuvent y écrire), ne résume que ce
 * contenu, met le résultat en cache 30 jours (un même job ne coûte qu'un appel) et plafonne à 150 appels par jour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'sst/v1', '/ia', array( 'methods' => 'POST', 'callback' => 'sst_ia', 'permission_callback' => '__return_true' ) );
} );

function sst_ia_secret( $name ) {
	if ( function_exists( 'sa_event_secret' ) ) {
		return sa_event_secret( $name );
	}
	if ( defined( $name ) && '' !== constant( $name ) ) {
		return constant( $name );
	}
	$env = getenv( $name );
	return ( false !== $env && '' !== $env ) ? $env : null;
}

function sst_ia_job( $id ) {
	$headers = array( 'Accept' => 'application/vnd.github.raw', 'User-Agent' => 'souss-studio' );
	$token   = function_exists( 'sst_opt' ) ? sst_opt( 'github_token' ) : '';
	if ( $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}
	$r = wp_remote_get( 'https://api.github.com/repos/' . SST_REPO . '/contents/jobs/' . rawurlencode( $id ) . '/job.json?ref=' . SST_BRANCH, array( 'timeout' => 20, 'headers' => $headers ) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return null;
	}
	$job = json_decode( wp_remote_retrieve_body( $r ), true );
	return is_array( $job ) ? $job : null;
}

function sst_ia( WP_REST_Request $req ) {
	$id = (string) $req->get_param( 'job' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{3,60}$/', $id ) ) {
		return new WP_REST_Response( array( 'error' => 'job invalide' ), 400 );
	}
	$cache_key = 'sst_ia_' . md5( $id );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$api_key = sst_ia_secret( 'ANTHROPIC_API_KEY' );
	if ( ! $api_key ) {
		return new WP_REST_Response( array( 'error' => 'clé absente' ), 503 );
	}
	$day   = 'sst_ia_count_' . gmdate( 'Ymd' );
	$count = (int) get_transient( $day );
	if ( $count >= 150 ) {
		return new WP_REST_Response( array( 'error' => 'quota du jour atteint' ), 429 );
	}

	$job = sst_ia_job( $id );
	if ( ! $job ) {
		return new WP_REST_Response( array( 'error' => 'job introuvable' ), 404 );
	}

	$title   = '';
	$content = '';
	$url     = isset( $job['url'] ) ? trim( (string) $job['url'] ) : '';
	if ( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && false !== strpos( $host, 'souss-actualites.com' ) ) {
			$pid = url_to_postid( $url );
			if ( $pid ) {
				$post    = get_post( $pid );
				$title   = wp_strip_all_tags( $post->post_title );
				$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			}
		}
	}
	if ( '' === $content && ! empty( $job['text'] ) ) {
		$lines   = preg_split( '/\r?\n/', trim( (string) $job['text'] ) );
		$title   = ! empty( $job['title'] ) ? (string) $job['title'] : (string) array_shift( $lines );
		$content = implode( "\n", $lines );
	}
	$content = trim( mb_substr( preg_replace( '/📰.*$/us', '', $content ), 0, 12000, 'UTF-8' ) );
	if ( '' === $content ) {
		return new WP_REST_Response( array( 'error' => 'article vide' ), 422 );
	}

	$prompt = "Tu es le rédacteur vidéo de Souss Actualités. À partir de l'article ci-dessous, écris le texte d'une vidéo verticale "
		. "d'environ 60 secondes (140 à 160 mots), DANS LA MÊME LANGUE QUE L'ARTICLE, fidèle à l'article (aucun fait ajouté, "
		. "déclarations attribuées, hypothèses présentées comme telles). Phrases courtes, rythme journalistique, première phrase accrocheuse. "
		. "Ajoute une accroche d'ouverture de 3 à 5 mots, dans la même langue, percutante mais fidèle (pas d'exagération, pas de point "
		. "d'exclamation, pas de guillemets, pas d'emoji ; une déclaration ou une hypothèse ne doit pas devenir un fait). "
		. 'Réponds UNIQUEMENT en JSON: {"lines": ["phrase 1", "phrase 2"], "accroche": "3 à 5 mots", '
		. '"description": "texte pour réseaux sociaux (2 phrases)", "hashtags": ["#..."], "keywords_images": ["mots-clés"]}'
		. "\n\nTITRE: {$title}\n\nARTICLE:\n{$content}";

	set_transient( $day, $count + 1, DAY_IN_SECONDS );
	$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
		'timeout' => 90,
		'headers' => array(
			'x-api-key'         => $api_key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		),
		'body'    => wp_json_encode( array(
			'model'      => sst_ia_secret( 'CLAUDE_MODEL' ) ?: 'claude-sonnet-5-5',
			'max_tokens' => 2000,
			'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
		) ),
	) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return new WP_REST_Response( array( 'error' => 'Claude indisponible (' . ( is_wp_error( $r ) ? 'réseau' : (int) wp_remote_retrieve_response_code( $r ) ) . ')' ), 502 );
	}
	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	$txt  = isset( $body['content'][0]['text'] ) ? (string) $body['content'][0]['text'] : '';
	$a    = strpos( $txt, '{' );
	$b    = strrpos( $txt, '}' );
	$data = ( false !== $a && false !== $b ) ? json_decode( substr( $txt, $a, $b - $a + 1 ), true ) : null;
	if ( ! is_array( $data ) || empty( $data['lines'] ) || ! is_array( $data['lines'] ) ) {
		return new WP_REST_Response( array( 'error' => 'réponse illisible' ), 502 );
	}
	$out = array(
		'lines'           => array_values( array_map( 'strval', $data['lines'] ) ),
		'accroche'        => isset( $data['accroche'] ) ? (string) $data['accroche'] : '',
		'description'     => isset( $data['description'] ) ? (string) $data['description'] : '',
		'hashtags'        => isset( $data['hashtags'] ) && is_array( $data['hashtags'] ) ? array_values( array_map( 'strval', $data['hashtags'] ) ) : array(),
		'keywords_images' => isset( $data['keywords_images'] ) && is_array( $data['keywords_images'] ) ? array_values( array_map( 'strval', $data['keywords_images'] ) ) : array(),
	);
	set_transient( $cache_key, $out, 30 * DAY_IN_SECONDS );
	return $out;
}
