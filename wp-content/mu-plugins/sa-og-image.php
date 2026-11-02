<?php
/**
 * Plugin Name: SA OG Image 1200x630 (mu-plugin)
 * Description: Genera automaticamente una imagen 1200x630 para Facebook/X a partir de la imagen destacada (sin cortar cabezas: la foto entera centrada sobre fondo difuminado).
 * Version: 1.0.0
 * Author: Souss Actualites
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function souss_og_build( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) || ! function_exists( 'imagecreatetruecolor' ) ) { return false; }
	$up   = wp_upload_dir();
	$dir  = trailingslashit( $up['basedir'] ) . 'og-1200';
	$url  = trailingslashit( $up['baseurl'] ) . 'og-1200';
	$name = $attachment_id . '-' . filemtime( $file ) . '.jpg';
	if ( file_exists( "$dir/$name" ) ) { return "$url/$name"; }
	if ( ! wp_mkdir_p( $dir ) ) { return false; }

	$data = @file_get_contents( $file );
	$src  = $data ? @imagecreatefromstring( $data ) : false;
	if ( ! $src ) { return false; }
	$sw = imagesx( $src ); $sh = imagesy( $src );
	$W = 1200; $H = 630;
	$dst = imagecreatetruecolor( $W, $H );

	// Fondo: misma foto en modo "cover", reducida, difuminada y oscurecida.
	$scale = max( $W / $sw, $H / $sh );
	$bw = (int) ceil( $sw * $scale ); $bh = (int) ceil( $sh * $scale );
	$small = imagecreatetruecolor( 120, 63 );
	imagecopyresampled( $small, $src, 0, 0, (int)( ( $bw - $W ) / 2 / $scale ), (int)( ( $bh - $H ) / 2 / $scale ), 120, 63, (int)( $W / $scale ), (int)( $H / $scale ) );
	for ( $i = 0; $i < 6; $i++ ) { imagefilter( $small, IMG_FILTER_GAUSSIAN_BLUR ); }
	imagefilter( $small, IMG_FILTER_BRIGHTNESS, -60 );
	imagecopyresampled( $dst, $small, 0, 0, 0, 0, $W, $H, 120, 63 );
	imagedestroy( $small );

	// Delante: la foto entera, sin recortar, centrada.
	$fit = min( $W / $sw, $H / $sh );
	$fw = (int) round( $sw * $fit ); $fh = (int) round( $sh * $fit );
	imagecopyresampled( $dst, $src, (int)( ( $W - $fw ) / 2 ), (int)( ( $H - $fh ) / 2 ), 0, 0, $fw, $fh, $sw, $sh );

	$ok = imagejpeg( $dst, "$dir/$name", 88 );
	imagedestroy( $dst ); imagedestroy( $src );
	return $ok ? "$url/$name" : false;
}

function souss_og_current() {
	static $cache = null;
	if ( null !== $cache ) { return $cache; }
	$cache = false;
	if ( ! is_singular() ) { return $cache; }
	$id = get_post_thumbnail_id( get_queried_object_id() );
	if ( ! $id ) { return $cache; }
	$meta = wp_get_attachment_metadata( $id );
	if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
		$r = $meta['width'] / $meta['height'];
		if ( $r > 1.75 && $r < 2.05 && $meta['width'] >= 1000 ) { return $cache; } // ya es formato Facebook
	}
	$cache = souss_og_build( $id );
	return $cache;
}

add_filter( 'rank_math/opengraph/facebook/og_image', function ( $v ) { $u = souss_og_current(); return $u ? $u : $v; }, 99 );
add_filter( 'rank_math/opengraph/facebook/og_image_secure_url', function ( $v ) { $u = souss_og_current(); return $u ? $u : $v; }, 99 );
add_filter( 'rank_math/opengraph/facebook/og_image_width', function ( $v ) { return souss_og_current() ? 1200 : $v; }, 99 );
add_filter( 'rank_math/opengraph/facebook/og_image_height', function ( $v ) { return souss_og_current() ? 630 : $v; }, 99 );
add_filter( 'rank_math/opengraph/facebook/og_image_type', function ( $v ) { return souss_og_current() ? 'image/jpeg' : $v; }, 99 );
add_filter( 'rank_math/opengraph/twitter/twitter_image', function ( $v ) { $u = souss_og_current(); return $u ? $u : $v; }, 99 );
