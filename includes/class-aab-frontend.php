<?php
/**
 * Front end: shortcode, block and the visitor cancel page.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public-facing output.
 */
final class AAB_Frontend {

	/**
	 * Counter for unique widget IDs.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'anderson_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'template_redirect', array( __CLASS__, 'manage_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'preview_page' ) );
	}

	/**
	 * Style keys that a block or shortcode may override, mapped to their setting.
	 *
	 * @return array<string,string> attribute => setting key
	 */
	public static function style_attributes() {
		return array(
			'buttons'  => 'style_buttons',
			'radius'   => 'style_radius',
			'density'  => 'style_density',
			'headings' => 'style_headings',
		);
	}

	/**
	 * Register scripts and styles (enqueued only where the widget appears).
	 */
	public static function register_assets() {
		wp_register_style( 'aab-booking', AAB_URL . 'assets/css/booking.css', array(), AAB_VERSION );
		wp_register_script( 'aab-booking', AAB_URL . 'assets/js/booking.js', array(), AAB_VERSION, true );

		$css = AAB_Style::custom_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'aab-booking', $css );
		}
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$block = register_block_type(
			AAB_DIR . 'blocks/booking',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
		if ( $block && ! empty( $block->editor_script_handles ) ) {
			add_action(
				'enqueue_block_editor_assets',
				static function () use ( $block ) {
					wp_add_inline_script(
						$block->editor_script_handles[0],
						'window.aabEditor = ' . wp_json_encode( self::editor_data() ) . ';',
						'before'
					);
				}
			);
		}
	}

	/**
	 * Data the block editor needs to draw a live, styled preview.
	 *
	 * @return array
	 */
	public static function editor_data() {
		$resolved = AAB_Style::resolve();
		$tokens   = AAB_Style::theme_tokens();
		return array(
			'config'   => self::config( array( 'heading' => '' ), true ),
			'types'    => array_map(
				static function ( $t ) {
					return array(
						'id'   => $t['id'],
						'name' => $t['name'],
					);
				},
				AAB_Types::all()
			),
			'vars'     => $resolved['vars'],
			'classes'  => $resolved['classes'],
			'radii'    => AAB_Style::radii(),
			'spaces'   => AAB_Style::densities(),
			'theme'    => array(
				'radius'      => $tokens['radius'],
				'headingFont' => $tokens['heading_font'],
			),
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$atts = array(
			'heading' => isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '',
			'type'    => isset( $attributes['type'] ) ? (string) $attributes['type'] : '',
		);
		foreach ( array_keys( self::style_attributes() ) as $key ) {
			$atts[ $key ] = isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';
		}
		$atts['accent']     = isset( $attributes['accent'] ) ? (string) $attributes['accent'] : '';
		$atts['background'] = isset( $attributes['surface'] ) ? (string) $attributes['surface'] : '';

		$html = self::render( $atts );
		return function_exists( 'get_block_wrapper_attributes' )
			? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>'
			: $html;
	}

	/**
	 * Shortcode callback.
	 *
	 * Style attributes are optional and override Appointments → Settings → Appearance:
	 * [anderson_booking heading="Book a call" accent="#0b5fff" buttons="plugin" radius="round" density="compact" background="#ffffff" headings="inherit"]
	 * Show one appointment type only with type="its-id" (IDs are listed under Appointments → Appointment types).
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'heading'    => '',
				'type'       => '',
				'accent'     => '',
				'background' => '',
				'buttons'    => '',
				'radius'     => '',
				'density'    => '',
				'headings'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'anderson_booking'
		);
		return self::render( $atts );
	}

	/**
	 * Script configuration for one widget.
	 *
	 * @param array $atts Attributes.
	 * @param bool  $demo Use sample times instead of live data (previews).
	 * @return array
	 */
	private static function config( array $atts, $demo = false ) {
		$types = AAB_Types::public_list();
		$only  = isset( $atts['type'] ) ? sanitize_key( (string) $atts['type'] ) : '';
		if ( '' !== $only ) {
			$match = array_values(
				array_filter(
					$types,
					static function ( $t ) use ( $only ) {
						return $t['id'] === $only;
					}
				)
			);
			// An unknown or hidden type falls back to showing all types rather than an empty widget.
			$types = $match ? $match : $types;
		}
		$first = $types ? $types[0] : null;
		return array(
			'rest'      => esc_url_raw( rest_url( AAB_Rest::NS . '/' ) ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'weekStart' => (int) get_option( 'start_of_week', 1 ),
			'consent'   => (string) AAB_Settings::get( 'consent_text' ),
			'phone'     => (string) AAB_Settings::get( 'phone_field' ),
			'heading'   => isset( $atts['heading'] ) ? (string) $atts['heading'] : '',
			'types'     => $types,
			'duration'  => $first ? $first['duration'] : (int) AAB_Settings::get( 'duration' ),
			'message'   => (string) AAB_Settings::get( 'confirmation_message' ),
			'demo'      => (bool) $demo,
			'i18n'      => self::strings(),
		);
	}

	/**
	 * Widget markup. The script builds the calendar inside it; without JavaScript a short message shows.
	 *
	 * @param array $atts Attributes: heading, plus optional style overrides (accent, background, buttons, radius, density, headings).
	 * @param bool  $demo  Render with sample times (used by the settings preview).
	 * @param array $extra Extra script configuration (e.g. rescheduling).
	 * @return string
	 */
	public static function render( array $atts, $demo = false, array $extra = array() ) {
		++self::$instance;

		/**
		 * Whether to load the plugin's stylesheet. Return false to style the widget entirely from your theme.
		 *
		 * @param bool $load Load it.
		 */
		if ( apply_filters( 'aab_enqueue_styles', true ) ) {
			wp_enqueue_style( 'aab-booking' );
		}
		wp_enqueue_script( 'aab-booking' );

		$overrides = array();
		foreach ( self::style_attributes() as $attr => $setting ) {
			if ( ! empty( $atts[ $attr ] ) ) {
				$overrides[ $setting ] = $atts[ $attr ];
			}
		}
		if ( ! empty( $atts['accent'] ) ) {
			$overrides['accent'] = $atts['accent'];
		}
		if ( ! empty( $atts['background'] ) ) {
			if ( in_array( $atts['background'], array( 'none', 'theme' ), true ) ) {
				$overrides['style_background'] = $atts['background'];
			} else {
				$overrides['background_color'] = $atts['background'];
			}
		}

		$style = AAB_Style::resolve( $overrides );

		return sprintf(
			'<div class="%1$s" id="aab-%2$d" style="%3$s" data-aab-config="%4$s"><noscript>%5$s</noscript></div>',
			esc_attr( implode( ' ', array_merge( array( 'aab' ), $style['classes'] ) ) ),
			self::$instance,
			esc_attr( AAB_Style::inline( $style['vars'] ) ),
			esc_attr( wp_json_encode( array_merge( self::config( $atts, $demo ), $extra ) ) ),
			esc_html__( 'Turn on JavaScript to book an appointment.', 'anderson-appointment-booking' )
		);
	}

	/**
	 * Appearance preview: the widget with sample times, rendered inside the site's own theme
	 * (its fonts, colors and buttons). Loaded in an iframe on the settings screen; admins only.
	 */
	public static function preview_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, capability-checked preview.
		if ( ! isset( $_GET['aab_preview'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this preview.', 'anderson-appointment-booking' ), 403 );
		}
		nocache_headers();
		show_admin_bar( false );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		$widget = self::render( array( 'heading' => '' ), true );
		$bodyc  = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? 'aab-preview wp-site-blocks' : 'aab-preview';
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
<style>
	html { margin-top: 0 !important; }
	body.aab-preview-body { margin: 0; }
	.aab-preview { padding: 8px 24px 32px; }
	.aab-preview .aab { margin-top: 0; }
</style>
</head>
<body <?php body_class( 'aab-preview-body' ); ?>>
<div class="<?php echo esc_attr( $bodyc ); ?>">
<main class="entry-content is-layout-constrained">
		<?php echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in render(). ?>
</main>
</div>
<script>
/* Live updates from the settings screen (same origin only). */
window.addEventListener( 'message', function ( e ) {
	if ( e.origin !== window.location.origin || ! e.data || e.data.type !== 'aab-style' ) { return; }
	var el = document.querySelector( '.aab' );
	if ( ! el ) { return; }
	el.style.cssText = e.data.style;
	el.className = 'aab aab--ready ' + e.data.classes.join( ' ' );
	var submit = el.querySelector( '.aab__submit' );
	if ( submit ) { submit.classList.toggle( 'wp-element-button', e.data.classes.indexOf( 'aab--buttons-theme' ) !== -1 && e.data.classes.indexOf( 'aab--wp-buttons' ) !== -1 ); }
	if ( window.AABBooking ) { window.AABBooking.probe( el ); }
	if ( typeof e.data.css !== 'string' ) { return; }
	var css = document.getElementById( 'aab-preview-custom-css' );
	if ( ! css ) { css = document.createElement( 'style' ); css.id = 'aab-preview-custom-css'; document.head.appendChild( css ); }
	var inline = document.getElementById( 'aab-booking-inline-css' );
	if ( inline ) { inline.disabled = true; }
	css.textContent = e.data.css || '';
} );
</script>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Translatable UI strings for the script.
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'pickDay'      => __( 'Pick a day', 'anderson-appointment-booking' ),
			'pickTime'     => __( 'Pick a time', 'anderson-appointment-booking' ),
			'prevMonth'    => __( 'Previous month', 'anderson-appointment-booking' ),
			'nextMonth'    => __( 'Next month', 'anderson-appointment-booking' ),
			'loading'      => __( 'Loading open times…', 'anderson-appointment-booking' ),
			'noDay'        => __( 'Choose a highlighted day to see open times.', 'anderson-appointment-booking' ),
			'noTimes'      => __( 'No open times this month. Try the next month.', 'anderson-appointment-booking' ),
			'dayFull'      => __( 'No open times left on this day.', 'anderson-appointment-booking' ),
			/* translators: %d: number of open times */
			'openCount'    => __( '%d open times', 'anderson-appointment-booking' ),
			'openOne'      => __( '1 open time', 'anderson-appointment-booking' ),
			/* translators: %s: timezone name */
			'timezone'     => __( 'Times are shown in your timezone, %s.', 'anderson-appointment-booking' ),
			/* translators: %d: appointment length in minutes */
			'minutes'      => __( '%d min', 'anderson-appointment-booking' ),
			'changeTime'   => __( 'Change time', 'anderson-appointment-booking' ),
			'name'         => __( 'Your name', 'anderson-appointment-booking' ),
			'email'        => __( 'Email', 'anderson-appointment-booking' ),
			'notes'        => __( 'Anything I should know? (optional)', 'anderson-appointment-booking' ),
			'needName'     => __( 'Enter your name.', 'anderson-appointment-booking' ),
			'needEmail'    => __( 'Enter a valid email address.', 'anderson-appointment-booking' ),
			'needConsent'  => __( 'Check the box to continue.', 'anderson-appointment-booking' ),
			'phoneLabel'   => __( 'Phone', 'anderson-appointment-booking' ),
			'phoneOpt'     => __( 'Phone (optional)', 'anderson-appointment-booking' ),
			'needPhone'    => __( 'Enter your phone number.', 'anderson-appointment-booking' ),
			/* translators: %s: appointment start time */
			'book'         => __( 'Book %s', 'anderson-appointment-booking' ),
			'booking'      => __( 'Booking…', 'anderson-appointment-booking' ),
			'booked'       => __( 'Booked', 'anderson-appointment-booking' ),
			'joinCall'     => __( 'Video call link', 'anderson-appointment-booking' ),
			'bookAnother'  => __( 'Book another time', 'anderson-appointment-booking' ),
			'error'        => __( 'Something went wrong. Check your connection and try again.', 'anderson-appointment-booking' ),
			'retry'        => __( 'Try again', 'anderson-appointment-booking' ),
			'website'      => __( 'Leave this field empty', 'anderson-appointment-booking' ),
			'pickType'     => __( 'What would you like to book?', 'anderson-appointment-booking' ),
			'allTypes'     => __( 'All appointment types', 'anderson-appointment-booking' ),
			'video'        => __( 'Video call', 'anderson-appointment-booking' ),
			'needAnswer'   => __( 'Answer this question.', 'anderson-appointment-booking' ),
			'needChoice'   => __( 'Choose one of the options.', 'anderson-appointment-booking' ),
			'optional'     => __( '(optional)', 'anderson-appointment-booking' ),
			'choose'       => __( 'Choose…', 'anderson-appointment-booking' ),
			'currentTime'  => __( 'Your current time', 'anderson-appointment-booking' ),
			/* translators: %s: new appointment time */
			'moveTo'       => __( 'Move to %s', 'anderson-appointment-booking' ),
			'moving'       => __( 'Moving…', 'anderson-appointment-booking' ),
			'moved'        => __( 'Moved', 'anderson-appointment-booking' ),
			'movedText'    => __( 'Your appointment has a new time. A confirmation is on its way to your inbox.', 'anderson-appointment-booking' ),
			'backToBooking' => __( 'Back to your appointment', 'anderson-appointment-booking' ),
		);
	}

	/**
	 * Visitor manage page: ?aab_manage=ID&aab_token=TOKEN (older emails used ?aab_cancel=).
	 *
	 * Shows the appointment with Reschedule and Cancel. Cancelling is a POST behind a
	 * confirmation button, so link scanners in mail apps can't cancel by visiting the link.
	 * The page loads the theme's head so it looks like the rest of the site.
	 */
	public static function manage_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the emailed token authenticates the visitor.
		$id_raw = isset( $_GET['aab_manage'] ) ? $_GET['aab_manage'] : ( isset( $_GET['aab_cancel'] ) ? $_GET['aab_cancel'] : null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint below.
		if ( null === $id_raw || ! isset( $_GET['aab_token'] ) ) {
			return;
		}
		$id      = absint( $id_raw );
		$token   = sanitize_text_field( wp_unslash( $_GET['aab_token'] ) );
		$view    = isset( $_GET['aab_do'] ) ? sanitize_key( $_GET['aab_do'] ) : ( isset( $_GET['aab_cancel'] ) ? 'cancel' : '' );
		$posted  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['aab_confirm_cancel'] );
		// phpcs:enable
		$booking = AAB_Bookings::get( $id );
		$valid   = $booking && AAB_Bookings::verify_token( $id, $token );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$base   = add_query_arg(
			array(
				'aab_manage' => $id,
				'aab_token'  => rawurlencode( $token ),
			),
			home_url( '/' )
		);
		$themed = 'theme' === AAB_Settings::get( 'style_buttons' ) && 'theme.json' === AAB_Style::theme_tokens()['source'] ? ' wp-element-button' : '';
		$title  = __( 'Your appointment', 'anderson-appointment-booking' );
		$body   = '';
		$wide   = false;

		if ( $booking && 'cancelled' === $booking['status'] && $valid ) {
			$body = '<p>' . esc_html__( 'This appointment is cancelled.', 'anderson-appointment-booking' ) . '</p>';
		} elseif ( ! $valid ) {
			// Cancelled bookings drop their token, so an old link lands here too.
			$body = '<p>' . esc_html__( 'This link is no longer valid. If you cancelled, nothing else is needed. Otherwise reply to your confirmation email and we’ll sort it out.', 'anderson-appointment-booking' ) . '</p>';
		} else {
			$can = AAB_Bookings::can_change( $booking );
			if ( is_wp_error( $can ) ) {
				$body = self::summary_html( $booking ) . '<p>' . esc_html( $can->get_error_message() ) . '</p>';
			} elseif ( $posted ) {
				$result = AAB_Bookings::cancel( $id, 'visitor' );
				if ( is_wp_error( $result ) ) {
					$body = '<p>' . esc_html__( 'The appointment could not be cancelled right now. Try again in a few minutes, or reply to your confirmation email.', 'anderson-appointment-booking' ) . '</p>';
				} else {
					$title = __( 'Appointment cancelled', 'anderson-appointment-booking' );
					/* translators: %s: date and time */
					$body = '<p>' . esc_html( sprintf( __( 'Your appointment on %s is cancelled. You’ll get an email confirming it.', 'anderson-appointment-booking' ), AAB_Bookings::format_time( $booking['start'] ) ) ) . '</p>'
						. '<p><a class="aab-cancel__home" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the site', 'anderson-appointment-booking' ) . '</a></p>';
				}
			} elseif ( 'cancel' === $view ) {
				$title = __( 'Cancel appointment', 'anderson-appointment-booking' );
				$body  = self::summary_html( $booking )
					. '<form method="post" action="' . esc_url( add_query_arg( 'aab_do', 'cancel', $base ) ) . '"><input type="hidden" name="aab_confirm_cancel" value="1">'
					. '<button type="submit" class="aab-cancel__button' . $themed . '">' . esc_html__( 'Yes, cancel it', 'anderson-appointment-booking' ) . '</button></form>'
					. '<p class="aab-cancel__keep"><a href="' . esc_url( $base ) . '">' . esc_html__( 'Keep it', 'anderson-appointment-booking' ) . '</a></p>';
			} elseif ( 'reschedule' === $view ) {
				$title = __( 'Pick a new time', 'anderson-appointment-booking' );
				$wide  = true;
				$type  = AAB_Bookings::type_of( $booking );
				$body  = self::summary_html( $booking, true )
					. self::render(
						array( 'heading' => '' ),
						false,
						array(
							'types'      => array( AAB_Types::public_type( $type ) ),
							'duration'   => (int) $type['duration'],
							'reschedule' => array(
								'id'     => $id,
								'token'  => $token,
								'start'  => $booking['start'],
								'manage' => $base,
							),
						)
					)
					. '<p class="aab-cancel__keep"><a href="' . esc_url( $base ) . '">' . esc_html__( 'Keep the current time', 'anderson-appointment-booking' ) . '</a></p>';
			} else {
				$limit = (int) AAB_Settings::get( 'max_reschedules' );
				$body  = self::summary_html( $booking ) . '<div class="aab-manage__actions">';
				if ( ! $limit || $booking['reschedules'] < $limit ) {
					$body .= '<a class="aab-cancel__button aab-manage__primary' . $themed . '" href="' . esc_url( add_query_arg( 'aab_do', 'reschedule', $base ) ) . '">' . esc_html__( 'Reschedule', 'anderson-appointment-booking' ) . '</a>';
				}
				$body .= '<a class="aab-manage__secondary" href="' . esc_url( add_query_arg( 'aab_do', 'cancel', $base ) ) . '">' . esc_html__( 'Cancel appointment', 'anderson-appointment-booking' ) . '</a></div>';
			}
		}

		$style = AAB_Style::resolve();
		wp_enqueue_style( 'aab-booking' );
		show_admin_bar( false );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		status_header( 200 );
		$classes = array_merge( array( 'aab', 'aab-cancel', 'aab-manage' ), $wide ? array( 'aab-manage--wide' ) : array(), $style['classes'] );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); // Loads the theme's fonts, colors and button styles so the page looks like the site. ?>
