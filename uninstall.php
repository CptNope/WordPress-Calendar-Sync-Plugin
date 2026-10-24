<?php
/**
 * Uninstall: always revoke Google access; delete data only if the owner opted in.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aab_settings = get_option( 'aab_settings', array() );

// Revoke the Google grant so no token outlives the plugin.
require_once __DIR__ . '/includes/class-aab-crypto.php';
$aab_google = get_option( 'aab_google', array() );
if ( ! empty( $aab_google['refresh_token'] ) ) {
	$aab_token = AAB_Crypto::decrypt( $aab_google['refresh_token'] );
	if ( '' !== $aab_token ) {
		wp_remote_post(
			'https://oauth2.googleapis.com/revoke',
			array(
				'timeout' => 10,
				'body'    => array( 'token' => $aab_token ),
			)
		);
	}
}
delete_option( 'aab_google' );
delete_option( 'aab_google_error' );
delete_option( 'aab_busy_generation' );
delete_option( 'aab_log' );
delete_option( 'aab_last_run' );
delete_option( 'aab_installed' );
wp_clear_scheduled_hook( 'aab_hourly' );
delete_transient( 'aab_access_token' );
delete_transient( 'aab_calendars' );

if ( empty( $aab_settings['delete_data'] ) ) {
	return;
}

delete_option( 'aab_settings' );

$aab_ids = get_posts(
	array(
		'post_type'      => 'aab_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $aab_ids as $aab_id ) {
	wp_delete_post( $aab_id, true );
}
