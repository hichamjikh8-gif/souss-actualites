<?php
/**
 * Plugin Name: Souss Actualites - Bandeau Breaking News
 * Description: Affiche une banniere rouge "BREAKING NEWS" en haut du site avec
 *              le titre du dernier article publie, en ticker defilant (fond
 *              rouge, texte blanc). Le titre est cliquable vers l'article.
 * Version:     1.0.0
 * Author:      Souss Actualites
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}

	$banner_css = '
.sa-breaking-news {
	display: flex;
	align-items: center;
	width: 100%;
	box-sizing: border-box;
	background: #d40000;
	color: #ffffff;
	overflow: hidden;
	height: 40px;
	z-index: 99999;
	position: relative;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.sa-breaking-news-label {
	flex: 0 0 auto;
	background: #a80000;
	color: #ffffff;
	font-weight: 700;
	font-size: 13px;
	letter-spacing: 1px;
	padding: 0 18px;
	line-height: 40px;
	text-transform: uppercase;
	white-space: nowrap;
	z-index: 2;
}
.sa-breaking-news-track {
	flex: 1 1 auto;
	overflow: hidden;
	position: relative;
	height: 40px;
	mask-image: linear-gradient(90deg, #000 92%, transparent 100%);
	-webkit-mask-image: linear-gradient(90deg, #000 92%, transparent 100%);
}
.sa-breaking-news-track ul {
	display: inline-flex;
	list-style: none;
	margin: 0;
	padding: 0;
	white-space: nowrap;
	animation: sa-news-marquee 24s linear infinite;
}
.sa-breaking-news-track li {
	margin: 0;
	padding: 0;
}
.sa-breaking-news-track a {
	color: #ffffff;
	text-decoration: none;
	font-size: 15px;
	font-weight: 600;
	line-height: 40px;
	padding: 0 25px;
	display: inline-block;
	white-space: nowrap;
}
.sa-breaking-news-track a:hover {
	text-decoration: underline;
	color: #ffe9e9;
}
@keyframes sa-news-marquee {
	0%   { transform: translateX(0); }
	100% { transform: translateX(-50%); }
}
@media (prefers-reduced-motion: reduce) {
	.sa-breaking-news-track ul {
		animation: none;
	}
}
';
	echo '<style id="sa-breaking-news-css">' . $banner_css . '</style>' . "\n";
} );

add_action( 'wp_body_open', function () {
	if ( is_admin() || is_feed() ) {
		return;
	}

	$latest = get_posts( array(
		'numberposts'      => 1,
		'post_status'      => 'publish',
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => false,
	) );

	if ( empty( $latest ) ) {
		return;
	}

	$post = $latest[0];
	$url  = get_permalink( $post );
	$item = '<li class="sa-breaking-news-item"><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';

	echo '<div class="sa-breaking-news" role="region" aria-label="Breaking news">' . "\n";
	echo '  <span class="sa-breaking-news-label">BREAKING NEWS</span>' . "\n";
	echo '  <div class="sa-breaking-news-track">' . "\n";
	echo '    <ul>' . $item . $item . '</ul>' . "\n";
	echo '  </div>' . "\n";
	echo '</div>' . "\n";
} );