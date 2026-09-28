<?php
/**
 * Plugin Name: Souss Actualites - Publicite native
 * Description: Remplace les anciens interstitiels plein ecran (Atlas Depeche, Zainbella) par une carte publicitaire integree au contenu, avec effet de relief au survol (CSS only, sans librairie externe).
 * Version: 1.0.0
 * Author: Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupe-circuit pour les deux anciens interstitiels plein ecran.
 *
 * Leur code source reste introuvable (ni plugin, ni mu-plugin, ni theme, ni
 * volume Railway ne le contiennent - voir discussion avec le redacteur en
 * chef du 2026-09-28) : on ne peut donc pas supprimer le fichier a la source.
 * A la place, on intercepte les deux routes au tout debut de 'init' (priorite
 * tres basse = executee avant tout autre callback sur ce hook, y compris celui
 * qui genere l'ancien script) et on renvoie une reponse vide : plus aucun
 * visiteur ne recoit le JS qui affichait ces popups.
 */
add_action( 'init', function () {
	$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

	if ( ! in_array( $path, array( 'atlas-panel-script', 'zb-panel-script' ), true ) ) {
		return;
	}

	status_header( 410 );
	header( 'Content-Type: application/javascript; charset=utf-8' );
	exit;
}, -9999 );

/**
 * Campagnes actives. Modifiable ici sans toucher au reste du fichier :
 * advertiser, tagline, headline, image, url, cta.
 */
function souss_ads_campaigns() {
	return apply_filters( 'souss_ads_campaigns', array(
		'atlas'     => array(
			'advertiser' => 'Atlas Depeche',
			'tagline'    => "L'actualite autrement.",
			'headline'   => 'Des titres qui bougent aussi vite que le pays.',
			'image'      => '', // pas de visuel fourni par l'annonceur : badge degrade CSS
			'url'        => 'https://www.xn--atlasdpche-g7an.com/fr',
			'cta'        => 'Decouvrir Atlas Depeche',
		),
		'zainbella' => array(
			'advertiser' => 'ZAINBELLA',
			'tagline'    => 'Beaute europeenne, a un clic.',
			'headline'   => "L'Oreal Paris BB Creme 5-en-1",
			'image'      => 'https://zainbella.com/assets/images/l-oreal-paris-bb-creme-5-en-1-hydratation-24-h-texture-tres-legere-spf-11-30-ml-f4495ac3.png',
			'url'        => 'https://zainbella.com/',
			'cta'        => 'Decouvrir Zainbella',
		),
	) );
}

/**
 * Combien de paragraphes avant d'inserer la carte dans un article.
 */
function souss_ads_paragraph_offset() {
	return (int) apply_filters( 'souss_ads_paragraph_offset', 3 );
}

/**
 * Carte flottante en coin (desactivee par defaut : on commence par la version
 * la moins intrusive et on l'active seulement si le rendacteur en chef le decide).
 */
function souss_ads_floating_enabled() {
	return (bool) apply_filters( 'souss_ads_floating_enabled', false );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}

	$base = WPMU_PLUGIN_DIR . '/souss-ads/assets';

	wp_enqueue_style(
		'souss-ads',
		content_url( 'mu-plugins/souss-ads/assets/ads.css' ),
		array(),
		file_exists( $base . '/ads.css' ) ? filemtime( $base . '/ads.css' ) : '1.0.0'
	);

	wp_enqueue_script(
		'souss-ads',
		content_url( 'mu-plugins/souss-ads/assets/ads.js' ),
		array(),
		file_exists( $base . '/ads.js' ) ? filemtime( $base . '/ads.js' ) : '1.0.0',
		true
	);
} );

function souss_ads_pick_campaign( $seed ) {
	$campaigns = souss_ads_campaigns();
	if ( empty( $campaigns ) ) {
		return null;
	}
	$keys  = array_keys( $campaigns );
	$index = $seed % count( $keys );
	return $campaigns[ $keys[ $index ] ];
}

function souss_ads_render_visual( $campaign ) {
	if ( ! empty( $campaign['image'] ) ) {
		return sprintf(
			'<img class="souss-ad__img" src="%1$s" alt="%2$s" loading="lazy" decoding="async" width="96" height="96">',
			esc_url( $campaign['image'] ),
			esc_attr( $campaign['headline'] )
		);
	}

	$initial = mb_substr( trim( $campaign['advertiser'] ), 0, 1 );

	return sprintf(
		'<div class="souss-ad__img souss-ad__img--badge" aria-hidden="true"><span>%s</span></div>',
		esc_html( $initial )
	);
}

function souss_ads_render_card( $campaign, $context = 'inline' ) {
	if ( empty( $campaign ) ) {
		return '';
	}

	return sprintf(
		'<div class="souss-ad souss-ad--%1$s" data-souss-ad>
			<span class="souss-ad__label">Publicite</span>
			<a class="souss-ad__link" href="%2$s" target="_blank" rel="sponsored noopener">
				<div class="souss-ad__inner">
					%3$s
					<div class="souss-ad__body">
						<p class="souss-ad__brand">%4$s</p>
						<p class="souss-ad__headline">%5$s</p>
						<p class="souss-ad__tagline">%6$s</p>
						<span class="souss-ad__cta">%7$s</span>
					</div>
				</div>
			</a>
		</div>',
		esc_attr( $context ),
		esc_url( $campaign['url'] ),
		souss_ads_render_visual( $campaign ),
		esc_html( $campaign['advertiser'] ),
		esc_html( $campaign['headline'] ),
		esc_html( $campaign['tagline'] ),
		esc_html( $campaign['cta'] )
	);
}

add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$campaign = souss_ads_pick_campaign( get_the_ID() );
	if ( empty( $campaign ) ) {
		return $content;
	}

	$card       = souss_ads_render_card( $campaign, 'inline' );
	$paragraphs = explode( '</p>', $content );
	$offset     = min( souss_ads_paragraph_offset(), max( count( $paragraphs ) - 1, 0 ) );

	if ( $offset <= 0 || count( $paragraphs ) <= 1 ) {
		return $content . $card;
	}

	$paragraphs[ $offset ] .= '</p>' . $card;

	return implode( '</p>', $paragraphs );
} );

add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_singular( 'post' ) || ! souss_ads_floating_enabled() ) {
		return;
	}

	$campaign = souss_ads_pick_campaign( get_the_ID() + 1 );
	if ( empty( $campaign ) ) {
		return;
	}

	echo souss_ads_render_card( $campaign, 'floating' ); // phpcs:ignore -- deja echappe dans souss_ads_render_card
} );
