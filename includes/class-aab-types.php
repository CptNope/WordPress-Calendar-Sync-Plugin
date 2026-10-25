<?php
/**
 * Appointment types (e.g. "15-minute intro", "60-minute consult").
 *
 * Each type has its own length, start-time spacing, optional own weekly hours,
 * location, video-call setting and booking questions. Everything else (buffers,
 * notice, booking window, days off, daily cap) is shared, because one person's
 * calendar can only hold one appointment at a time.
 *
 * Stored as an ordered list in the `aab_types` option.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Appointment type storage and validation.
 */
final class AAB_Types {

	const OPTION = 'aab_types';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Question field kinds.
	 *
	 * @return array<string,string>
	 */
	public static function question_kinds() {
		return array(
			'text'     => __( 'Short answer', 'anderson-appointment-booking' ),
			'textarea' => __( 'Long answer', 'anderson-appointment-booking' ),
			'select'   => __( 'Dropdown', 'anderson-appointment-booking' ),
			'checkbox' => __( 'Checkbox', 'anderson-appointment-booking' ),
		);
	}

	/**
	 * Defaults for one type.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'           => '',
			'name'         => '',
			'description'  => '',
			'duration'     => 30,
			'interval'     => 30,
			'custom_hours' => 0,
			'hours'        => array(),
			'location'     => '',
			'meet'         => 'default',
			'notes'        => 1,
			'questions'    => array(),
			'active'       => 1,
			'price'        => 0, // Minor units (cents); 0 = free.
		);
	}

	/**
	 * Create the first type from the 1.x single-type settings, once.
	 */
	public static function maybe_migrate() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}
		$duration = max( 5, (int) AAB_Settings::get( 'duration' ) );
		$type     = array_merge(
			self::defaults(),
			array(
				'id'       => 'appointment',
				/* translators: %d: minutes */
				'name'     => sprintf( __( '%d-minute appointment', 'anderson-appointment-booking' ), $duration ),
				'duration' => $duration,
				'interval' => max( 5, (int) AAB_Settings::get( 'interval' ) ),
			)
		);
		add_option( self::OPTION, array( $type ), '', false );
		self::$cache = null;
	}

	/**
	 * All types in display order.
	 *
	 * @return array[]
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array();
			foreach ( is_array( $saved ) ? $saved : array() as $type ) {
				if ( is_array( $type ) && ! empty( $type['id'] ) ) {
					self::$cache[] = array_merge( self::defaults(), $type );
				}
			}
		}
		return self::$cache;
	}

	/**
	 * Types visitors can book.
	 *
	 * @return array[]
	 */
	public static function active() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $t ) {
					return ! empty( $t['active'] );
				}
			)
		);
	}

	/**
	 * One type by ID.
	 *
	 * @param string $id Type ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $type ) {
			if ( $type['id'] === (string) $id ) {
				return $type;
			}
		}
		return null;
	}

	/**
	 * The type to use when none is named: the first active one.
	 *
	 * @return array|null
	 */
	public static function first() {
		$active = self::bookable();
		return $active ? $active[0] : null;
	}

	/**
	 * A stand-in type for an old booking whose type was deleted (used for rescheduling it).
	 *
	 * @param int    $minutes Length.
	 * @param string $name    Name.
	 * @return array
	 */
	public static function fallback( $minutes, $name = '' ) {
		return array_merge(
			self::defaults(),
			array(
				'id'       => '',
				'name'     => '' !== $name ? $name : __( 'Appointment', 'anderson-appointment-booking' ),
				'duration' => max( 5, (int) $minutes ),
				'interval' => max( 5, min( 30, (int) $minutes ) ),
			)
		);
	}

	/**
	 * Weekly hours for a type (its own, or the shared ones).
	 *
	 * @param array $type Type.
	 * @return array<string,string>
	 */
	public static function hours( array $type ) {
		return ! empty( $type['custom_hours'] ) && is_array( $type['hours'] ) ? $type['hours'] : (array) AAB_Settings::get( 'hours' );
	}

	/**
	 * Location text for a type.
	 *
	 * @param array $type Type.
	 * @return string
	 */
	public static function location( array $type ) {
		$own = trim( (string) $type['location'] );
		return '' !== $own ? $own : trim( (string) AAB_Settings::get( 'location' ) );
	}

	/**
	 * Whether a type gets a Google Meet link.
	 *
	 * @param array $type Type.
	 * @return bool
	 */
	public static function wants_meet( array $type ) {
		if ( 'on' === $type['meet'] ) {
			return true;
		}
		if ( 'off' === $type['meet'] ) {
			return false;
		}
		return (bool) AAB_Settings::get( 'add_meet' );
	}

	/**
	 * Save the whole list.
	 *
	 * @param array[] $types Types.
	 */
	public static function save_all( array $types ) {
		update_option( self::OPTION, array_values( $types ), false );
		self::$cache = null;
	}

	/**
	 * Insert or replace one type.
	 *
	 * @param array $type Clean type.
	 */
	public static function save( array $type ) {
		$all   = self::all();
		$found = false;
		foreach ( $all as $i => $existing ) {
			if ( $existing['id'] === $type['id'] ) {
				$all[ $i ] = $type;
				$found     = true;
			}
		}
		if ( ! $found ) {
			$all[] = $type;
		}
		self::save_all( $all );
	}

	/**
	 * Delete one type.
	 *
	 * @param string $id Type ID.
	 */
	public static function delete( $id ) {
		self::save_all(
			array_filter(
				self::all(),
				static function ( $t ) use ( $id ) {
					return $t['id'] !== $id;
				}
			)
		);
	}

	/**
	 * Move a type up or down in the list.
	 *
	 * @param string $id        Type ID.
	 * @param int    $direction -1 up, 1 down.
	 */
	public static function move( $id, $direction ) {
		$all = self::all();
		foreach ( $all as $i => $t ) {
			if ( $t['id'] === $id ) {
				$j = $i + ( $direction < 0 ? -1 : 1 );
				if ( isset( $all[ $j ] ) ) {
					$tmp       = $all[ $j ];
					$all[ $j ] = $t;
					$all[ $i ] = $tmp;
					self::save_all( $all );
				}
				return;
			}
		}
	}

	/**
	 * A unique ID made from a name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function unique_id( $name ) {
		$base = sanitize_title( $name );
		$base = '' !== $base ? substr( $base, 0, 40 ) : 'appointment';
		$id   = $base;
		$n    = 2;
		while ( self::get( $id ) ) {
			$id = $base . '-' . $n++;
		}
		return $id;
	}

	/**
	 * Validate and clean raw form input into a type.
	 *
	 * @param array  $in       Raw input (unslashed).
	 * @param string $existing Existing ID when editing, '' when adding.
	 * @return array{type:array,errors:string[]}
	 */
	public static function clean( array $in, $existing = '' ) {
		$errors = array();
		$t      = self::defaults();

		$t['name'] = sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' );
		if ( '' === $t['name'] ) {
			$errors[] = __( 'Give the appointment type a name.', 'anderson-appointment-booking' );
		}
		$t['id']          = '' !== $existing ? $existing : self::unique_id( $t['name'] );
		$t['description'] = sanitize_textarea_field( isset( $in['description'] ) ? $in['description'] : '' );
		$t['duration']    = max( 5, min( 480, absint( isset( $in['duration'] ) ? $in['duration'] : 30 ) ) );
		$t['interval']    = max( 5, min( 480, absint( isset( $in['interval'] ) ? $in['interval'] : $t['duration'] ) ) );
		$t['location']    = sanitize_text_field( isset( $in['location'] ) ? $in['location'] : '' );
		$t['meet']        = isset( $in['meet'] ) && in_array( $in['meet'], array( 'default', 'on', 'off' ), true ) ? $in['meet'] : 'default';
		$t['notes']       = empty( $in['notes'] ) ? 0 : 1;
		$t['active']      = empty( $in['active'] ) ? 0 : 1;
		$t['price']       = isset( $in['price'] ) ? AAB_Stripe::to_minor( $in['price'], AAB_Payments::currency() ) : 0;

		$t['custom_hours'] = empty( $in['custom_hours'] ) ? 0 : 1;
		foreach ( AAB_Settings::weekdays() as $day ) {
			$raw                 = isset( $in['hours'][ $day ] ) ? sanitize_text_field( $in['hours'][ $day ] ) : '';
			$t['hours'][ $day ] = AAB_Settings::normalize_hours( $raw );
			if ( $t['custom_hours'] && '' !== trim( $raw ) && '' === $t['hours'][ $day ] ) {
				/* translators: %s: weekday */
				$errors[] = sprintf( __( 'The hours for %s could not be read. Use 24-hour ranges like 09:00-12:00, 13:00-17:00.', 'anderson-appointment-booking' ), ucfirst( $day ) );
			}
		}

		$kinds = array_keys( self::question_kinds() );
		$seen  = array();
		$list  = isset( $in['questions'] ) && is_array( $in['questions'] ) ? $in['questions'] : array();
		foreach ( $list as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$label = sanitize_text_field( isset( $q['label'] ) ? $q['label'] : '' );
			if ( '' === $label ) {
				continue;
			}
			$kind = isset( $q['kind'] ) && in_array( $q['kind'], $kinds, true ) ? $q['kind'] : 'text';
			$qid  = isset( $q['id'] ) ? sanitize_key( $q['id'] ) : '';
			if ( '' === $qid || isset( $seen[ $qid ] ) ) {
				$qid = 'q' . substr( md5( $label . wp_rand() ), 0, 8 );
			}
			$seen[ $qid ] = true;
			$options      = array();
			if ( 'select' === $kind ) {
				foreach ( preg_split( '/\r\n|\r|\n/', isset( $q['options'] ) ? (string) $q['options'] : '' ) as $opt ) {
					$opt = sanitize_text_field( $opt );
					if ( '' !== $opt && ! in_array( $opt, $options, true ) ) {
						$options[] = $opt;
					}
				}
				if ( ! $options ) {
					/* translators: %s: question label */
					$errors[] = sprintf( __( 'Add at least one choice to the dropdown “%s”, one per line.', 'anderson-appointment-booking' ), $label );
				}
			}
			$t['questions'][] = array(
				'id'       => $qid,
				'label'    => $label,
				'kind'     => $kind,
				'required' => empty( $q['required'] ) ? 0 : 1,
				'options'  => $options,
			);
			if ( count( $t['questions'] ) >= 20 ) {
				break;
			}
		}

		return array(
			'type'   => $t,
			'errors' => $errors,
		);
	}

	/**
	 * What the widget needs to know about each active type (no private settings).
	 *
	 * @return array[]
	 */
	public static function public_list() {
		$out = array();
		foreach ( self::active() as $t ) {
			// Paid types are hidden while payments aren't set up (the types screen warns about it).
			if ( AAB_Payments::is_paid( $t ) && ! AAB_Payments::ready() ) {
				continue;
			}
			$out[] = self::public_type( $t );
		}
		return $out;
	}

	/**
	 * Types visitors can actually book right now (visible, and payable if paid).
	 *
	 * @return array[]
	 */
	public static function bookable() {
		return array_values(
			array_filter(
				self::active(),
				static function ( $t ) {
					return ! AAB_Payments::is_paid( $t ) || AAB_Payments::ready();
				}
			)
		);
	}

	/**
	 * Public view of one type.
	 *
	 * @param array $t Type.
	 * @return array
	 */
	public static function public_type( array $t ) {
		$questions = array();
		foreach ( $t['questions'] as $q ) {
			$questions[] = array(
				'id'       => $q['id'],
				'label'    => $q['label'],
				'kind'     => $q['kind'],
				'required' => (bool) $q['required'],
				'options'  => array_values( $q['options'] ),
			);
		}
		return array(
			'id'          => $t['id'],
			'name'        => $t['name'],
			'description' => $t['description'],
			'duration'    => (int) $t['duration'],
			'notes'       => (bool) $t['notes'],
			'video'       => self::wants_meet( $t ),
			'location'    => self::location( $t ),
			'questions'   => $questions,
			'price'       => AAB_Payments::price( $t ),
			'priceText'   => AAB_Payments::price( $t ) ? AAB_Stripe::format( AAB_Payments::price( $t ), AAB_Payments::currency() ) : '',
		);
	}

	/**
	 * Validate a visitor's answers against a type's questions.
	 *
	 * @param array $type    Type.
	 * @param mixed $answers Raw answers keyed by question ID.
	 * @return array{answers:array<int,array{label:string,value:string}>,errors:array<string,string>}
	 */
	public static function check_answers( array $type, $answers ) {
		$answers = is_array( $answers ) ? $answers : array();
		$out     = array();
		$errors  = array();
		foreach ( $type['questions'] as $q ) {
			$raw = isset( $answers[ $q['id'] ] ) ? $answers[ $q['id'] ] : '';
			if ( 'checkbox' === $q['kind'] ) {
				$on = ! empty( $raw ) && 'false' !== $raw && '0' !== $raw;
				if ( $q['required'] && ! $on ) {
					$errors[ 'q_' . $q['id'] ] = __( 'Check the box to continue.', 'anderson-appointment-booking' );
				}
				$value = $on ? __( 'Yes', 'anderson-appointment-booking' ) : __( 'No', 'anderson-appointment-booking' );
			} else {
				$value = 'textarea' === $q['kind'] ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
				if ( mb_strlen( $value ) > 2000 ) {
					$errors[ 'q_' . $q['id'] ] = __( 'Keep this under 2,000 characters.', 'anderson-appointment-booking' );
				} elseif ( 'select' === $q['kind'] && '' !== $value && ! in_array( $value, $q['options'], true ) ) {
					$errors[ 'q_' . $q['id'] ] = __( 'Choose one of the options.', 'anderson-appointment-booking' );
				} elseif ( $q['required'] && '' === trim( $value ) ) {
					$errors[ 'q_' . $q['id'] ] = 'select' === $q['kind'] ? __( 'Choose one of the options.', 'anderson-appointment-booking' ) : __( 'Answer this question.', 'anderson-appointment-booking' );
				}
			}
			$out[] = array(
				'label' => $q['label'],
				'value' => $value,
			);
		}
		return array(
			'answers' => $out,
			'errors'  => $errors,
		);
	}
}