<style>html { margin-top: 0 !important; }</style>
</head>
<body <?php body_class( 'aab-cancel-page' ); ?>>
<main class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( AAB_Style::inline( $style['vars'] ) ); ?>">
<p class="aab-cancel__site"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></p>
<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part of $body is escaped where it is built above. ?>
</main>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The appointment summary block on the manage page.
	 *
	 * @param array $booking Booking.
	 * @param bool  $compact One line ("Currently: …").
	 * @return string
	 */
	private static function summary_html( array $booking, $compact = false ) {
		$when = AAB_Bookings::format_time( $booking['start'] ) . ' – ' . wp_date( get_option( 'time_format' ), $booking['end'] );
		if ( $compact ) {
			/* translators: %s: current date and time */
			return '<p class="aab-manage__current">' . esc_html( sprintf( __( 'Currently: %s', 'anderson-appointment-booking' ), $when ) ) . '</p>';
		}
		$rows = array();
		if ( '' !== $booking['type_name'] ) {
			$rows[ __( 'What', 'anderson-appointment-booking' ) ] = $booking['type_name'];
		}
		$rows[ __( 'When', 'anderson-appointment-booking' ) ] = $when . ' (' . wp_timezone_string() . ')';
		if ( '' !== $booking['location'] ) {
			$rows[ __( 'Where', 'anderson-appointment-booking' ) ] = $booking['location'];
		}
		$html = '<dl class="aab-manage__details">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		if ( '' !== $booking['meet_link'] && 'confirmed' === $booking['status'] ) {
			$html .= '<dt>' . esc_html__( 'Video call', 'anderson-appointment-booking' ) . '</dt><dd><a href="' . esc_url( $booking['meet_link'] ) . '" rel="noopener">' . esc_html( $booking['meet_link'] ) . '</a></dd>';
		}
		return $html . '</dl>';
	}
}
