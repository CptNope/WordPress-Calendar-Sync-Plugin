<?php
/**
 * Background upkeep (hourly) and Site Health checks.
 *
 * Every hour, while Google Calendar is connected:
 * - Sync: upcoming bookings whose event was deleted in Google Calendar are cancelled here,
 *   and ones moved to a new time are updated, with an email to the visitor either way.
 * - Reminders: visitors get a reminder email N hours before (setting; 0 turns it off).
 * - Retention: booking records older than N days are deleted (setting; 0 keeps them).
 *
 * WP-Cron runs on site traffic. On hosts that disable it (DISABLE_WP_CRON), make sure a real
 * cron job calls wp-cron.php; Site Health warns when the hourly task stops running.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hourly tasks and health checks.
 */
final class AAB_Maintenance {

	const HOOK       = 'aab_hourly';
	const LAST_RUN   = 'aab_last_run';
	const BATCH_SIZE = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'health_tests' ) );
	}

	/**
	 * Make sure the hourly event exists.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the hourly event (deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run all tasks.
	 *
	 * @return array{synced:int,cancelled:int,moved:int,reminded:int,deleted:int}
	 */
	public static function run() {
		$stats = array(
			'synced'    => 0,
			'cancelled' => 0,
			'moved'     => 0,
			'reminded'  => 0,
			'deleted'   => 0,
		);
		if ( AAB_Google::is_connected() ) {
			$stats = array_merge( $stats, self::sync() );
			$stats['reminded'] = self::reminders();
		}
		$payments           = AAB_Payments::sweep();
		$stats['paid']      = $payments['confirmed'];
		$stats['released']  = $payments['expired'];
		$stats['deleted'] = self::retention();
		update_option( self::LAST_RUN, time(), false );
		return $stats;
	}

	/**
	 * Upcoming confirmed booking IDs, soonest first.
	 *
	 * @param int $until Only bookings starting before this time (0 = no limit).
	 * @return int[]
	 */
	private static function upcoming( $until = 0 ) {
		$meta = array(
			'relation' => 'AND',
			array(
				'key'   => '_aab_status',
				'value' => 'confirmed',
			),
			'start'    => array(
				'key'     => '_aab_start',
				'value'   => time(),
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
		);
		if ( $until ) {
			$meta[] = array(
				'key'     => '_aab_start',
				'value'   => $until,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		return get_posts(
			array(
				'post_type'      => AAB_Bookings::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'orderby'        => 'start',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Mirror changes made directly in Google Calendar.
	 *
	 * @return array{synced:int,cancelled:int,moved:int}
	 */
	public static function sync() {
		$out = array(
			'synced'    => 0,
			'cancelled' => 0,
			'moved'     => 0,
		);
		foreach ( self::upcoming() as $id ) {
			$b = AAB_Bookings::get( $id );
			if ( ! $b || '' === $b['event_id'] ) {
				continue;
			}
			$event = AAB_Google::get_event( $b['calendar_id'], $b['event_id'] );
			++$out['synced'];

			if ( is_wp_error( $event ) ) {
				$data = $event->get_error_data();
				$gone = is_array( $data ) && isset( $data['status'] ) && in_array( (int) $data['status'], array( 404, 410 ), true );
				if ( $gone && ! is_wp_error( AAB_Bookings::cancel( $id, 'calendar' ) ) ) {
					++$out['cancelled'];
				}
				continue; // Other errors: try again next hour.
			}

			if ( isset( $event['status'] ) && 'cancelled' === $event['status'] ) {
				if ( ! is_wp_error( AAB_Bookings::cancel( $id, 'calendar' ) ) ) {
					++$out['cancelled'];
				}
				continue;
			}

			if ( empty( $event['start']['dateTime'] ) || empty( $event['end']['dateTime'] ) ) {
				continue; // Turned into an all-day event; leave it alone.
			}
			$start = strtotime( $event['start']['dateTime'] );
			$end   = strtotime( $event['end']['dateTime'] );
			if ( $start && $end && $end > $start && ( $start !== $b['start'] || $end !== $b['end'] ) ) {
				update_post_meta( $id, '_aab_start', $start );
				update_post_meta( $id, '_aab_end', $end );
				update_post_meta( $id, '_aab_date', wp_date( 'Y-m-d', $start ) );
				delete_post_meta( $id, '_aab_reminded' );
				wp_update_post(
					array(
						'ID'         => $id,
						/* translators: 1: visitor name, 2: date and time */
						'post_title' => sprintf( __( '%1$s, %2$s', 'anderson-appointment-booking' ), $b['name'], AAB_Bookings::format_time( $start ) ),
					)
				);
				AAB_Availability::bust_cache();
				if ( $start !== $b['start'] ) {
					AAB_Bookings::email_rescheduled( AAB_Bookings::get( $id ), $b['start'], 'calendar' );
				}
				++$out['moved'];
			}
		}
		return $out;
	}

	/**
	 * Send reminder emails.
	 *
	 * A booking only gets a reminder if it was made before its reminder window opened,
	 * so someone booking for this afternoon doesn't get a "reminder" minutes later.
	 *
	 * @return int Reminders sent.
	 */
	public static function reminders() {
		$hours = (int) AAB_Settings::get( 'reminder_hours' );
		if ( $hours <= 0 ) {
			return 0;
		}
		$window = $hours * HOUR_IN_SECONDS;
		$sent   = 0;
		foreach ( self::upcoming( time() + $window ) as $id ) {
			if ( get_post_meta( $id, '_aab_reminded', true ) ) {
				continue;
			}
			update_post_meta( $id, '_aab_reminded', time() );
			$b       = AAB_Bookings::get( $id );
			$created = (int) get_post_time( 'U', true, $id );
			if ( $b && $created && $created < $b['start'] - $window ) {
				AAB_Bookings::email_reminder( $b );
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Delete old booking records.
	 *
	 * @return int Records deleted.
	 */
	public static function retention() {
		$days = (int) AAB_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => AAB_Bookings::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_aab_end',
						'value'   => time() - $days * DAY_IN_SECONDS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		return count( $ids );
	}

	/**
	 * Register Site Health tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function health_tests( $tests ) {
		$tests['direct']['aab_google']   = array(
			'label' => __( 'Appointment booking: Google Calendar connection', 'anderson-appointment-booking' ),
			'test'  => array( __CLASS__, 'test_google' ),
		);
		$tests['direct']['aab_outlook']  = array(
			'label' => __( 'Appointment booking: Outlook calendars', 'anderson-appointment-booking' ),
			'test'  => array( __CLASS__, 'test_outlook' ),
		);
		$tests['direct']['aab_cron']     = array(
			'label' => __( 'Appointment booking: background tasks', 'anderson-appointment-booking' ),
			'test'  => array( __CLASS__, 'test_cron' ),
		);
		$tests['direct']['aab_timezone'] = array(
			'label' => __( 'Appointment booking: timezone', 'anderson-appointment-booking' ),
			'test'  => array( __CLASS__, 'test_timezone' ),
		);
		return $tests;
	}

	/**
	 * Build a Site Health result.
	 *
	 * @param string $test        Test ID.
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Heading.
	 * @param string $description Plain-text explanation.
	 * @param string $action      Optional HTML action.
	 * @return array
	 */
	private static function result( $test, $status, $label, $description, $action = '' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Appointments', 'anderson-appointment-booking' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $action,
			'test'        => $test,
		);
	}

	/**
	 * Link to the settings page.
	 *
	 * @return string
	 */
	private static function settings_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=aab-settings' ) ) . '">' . esc_html__( 'Open appointment settings', 'anderson-appointment-booking' ) . '</a>';
	}

	/**
	 * Is Google connected and working?
	 *
	 * @return array
	 */
	public static function test_google() {
		if ( get_option( AAB_Google::ERROR_OPTION ) ) {
			return self::result( 'aab_google', 'critical', __( 'Online booking is offline: Google Calendar access expired or was revoked', 'anderson-appointment-booking' ), __( 'Visitors can’t book until you reconnect Google Calendar. If this keeps happening every week, publish your Google OAuth app to In production.', 'anderson-appointment-booking' ), self::settings_link() );
		}
		if ( ! AAB_Google::is_connected() ) {
			return self::result( 'aab_google', 'recommended', __( 'Google Calendar is not connected, so visitors can’t book', 'anderson-appointment-booking' ), __( 'Connect Google Calendar to turn on online booking.', 'anderson-appointment-booking' ), self::settings_link() );
		}
		return self::result( 'aab_google', 'good', __( 'Google Calendar is connected', 'anderson-appointment-booking' ), __( 'Availability and new bookings are going through Google Calendar.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Outlook connection health (only reported when Outlook is in use).
	 *
	 * @return array
	 */
	public static function test_outlook() {
		if ( get_option( AAB_Microsoft::ERROR_OPTION ) ) {
			return self::result( 'aab_outlook', 'critical', __( 'Online booking is offline: Outlook calendar access expired', 'anderson-appointment-booking' ), __( 'Your Outlook calendars are set to block times but can’t be read, so no times are offered. Reconnect Outlook, or disconnect it if you no longer use it.', 'anderson-appointment-booking' ), self::settings_link() );
		}
		if ( AAB_Microsoft::active() ) {
			return self::result( 'aab_outlook', 'good', __( 'Outlook calendars are connected', 'anderson-appointment-booking' ), __( 'Busy times in your selected Outlook calendars block bookings.', 'anderson-appointment-booking' ) );
		}
		return self::result( 'aab_outlook', 'good', __( 'Outlook calendars are not in use', 'anderson-appointment-booking' ), __( 'Only Google Calendar is checked for busy times.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Is the hourly task running?
	 *
	 * @return array
	 */
	public static function test_cron() {
		$last = (int) get_option( self::LAST_RUN );
		$late = AAB_Google::is_connected() && ( ! $last || time() - $last > 3 * HOUR_IN_SECONDS );
		// A fresh install hasn't had its first run yet.
		$installed = (int) get_option( 'aab_installed', time() );
		if ( $late && time() - $installed > 3 * HOUR_IN_SECONDS ) {
			return self::result( 'aab_cron', 'recommended', __( 'Booking reminders and Google Calendar sync aren’t running', 'anderson-appointment-booking' ), __( 'The hourly task that sends reminders and notices events you move or delete in Google Calendar hasn’t run in over three hours. If WP-Cron is disabled on this host, add a server cron job that calls wp-cron.php every few minutes.', 'anderson-appointment-booking' ) );
		}
		return self::result( 'aab_cron', 'good', __( 'Booking background tasks are running', 'anderson-appointment-booking' ), __( 'Reminders and Google Calendar sync run hourly.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Is the site timezone a city (needed for correct daylight-saving handling)?
	 *
	 * @return array
	 */
	public static function test_timezone() {
		if ( '' === (string) get_option( 'timezone_string' ) ) {
			return self::result(
				'aab_timezone',
				'recommended',
				__( 'Set your site timezone to a city for correct appointment times', 'anderson-appointment-booking' ),
				__( 'Your site uses a fixed UTC offset, which doesn’t change for daylight saving time, so your booking hours would shift by an hour twice a year. Choose a city (for example New York) under Settings → General → Timezone.', 'anderson-appointment-booking' ),
				'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Open general settings', 'anderson-appointment-booking' ) . '</a>'
			);
		}
		return self::result( 'aab_timezone', 'good', __( 'Site timezone is set to a city', 'anderson-appointment-booking' ), __( 'Booking hours follow daylight saving time correctly.', 'anderson-appointment-booking' ) );
	}
}
