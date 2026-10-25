<?php
/**
 * Paid appointments: hold the slot, take payment on Stripe Checkout, then confirm.
 *
 * 1. The visitor submits the form. The slot is locked and re-checked live, a booking is
 *    saved as "awaiting_payment" (it blocks the slot for about 35 minutes) and a Stripe
 *    Checkout Session is created. The visitor is sent to Stripe.
 * 2. The booking is confirmed (Google event, emails) when Stripe says it's paid, by
 *    whichever comes first: the webhook, the visitor returning to the site, or the hourly
 *    task asking Stripe. Each path re-reads the session from Stripe, so nothing is trusted
 *    from the browser, and confirming twice is harmless.
 * 3. Unpaid holds expire and free the slot. Cancelling a paid booking refunds it when the
 *    owner cancels, or when the visitor cancels before the refund cut-off.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment flow.
 */
final class AAB_Payments {

	const HOLD_MINUTES = 30;

	/**
	 * Shop currency (lowercase ISO).
	 *
	 * @return string
	 */
	public static function currency() {
		$c = strtolower( (string) AAB_Settings::get( 'currency' ) );
		return preg_match( '/^[a-z]{3}$/', $c ) ? $c : 'usd';
	}

	/**
	 * Price of a type in minor units (0 = free).
	 *
	 * @param array $type Type.
	 * @return int
	 */
	public static function price( array $type ) {
		return isset( $type['price'] ) ? max( 0, (int) $type['price'] ) : 0;
	}

	/**
	 * Does booking this type need payment?
	 *
	 * @param array $type Type.
	 * @return bool
	 */
	public static function is_paid( array $type ) {
		return self::price( $type ) > 0;
	}

	/**
	 * Can paid types be booked right now?
	 *
	 * @return bool
	 */
	public static function ready() {
		return AAB_Stripe::enabled();
	}

	/**
	 * The visitor's manage page URL for a booking (needs the plain token).
	 *
	 * @param int    $id    Booking ID.
	 * @param string $token Token.
	 * @return string
	 */
	private static function manage_url( $id, $token ) {
		return AAB_Bookings::cancel_url( $id, $token );
	}

