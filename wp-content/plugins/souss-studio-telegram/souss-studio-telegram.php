<?php
/**
 * Plugin Name: Souss Studio — Telegram
 * Description: Créez une vidéo depuis votre téléphone : envoyez au bot Telegram « Souss Studio » le lien de l'article et vos photos, écrivez « video », et la vidéo terminée revient dans le chat. Indépendant du bot de publication existant.
 * Version: 1.0.1
 * Author: Souss Actualités
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SST_REPO', 'hichamjikh8-gif/souss-actualites' );
define( 'SST_BRANCH', 'videos' );

// Résumé + accroche des vidéos avec la clé Claude du site (voir ia.php).
if ( file_exists( __DIR__ . '/ia.php' ) ) {
	require_once __DIR__ . '/ia.php';
}

/* -------------------------------------------------------------------------
 * Réglages (Réglages → Studio vidéo Telegram)
 * ---------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_options_page( 'Studio vidéo Telegram', 'Studio vidéo Telegram', 'manage_options', 'sst-telegram', 'sst_settings_page' );
} );

function sst_opt( $k, $d = '' ) {
	$v = get_option( 'sst_' . $k, $d );
	return is_string( $v ) ? trim( $v ) : $v;
}

function sst_tg( $method, $params = array(), $multipart = false ) {
	$token = sst_opt( 'bot_token' );
	if ( ! $token ) {
		return null;
	}
	$url = 'https://api.telegram.org/bot' . $token . '/' . $method;
	if ( $multipart ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120 ) );
		$body = curl_exec( $ch );
		curl_close( $ch );
		return json_decode( (string) $body, true );
	}
	$r = wp_remote_post( $url, array( 'timeout' => 30, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $params ) ) );
	return is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
}

function sst_say( $chat, $text ) {
	sst_tg( 'sendMessage', array( 'chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true ) );
}

function sst_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! sst_opt( 'link_code' ) ) {
		update_option( 'sst_link_code', strtoupper( wp_generate_password( 6, false, false ) ), false );
	}
	if ( ! sst_opt( 'hook_secret' ) ) {
		update_option( 'sst_hook_secret', wp_generate_password( 40, false, false ), false );
	}
	$msg = '';
	if ( isset( $_POST['sst_save'] ) && check_admin_referer( 'sst_save' ) ) {
		if ( ! empty( $_POST['sst_bot_token'] ) ) {
			update_option( 'sst_bot_token', sanitize_text_field( wp_unslash( $_POST['sst_bot_token'] ) ), false );
		}
		if ( ! empty( $_POST['sst_github_token'] ) ) {
			update_option( 'sst_github_token', sanitize_text_field( wp_unslash( $_POST['sst_github_token'] ) ), false );
		}
		$res = sst_tg( 'setWebhook', array(
			'url'             => rest_url( 'sst/v1/telegram' ),
			'secret_token'    => sst_opt( 'hook_secret' ),
			'allowed_updates' => array( 'message' ),
		) );
		$msg = ( $res && ! empty( $res['ok'] ) ) ? '✅ Enregistré. Le bot est connecté.' : '⚠️ Enregistré, mais le bot ne répond pas : vérifiez la clé du bot.';
	}
	$me     = sst_opt( 'bot_token' ) ? sst_tg( 'getMe' ) : null;
	$botname = ( $me && ! empty( $me['ok'] ) ) ? '@' . $me['result']['username'] : '';
	?>
	<div class="wrap">
		<h1>🎬 Studio vidéo — Telegram</h1>
		<?php if ( $msg ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'sst_save' ); ?>
			<table class="form-table">
				<tr><th>Clé du bot Telegram</th><td><input type="password" name="sst_bot_token" class="regular-text" placeholder="<?php echo sst_opt( 'bot_token' ) ? 'déjà enregistrée — laisser vide pour garder' : '123456:ABC…'; ?>"></td></tr>
				<tr><th>Clé d'accès GitHub</th><td><input type="password" name="sst_github_token" class="regular-text" placeholder="<?php echo sst_opt( 'github_token' ) ? 'déjà enregistrée — laisser vide pour garder' : 'github_pat_…'; ?>"></td></tr>
			</table>
			<p><button class="button button-primary" name="sst_save" value="1">Enregistrer et connecter le bot</button></p>
		</form>
		<h2>État</h2>
		<p>Bot : <strong><?php echo $botname ? esc_html( $botname ) : 'non connecté'; ?></strong></p>
		<p>Votre téléphone : <strong><?php echo sst_opt( 'chat_id' ) ? 'relié ✅' : 'pas encore relié'; ?></strong></p>
		<?php if ( ! sst_opt( 'chat_id' ) ) : ?>
			<p>Pour relier votre téléphone, envoyez au bot ce message : <code style="font-size:18px">/start <?php echo esc_html( sst_opt( 'link_code' ) ); ?></code></p>
		<?php endif; ?>
		<?php
		$hit  = get_option( 'sst_last_hit', array() );
		$info = sst_opt( 'bot_token' ) ? sst_tg( 'getWebhookInfo' ) : null;
		$wi   = ( $info && ! empty( $info['ok'] ) ) ? $info['result'] : array();
		?>
		<h2>Diagnostic</h2>
		<ul>
			<li>Adresse du webhook : <code><?php echo esc_html( isset( $wi['url'] ) ? $wi['url'] : '—' ); ?></code></li>
			<li>Messages en attente chez Telegram : <?php echo esc_html( isset( $wi['pending_update_count'] ) ? (string) $wi['pending_update_count'] : '—' ); ?></li>
			<li>Dernière erreur Telegram : <?php echo esc_html( ! empty( $wi['last_error_message'] ) ? $wi['last_error_message'] . ' (' . gmdate( 'H:i', (int) $wi['last_error_date'] ) . ' UTC)' : 'aucune' ); ?></li>
			<li>Dernier message reçu par le site : <?php echo $hit ? esc_html( gmdate( 'H:i:s', (int) $hit['t'] ) . ' UTC — clé ' . ( $hit['secret_ok'] ? 'valide' : ( $hit['has_secret'] ? 'incorrecte' : 'absente' ) ) ) : 'jamais'; ?></li>
		</ul>
		<h2>Mode d'emploi</h2>
		<ol>
			<li>Envoyez au bot le <strong>lien de l'article</strong> (ou collez le texte).</li>
			<li>Envoyez vos <strong>photos</strong> (5 conseillées).</li>
			<li>Écrivez <strong>video</strong>. La vidéo revient dans le chat en 5 minutes environ.</li>
		</ol>
		<p>Options à écrire avant « video » : <code>arabe</code>, <code>complet</code>, <code>femme</code>, <code>musique beethoven</code>… — <code>annuler</code> pour recommencer.</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Webhook Telegram + retour de la vidéo
 * ---------------------------------------------------------------------- */
