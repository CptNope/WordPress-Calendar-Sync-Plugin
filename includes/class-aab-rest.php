<?php
/**
 * Public REST endpoints used by the booking widget.
 *
 * GET  /wp-json/aab/v1/availability?from=Y-m-d&to=Y-m-d
 * POST /wp-json/aab/v1/bookings
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class AAB_Rest {

	const NS = 'aab/v1';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'start'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'name'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'notes'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'consent' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'website' => array(
						'type'    => 'string',
						'default' => '',
					),
					'elapsed' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Unavailable error shared by both endpoints.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error( 'aab_unavailable', __( 'Online booking is unavailable right now. Please get in touch another way.', 'anderson-appointment-booking' ), array( 'status' => 503 ) );
	}

	/**
	 * Open slots for a date range.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function availability( WP_REST_Request $request ) {
		$from = (string) $request['from'];
		$to   = (string) $request['to'];
		if ( ! AAB_Availability::is_valid_date( $from ) || ! AAB_Availability::is_valid_date( $to ) || $to < $from ) {
			return new WP_Error( 'aab_bad_range', __( 'Invalid date range.', 'anderson-appointment-booking' ), array( 'status' => 400 ) );
		}
		$span = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		if ( $span > 42 ) {
			return new WP_Error( 'aab_bad_range', __( 'Ask for at most six weeks at a time.', 'anderson-appointment-booking' ), array( 'status' => 400 ) );
		}
		if ( self::rate_limited( 'avail', 300, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'aab_rate', __( 'Too many requests. Wait a few minutes and try again.', 'anderson-appointment-booking' ), array( 'status' => 429 ) );
		}
		if ( ! AAB_Google::is_connected() ) {
			return self::unavailable();
		}

		$slots = AAB_Availability::slots( $from, $to );
		if ( is_wp_error( $slots ) ) {
			return self::unavailable();
		}

		list( $first, $last ) = AAB_Availability::window();
		$response             = rest_ensure_response(
			array(
				'timezone' => wp_timezone_string(),
				'duration' => (int) AAB_Settings::get( 'duration' ),
				'first'    => $first,
				'last'     => $last,
				'days'     => (object) $slots,
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Create a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function book( WP_REST_Request $request ) {
		// Honeypot field and a minimum time on the form stop most bots without a CAPTCHA.
		// The widget waits until 3 seconds have passed before sending, so a real visitor never sees this.
		if ( '' !== trim( (string) $request['website'] ) || (int) $request['elapsed'] < 3 ) {
			return new WP_Error( 'aab_spam', __( 'The booking could not be submitted. Reload the page and try again.', 'anderson-appointment-booking' ), array( 'status' => 400 ) );
		}
		if ( self::rate_limited( 'book', 5, HOUR_IN_SECONDS ) || self::rate_limited( 'try', 20, HOUR_IN_SECONDS, true ) ) {
			return new WP_Error( 'aab_rate', __( 'Too many bookings from your connection. Try again later or get in touch directly.', 'anderson-appointment-booking' ), array( 'status' => 429 ) );
		}

		$name  = sanitize_text_field( (string) $request['name'] );
		$email = sanitize_email( (string) $request['email'] );
		$notes = sanitize_textarea_field( (string) $request['notes'] );
		$start = (int) $request['start'];

		$errors = array();
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			$errors['name'] = __( 'Enter your name.', 'anderson-appointment-booking' );
		}
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'anderson-appointment-booking' );
		}
		if ( mb_strlen( $notes ) > 2000 ) {
			$errors['notes'] = __( 'Keep notes under 2,000 characters.', 'anderson-appointment-booking' );
		}
		if ( '' !== trim( (string) AAB_Settings::get( 'consent_text' ) ) && ! $request['consent'] ) {
			$errors['consent'] = __( 'Check the box to continue.', 'anderson-appointment-booking' );
		}
		if ( $errors ) {
			return new WP_Error( 'aab_invalid', __( 'Check the highlighted fields.', 'anderson-appointment-booking' ), array( 'status' => 400, 'fields' => $errors ) );
		}
		if ( ! AAB_Google::is_connected() ) {
			return self::unavailable();
		}

		$booking = AAB_Bookings::create( $start, $name, $email, $notes );
		if ( is_wp_error( $booking ) ) {
			$data   = $booking->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			if ( 'aab_slot_taken' === $booking->get_error_code() ) {
				return $booking;
			}
			if ( 'aab_google_failed' === $booking->get_error_code() ) {
				return new WP_Error( $booking->get_error_code(), $booking->get_error_message(), array( 'status' => $status ) );
			}
			return self::unavailable();
		}

		self::rate_limited( 'book', 5, HOUR_IN_SECONDS, true );

		return rest_ensure_response(
			array(
				'start'   => $booking['start'],
				'end'     => $booking['end'],
				'meet'    => $booking['meet_link'],
				'message' => (string) AAB_Settings::get( 'confirmation_message' ),
			)
		);
	}

	/**
	 * Simple per-IP rate limiter.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Max hits per window.
	 * @param int    $window Window in seconds.
	 * @param bool   $hit    Record a hit (otherwise only checks, except for read buckets which always count).
	 * @return bool True when over the limit.
	 */
	private static function rate_limited( $bucket, $limit, $window, $hit = false ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the client IP used for rate limiting (for sites behind a trusted proxy).
		 *
		 * @param string $ip Client IP.
		 */
		$ip    = (string) apply_filters( 'aab_client_ip', $ip );
		$key   = 'aab_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		if ( $hit || 'avail' === $bucket ) {
			set_transient( $key, $count + 1, $window );
		}
		return false;
	}
}