	/**
	 * Hold the slot and create a Stripe Checkout Session. Called inside the slot lock.
	 *
	 * @param int   $start   Start.
	 * @param array $visitor Visitor.
	 * @param array $type    Type.
	 * @return array|WP_Error Booking plus 'checkout_url'.
	 */
	public static function start_checkout( $start, array $visitor, array $type ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'aab_payments_off', __( 'Online payment isn’t available right now, so this appointment can’t be booked online. Please get in touch another way.', 'anderson-appointment-booking' ), array( 'status' => 503 ) );
		}
		$amount   = self::price( $type );
		$currency = self::currency();
		$expires  = time() + self::HOLD_MINUTES * MINUTE_IN_SECONDS + 60;

		$post_id = AAB_Bookings::store(
			$start,
			$visitor,
			$type,
			'awaiting_payment',
			array(
				'_aab_amount'     => $amount,
				'_aab_currency'   => $currency,
				// A few minutes of grace beyond Stripe's expiry, so a last-second payment still finds its slot.
				'_aab_hold_until' => $expires + 5 * MINUTE_IN_SECONDS,
				// The page the visitor booked from, to send them back if they don't pay.
				'_aab_page'       => esc_url_raw( wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) ),
			)
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$booking = AAB_Bookings::get( $post_id );
		$manage  = AAB_Bookings::cancel_url_for( $booking );
		$session = AAB_Stripe::create_session(
			$booking,
			$amount,
			$currency,
			// Stripe fills in {CHECKOUT_SESSION_ID}; the braces must stay unencoded.
			$manage . '&aab_paid=1&session_id={CHECKOUT_SESSION_ID}',
			add_query_arg( 'aab_do', 'abandon', $manage ),
			$expires
		);
		if ( is_wp_error( $session ) || empty( $session['url'] ) || empty( $session['id'] ) ) {
			wp_delete_post( $post_id, true );
			AAB_Availability::bust_cache();
			return new WP_Error( 'aab_payments_failed', __( 'Payment couldn’t be started. Try again in a minute.', 'anderson-appointment-booking' ), array( 'status' => 502 ) );
		}
		update_post_meta( $post_id, '_aab_session_id', sanitize_text_field( $session['id'] ) );
		update_post_meta( $post_id, '_aab_checkout_url', esc_url_raw( $session['url'] ) );
		AAB_Availability::bust_cache();

		$booking                 = AAB_Bookings::get( $post_id );
		$booking['checkout_url'] = (string) $session['url'];
		return $booking;
	}

	/**
	 * Confirm a booking from a Stripe Checkout Session (webhook, return or hourly check).
	 *
	 * @param array $session Session as returned by Stripe.
	 * @return array|WP_Error|null Booking, an error, or null if the session isn't paid yet.
	 */
	public static function confirm_from_session( array $session ) {
		$id      = isset( $session['metadata']['aab_booking_id'] ) ? (int) $session['metadata']['aab_booking_id'] : 0;
		$booking = $id ? AAB_Bookings::get( $id ) : null;
		if ( ! $booking || (string) $booking['session'] !== (string) $session['id'] ) {
			return new WP_Error( 'aab_payment_unknown', 'Session does not match a booking' );
		}
		if ( 'confirmed' === $booking['status'] ) {
			return $booking;
		}
		if ( ! isset( $session['payment_status'] ) || 'paid' !== $session['payment_status'] ) {
			return null;
		}
		// Make sure what was paid is what was asked for.
		if ( (int) $session['amount_total'] !== $booking['amount'] || strtolower( (string) $session['currency'] ) !== $booking['currency'] ) {
			AAB_Google::log( 'Stripe payment', 'Amount mismatch for booking ' . $id );
			return new WP_Error( 'aab_payment_mismatch', 'Amount mismatch' );
		}

		if ( ! $booking['paid_at'] ) {
			update_post_meta( $id, '_aab_paid_at', time() );
			update_post_meta( $id, '_aab_payment_intent', sanitize_text_field( (string) ( isset( $session['payment_intent'] ) ? $session['payment_intent'] : '' ) ) );
			if ( 'cancelled' === $booking['status'] || 'expired' === $booking['status'] ) {
				// Paid after the hold lapsed (rare): refund rather than double-book.
				update_post_meta( $id, '_aab_status', 'expired' );
				$refund = AAB_Stripe::refund( (string) $session['payment_intent'], $id );
				if ( ! is_wp_error( $refund ) ) {
					update_post_meta( $id, '_aab_refunded_at', time() );
				}
				self::email_late_payment( AAB_Bookings::get( $id ), ! is_wp_error( $refund ) );
				return new WP_Error( 'aab_payment_late', 'Paid after the hold expired; refunded' );
			}
		}

		$confirmed = AAB_Bookings::finalize( $id );
		if ( is_wp_error( $confirmed ) && 'aab_busy' !== $confirmed->get_error_code() ) {
			// Paid, but Google is unreachable: the slot stays held and the hourly task retries.
			if ( ! get_post_meta( $id, '_aab_owner_alerted', true ) ) {
				update_post_meta( $id, '_aab_owner_alerted', time() );
				self::email_owner_retry( AAB_Bookings::get( $id ) );
			}
		}
		return $confirmed;
	}

	/**
	 * Ask Stripe about a booking's session and confirm it if paid.
	 *
	 * @param int $id Booking ID.
	 * @return array|WP_Error|null
	 */
	public static function check( $id ) {
		$booking = AAB_Bookings::get( $id );
		if ( ! $booking || '' === $booking['session'] ) {
			return null;
		}
		$session = AAB_Stripe::get_session( $booking['session'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return self::confirm_from_session( $session );
	}

	/**
	 * Release an unpaid hold.
	 *
	 * @param int    $id     Booking ID.
	 * @param string $status 'expired' or 'cancelled'.
	 */
	public static function release( $id, $status = 'expired' ) {
		$booking = AAB_Bookings::get( $id );
		if ( ! $booking || 'awaiting_payment' !== $booking['status'] || $booking['paid_at'] ) {
			return;
		}
		if ( '' !== $booking['session'] ) {
			AAB_Stripe::expire_session( $booking['session'] ); // Best effort; an already-expired session is fine.
		}
		update_post_meta( $id, '_aab_status', 'cancelled' === $status ? 'cancelled' : 'expired' );
		delete_post_meta( $id, '_aab_hold_until' );
		AAB_Availability::bust_cache();
	}

	/**
	 * Refund a paid booking that's being cancelled, per the refund policy.
	 *
	 * @param array  $booking Booking (before cancelling).
	 * @param string $by      'visitor', 'admin' or 'calendar'.
	 * @return string 'refunded', 'not_refunded', 'failed' or '' (nothing was paid).
	 */
	public static function refund_on_cancel( array $booking, $by ) {
		if ( ! $booking['paid_at'] || '' === $booking['payment'] || $booking['refunded'] ) {
			return '';
		}
		if ( 'visitor' === $by && ! self::visitor_gets_refund( $booking ) ) {
			return 'not_refunded';
		}
		$refund = AAB_Stripe::refund( $booking['payment'], $booking['id'] );
		if ( is_wp_error( $refund ) ) {
			update_post_meta( $booking['id'], '_aab_refund_error', sanitize_text_field( $refund->get_error_message() ) );
			self::email_owner_refund_failed( $booking, $refund->get_error_message() );
			return 'failed';
		}
		update_post_meta( $booking['id'], '_aab_refunded_at', time() );
		update_post_meta( $booking['id'], '_aab_refund_id', sanitize_text_field( (string) $refund['id'] ) );
		return 'refunded';
	}

	/**
	 * Would a visitor cancelling now get their money back?
	 *
	 * @param array $booking Booking.
	 * @return bool
	 */
	public static function visitor_gets_refund( array $booking ) {
		$hours = (int) AAB_Settings::get( 'refund_cutoff' );
		return $booking['start'] - $hours * HOUR_IN_SECONDS > time();
	}

	/**
	 * Hourly: confirm paid bookings whose confirmation didn't arrive, expire stale holds.
	 *
	 * @return array{confirmed:int,expired:int}
	 */
	public static function sweep() {
		$out = array(
			'confirmed' => 0,
			'expired'   => 0,
		);
		$ids = get_posts(
			array(
				'post_type'      => AAB_Bookings::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_aab_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'awaiting_payment', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			$b = AAB_Bookings::get( $id );
			if ( $b['paid_at'] ) {
				// Paid but Google was down: try again.
				if ( ! is_wp_error( AAB_Bookings::finalize( $id ) ) ) {
					++$out['confirmed'];
				}
				continue;
			}
			if ( ! AAB_Stripe::enabled() ) {
				if ( $b['hold_until'] < time() ) {
					self::release( $id );
					++$out['expired'];
				}
				continue;
			}
			$result = self::check( $id );
			if ( is_array( $result ) && 'confirmed' === $result['status'] ) {
				++$out['confirmed'];
			} elseif ( null === $result && $b['hold_until'] < time() ) {
				self::release( $id );
				++$out['expired'];
			}
		}
		return $out;
	}

	/**
	 * Handle a Stripe webhook (already signature-checked).
	 *
	 * @param array $event Event.
	 * @return string What happened (for the response body).
	 */
	public static function handle_event( array $event ) {
		$type    = isset( $event['type'] ) ? (string) $event['type'] : '';
		$session = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		if ( empty( $session['metadata']['aab_booking_id'] ) ) {
			return 'ignored';
		}
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$result = self::confirm_from_session( $session );
				return is_array( $result ) ? 'confirmed' : ( null === $result ? 'pending' : 'error' );
			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				self::release( (int) $session['metadata']['aab_booking_id'] );
				return 'released';
		}
		return 'ignored';
	}

	/**
	 * Tell the owner a paid booking is waiting for Google Calendar.
	 *
	 * @param array $booking Booking.
	 */
	private static function email_owner_retry( array $booking ) {
		$to = AAB_Bookings::notify_email();
		if ( '' === $to ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] Paid booking waiting for Google Calendar', 'anderson-appointment-booking' ), $site ),
			sprintf(
				/* translators: 1: visitor name, 2: date and time */
				__( "%1\$s paid for an appointment on %2\$s, but Google Calendar couldn't be reached to add it. The time stays reserved and the plugin retries every hour; the visitor gets their confirmation as soon as it goes through.\n\nIf this keeps happening, check the Google Calendar connection under Appointments → Settings.", 'anderson-appointment-booking' ),
				$booking['name'],
				AAB_Bookings::format_time( $booking['start'] )
			)
		);
	}

	/**
	 * Tell the owner a refund needs doing by hand.
	 *
	 * @param array  $booking Booking.
	 * @param string $error   Stripe error.
	 */
	private static function email_owner_refund_failed( array $booking, $error ) {
		$to = AAB_Bookings::notify_email();
		if ( '' === $to ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] Refund needed', 'anderson-appointment-booking' ), $site ),
			sprintf(
				/* translators: 1: visitor name, 2: email, 3: amount, 4: error */
				__( "The appointment for %1\$s (%2\$s) was cancelled, but the automatic refund of %3\$s failed: %4\$s\n\nPlease refund it in your Stripe dashboard.", 'anderson-appointment-booking' ),
				$booking['name'],
				$booking['email'],
				AAB_Stripe::format( $booking['amount'], $booking['currency'] ),
				$error
			)
		);
	}

	/**
	 * Tell the visitor a payment arrived after their hold lapsed.
	 *
	 * @param array $booking  Booking.
	 * @param bool  $refunded Refund succeeded.
	 */
	private static function email_late_payment( array $booking, $refunded ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$booking['email'],
			/* translators: %s: site name */
			sprintf( __( '[%s] Your payment arrived after the time was released', 'anderson-appointment-booking' ), $site ),
			$refunded
				? __( "Your payment came through after the reserved time had expired, so it wasn't booked and your payment has been refunded. Please book again.", 'anderson-appointment-booking' )
				: __( "Your payment came through after the reserved time had expired, so it wasn't booked. We'll refund you; reply to this email if you have questions.", 'anderson-appointment-booking' )
		);
		if ( ! $refunded ) {
			self::email_owner_refund_failed( $booking, 'late payment' );
		}
	}
}
