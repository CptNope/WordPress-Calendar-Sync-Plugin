<?php
/**
 * Bookings: storage, creation, cancellation and emails.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Booking records live in a private post type so they appear in wp-admin and back up with the site.
 */
final class AAB_Bookings {

	const POST_TYPE = 'aab_booking';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register the private post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Bookings', 'anderson-appointment-booking' ),
					'singular_name' => __( 'Booking', 'anderson-appointment-booking' ),
					'menu_name'     => __( 'Bookings', 'anderson-appointment-booking' ),
					'all_items'     => __( 'Bookings', 'anderson-appointment-booking' ),
					'edit_item'     => __( 'Booking', 'anderson-appointment-booking' ),
					'search_items'  => __( 'Search bookings', 'anderson-appointment-booking' ),
					'not_found'     => __( 'No bookings yet.', 'anderson-appointment-booking' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'aab-settings',
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts'       => 'do_not_allow',
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'delete_posts'       => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'map_meta_cap'    => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Booking data as an array.
	 *
	 * @param int|WP_Post $post Booking.
	 * @return array|null
	 */
	public static function get( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$m = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, '_aab_' . $key, true );
		};
		return array(
			'id'          => $post->ID,
			'start'       => (int) $m( 'start' ),
			'end'         => (int) $m( 'end' ),
			'date'        => (string) $m( 'date' ),
			'name'        => (string) $m( 'name' ),
			'email'       => (string) $m( 'email' ),
			'notes'       => (string) $m( 'notes' ),
			'phone'       => (string) $m( 'phone' ),
			'status'      => (string) $m( 'status' ),
			'event_id'    => (string) $m( 'event_id' ),
			'calendar_id' => (string) $m( 'calendar_id' ),
			'meet_link'   => (string) $m( 'meet_link' ),
			'event_link'  => (string) $m( 'event_link' ),
			'type'        => (string) $m( 'type' ),
			'type_name'   => (string) $m( 'type_name' ),
			'answers'     => is_array( $m( 'answers' ) ) ? $m( 'answers' ) : array(),
			// Bookings made before 1.3 have no stored location: fall back to the global one.
			'location'    => metadata_exists( 'post', $post->ID, '_aab_location' ) ? (string) $m( 'location' ) : trim( (string) AAB_Settings::get( 'location' ) ),
			'reschedules' => (int) $m( 'reschedules' ),
			'amount'      => (int) $m( 'amount' ),
			'currency'    => (string) $m( 'currency' ),
			'paid_at'     => (int) $m( 'paid_at' ),
			'payment'     => (string) $m( 'payment_intent' ),
			'refunded'    => (int) $m( 'refunded_at' ),
			'refund_error' => (string) $m( 'refund_error' ),
			'hold_until'  => (int) $m( 'hold_until' ),
			'session'     => (string) $m( 'session_id' ),
			'checkout'    => (string) $m( 'checkout_url' ),
		);
	}

	/**
	 * Does this booking hold its slot right now? Confirmed bookings always do; unpaid ones
	 * only while their payment window is open (or once paid, while being confirmed).
	 *
	 * @param int $id Booking ID.
	 * @return bool
	 */
	public static function holds_slot( $id ) {
		$status = (string) get_post_meta( $id, '_aab_status', true );
		if ( 'confirmed' === $status ) {
			return true;
		}
		if ( 'awaiting_payment' === $status ) {
			return (int) get_post_meta( $id, '_aab_paid_at', true ) > 0 || (int) get_post_meta( $id, '_aab_hold_until', true ) > time();
		}
		return false;
	}

	/**
	 * The appointment type a booking was made with (or a stand-in if that type was deleted).
	 *
	 * @param array $booking Booking.
	 * @return array
	 */
	public static function type_of( array $booking ) {
		$type = '' !== $booking['type'] ? AAB_Types::get( $booking['type'] ) : null;
		if ( ! $type ) {
			$type = AAB_Types::fallback( (int) round( ( $booking['end'] - $booking['start'] ) / MINUTE_IN_SECONDS ), $booking['type_name'] );
		}
		return $type;
	}

	/**
	 * Can the visitor still change (reschedule or cancel) this booking online?
	 *
	 * @param array $booking Booking.
	 * @return true|WP_Error
	 */
	public static function can_change( array $booking ) {
		if ( 'confirmed' !== $booking['status'] ) {
			return new WP_Error( 'aab_not_confirmed', __( 'This appointment is already cancelled.', 'anderson-appointment-booking' ), array( 'status' => 409 ) );
		}
		$cutoff = (int) AAB_Settings::get( 'change_cutoff' ) * HOUR_IN_SECONDS;
		if ( $booking['start'] - $cutoff <= time() ) {
			$message = $cutoff
				/* translators: %d: hours */
				? sprintf( _n( 'Appointments can only be changed online up to %d hour before they start. Reply to your confirmation email instead.', 'Appointments can only be changed online up to %d hours before they start. Reply to your confirmation email instead.', (int) AAB_Settings::get( 'change_cutoff' ), 'anderson-appointment-booking' ), (int) AAB_Settings::get( 'change_cutoff' ) )
				: __( 'This appointment has already started, so it can’t be changed online.', 'anderson-appointment-booking' );
			return new WP_Error( 'aab_too_late', $message, array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Create a booking: lock the slot, re-check Google live, add the event, store and email.
	 *
	 * @param int        $start   Unix start time.
	 * @param array      $visitor name, email, notes, phone, answers (list of {label, value}).
	 * @param array|null $type    Appointment type (null: the first active one).
	 * @return array|WP_Error Booking.
	 */
	public static function create( $start, array $visitor, $type = null ) {
		$visitor = wp_parse_args(
			$visitor,
			array(
				'name'    => '',
				'email'   => '',
				'notes'   => '',
				'phone'   => '',
				'answers' => array(),
			)
		);
		$type    = is_array( $type ) ? $type : AAB_Types::first();
		if ( ! $type ) {
			return new WP_Error( 'aab_no_types', __( 'No appointment types are available.', 'anderson-appointment-booking' ), array( 'status' => 503 ) );
		}
		if ( ! self::acquire_lock( $start ) ) {
			return new WP_Error( 'aab_slot_taken', __( 'Someone else is booking that time right now. Pick another time.', 'anderson-appointment-booking' ), array( 'status' => 409 ) );
		}

		try {
			$check = AAB_Availability::check_slot( $start, $type );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( AAB_Payments::is_paid( $type ) ) {
				return AAB_Payments::start_checkout( $start, $visitor, $type );
			}
			return self::insert( $start, $visitor, $type );
		} finally {
			self::release_lock( $start );
		}
	}

	/**
	 * Take a named lock (atomic; see acquire_lock()).
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which a lock is considered abandoned.
	 * @return bool
	 */
	public static function acquire_named( $name, $ttl = 60 ) {
		global $wpdb;
		$name = '_aab_lock_' . substr( sanitize_key( $name ), 0, 150 );
		$now  = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( $held && $now - $held < $ttl ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $held ) );
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release_named( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_aab_lock_' . substr( sanitize_key( $name ), 0, 150 ) ) );
	}

	/**
	 * Take the lock for one start time.
	 *
	 * add_option() is not atomic (it checks, then inserts with ON DUPLICATE KEY UPDATE),
	 * so two simultaneous requests could both "win" it. INSERT IGNORE on the options
	 * table's unique key is atomic: exactly one request inserts the row.
	 *
	 * @param int $start Unix start time.
	 * @return bool
	 */
	private static function acquire_lock( $start ) {
		global $wpdb;
		$name = '_aab_lock_' . (int) $start;
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic lock; must bypass the options cache.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) );
		if ( $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( $held && $now - $held < 60 ) {
			return false;
		}
		// A request that died left the lock behind: take it over, but only if nobody else just did.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $held ) );
	}

	/**
	 * Release the lock for one start time.
	 *
	 * @param int $start Unix start time.
	 */
	private static function release_lock( $start ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_aab_lock_' . (int) $start ) );
	}

	/**
	 * Number of upcoming confirmed bookings for an email address.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	public static function upcoming_count_for_email( $email ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_aab_email',
						'value' => $email,
					),
					array(
						'key'     => '_aab_status',
						'value'   => array( 'confirmed', 'awaiting_payment' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_aab_end',
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return count( array_filter( $ids, array( __CLASS__, 'holds_slot' ) ) );
	}

	/**
	 * Store the booking and create the Google event (free appointments).
	 *
	 * @param int   $start   Start.
	 * @param array $visitor Visitor details.
	 * @param array $type    Appointment type.
	 * @return array|WP_Error
	 */
	private static function insert( $start, array $visitor, array $type ) {
		$post_id = self::store( $start, $visitor, $type, 'pending' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$booking = self::finalize( $post_id );
		if ( is_wp_error( $booking ) ) {
			wp_delete_post( $post_id, true );
		}
		return $booking;
	}

	/**
	 * Save a booking record (no calendar event yet).
	 *
	 * @param int    $start   Start.
	 * @param array  $visitor Visitor details.
	 * @param array  $type    Appointment type.
	 * @param string $status  'pending' or 'awaiting_payment'.
	 * @param array  $extra   Extra meta.
	 * @return int|WP_Error Post ID.
	 */
	public static function store( $start, array $visitor, array $type, $status, array $extra = array() ) {
		$token   = wp_generate_password( 32, false );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: 1: visitor name, 2: date and time */
				'post_title'  => sprintf( __( '%1$s, %2$s', 'anderson-appointment-booking' ), $visitor['name'], self::format_time( $start ) ),
				'meta_input'  => array_merge(
					array(
						'_aab_start'       => $start,
						'_aab_end'         => $start + AAB_Availability::duration_seconds( $type ),
						'_aab_date'        => wp_date( 'Y-m-d', $start ),
						'_aab_name'        => $visitor['name'],
						'_aab_email'       => $visitor['email'],
						'_aab_notes'       => $visitor['notes'],
						'_aab_phone'       => $visitor['phone'],
						'_aab_type'        => $type['id'],
						'_aab_type_name'   => $type['name'],
						'_aab_answers'     => $visitor['answers'],
						'_aab_location'    => AAB_Types::location( $type ),
						'_aab_meet'        => AAB_Types::wants_meet( $type ) ? 1 : 0,
						'_aab_status'      => $status,
						'_aab_calendar_id' => (string) AAB_Settings::get( 'booking_calendar' ),
						'_aab_cancel_hash' => self::hash_token( $token ),
						// Kept (encrypted) so later emails can include the manage link too.
						'_aab_cancel_enc'  => AAB_Crypto::encrypt( $token ),
					),
					$extra
				),
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			// Fixed up front, so creating the Google event is safe to repeat (a retry gets "already exists").
			update_post_meta( $post_id, '_aab_event_gid', self::event_id( $post_id ) );
		}
		return $post_id;
	}

	/**
	 * Create the Google event, mark the booking confirmed and send the emails.
	 *
	 * Safe to call more than once (e.g. Stripe webhook and the visitor's return at the same
	 * moment): a lock serializes callers and a confirmed booking is returned as is.
	 *
	 * @param int $post_id Booking ID.
	 * @return array|WP_Error Booking.
	 */
	public static function finalize( $post_id ) {
		if ( ! self::acquire_named( 'fin_' . $post_id, 120 ) ) {
			return new WP_Error( 'aab_busy', __( 'This booking is being confirmed. Refresh in a moment.', 'anderson-appointment-booking' ), array( 'status' => 409 ) );
		}
		try {
			$booking = self::get( $post_id );
			if ( ! $booking ) {
				return new WP_Error( 'aab_not_found', __( 'Booking not found.', 'anderson-appointment-booking' ) );
			}
			if ( 'confirmed' === $booking['status'] ) {
				return $booking;
			}
			$calendar = $booking['calendar_id'];
			$tz       = self::google_timezone( wp_timezone_string() );
			$event    = array(
				'id'                 => (string) get_post_meta( $post_id, '_aab_event_gid', true ),
				'summary'            => self::render_title( $booking['name'], $booking['type_name'] ),
				'description'        => self::event_description( $booking['name'], $booking['email'], $booking['notes'], $booking['phone'], $booking['type_name'], $booking['answers'], $booking ),
				'start'              => array(
					'dateTime' => wp_date( 'c', $booking['start'] ),
					'timeZone' => $tz,
				),
				'end'                => array(
					'dateTime' => wp_date( 'c', $booking['end'] ),
					'timeZone' => $tz,
				),
				'reminders'          => array( 'useDefault' => true ),
				'extendedProperties' => array( 'private' => array( 'aab_booking_id' => (string) $post_id ) ),
			);
			if ( '' !== $booking['location'] ) {
				$event['location'] = $booking['location'];
			}
			$invite = (bool) AAB_Settings::get( 'invite_guest' );
			if ( $invite ) {
				$event['attendees'] = array(
					array(
						'email'       => $booking['email'],
						'displayName' => $booking['name'],
					),
				);
			}
			if ( get_post_meta( $post_id, '_aab_meet', true ) ) {
				$event['conferenceData'] = array(
					'createRequest' => array(
						'requestId'             => 'aab-' . $post_id,
						'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
					),
				);
			}

			$created = AAB_Google::insert_event( $calendar, $event, $invite );
			if ( is_wp_error( $created ) ) {
				return new WP_Error(
					'aab_google_failed',
					__( 'The booking could not be added to the calendar. Try again in a minute.', 'anderson-appointment-booking' ),
					array(
						'status' => 502,
						'detail' => $created->get_error_message(),
					)
				);
			}

			$meet = '';
			if ( ! empty( $created['hangoutLink'] ) ) {
				$meet = (string) $created['hangoutLink'];
			} elseif ( ! empty( $created['conferenceData']['entryPoints'] ) ) {
				foreach ( $created['conferenceData']['entryPoints'] as $entry ) {
					if ( isset( $entry['entryPointType'] ) && 'video' === $entry['entryPointType'] ) {
						$meet = (string) $entry['uri'];
						break;
					}
				}
			}
			update_post_meta( $post_id, '_aab_event_id', sanitize_text_field( $created['id'] ) );
			update_post_meta( $post_id, '_aab_event_link', esc_url_raw( isset( $created['htmlLink'] ) ? $created['htmlLink'] : '' ) );
			update_post_meta( $post_id, '_aab_meet_link', esc_url_raw( $meet ) );
			update_post_meta( $post_id, '_aab_status', 'confirmed' );
			delete_post_meta( $post_id, '_aab_hold_until' );

			AAB_Availability::bust_cache();

			$booking = self::get( $post_id );
			self::email_confirmation( $booking );
			self::email_admin_new( $booking );

			/**
			 * Fires after a booking is confirmed and added to Google Calendar.
			 *
			 * @param array $booking Booking data.
			 */
			do_action( 'aab_booking_confirmed', $booking );

			return $booking;
		} finally {
			self::release_named( 'fin_' . $post_id );
		}
	}

	/**
	 * Move a booking to a new time (visitor self-service).
	 *
	 * Locks the new time, re-checks it live against Google (ignoring the booking's own
	 * current slot), moves the Google event, then updates the record and emails both sides.
	 *
	 * @param int $id    Booking ID.
	 * @param int $start New Unix start time.
	 * @return array|WP_Error Updated booking.
	 */
	public static function reschedule( $id, $start ) {
		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'aab_not_found', __( 'Booking not found.', 'anderson-appointment-booking' ), array( 'status' => 404 ) );
		}
		$ok = self::can_change( $booking );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( (int) $start === $booking['start'] ) {
			return new WP_Error( 'aab_same_time', __( 'That’s already your appointment time. Pick a different time.', 'anderson-appointment-booking' ), array( 'status' => 400 ) );
		}
		$limit = (int) AAB_Settings::get( 'max_reschedules' );
		if ( $limit > 0 && $booking['reschedules'] >= $limit ) {
			return new WP_Error( 'aab_too_many_moves', __( 'This appointment can’t be moved again online. Reply to your confirmation email to change it.', 'anderson-appointment-booking' ), array( 'status' => 403 ) );
		}
		$type = self::type_of( $booking );

		if ( ! self::acquire_lock( $start ) ) {
			return new WP_Error( 'aab_slot_taken', __( 'Someone else is booking that time right now. Pick another time.', 'anderson-appointment-booking' ), array( 'status' => 409 ) );
		}
		try {
			$check = AAB_Availability::check_slot( $start, $type, $booking );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$end = $start + (int) $type['duration'] * MINUTE_IN_SECONDS;
			$tz  = self::google_timezone( wp_timezone_string() );

			if ( '' !== $booking['event_id'] ) {
				$moved = AAB_Google::patch_event(
					$booking['calendar_id'],
					$booking['event_id'],
					array(
						'start' => array(
							'dateTime' => wp_date( 'c', $start ),
							'timeZone' => $tz,
						),
						'end'   => array(
							'dateTime' => wp_date( 'c', $end ),
							'timeZone' => $tz,
						),
					),
					(bool) AAB_Settings::get( 'invite_guest' )
				);
				if ( is_wp_error( $moved ) ) {
					return new WP_Error( 'aab_google_failed', __( 'Your appointment could not be moved right now. Try again in a minute.', 'anderson-appointment-booking' ), array( 'status' => 502 ) );
				}
			}

			$old = $booking['start'];
			update_post_meta( $id, '_aab_start', $start );
			update_post_meta( $id, '_aab_end', $end );
			update_post_meta( $id, '_aab_date', wp_date( 'Y-m-d', $start ) );
			update_post_meta( $id, '_aab_reschedules', $booking['reschedules'] + 1 );
			delete_post_meta( $id, '_aab_reminded' );
			wp_update_post(
				array(
					'ID'         => $id,
					/* translators: 1: visitor name, 2: date and time */
					'post_title' => sprintf( __( '%1$s, %2$s', 'anderson-appointment-booking' ), $booking['name'], self::format_time( $start ) ),
				)
			);
			AAB_Availability::bust_cache();

			$updated = self::get( $id );
			self::email_rescheduled( $updated, $old, 'visitor' );

			/**
			 * Fires after a visitor moves their booking.
			 *
			 * @param array $booking   Updated booking.
			 * @param int   $old_start Previous start time.
			 */
			do_action( 'aab_booking_rescheduled', $updated, $old );
			return $updated;
		} finally {
			self::release_lock( $start );
		}
	}

	/**
	 * Cancel a booking.
	 *
	 * @param int    $id Booking ID.
	 * @param string $by 'visitor', 'admin' or 'calendar' (the event was deleted in Google Calendar).
	 * @return true|WP_Error
	 */
	public static function cancel( $id, $by ) {
		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'aab_not_found', __( 'Booking not found.', 'anderson-appointment-booking' ) );
		}
		if ( 'cancelled' === $booking['status'] ) {
			return true;
		}
		if ( 'confirmed' !== $booking['status'] && ! $booking['paid_at'] ) {
			// Never confirmed (payment abandoned): just let the slot go.
			AAB_Payments::release( $id, 'cancelled' );
			return true;
		}
		if ( '' !== $booking['event_id'] && 'calendar' !== $by ) {
			$result = AAB_Google::delete_event( $booking['calendar_id'], $booking['event_id'], (bool) AAB_Settings::get( 'invite_guest' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		update_post_meta( $id, '_aab_status', 'cancelled' );
		update_post_meta( $id, '_aab_cancelled_by', in_array( $by, array( 'admin', 'calendar' ), true ) ? $by : 'visitor' );
		delete_post_meta( $id, '_aab_cancel_hash' );
		delete_post_meta( $id, '_aab_cancel_enc' );
		AAB_Availability::bust_cache();

		$refund = AAB_Payments::refund_on_cancel( $booking, $by );

		$booking['status'] = 'cancelled';
		self::email_cancelled( $booking, $by, $refund );

		/**
		 * Fires after a booking is cancelled.
		 *
		 * @param array  $booking Booking data.
		 * @param string $by      'visitor' or 'admin'.
		 */
		do_action( 'aab_booking_cancelled', $booking, $by );
		return true;
	}

	/**
	 * Trashing an upcoming confirmed booking in wp-admin cancels it on Google too.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trash( $post_id ) {
		$booking = self::get( $post_id );
		if ( $booking && 'confirmed' === $booking['status'] && $booking['end'] > time() ) {
			self::cancel( $post_id, 'admin' );
		}
	}

	/**
	 * Verify a visitor's cancel token.
	 *
	 * @param int    $id    Booking ID.
	 * @param string $token Token from the email link.
	 * @return bool
	 */
	public static function verify_token( $id, $token ) {
		$hash = (string) get_post_meta( $id, '_aab_cancel_hash', true );
		return '' !== $hash && '' !== $token && hash_equals( $hash, self::hash_token( $token ) );
	}

	/**
	 * The visitor's cancel link.
	 *
	 * @param int    $id    Booking ID.
	 * @param string $token Token.
	 * @return string
	 */
	public static function cancel_url( $id, $token ) {
		return add_query_arg(
			array(
				'aab_manage' => (int) $id,
				'aab_token'  => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Confirmed bookings overlapping a range (as busy intervals).
	 *
	 * @param int $from    Unix start.
	 * @param int $to      Unix end.
	 * @param int $exclude Booking ID to leave out (one being moved).
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function busy_ranges( $from, $to, $exclude = 0 ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_aab_start',
						'value'   => array( $from - DAY_IN_SECONDS, $to ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_aab_status',
						'value'   => array( 'confirmed', 'awaiting_payment' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( (int) $id === (int) $exclude || ! self::holds_slot( $id ) ) {
				continue;
			}
			$start = (int) get_post_meta( $id, '_aab_start', true );
			$end   = (int) get_post_meta( $id, '_aab_end', true );
			if ( $end > $from && $start < $to ) {
				$out[] = array( $start, $end );
			}
		}
		return $out;
	}

	/**
	 * Number of confirmed bookings per date.
	 *
	 * @param string $from    First date (Y-m-d).
	 * @param string $to      Last date (Y-m-d).
	 * @param int    $exclude Booking ID to leave out.
	 * @return array<string,int>
	 */
	public static function counts_by_date( $from, $to, $exclude = 0 ) {
		$ids    = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_aab_date',
						'value'   => array( $from, $to ),
						'compare' => 'BETWEEN',
					),
					array(
						'key'     => '_aab_status',
						'value'   => array( 'confirmed', 'awaiting_payment' ),
						'compare' => 'IN',
					),
				),
			)
		);
		$counts = array();
		foreach ( $ids as $id ) {
			if ( (int) $id === (int) $exclude || ! self::holds_slot( $id ) ) {
				continue;
			}
			$date            = (string) get_post_meta( $id, '_aab_date', true );
			$counts[ $date ] = isset( $counts[ $date ] ) ? $counts[ $date ] + 1 : 1;
		}
		return $counts;
	}

	/**
	 * Date and time in the site's format and timezone.
	 *
	 * @param int $ts Unix time.
	 * @return string
	 */
	public static function format_time( $ts ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Google needs an IANA zone name; WordPress may be set to a raw offset like "+02:00".
	 *
	 * @param string $tz Timezone string.
	 * @return string
	 */
	private static function google_timezone( $tz ) {
		return preg_match( '#^[A-Za-z_]+/[A-Za-z_/\-+0-9]+$#', $tz ) || 'UTC' === $tz ? $tz : 'UTC';
	}

	/**
	 * Hash a cancel token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function hash_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'nonce' ) );
	}

	/**
	 * Event title from the template.
	 *
	 * @param string $name Visitor name.
	 * @return string
	 */
	private static function render_title( $name, $type_name = '' ) {
		$template = (string) AAB_Settings::get( 'event_title' );
		return strtr(
			'' !== trim( $template ) ? $template : 'Appointment with {name}',
			array(
				'{name}' => $name,
				'{type}' => $type_name,
				'{site}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
	}

	/**
	 * Google event ID for a booking. Google allows 5–1024 characters from a–v and 0–9.
	 *
	 * @param int $post_id Booking ID.
	 * @return string
	 */
	private static function event_id( $post_id ) {
		$alphabet = '0123456789abcdefghijklmnopqrstuv';
		$random   = '';
		foreach ( str_split( random_bytes( 10 ) ) as $byte ) {
			$random .= $alphabet[ ord( $byte ) % 32 ];
		}
		return 'aab' . (int) $post_id . 'v' . $random;
	}

	/**
	 * Event description.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @param string $notes Notes.
	 * @param string $phone     Phone.
	 * @param string $type_name Appointment type.
	 * @param array  $answers   Answers to booking questions.
	 * @return string
	 */
	private static function event_description( $name, $email, $notes, $phone = '', $type_name = '', array $answers = array(), array $booking = array() ) {
		$lines = array();
		if ( '' !== $type_name ) {
			$lines[] = $type_name;
			$lines[] = '';
		}
		$lines = array_merge(
			$lines,
			array(
			/* translators: %s: visitor name */
			sprintf( __( 'Name: %s', 'anderson-appointment-booking' ), $name ),
			/* translators: %s: visitor email */
			sprintf( __( 'Email: %s', 'anderson-appointment-booking' ), $email ),
			)
		);
		if ( '' !== $phone ) {
			/* translators: %s: visitor phone number */
			$lines[] = sprintf( __( 'Phone: %s', 'anderson-appointment-booking' ), $phone );
		}
		if ( ! empty( $booking['paid_at'] ) ) {
			/* translators: %s: amount paid */
			$lines[] = sprintf( __( 'Paid: %s (Stripe)', 'anderson-appointment-booking' ), AAB_Stripe::format( $booking['amount'], $booking['currency'] ) );
		}
		foreach ( self::answer_lines( $answers ) as $line ) {
			$lines[] = $line;
		}
		if ( '' !== $notes ) {
			$lines[] = '';
			$lines[] = $notes;
		}
		$lines[] = '';
		/* translators: %s: site URL */
		$lines[] = sprintf( __( 'Booked through %s', 'anderson-appointment-booking' ), home_url( '/' ) );
		return implode( "\n", $lines );
	}

	/**
	 * Answers as "Question: answer" lines.
	 *
	 * @param array $answers Answers.
	 * @return string[]
	 */
	public static function answer_lines( array $answers ) {
		$out = array();
		foreach ( $answers as $a ) {
			if ( is_array( $a ) && isset( $a['label'], $a['value'] ) && '' !== (string) $a['value'] ) {
				$out[] = $a['label'] . ': ' . $a['value'];
			}
		}
		return $out;
	}

	/**
	 * The visitor's cancel link for a stored booking ('' if it can't be rebuilt).
	 *
	 * @param array $booking Booking.
	 * @return string
	 */
	public static function cancel_url_for( array $booking ) {
		$token = AAB_Crypto::decrypt( (string) get_post_meta( $booking['id'], '_aab_cancel_enc', true ) );
		return '' !== $token && self::verify_token( $booking['id'], $token ) ? self::cancel_url( $booking['id'], $token ) : '';
	}

	/**
	 * Site name for emails.
	 *
	 * @return string
	 */
	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * The "when / where / how to join / how to cancel" block shared by visitor emails.
	 *
	 * @param array $booking Booking.
	 * @return string[]
	 */
	private static function details_lines( array $booking ) {
		$lines = array();
		if ( '' !== $booking['type_name'] ) {
			/* translators: %s: appointment type */
			$lines[] = sprintf( __( 'What: %s', 'anderson-appointment-booking' ), $booking['type_name'] );
		}
		$lines = array_merge(
			$lines,
			array(
			/* translators: 1: date and time, 2: timezone */
			sprintf( __( 'When: %1$s (%2$s)', 'anderson-appointment-booking' ), self::format_time( $booking['start'] ), wp_timezone_string() ),
			)
		);
		if ( '' !== $booking['meet_link'] ) {
			/* translators: %s: video call link */
			$lines[] = sprintf( __( 'Join the video call: %s', 'anderson-appointment-booking' ), $booking['meet_link'] );
		}
		if ( '' !== $booking['location'] ) {
			/* translators: %s: location */
			$lines[] = sprintf( __( 'Location: %s', 'anderson-appointment-booking' ), $booking['location'] );
		}
		$manage = self::cancel_url_for( $booking );
		if ( '' !== $manage ) {
			$lines[] = '';
			/* translators: %s: link to reschedule or cancel */
			$lines[] = sprintf( __( 'Need to reschedule or cancel? %s', 'anderson-appointment-booking' ), $manage );
		}
		return $lines;
	}

	/**
	 * Calendar file (iCalendar) for a booking.
	 *
	 * @param array $booking Booking.
	 * @return string
	 */
	public static function ics( array $booking ) {
		$esc  = static function ( $text ) {
			return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\\,', '\\n', '\\n' ), (string) $text );
		};
		$fold = static function ( $line ) {
			$out = '';
			while ( strlen( $line ) > 74 ) {
				$cut  = 74;
				// Don't split a multibyte character.
				while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
					--$cut;
				}
				$out .= substr( $line, 0, $cut ) . "\r\n ";
				$line = substr( $line, $cut );
			}
			return $out . $line;
		};
		$desc = array();
		if ( '' !== $booking['meet_link'] ) {
			/* translators: %s: video call link */
			$desc[] = sprintf( __( 'Join the video call: %s', 'anderson-appointment-booking' ), $booking['meet_link'] );
		}
		$manage = self::cancel_url_for( $booking );
		if ( '' !== $manage ) {
			/* translators: %s: link to reschedule or cancel */
			$desc[] = sprintf( __( 'Need to reschedule or cancel? %s', 'anderson-appointment-booking' ), $manage );
		}
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Anderson Appointment Booking//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:aab-' . $booking['id'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $booking['start'] ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $booking['end'] ),
			/* translators: %s: site name */
			'SUMMARY:' . $esc( '' !== $booking['type_name'] ? $booking['type_name'] . ' – ' . self::site_name() : sprintf( __( 'Appointment with %s', 'anderson-appointment-booking' ), self::site_name() ) ),
			'DESCRIPTION:' . $esc( implode( "\n", $desc ) ),
		);
		$location = $booking['location'];
		if ( '' !== $location || '' !== $booking['meet_link'] ) {
			$lines[] = 'LOCATION:' . $esc( '' !== $location ? $location : $booking['meet_link'] );
		}
		if ( '' !== $booking['meet_link'] ) {
			$lines[] = 'URL:' . $booking['meet_link'];
		}
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( $fold, $lines ) ) . "\r\n";
	}

	/**
	 * Send an email to the visitor, attaching a calendar file when Google isn't sending them an invite.
	 *
	 * @param array    $booking Booking.
	 * @param string   $subject Subject.
	 * @param string[] $lines   Body lines.
	 * @param bool     $attach  Attach the .ics file.
	 */
	private static function mail_visitor( array $booking, $subject, array $lines, $attach ) {
		$file = '';
		if ( $attach && ! AAB_Settings::get( 'invite_guest' ) ) {
			$file = trailingslashit( get_temp_dir() ) . 'appointment-' . (int) $booking['id'] . '-' . wp_generate_password( 6, false ) . '.ics';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- short-lived temp attachment.
			if ( false === file_put_contents( $file, self::ics( $booking ) ) ) {
				$file = '';
			}
		}
		wp_mail( $booking['email'], $subject, implode( "\n", $lines ), self::reply_to_header( self::notify_email() ), $file ? array( $file ) : array() );
		if ( $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Email the visitor.
	 *
	 * @param array $booking Booking.
	 */
	private static function email_confirmation( array $booking ) {
		$lines = array_merge(
			array(
				/* translators: %s: visitor name */
				sprintf( __( 'Hi %s,', 'anderson-appointment-booking' ), $booking['name'] ),
				'',
				/* translators: %s: site name */
				sprintf( __( 'Your appointment with %s is booked.', 'anderson-appointment-booking' ), self::site_name() ),
				'',
			),
			self::details_lines( $booking )
		);
		/* translators: 1: site name, 2: date and time */
		$subject = sprintf( __( '[%1$s] Appointment booked: %2$s', 'anderson-appointment-booking' ), self::site_name(), self::format_time( $booking['start'] ) );
		self::mail_visitor( $booking, $subject, $lines, true );
	}

	/**
	 * Reminder before the appointment.
	 *
	 * @param array $booking Booking.
	 */
	public static function email_reminder( array $booking ) {
		$lines = array_merge(
			array(
				/* translators: %s: visitor name */
				sprintf( __( 'Hi %s,', 'anderson-appointment-booking' ), $booking['name'] ),
				'',
				/* translators: %s: site name */
				sprintf( __( 'A reminder of your upcoming appointment with %s.', 'anderson-appointment-booking' ), self::site_name() ),
				'',
			),
			self::details_lines( $booking )
		);
		/* translators: 1: site name, 2: date and time */
		$subject = sprintf( __( '[%1$s] Reminder: appointment %2$s', 'anderson-appointment-booking' ), self::site_name(), self::format_time( $booking['start'] ) );
		self::mail_visitor( $booking, $subject, $lines, false );
	}

	/**
	 * The appointment moved (by the visitor, or in Google Calendar).
	 *
	 * @param array  $booking Booking (already updated).
	 * @param int    $old     Previous start time.
	 * @param string $by      'visitor' or 'calendar'.
	 */
	public static function email_rescheduled( array $booking, $old, $by = 'calendar' ) {
		$lines = array_merge(
			array(
				/* translators: %s: visitor name */
				sprintf( __( 'Hi %s,', 'anderson-appointment-booking' ), $booking['name'] ),
				'',
				'visitor' === $by
					/* translators: 1: old date and time */
					? sprintf( __( 'Your appointment has been moved from %1$s to the new time below.', 'anderson-appointment-booking' ), self::format_time( $old ) )
					/* translators: 1: site name, 2: old date and time */
					: sprintf( __( 'Your appointment with %1$s, previously %2$s, has moved.', 'anderson-appointment-booking' ), self::site_name(), self::format_time( $old ) ),
				'',
			),
			self::details_lines( $booking )
		);
		/* translators: 1: site name, 2: new date and time */
		$subject = sprintf( __( '[%1$s] Appointment moved to %2$s', 'anderson-appointment-booking' ), self::site_name(), self::format_time( $booking['start'] ) );
		self::mail_visitor( $booking, $subject, $lines, true );

		$to = self::notify_email();
		if ( 'visitor' === $by && '' !== $to ) {
			$body = sprintf(
				/* translators: 1: visitor name, 2: email, 3: old time, 4: new time */
				__( "%1\$s (%2\$s) rescheduled their appointment.\n\nWas: %3\$s\nNow: %4\$s\n\nGoogle Calendar has been updated.", 'anderson-appointment-booking' ),
				$booking['name'],
				$booking['email'],
				self::format_time( $old ),
				self::format_time( $booking['start'] )
			);
			if ( '' !== $booking['event_link'] ) {
				/* translators: %s: Google Calendar event link */
				$body .= "\n" . sprintf( __( 'Open in Google Calendar: %s', 'anderson-appointment-booking' ), $booking['event_link'] );
			}
			/* translators: 1: site name, 2: visitor name */
			wp_mail( $to, sprintf( __( '[%1$s] %2$s rescheduled', 'anderson-appointment-booking' ), self::site_name(), $booking['name'] ), $body, self::reply_to_header( $booking['email'] ) );
		}
	}

	/**
	 * Email the site owner about a new booking.
	 *
	 * @param array $booking Booking.
	 */
	private static function email_admin_new( array $booking ) {
		$to = self::notify_email();
		if ( '' === $to ) {
			return;
		}
		$lines = array(
			/* translators: %s: date and time */
			sprintf( __( 'New appointment: %s', 'anderson-appointment-booking' ), self::format_time( $booking['start'] ) ),
			$booking['type_name'],
			'',
			/* translators: %s: name */
			sprintf( __( 'Name: %s', 'anderson-appointment-booking' ), $booking['name'] ),
			/* translators: %s: email */
			sprintf( __( 'Email: %s', 'anderson-appointment-booking' ), $booking['email'] ),
		);
		if ( '' !== $booking['phone'] ) {
			/* translators: %s: phone number */
			$lines[] = sprintf( __( 'Phone: %s', 'anderson-appointment-booking' ), $booking['phone'] );
		}
		$answers = self::answer_lines( $booking['answers'] );
		if ( $answers ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $answers );
		}
		if ( '' !== $booking['notes'] ) {
			$lines[] = '';
			$lines[] = $booking['notes'];
		}
		if ( '' !== $booking['event_link'] ) {
			$lines[] = '';
			/* translators: %s: Google Calendar event link */
			$lines[] = sprintf( __( 'Open in Google Calendar: %s', 'anderson-appointment-booking' ), $booking['event_link'] );
		}
		/* translators: 1: site name, 2: visitor name */
		$subject = sprintf( __( '[%1$s] New booking from %2$s', 'anderson-appointment-booking' ), self::site_name(), $booking['name'] );
		wp_mail( $to, $subject, implode( "\n", $lines ), self::reply_to_header( $booking['email'] ) );
	}

	/**
	 * Email about a cancellation.
	 *
	 * @param array  $booking Booking.
	 * @param string $by      Who cancelled.
	 * @param string $refund  'refunded', 'not_refunded', 'failed' or '' (nothing was paid).
	 */
	private static function email_cancelled( array $booking, $by, $refund = '' ) {
		$refund_line = '';
		if ( 'refunded' === $refund ) {
			/* translators: %s: amount */
			$refund_line = "\n\n" . sprintf( __( 'Your payment of %s has been refunded. It can take 5–10 business days to appear on your statement.', 'anderson-appointment-booking' ), AAB_Stripe::format( $booking['amount'], $booking['currency'] ) );
		} elseif ( 'not_refunded' === $refund ) {
			$refund_line = "\n\n" . __( 'This cancellation was inside the refund window, so the payment was not refunded. Reply to this email if you have questions.', 'anderson-appointment-booking' );
		}
		$when = self::format_time( $booking['start'] );

		/* translators: 1: site name, 2: date and time */
		$subject = sprintf( __( '[%1$s] Appointment cancelled: %2$s', 'anderson-appointment-booking' ), self::site_name(), $when );

		if ( $booking['end'] > time() ) {
			/* translators: 1: visitor name, 2: date and time */
			$body = sprintf( __( "Hi %1\$s,\n\nYour appointment on %2\$s has been cancelled.", 'anderson-appointment-booking' ), $booking['name'], $when ) . $refund_line;
			wp_mail( $booking['email'], $subject, $body, self::reply_to_header( self::notify_email() ) );
		}

		$to = self::notify_email();
		if ( '' !== $to && 'visitor' === $by ) {
			/* translators: 1: visitor name, 2: email, 3: date and time */
			$body = sprintf( __( "%1\$s (%2\$s) cancelled their appointment on %3\$s. It has been removed from Google Calendar.", 'anderson-appointment-booking' ), $booking['name'], $booking['email'], $when );
			wp_mail( $to, $subject, $body, self::reply_to_header( $booking['email'] ) );
		}
	}

	/**
	 * Where owner notifications go.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = sanitize_email( (string) AAB_Settings::get( 'notify_email' ) );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Reply-To header.
	 *
	 * @param string $email Address.
	 * @return string[]
	 */
	private static function reply_to_header( $email ) {
		return is_email( $email ) ? array( 'Reply-To: ' . $email ) : array();
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['anderson-appointment-booking'] = array(
			'exporter_friendly_name' => __( 'Appointment bookings', 'anderson-appointment-booking' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['anderson-appointment-booking'] = array(
			'eraser_friendly_name' => __( 'Appointment bookings', 'anderson-appointment-booking' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Booking IDs for an email address.
	 *
	 * @param string $email Email.
	 * @return int[]
	 */
	private static function ids_for_email( $email ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_aab_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * Personal data exporter.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export_personal_data( $email ) {
		$items = array();
		foreach ( self::ids_for_email( $email ) as $id ) {
			$b       = self::get( $id );
			$items[] = array(
				'group_id'    => 'aab-bookings',
				'group_label' => __( 'Appointment bookings', 'anderson-appointment-booking' ),
				'item_id'     => 'aab-booking-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Time', 'anderson-appointment-booking' ),
						'value' => self::format_time( $b['start'] ),
					),
					array(
						'name'  => __( 'Name', 'anderson-appointment-booking' ),
						'value' => $b['name'],
					),
					array(
						'name'  => __( 'Email', 'anderson-appointment-booking' ),
						'value' => $b['email'],
					),
					array(
						'name'  => __( 'Phone', 'anderson-appointment-booking' ),
						'value' => $b['phone'],
					),
					array(
						'name'  => __( 'Appointment type', 'anderson-appointment-booking' ),
						'value' => $b['type_name'],
					),
					array(
						'name'  => __( 'Answers', 'anderson-appointment-booking' ),
						'value' => implode( "\n", self::answer_lines( $b['answers'] ) ),
					),
					array(
						'name'  => __( 'Notes', 'anderson-appointment-booking' ),
						'value' => $b['notes'],
					),
					array(
						'name'  => __( 'Status', 'anderson-appointment-booking' ),
						'value' => $b['status'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Personal data eraser. Removes the stored booking records (Google Calendar events are not touched).
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase_personal_data( $email ) {
		$removed = false;
		foreach ( self::ids_for_email( $email ) as $id ) {
			remove_action( 'wp_trash_post', array( __CLASS__, 'on_trash' ) );
			wp_delete_post( $id, true );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Booking records were deleted from this site. Events already on the owner’s Google Calendar are not changed.', 'anderson-appointment-booking' ) ) : array(),
			'done'           => true,
		);
	}
}
