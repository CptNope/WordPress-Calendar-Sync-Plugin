<?php
/**
 * Settings storage and sanitization.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings.
 */
final class AAB_Settings {

	const OPTION = 'aab_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Weekday keys in ISO-8601 order (Monday = 1).
	 *
	 * @return array<int,string>
	 */
	public static function weekdays() {
		return array(
			1 => 'mon',
			2 => 'tue',
			3 => 'wed',
			4 => 'thu',
			5 => 'fri',
			6 => 'sat',
			7 => 'sun',
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'client_id'            => '',
			'client_secret'        => '', // Encrypted.
			'booking_calendar'     => 'primary',
			'conflict_calendars'   => array(),
			'hours'                => array(
				'mon' => '09:00-17:00',
				'tue' => '09:00-17:00',
				'wed' => '09:00-17:00',
				'thu' => '09:00-17:00',
				'fri' => '09:00-17:00',
				'sat' => '',
				'sun' => '',
			),
			'duration'             => 30,
			'interval'             => 30,
			'buffer_before'        => 0,
			'buffer_after'         => 15,
			'min_notice'           => 12,
			'max_days'             => 30,
			'daily_limit'          => 0,
			'days_off'             => '',
			'max_per_email'        => 2,
			'phone_field'          => 'off',
			'reminder_hours'       => 24,
			'retention_days'       => 0,
			'change_cutoff'        => 0,
			'max_reschedules'      => 0,
			'event_title'          => 'Appointment with {name}',
			'location'             => '',
			'add_meet'             => 1,
			'invite_guest'         => 1,
			'notify_email'         => get_option( 'admin_email' ),
			'confirmation_message' => "You're booked. A confirmation is on its way to your inbox.",
			'consent_text'         => '',
			'accent'               => '#2e7d4f',
			'style_accent'         => 'theme',
			'style_buttons'        => 'theme',
			'style_radius'         => 'auto',
			'style_background'     => 'none',
			'style_bg_color'       => '#ffffff',
			'style_density'        => 'comfortable',
			'style_headings'       => 'theme',
			'custom_css'           => '',
			'delete_data'          => 0,
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			if ( ! is_array( self::$cache['hours'] ) ) {
				self::$cache['hours'] = self::defaults()['hours'];
			}
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Decrypted client secret.
	 *
	 * @return string
	 */
	public static function client_secret() {
		if ( defined( 'AAB_GOOGLE_CLIENT_SECRET' ) ) {
			return (string) AAB_GOOGLE_CLIENT_SECRET;
		}
		return AAB_Crypto::decrypt( self::get( 'client_secret' ) );
	}

	/**
	 * Client ID (wp-config.php constant wins).
	 *
	 * @return string
	 */
	public static function client_id() {
		if ( defined( 'AAB_GOOGLE_CLIENT_ID' ) ) {
			return (string) AAB_GOOGLE_CLIENT_ID;
		}
		return (string) self::get( 'client_id' );
	}

	/**
	 * Save a full settings array.
	 *
	 * @param array $settings Settings.
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION, $settings, false );
		self::$cache = null;
	}

	/**
	 * Clear the request cache (after update_option elsewhere).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Parse the days-off list: one date or range per line, e.g. "2026-12-24" or
	 * "2026-12-24 to 2027-01-02", optionally followed by "# note".
	 *
	 * @param string $value Raw text.
	 * @return array{ranges:array<int,array{0:string,1:string}>,invalid:string[]}
	 */
	public static function parse_days_off( $value ) {
		$ranges  = array();
		$invalid = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?:\s*(?:to|-|–|—|\.\.)\s*(\d{4}-\d{2}-\d{2}))?$/u', $line, $m ) ) {
				$from = $m[1];
				$to   = isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1];
				if ( AAB_Availability::is_valid_date( $from ) && AAB_Availability::is_valid_date( $to ) ) {
					$ranges[] = $from <= $to ? array( $from, $to ) : array( $to, $from );
					continue;
				}
			}
			$invalid[] = $line;
		}
		return array(
			'ranges'  => $ranges,
			'invalid' => $invalid,
		);
	}

	/**
	 * Is a date (Y-m-d) inside the days-off list?
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	public static function is_day_off( $date ) {
		static $ranges = null;
		if ( null === $ranges ) {
			$parsed = self::parse_days_off( self::get( 'days_off' ) );
			$ranges = $parsed['ranges'];
		}
		foreach ( $ranges as $range ) {
			if ( $date >= $range[0] && $date <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse an hours string like "09:00-12:00, 13:00-17:00" into minute ranges.
	 *
	 * @param string $value Hours string.
	 * @return array<int,array{0:int,1:int}> List of [start_minute, end_minute].
	 */
	public static function parse_hours( $value ) {
		$ranges = array();
		foreach ( explode( ',', (string) $value ) as $part ) {
			if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $part, $m ) ) {
				continue;
			}
			$start = (int) $m[1] * 60 + (int) $m[2];
			$end   = (int) $m[3] * 60 + (int) $m[4];
			if ( '24' === $m[3] && '00' === $m[4] ) {
				$end = 1440;
			}
			if ( $start >= 0 && $end <= 1440 && $end > $start && (int) $m[2] < 60 && (int) $m[4] < 60 ) {
				$ranges[] = array( $start, $end );
			}
		}
		usort(
			$ranges,
			static function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		return $ranges;
	}

	/**
	 * Normalize an hours string; returns '' when nothing valid remains.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function normalize_hours( $value ) {
		$out = array();
		foreach ( self::parse_hours( $value ) as $range ) {
			$out[] = sprintf( '%02d:%02d-%02d:%02d', intdiv( $range[0], 60 ), $range[0] % 60, intdiv( $range[1], 60 ), $range[1] % 60 );
		}
		return implode( ', ', $out );
	}
}
