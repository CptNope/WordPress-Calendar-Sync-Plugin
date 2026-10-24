<?php
/**
 * Works out which appointment slots are open.
 *
 * Candidate slots come from your weekly hours (in the site's timezone). A slot is
 * offered only if it, plus its buffers, doesn't overlap anything busy on the
 * checked Google calendars or another booking made here.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slot calculator.
 */
final class AAB_Availability {

	const GENERATION_OPTION = 'aab_busy_generation';
	const CACHE_TTL         = 120;

	/**
	 * Calendars whose busy times block slots.
	 *
	 * @return string[]
	 */
	public static function conflict_calendars() {
		$ids   = (array) AAB_Settings::get( 'conflict_calendars' );
		$ids[] = (string) AAB_Settings::get( 'booking_calendar' );
		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * Invalidate cached busy data (after a booking or cancellation).
	 */
	public static function bust_cache() {
		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 0 ) + 1, false );
	}

	/**
	 * Validate a Y-m-d string.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * First and last bookable dates (site timezone, Y-m-d).
	 *
	 * @return array{0:string,1:string}
	 */
	public static function window() {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$days  = max( 1, (int) AAB_Settings::get( 'max_days' ) );
		return array( $today->format( 'Y-m-d' ), $today->modify( '+' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ) );
	}

	/**
	 * Open slots for each date in a range.
	 *
	 * @param string $from  First date (Y-m-d, site timezone).
	 * @param string $to    Last date (Y-m-d, inclusive).
	 * @param bool   $fresh Bypass the busy-time cache.
	 * @return array<string,int[]>|WP_Error Map of date => list of Unix start times.
	 */
	public static function slots( $from, $to, $fresh = false ) {
		list( $first, $last ) = self::window();
		$from                 = max( $from, $first );
		$to                   = min( $to, $last );
		if ( $from > $to ) {
			return array();
		}

		$candidates = self::candidates( $from, $to );
		$all        = array();
		foreach ( $candidates as $list ) {
			$all = array_merge( $all, $list );
		}
		if ( ! $all ) {
			return $candidates;
		}

		$duration = self::duration_seconds();
		$before   = (int) AAB_Settings::get( 'buffer_before' ) * MINUTE_IN_SECONDS;
		$after    = (int) AAB_Settings::get( 'buffer_after' ) * MINUTE_IN_SECONDS;

		$range_start = min( $all ) - $before;
		$range_end   = max( $all ) + $duration + $after;

		$busy = self::busy( $range_start, $range_end, $fresh );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}
		$busy = array_merge( $busy, AAB_Bookings::busy_ranges( $range_start, $range_end ) );

		$limit  = (int) AAB_Settings::get( 'daily_limit' );
		$counts = $limit > 0 ? AAB_Bookings::counts_by_date( $from, $to ) : array();

		$open = array();
		foreach ( $candidates as $date => $starts ) {
			$open[ $date ] = array();
			if ( $limit > 0 && isset( $counts[ $date ] ) && $counts[ $date ] >= $limit ) {
				continue;
			}
			foreach ( $starts as $start ) {
				$a = $start - $before;
				$b = $start + $duration + $after;
				if ( ! self::overlaps( $a, $b, $busy ) ) {
					$open[ $date ][] = $start;
				}
			}
		}
		return $open;
	}

	/**
	 * Is one specific start time bookable right now? Always checks Google live.
	 *
	 * @param int $start Unix start time.
	 * @return true|WP_Error
	 */
	public static function check_slot( $start ) {
		$date  = wp_date( 'Y-m-d', $start );
		$slots = self::slots( $date, $date, true );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}
		if ( empty( $slots[ $date ] ) || ! in_array( (int) $start, $slots[ $date ], true ) ) {
			return new WP_Error( 'aab_slot_taken', __( 'That time was just taken. Pick another time.', 'anderson-appointment-booking' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Appointment length in seconds.
	 *
	 * @return int
	 */
	public static function duration_seconds() {
		return max( 5, (int) AAB_Settings::get( 'duration' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Slot starts generated from weekly hours, before checking busy times.
	 *
	 * @param string $from First date.
	 * @param string $to   Last date.
	 * @return array<string,int[]>
	 */
	private static function candidates( $from, $to ) {
		$tz        = wp_timezone();
		$weekdays  = AAB_Settings::weekdays();
		$hours     = (array) AAB_Settings::get( 'hours' );
		$duration  = (int) max( 5, (int) AAB_Settings::get( 'duration' ) );
		$interval  = (int) max( 5, (int) AAB_Settings::get( 'interval' ) );
		$earliest  = time() + (int) AAB_Settings::get( 'min_notice' ) * HOUR_IN_SECONDS;
		$day       = new DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end       = new DateTimeImmutable( $to . ' 00:00:00', $tz );
		$out       = array();
		$guard     = 0;

		while ( $day <= $end && $guard++ < 400 ) {
			$date         = $day->format( 'Y-m-d' );
			$key          = $weekdays[ (int) $day->format( 'N' ) ];
			$out[ $date ] = array();
			$ranges       = AAB_Settings::parse_hours( isset( $hours[ $key ] ) ? $hours[ $key ] : '' );

			foreach ( $ranges as $range ) {
				for ( $minute = $range[0]; $minute + $duration <= $range[1]; $minute += $interval ) {
					// setTime() keeps wall-clock times correct across daylight-saving changes.
					$start = $day->setTime( intdiv( $minute, 60 ), $minute % 60 )->getTimestamp();
					if ( $start >= $earliest && ! in_array( $start, $out[ $date ], true ) ) {
						$out[ $date ][] = $start;
					}
				}
			}
			sort( $out[ $date ] );
			$day = $day->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * Busy ranges from Google, cached briefly.
	 *
	 * @param int  $from  Unix start.
	 * @param int  $to    Unix end.
	 * @param bool $fresh Bypass cache.
	 * @return array|WP_Error
	 */
	private static function busy( $from, $to, $fresh ) {
		$calendars = self::conflict_calendars();
		$key       = 'aab_busy_' . md5( $from . '|' . $to . '|' . implode( ',', $calendars ) . '|' . (int) get_option( self::GENERATION_OPTION, 0 ) );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$busy = AAB_Google::busy( $from, $to, $calendars );
		if ( ! is_wp_error( $busy ) ) {
			set_transient( $key, $busy, self::CACHE_TTL );
		}
		return $busy;
	}

	/**
	 * Does [a, b) overlap any busy range?
	 *
	 * @param int   $a    Start.
	 * @param int   $b    End.
	 * @param array $busy Busy ranges.
	 * @return bool
	 */
	private static function overlaps( $a, $b, array $busy ) {
		foreach ( $busy as $range ) {
			if ( $a < $range[1] && $b > $range[0] ) {
				return true;
			}
		}
		return false;
	}
}