add_action( 'rest_api_init', function () {
	register_rest_route( 'sst/v1', '/telegram', array( 'methods' => 'POST', 'callback' => 'sst_webhook', 'permission_callback' => '__return_true' ) );
	register_rest_route( 'sst/v1', '/done', array( 'methods' => 'POST', 'callback' => 'sst_done', 'permission_callback' => '__return_true' ) );
} );

function sst_session( $chat, $set = null ) {
	$key = 'sst_session_' . md5( (string) $chat );
	if ( null === $set ) {
		$s = get_option( $key, array() );
		return is_array( $s ) ? $s : array();
	}
	update_option( $key, $set, false );
	return $set;
}

function sst_webhook( WP_REST_Request $req ) {
	$secret = (string) $req->get_header( 'x_telegram_bot_api_secret_token' );
	if ( '' === $secret && isset( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ) ) {
		$secret = (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'];
	}
	$ok_secret = sst_opt( 'hook_secret' ) && hash_equals( sst_opt( 'hook_secret' ), $secret );
	update_option( 'sst_last_hit', array( 't' => time(), 'secret_ok' => $ok_secret, 'has_secret' => '' !== $secret ), false );
	if ( ! $ok_secret ) {
		return new WP_REST_Response( array( 'ok' => false ), 403 );
	}
	$u   = $req->get_json_params();
	$m   = isset( $u['message'] ) ? $u['message'] : null;
	if ( ! $m || empty( $m['chat']['id'] ) ) {
		return array( 'ok' => true );
	}
	$chat = (string) $m['chat']['id'];
	$text = isset( $m['text'] ) ? trim( $m['text'] ) : ( isset( $m['caption'] ) ? trim( $m['caption'] ) : '' );

	// Liaison du téléphone (une seule fois, avec le code affiché dans les réglages)
	if ( ! sst_opt( 'chat_id' ) ) {
		$norm = strtoupper( preg_replace( '#[^A-Za-z0-9]#', '', $text ) );
		if ( sst_opt( 'link_code' ) && false !== strpos( $norm, sst_opt( 'link_code' ) ) ) {
			update_option( 'sst_chat_id', $chat, false );
			sst_say( $chat, "✅ Téléphone relié au Studio vidéo Souss Actualités.\n\n1) Envoyez le lien de l'article\n2) Envoyez vos photos\n3) Écrivez « video »" );
		}
		return array( 'ok' => true );
	}
	if ( ! hash_equals( sst_opt( 'chat_id' ), $chat ) ) {
		return array( 'ok' => true ); // personne d'autre ne peut utiliser le bot
	}

	$s = sst_session( $chat );
	$s += array( 'url' => '', 'text' => '', 'files' => array(), 'opts' => array() );

	// Photos / vidéos
	$fid = '';
	$kind = 'jpg';
	if ( ! empty( $m['photo'] ) ) {
		$p   = end( $m['photo'] );
		$fid = $p['file_id'];
	} elseif ( ! empty( $m['video'] ) ) {
		$fid  = $m['video']['file_id'];
		$kind = 'mp4';
	} elseif ( ! empty( $m['document'] ) && preg_match( '#^(image|video)/#', (string) $m['document']['mime_type'] ) ) {
		$fid  = $m['document']['file_id'];
		$kind = 0 === strpos( $m['document']['mime_type'], 'video' ) ? 'mp4' : 'jpg';
	}
	if ( $fid ) {
		$s['files'][] = array( 'id' => $fid, 'ext' => $kind );
		sst_session( $chat, $s );
		sst_say( $chat, '📷 ' . count( $s['files'] ) . ( 'mp4' === $kind ? ' vidéo(s)/photo(s)' : ' photo(s)' ) . ' reçue(s). Écrivez « video » quand c\'est prêt.' );
		if ( ! $text ) {
			return array( 'ok' => true );
		}
	}

	$low = mb_strtolower( $text );
	if ( '' === $text ) {
		return array( 'ok' => true );
	}
	if ( in_array( $low, array( 'annuler', '/annuler', '/reset', 'reset', 'stop' ), true ) ) {
		sst_session( $chat, array() );
		sst_say( $chat, '🗑 C\'est remis à zéro. Envoyez un lien et des photos.' );
		return array( 'ok' => true );
	}
	if ( in_array( $low, array( '/start', 'aide', '/aide', 'help', '/help' ), true ) ) {
		sst_say( $chat, "1) Envoyez le lien de l'article\n2) Envoyez vos photos\n3) Écrivez « video »\n\nOptions : arabe, complet, femme, musique mozart / beethoven / chopin / vivaldi / bach / sans\n« annuler » pour recommencer." );
		return array( 'ok' => true );
	}
	if ( preg_match( '#^/?(video|vidéo|فيديو)$#u', $low ) ) {
		return sst_launch( $chat, $s );
	}
	// Options
	$known = false;
	if ( preg_match( '#^(arabe|عربي|العربية)$#u', $low ) ) { $s['opts']['langue'] = 'ar'; $known = true; }
	if ( preg_match( '#^(fran[cç]ais)$#u', $low ) ) { $s['opts']['langue'] = 'fr'; $known = true; }
	if ( 'complet' === $low ) { $s['opts']['mode'] = 'complet'; $known = true; }
	if ( 'résumé' === $low || 'resume' === $low ) { $s['opts']['mode'] = 'resume'; $known = true; }
	if ( 'femme' === $low ) { $s['opts']['voix_fr'] = 'fr-FR-DeniseNeural'; $s['opts']['voix_ar'] = 'ar-SA-ZariyahNeural'; $known = true; }
	if ( 'homme' === $low ) { $s['opts']['voix_fr'] = 'fr-FR-HenriNeural'; $s['opts']['voix_ar'] = 'ar-SA-HamedNeural'; $known = true; }
	if ( preg_match( '#^musique\s+(\S+)#u', $low, $mm ) ) {
		$map = array( 'mozart' => 'mozart_piano', 'nachtmusik' => 'mozart_nachtmusik', 'beethoven' => 'beethoven_lune', 'chopin' => 'chopin_nocturne', 'vivaldi' => 'vivaldi_printemps', 'bach' => 'bach_air', 'sans' => 'aucune', 'dynamique' => 'elan', 'calme' => 'calme' );
		$s['opts']['musique'] = isset( $map[ $mm[1] ] ) ? $map[ $mm[1] ] : 'mozart_piano';
		$known = true;
	}
	if ( $known ) {
		sst_session( $chat, $s );
		sst_say( $chat, '👍 Option notée.' );
		return array( 'ok' => true );
	}
	if ( preg_match( '#https?://\S+#', $text, $mm ) ) {
		$s['url'] = esc_url_raw( $mm[0] );
		sst_session( $chat, $s );
		sst_say( $chat, '🔗 Lien reçu. Envoyez maintenant vos photos, puis écrivez « video ».' );
		return array( 'ok' => true );
	}
	if ( mb_strlen( $text ) > 120 ) {
		$s['text'] = $text;
		sst_session( $chat, $s );
		sst_say( $chat, '📝 Texte reçu. Envoyez vos photos, puis écrivez « video ».' );
		return array( 'ok' => true );
	}
	sst_say( $chat, "Je n'ai pas compris. Envoyez un lien, des photos, puis « video ». (« aide » pour l'aide)" );
	return array( 'ok' => true );
}

function sst_gh( $method, $path, $body = null ) {
	$args = array(
		'method'  => $method,
		'timeout' => 60,
		'headers' => array( 'Authorization' => 'Bearer ' . sst_opt( 'github_token' ), 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'souss-studio' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = wp_remote_request( 'https://api.github.com/repos/' . SST_REPO . $path, $args );
	return is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
}

function sst_launch( $chat, $s ) {
	if ( ! $s['url'] && ! $s['text'] ) {
		sst_say( $chat, '⚠️ Envoyez d\'abord le lien de l\'article.' );
		return array( 'ok' => true );
	}
	if ( empty( $s['files'] ) ) {
		sst_say( $chat, '⚠️ Envoyez au moins une photo.' );
		return array( 'ok' => true );
	}
	if ( ! sst_opt( 'github_token' ) ) {
		sst_say( $chat, '⚠️ La clé GitHub manque dans les réglages WordPress.' );
		return array( 'ok' => true );
	}
	sst_session( $chat, array() ); // évite un double lancement si Telegram renvoie le message
	sst_say( $chat, '⏳ Préparation de la vidéo… (' . count( $s['files'] ) . ' média(s))' );
	$id = 'tg-' . gmdate( 'Ymd-His' );
	$n  = 0;
	foreach ( $s['files'] as $f ) {
		$info = sst_tg( 'getFile', array( 'file_id' => $f['id'] ) );
		if ( ! $info || empty( $info['ok'] ) ) {
			continue;
		}
		$dl = wp_remote_get( 'https://api.telegram.org/file/bot' . sst_opt( 'bot_token' ) . '/' . $info['result']['file_path'], array( 'timeout' => 60 ) );
		if ( is_wp_error( $dl ) ) {
			continue;
		}
		$n++;
		$code = sst_gh( 'PUT', '/contents/jobs/' . $id . '/media/' . sprintf( '%02d', $n ) . '.' . $f['ext'], array(
			'message' => 'média ' . $id . ' [skip ci]',
			'content' => base64_encode( wp_remote_retrieve_body( $dl ) ),
			'branch'  => SST_BRANCH,
		) );
		if ( $code < 200 || $code > 299 ) {
			sst_say( $chat, '⚠️ Envoi d\'une photo vers le studio refusé (' . $code . ').' );
		}
	}
	if ( ! $n ) {
		sst_say( $chat, '⚠️ Impossible de récupérer les photos. Réessayez.' );
		return array( 'ok' => true );
	}
	$key  = wp_generate_password( 32, false, false );
	$jobs = get_option( 'sst_jobs', array() );
	$jobs[ $id ] = array( 'key' => $key, 'chat' => $chat, 't' => time() );
	update_option( 'sst_jobs', array_slice( $jobs, -30, null, true ), false );
	$job = array_merge(
		array( 'mode' => 'resume', 'musique' => 'mozart_piano', 'effet' => '3d', 'langue' => 'auto', 'seulement_mes_photos' => true, 'rubrique' => '' ),
		$s['opts'],
		$s['url'] ? array( 'url' => $s['url'] ) : array( 'text' => $s['text'] ),
		array( 'callback' => array( 'url' => rest_url( 'sst/v1/done' ), 'id' => $id, 'key' => $key ) )
	);
	$code = sst_gh( 'PUT', '/contents/jobs/' . $id . '/job.json', array(
		'message' => 'Vidéo Telegram ' . $id,
		'content' => base64_encode( wp_json_encode( $job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ),
		'branch'  => SST_BRANCH,
	) );
	if ( $code >= 200 && $code <= 299 ) {
		sst_say( $chat, '🎬 C\'est parti ! La vidéo arrive ici dans 5 minutes environ.' );
	} else {
		sst_say( $chat, '⚠️ Le studio n\'a pas accepté la demande (' . $code . '). Vérifiez la clé GitHub.' );
	}
	return array( 'ok' => true );
}

function sst_done( WP_REST_Request $req ) {
	$p    = $req->get_json_params();
	$id   = isset( $p['id'] ) ? sanitize_text_field( $p['id'] ) : '';
	$jobs = get_option( 'sst_jobs', array() );
	if ( ! $id || empty( $jobs[ $id ] ) || ! hash_equals( $jobs[ $id ]['key'], (string) ( isset( $p['key'] ) ? $p['key'] : '' ) ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 403 );
	}
	$chat = $jobs[ $id ]['chat'];
	unset( $jobs[ $id ] );
	update_option( 'sst_jobs', $jobs, false );
	if ( empty( $p['video'] ) ) {
		sst_say( $chat, '❌ La vidéo n\'a pas pu être créée. Vérifiez le lien et les photos.' );
		return array( 'ok' => true );
	}
	$name = basename( sanitize_file_name( $p['video'] ) );
	$raw  = 'https://raw.githubusercontent.com/' . SST_REPO . '/' . SST_BRANCH . '/sorties/' . rawurlencode( $id ) . '/' . rawurlencode( $name );
	$dl   = wp_remote_get( $raw, array( 'timeout' => 120 ) );
	if ( is_wp_error( $dl ) || 200 !== (int) wp_remote_retrieve_response_code( $dl ) ) {
		sst_say( $chat, '✅ Vidéo prête, mais l\'envoi a échoué. Elle est dans le Studio vidéo du site.' );
		return array( 'ok' => true );
	}
	$tmp = wp_tempnam( $name );
	file_put_contents( $tmp, wp_remote_retrieve_body( $dl ) );
	$caption = isset( $p['description'] ) ? mb_substr( (string) $p['description'], 0, 1000 ) : '';
	sst_tg( 'sendVideo', array(
		'chat_id'            => $chat,
		'video'              => new CURLFile( $tmp, 'video/mp4', $name ),
		'caption'            => $caption,
		'supports_streaming' => 'true',
	), true );
	@unlink( $tmp );
	sst_say( $chat, "✅ Votre vidéo est prête ! Appuyez longuement dessus → Partager → TikTok / YouTube. Le texte ci-dessus est à copier." );
	return array( 'ok' => true );
}
