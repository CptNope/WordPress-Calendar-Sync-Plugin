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
		self::reschedule_route();
		register_rest_route(
			self::NS,
			'/stripe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stripe_webhook' ),
				// Authenticated by Stripe's signature, checked in the callback.
				'permission_callback' => '__return_true',
			)
		);
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
					'to'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'type'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'booking' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'token'   => array(
						'type'    => 'string',
						'default' => '',
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
					'phone'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'type'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'answers' => array(
						'type'    => 'object',
						'default' => array(),
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
	 * Register the reschedule route (called from routes()).
	 */
	private static function reschedule_route() {
		register_rest_route(
			self::NS,
			'/bookings/(?P<id>\d+)/reschedule',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reschedule' ),
				// The emailed token authenticates the visitor; it's checked in the callback.
				'permission_callback' => '__return_true',
				'args'                => array(
					'start' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The appointment type a request asks for (an active one, or the first active one).
	 *
	 * @param string $id Requested type ID.
	 * @return array|WP_Error
	 */
	private static function requested_type( $id ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id ) {
			$type = AAB_Types::first();
		} else {
			$type = AAB_Types::get( $id );
			$type = $type && $type['active'] && ( ! AAB_Payments::is_paid( $type ) || AAB_Payments::ready() ) ? $type : null;
		}
		return $type ? $type : new WP_Error( 'aab_bad_type', __( 'That appointment type isn’t available. Reload the page and pick another.', 'anderson-appointment-booking' ), array( 'status' => 404 ) );
	}

	/**
	 * A booking the request may change, checked against its emailed token.
	 *
	 * @param int    $id    Booking ID.
	 * @param string $token Token.
	 * @return array|WP_Error
	 */
	private static function owned_booking( $id, $token ) {
		$booking = AAB_Bookings::get( (int) $id );
		if ( ! $booking || ! AAB_Bookings::verify_token( (int) $id, (string) $token ) ) {
			return new WP_Error( 'aab_bad_link', __( 'This link is no longer valid. Reply to your confirmation email and we’ll sort it out.', 'anderson-appointment-booking' ), array( 'status' => 403 ) );
		}
		return $booking;
	}

	/**
	 * Stripe webhook: confirms paid bookings and releases expired holds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stripe_webhook( WP_REST_Request $request ) {
		$payload = (string) $request->get_body();
		if ( ! AAB_Stripe::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ) ) ) {
			return new WP_Error( 'aab_bad_signature', 'Invalid signature', array( 'status' => 400 ) );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) ) {
			return new WP_Error( 'aab_bad_payload', 'Invalid payload', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'result' => AAB_Payments::handle_event( $event ) ) );
	}

	/**
	 * Move a booking to a new time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reschedule( WP_REST_Request $request ) {
		if ( self::rate_limited( 'move', 10, HOUR_IN_SECONDS, true ) ) {
			return new WP_Error( 'aab_rate', __( 'Too many attempts. Wait a few minutes and try again.', 'anderson-appointment-booking' ), array( 'status' => 429 ) );
		}
		$booking = self::owned_booking( (int) $request['id'], (string) $request['token'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( ! AAB_Google::is_connected() ) {
			return self::unavailable();
		}
		$moved = AAB_Bookings::reschedule( $booking['id'], (int) $request['start'] );
		if ( is_wp_error( $moved ) ) {
			$code = $moved->get_error_code();
			if ( in_array( $code, array( 'aab_slot_taken', 'aab_google_failed', 'aab_too_late', 'aab_same_time', 'aab_not_confirmed', 'aab_too_many_moves' ), true ) ) {
				return $moved;
			}
			return self::unavailable();
		}
		return rest_ensure_response(
			array(
				'start' => $moved['start'],
				'end'   => $moved['end'],
				'meet'  => $moved['meet_link'],
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

		$exclude = null;
		if ( (int) $request['booking'] > 0 ) {
			// Rescheduling: show times as if this booking weren't there, using its own type.
			$exclude = self::owned_booking( (int) $request['booking'], (string) $request['token'] );
			if ( is_wp_error( $exclude ) ) {
				return $exclude;
			}
			$type = AAB_Bookings::type_of( $exclude );
		} else {
			$type = self::requested_type( (string) $request['type'] );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
		}

		$slots = AAB_Availability::slots( $from, $to, false, $type, $exclude );
		if ( is_wp_error( $slots ) ) {
			return self::unavailable();
		}

		list( $first, $last ) = AAB_Availability::window();
		$response             = rest_ensure_response(
			array(
				'timezone' => wp_timezone_string(),
				'type'     => $type['id'],
				'duration' => (int) $type['duration'],
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
		$phone = sanitize_text_field( (string) $request['phone'] );
		$start = (int) $request['start'];
		$ask   = (string) AAB_Settings::get( 'phone_field' );
		$type  = self::requested_type( (string) $request['type'] );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		if ( empty( $type['notes'] ) ) {
			$notes = '';
		}
		$checked = AAB_Types::check_answers( $type, $request['answers'] );

		$errors = array();
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			$errors['name'] = __( 'Enter your name.', 'anderson-appointment-booking' );
		}
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'anderson-appointment-booking' );
		}
		if ( 'off' === $ask ) {
			$phone = '';
		} elseif ( '' === $phone && 'required' === $ask ) {
			$errors['phone'] = __( 'Enter your phone number.', 'anderson-appointment-booking' );
		} elseif ( '' !== $phone && ! preg_match( '/^\+?[0-9\s().\-\/]{6,25}(\s*(x|ext\.?)\s*[0-9]{1,6})?$/i', $phone ) ) {
			$errors['phone'] = __( 'Enter a valid phone number.', 'anderson-appointment-booking' );
		}
		if ( mb_strlen( $notes ) > 2000 ) {
			$errors['notes'] = __( 'Keep notes under 2,000 characters.', 'anderson-appointment-booking' );
		}
		if ( '' !== trim( (string) AAB_Settings::get( 'consent_text' ) ) && ! $request['consent'] ) {
			$errors['consent'] = __( 'Check the box to continue.', 'anderson-appointment-booking' );
		}
		$errors = array_merge( $errors, $checked['errors'] );
		if ( $errors ) {
			return new WP_Error( 'aab_invalid', __( 'Check the highlighted fields.', 'anderson-appointment-booking' ), array( 'status' => 400, 'fields' => $errors ) );
		}
		if ( ! AAB_Google::is_connected() ) {
			return self::unavailable();
		}

		$max = (int) AAB_Settings::get( 'max_per_email' );
		if ( $max > 0 && AAB_Bookings::upcoming_count_for_email( $email ) >= $max ) {
			return new WP_Error(
				'aab_too_many',
				sprintf(
					/* translators: %d: number of upcoming appointments allowed */
					_n( 'You already have %d upcoming appointment. Cancel it from your confirmation email or reply to it to change it.', 'You already have %d upcoming appointments. Cancel one from its confirmation email or reply to it to make a change.', $max, 'anderson-appointment-booking' ),
					$max
				),
				array( 'status' => 403 )
			);
		}

		$booking = AAB_Bookings::create(
			$start,
			array(
				'name'    => $name,
				'email'   => $email,
				'notes'   => $notes,
				'phone'   => $phone,
				'answers' => $checked['answers'],
			),
			$type
		);
		if ( is_wp_error( $booking ) ) {
			$data   = $booking->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			if ( 'aab_slot_taken' === $booking->get_error_code() ) {
				return $booking;
			}
			if ( in_array( $booking->get_error_code(), array( 'aab_payments_failed', 'aab_payments_off' ), true ) ) {
				return $booking;
			}
			if ( 'aab_google_failed' === $booking->get_error_code() ) {
				return new WP_Error( $booking->get_error_code(), $booking->get_error_message(), array( 'status' => $status ) );
			}
			return self::unavailable();
		}

		self::rate_limited( 'book', 5, HOUR_IN_SECONDS, true );

		if ( ! empty( $booking['checkout_url'] ) ) {
			return rest_ensure_response(
				array(
					'checkout' => $booking['checkout_url'],
					'start'    => $booking['start'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'start'   => $booking['start'],
				'end'     => $booking['end'],
				'meet'    => $booking['meet_link'],
				'type'    => $booking['type'],
				'message' => (string) AAB_Settings::get( 'confirmation_message' ),
			)
		);
	}

	/**
	 * The visitor's IP address.
	 *
	 * Many hosts put a cache or proxy in front of PHP (Cloudways' Varnish, nginx, Cloudflare),
	 * so REMOTE_ADDR is the proxy's own private address and every visitor would share one
	 * rate-limit bucket. Forwarding headers are only trusted when the connection itself
	 * comes from a private or loopback address, i.e. from such a proxy, never from the internet.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		$public = static function ( $candidate ) {
			return (bool) filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		};

		if ( '' !== $remote && filter_var( $remote, FILTER_VALIDATE_IP ) && ! $public( $remote ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$found = '';
				// X-Forwarded-For is "client, proxy1, proxy2": take the first public address.
				foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) as $candidate ) {
					$candidate = trim( $candidate );
					if ( $public( $candidate ) ) {
						$found = $candidate;
						break;
					}
				}
				if ( '' !== $found ) {
					$ip = $found;
					break;
				}
			}
		}

		/**
		 * Filter the client IP used for rate limiting.
		 *
		 * @param string $ip     Detected client IP.
		 * @param string $remote The raw REMOTE_ADDR.
		 */
		return (string) apply_filters( 'aab_client_ip', $ip, $remote );
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
		$ip    = self::client_ip();
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
