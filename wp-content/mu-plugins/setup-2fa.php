<?php
/**
 * Plugin Name: Setup 2FA (emergency)
 * Description: Clears all Wordfence Login Security 2FA secrets and forces 2FA to be optional so admin can log in and set up fresh 2FA.
 */

function emergency_reset_2fa() {
	global $wpdb;

	// Delete all 2FA secrets from usermeta (wfls-* keys).
	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wfls-%'"
	);

	// Delete Wordfence Login Security global settings.
	delete_option( 'wfls-settings' );

	// Set fresh settings that make 2FA optional, allow trusted devices, and enable a 30-day grace period.
	update_option( 'wfls-settings', array(
		'require-2fa'                  => false,
		'enable-2fa'                   => true,
		'grace-period-enabled'         => true,
		'grace-period-days'            => 30,
		'allow-remember-device'        => true,
		'remember-device-duration'     => 30,
	) );

	error_log( 'Emergency 2FA reset ran: cleared wfls-* usermeta and reset wfls-settings to optional 2FA with 30-day grace period.' );
}
add_action( 'init', 'emergency_reset_2fa' );
