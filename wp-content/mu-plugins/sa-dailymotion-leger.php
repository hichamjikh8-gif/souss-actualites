<?php
/**
 * Plugin Name: Souss Actualites - Lecteur Dailymotion léger
 * Description: Dans les articles, les vidéos Dailymotion s'affichent d'abord
 *              sous forme d'image avec un bouton lecture ; le vrai lecteur
 *              Dailymotion n'est chargé qu'au clic. La page s'ouvre plus vite
 *              et Dailymotion ne dépose aucun cookie tant que le lecteur ne
 *              lit pas. Ajoute aussi la fiche VideoObject pour Google.
 *              Ne touche qu'aux blocs Embed Dailymotion (YouTube inchangé).
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Lecteur « Souss Actualités » créé sur Dailymotion : depuis 2025, une vidéo
// intégrée sans Player ID est refusée (« Forbidden ») et n'est pas monétisée.
define( 'SA_DM_PLAYER_ID', 'x1ow3m' );

// L'éditeur WordPress (aperçu) et tout affichage classique utilisent aussi ce lecteur.
add_filter( 'oembed_fetch_url', function ( $url ) {
	if ( false !== strpos( $url, 'dailymotion.com/services/oembed' ) ) {
		$url = add_query_arg( 'player', SA_DM_PLAYER_ID, $url );
	}
	return $url;
} );

function sa_dml_video_id( $url ) {
	if ( preg_match( '~(?:dailymotion\.com/(?:video|embed/video)/|dai\.ly/)([A-Za-z0-9]{5,20})~', (string) $url, $m ) ) {
		return $m[1];
	}
	return '';
}

$GLOBALS['sa_dml_ids'] = array();

add_filter( 'render_block', function ( $html, $block ) {
	if ( is_admin() || is_feed() || 'core/embed' !== $block['blockName'] ) {
		return $html;
	}
	$attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
	if ( empty( $attrs['providerNameSlug'] ) || 'dailymotion' !== $attrs['providerNameSlug'] ) {
		return $html;
	}
	$id = sa_dml_video_id( isset( $attrs['url'] ) ? $attrs['url'] : '' );
	if ( ! $id ) {
		return $html;
	}
	$GLOBALS['sa_dml_ids'][] = $id;

	// On garde la <figure> d'origine (classes de ratio 16:9 / 9:16 de WordPress) et on remplace seulement son contenu.
	$class = 'wp-block-embed is-type-video is-provider-dailymotion wp-block-embed-dailymotion';
	if ( preg_match( '~<figure[^>]*class="([^"]*)"~', $html, $m ) ) {
		$class = $m[1];
	}
	$vertical = ( false !== strpos( $class, 'aspect-9-16' ) );
	// Le bouton porte son propre ratio : on retire celui de WordPress pour ne pas doubler la hauteur.
	$class = trim( preg_replace( '~\s*(wp-has-aspect-ratio|wp-embed-aspect-[0-9-]+)~', '', $class ) );
	$thumb    = 'https://www.dailymotion.com/thumbnail/video/' . rawurlencode( $id );
	$title    = esc_attr( wp_strip_all_tags( get_the_title() ) );

	return '<figure class="' . esc_attr( $class ) . ' sa-dml">'
		. '<div class="wp-block-embed__wrapper">'
		. '<button type="button" class="sa-dml-btn' . ( $vertical ? ' sa-dml-v' : '' ) . '" data-dm="' . esc_attr( $id ) . '" aria-label="Lire la vidéo : ' . $title . '">'
		. '<img src="' . esc_url( $thumb ) . '" alt="' . $title . '" loading="lazy" decoding="async">'
		. '<span class="sa-dml-play" aria-hidden="true"></span>'
		. '</button>'
		. '</div></figure>';
}, 20, 2 );

add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['sa_dml_ids'] ) ) {
		return;
	}
	?>
	<style>
	.sa-dml .wp-block-embed__wrapper{position:relative}
	.sa-dml-btn{position:relative;display:block;width:100%;aspect-ratio:16/9;padding:0;border:0;background:#000;cursor:pointer;overflow:hidden}
	.sa-dml-btn.sa-dml-v{aspect-ratio:9/16;max-width:420px;margin:0 auto}
	.sa-dml-btn img{width:100%;height:100%;object-fit:cover;display:block}
	.sa-dml-play{position:absolute;left:50%;top:50%;width:72px;height:72px;margin:-36px 0 0 -36px;border-radius:50%;background:rgba(0,0,0,.65)}
	.sa-dml-play::after{content:"";position:absolute;left:29px;top:22px;border-style:solid;border-width:14px 0 14px 22px;border-color:transparent transparent transparent #fff}
	.sa-dml-btn:hover .sa-dml-play{background:#e6781e}
	.sa-dml iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
	</style>
	<script>
	document.addEventListener('click',function(e){
		var b=e.target.closest&&e.target.closest('.sa-dml-btn');if(!b)return;
		var f=document.createElement('iframe');
		f.src='https://geo.dailymotion.com/player/<?php echo esc_js( SA_DM_PLAYER_ID ); ?>.html?video='+encodeURIComponent(b.getAttribute('data-dm'))+'&autoplay=1';f.referrerPolicy='strict-origin-when-cross-origin';
		f.allow='autoplay; fullscreen; picture-in-picture; web-share';f.allowFullscreen=true;f.title=b.getAttribute('aria-label')||'Vidéo';
		b.style.visibility='hidden';b.parentNode.appendChild(f);
	});
	</script>
	<?php
}, 30 );

// Fiche VideoObject pour Google : le lecteur n'étant chargé qu'au clic, on décrit la vidéo explicitement.
add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post || false === strpos( (string) $post->post_content, 'wp-block-embed-dailymotion' ) ) {
		return;
	}
	if ( ! preg_match( '~dailymotion\.com/video/([A-Za-z0-9]{5,20})~', $post->post_content, $m ) ) {
		return;
	}
	$id   = $m[1];
	$name = wp_strip_all_tags( get_the_title( $post ) );
	$desc = has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '…' );
	$data = array(
		'@context'     => 'https://schema.org',
		'@type'        => 'VideoObject',
		'name'         => $name,
		'description'  => '' !== trim( $desc ) ? $desc : $name,
		'thumbnailUrl' => array( 'https://www.dailymotion.com/thumbnail/video/' . $id ),
		'uploadDate'   => get_post_time( 'c', true, $post ),
		'embedUrl'     => 'https://geo.dailymotion.com/player/' . SA_DM_PLAYER_ID . '.html?video=' . $id,
		'publisher'    => array( '@type' => 'NewsMediaOrganization', 'name' => 'Souss Actualités', 'url' => home_url( '/' ) ),
	);
	echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 30 );
