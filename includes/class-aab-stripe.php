<?php
/**
 * Minimal Stripe client: Checkout Sessions, refunds and webhook signature checks.
 *
 * Uses the WordPress HTTP API directly (no SDK). Card details never touch this site:
 * visitors pay on Stripe's hosted Checkout page.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Stripe.
 */
final class AAB_Stripe {

	const API = 'https://api.stripe.com/v1';

	/**
	 * Currencies Stripe charges in whole units (no cents).
	 *
	 * @return string[]
	 */
	public static function zero_decimal() {
		return array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
	}

	/**
	 * Decimal places for a currency.
	 *
	 * @param string $currency ISO code.
	 * @return int
	 */
	public static function decimals( $currency ) {
		return in_array( strtolower( $currency ), self::zero_decimal(), true ) ? 0 : 2;
	}

	/**
	 * Convert a typed amount ("49.50") to Stripe's smallest unit (4950).
	 *
	 * @param string $amount   Amount as typed.
	 * @param string $currency ISO code.
	 * @return int
	 */
	public static function to_minor( $amount, $currency ) {
		$amount = preg_replace( '/[^0-9.,]/', '', (string) $amount );
		// Accept "49,50" from comma-decimal locales when there's no dot.
		if ( false === strpos( $amount, '.' ) && preg_match( '/,\d{1,2}$/', $amount ) ) {
			$amount = str_replace( ',', '.', $amount );
		}
		$amount = str_replace( ',', '', $amount );
		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return 0;
		}
		return max( 0, (int) round( (float) $amount * ( 10 ** self::decimals( $currency ) ) ) );
	}

	/**
	 * Convert minor units back to a plain amount string ("49.50").
	 *
	 * @param int    $minor    Amount in smallest unit.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function from_minor( $minor, $currency ) {
		$d = self::decimals( $currency );
		return number_format( (int) $minor / ( 10 ** $d ), $d, '.', '' );
	}

	/**
	 * Human-readable price, e.g. "$49.50" or "49.50 EUR".
	 *
	 * @param int    $minor    Amount in smallest unit.
	 * @param string $currency ISO code.
	 * @return string
	 */
	public static function format( $minor, $currency ) {
		$symbols  = array(
			'usd' => '$',
			'cad' => 'CA$',
			'aud' => 'A$',
			'nzd' => 'NZ$',
			'gbp' => '£',
			'eur' => '€',
			'jpy' => '¥',
			'inr' => '₹',
		);
		$currency = strtolower( $currency );
		$d        = self::decimals( $currency );
		$number   = number_format_i18n( (int) $minor / ( 10 ** $d ), ( (int) $minor % ( 10 ** max( 1, $d ) ) ) ? $d : 0 );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $number : $number . ' ' . strtoupper( $currency );
	}

	/**
	 * Secret API key (wp-config.php constant wins).
	 *
	 * @return string
	 */
	public static function secret() {
		if ( defined( 'AAB_STRIPE_SECRET_KEY' ) ) {
			return (string) AAB_STRIPE_SECRET_KEY;
		}
		return AAB_Crypto::decrypt( (string) AAB_Settings::get( 'stripe_secret' ) );
	}

	/**
	 * Webhook signing secret.
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		if ( defined( 'AAB_STRIPE_WEBHOOK_SECRET' ) ) {
			return (string) AAB_STRIPE_WEBHOOK_SECRET;
		}
		return AAB_Crypto::decrypt( (string) AAB_Settings::get( 'stripe_webhook_secret' ) );
	}

	/**
	 * Are payments set up?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', self::secret() );
	}

	/**
	 * Test-mode key?
	 *
	 * @return bool
	 */
	public static function test_mode() {
		return 0 === strpos( self::secret(), 'sk_test_' ) || 0 === strpos( self::secret(), 'rk_test_' );
	}

	/**
	 * The URL to give Stripe for webhooks.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( AAB_Rest::NS . '/stripe' );
	}

	/**
	 * Flatten nested params into Stripe's form encoding: a[b][0][c]=v.
	 *
	 * @param array  $params Params.
	 * @param string $prefix Key prefix.
	 * @return array<string,string>
	 */
	private static function flatten( array $params, $prefix = '' ) {
		$out = array();
		foreach ( $params as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::flatten( $value, $name ) );
			} elseif ( null !== $value ) {
				$out[ $name ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Call the Stripe API. POSTs carry an idempotency key so a retry never double-charges or double-refunds.
	 *
	 * @param string $method          GET or POST.
	 * @param string $path            Path under /v1.
	 * @param array  $params          Params.
	 * @param string $idempotency_key Key for POSTs.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, array $params = array(), $idempotency_key = '' ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'aab_stripe_off', __( 'Stripe is not set up.', 'anderson-appointment-booking' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Stripe-Version' => '2024-06-20',
			),
		);
		$url  = self::API . $path;
		if ( 'GET' === $method ) {
			$url = add_query_arg( rawurlencode_deep( self::flatten( $params ) ), $url );
		} else {
			$args['body'] = self::flatten( $params );
			if ( '' !== $idempotency_key ) {
				$args['headers']['Idempotency-Key'] = $idempotency_key;
			}
		}

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			// Safe to retry: GETs are read-only and POSTs carry an idempotency key.
			if ( ( is_wp_error( $response ) || 429 === $code || $code >= 500 ) && 1 === $attempt && ( 'GET' === $method || '' !== $idempotency_key ) ) {
				usleep( 500000 );
				continue;
			}
			break;
		}
		if ( is_wp_error( $response ) ) {
			AAB_Google::log( 'Stripe ' . $method . ' ' . $path, $response->get_error_message() );
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $code;
			AAB_Google::log( 'Stripe ' . $method . ' ' . preg_replace( '#/(cs|pi|re)_[A-Za-z0-9_]+#', '/$1_…', $path ), $code . ' ' . $message );
			return new WP_Error( 'aab_stripe_http', $message, array( 'status' => $code ) );
		}
		return $body;
	}

	/**
	 * Create a Checkout Session for a booking.
	 *
	 * @param array  $booking  Booking.
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency ISO code.
	 * @param string $success  Return URL after paying ({CHECKOUT_SESSION_ID} is filled in by Stripe).
	 * @param string $cancel   Return URL when the visitor backs out.
	 * @param int    $expires  Unix time the session (and the held slot) expires.
	 * @return array|WP_Error Session.
	 */
	public static function create_session( array $booking, $amount, $currency, $success, $cancel, $expires ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return self::request(
			'POST',
			'/checkout/sessions',
			array(
				'mode'                => 'payment',
				'customer_email'      => $booking['email'],
				'client_reference_id' => (string) $booking['id'],
				'success_url'         => $success,
				'cancel_url'          => $cancel,
				'expires_at'          => (int) $expires,
				'line_items'          => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => strtolower( $currency ),
							'unit_amount'  => (int) $amount,
							'product_data' => array(
								'name'        => '' !== $booking['type_name'] ? $booking['type_name'] : __( 'Appointment', 'anderson-appointment-booking' ),
								/* translators: 1: date and time, 2: site name */
								'description' => sprintf( __( '%1$s with %2$s', 'anderson-appointment-booking' ), AAB_Bookings::format_time( $booking['start'] ), $site ),
							),
						),
					),
				),
				'metadata'            => array( 'aab_booking_id' => (string) $booking['id'] ),
				'payment_intent_data' => array(
					'metadata'    => array( 'aab_booking_id' => (string) $booking['id'] ),
					/* translators: %s: site name */
					'description' => sprintf( __( 'Appointment booking, %s', 'anderson-appointment-booking' ), $site ),
				),
			),
			'aab-session-' . $booking['id'] . '-' . get_post_meta( $booking['id'], '_aab_hold_until', true )
		);
	}

	/**
	 * Fetch a Checkout Session.
	 *
	 * @param string $id Session ID.
	 * @return array|WP_Error
	 */
	public static function get_session( $id ) {
		if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', (string) $id ) ) {
			return new WP_Error( 'aab_stripe_bad_id', 'Invalid session' );
		}
		return self::request( 'GET', '/checkout/sessions/' . $id );
	}

	/**
	 * Expire an open Checkout Session so it can no longer be paid.
	 *
	 * @param string $id Session ID.
	 * @return array|WP_Error
	 */
	public static function expire_session( $id ) {
		return self::request( 'POST', '/checkout/sessions/' . $id . '/expire', array(), 'aab-expire-' . $id );
	}

	/**
	 * Refund a payment in full.
	 *
	 * @param string $payment_intent Payment intent ID.
	 * @param int    $booking_id     Booking ID (for metadata and idempotency).
	 * @return array|WP_Error Refund.
	 */
	public static function refund( $payment_intent, $booking_id ) {
		return self::request(
			'POST',
			'/refunds',
			array(
				'payment_intent' => $payment_intent,
				'metadata'       => array( 'aab_booking_id' => (string) $booking_id ),
			),
			'aab-refund-' . $booking_id
		);
	}

	/**
	 * Check a webhook's Stripe-Signature header.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $header    Stripe-Signature header.
	 * @param int    $tolerance Seconds of clock skew allowed.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $tolerance = 300 ) {
		$secret = self::webhook_secret();
		if ( '' === $secret || '' === (string) $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
