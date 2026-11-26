<?php
/**
 * Nettoyage hebdomadaire des copies de miniatures en double.
 *
 * Pourquoi : quand une grande photo de téléphone est envoyée, la création des tailles intermédiaires
 * (768 px…) peut dépasser le temps du serveur ; l'éditeur relance alors le traitement et WordPress
 * enregistre à chaque fois une nouvelle copie « nom-767x292-N.jpg ». Le 26/11/2026, 70 180 copies
 * inutilisées occupaient 3 Go (86 % du volume).
 *
 * Règle : ne supprime que les fichiers « …-LARGEURxHAUTEUR-N.ext » de plus de 2 jours dont le nom
 * n'apparaît nulle part dans la base (fichiers joints, métadonnées des tailles, contenus, métas, options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'sa_media_dedup_weekly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'sa_media_dedup_weekly' );
	}
} );

add_action( 'sa_media_dedup_weekly', 'sa_media_dedup_run' );

function sa_media_dedup_run() {
	global $wpdb;
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore
	}
	$pattern = '/-\d+x\d+-\d+\.(?:jpe?g|png|webp)$/i';
	$keep    = array();

	foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ) as $f ) {
		$keep[ basename( $f ) ] = true;
	}
	foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" ) as $m ) {
		$m = maybe_unserialize( $m );
		if ( ! is_array( $m ) ) {
			continue;
		}
		if ( ! empty( $m['file'] ) ) {
			$keep[ basename( $m['file'] ) ] = true;
		}
		if ( ! empty( $m['original_image'] ) ) {
			$keep[ $m['original_image'] ] = true;
		}
		if ( ! empty( $m['sizes'] ) && is_array( $m['sizes'] ) ) {
			foreach ( $m['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$keep[ $s['file'] ] = true;
				}
			}
		}
	}
	$texts = array_merge(
		$wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE '%/uploads/%'" ),
		$wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE '%/uploads/%'" ),
		$wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE '%/uploads/%'" )
	);
	foreach ( $texts as $t ) {
		if ( preg_match_all( '#[\w.-]+-\d+x\d+-\d+\.(?:jpe?g|png|webp)#i', (string) $t, $mm ) ) {
			foreach ( $mm[0] as $x ) {
				$keep[ $x ] = true;
			}
		}
	}

	$up = wp_get_upload_dir();
	if ( empty( $up['basedir'] ) || ! is_dir( $up['basedir'] ) ) {
		return;
	}
	$limit = time() - 2 * DAY_IN_SECONDS;
	$n     = 0;
	$bytes = 0;
	foreach ( (array) glob( $up['basedir'] . '/[12][0-9][0-9][0-9]', GLOB_ONLYDIR ) as $year_dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $year_dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$bn = $file->getFilename();
			if ( ! $file->isFile() || ! preg_match( $pattern, $bn ) || isset( $keep[ $bn ] ) || $file->getMTime() > $limit ) {
				continue;
			}
			$size = $file->getSize();
			if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore
				$n++;
				$bytes += $size;
			}
		}
	}
	update_option( 'sa_media_dedup_last', array( 'date' => current_time( 'mysql' ), 'fichiers' => $n, 'mo' => round( $bytes / 1048576 ) ), false );
}
