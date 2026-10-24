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
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cancel_page' ) );
	}

	/**
	 * Register scripts and styles (enqueued only where the widget appears).
	 */
	public static function register_assets() {
		wp_register_style( 'aab-booking', AAB_URL . 'assets/css/booking.css', array(), AAB_VERSION );
		wp_register_script( 'aab-booking', AAB_URL . 'assets/js/booking.js', array(), AAB_VERSION, true );
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			AAB_DIR . 'blocks/booking',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$heading = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
		$html    = self::render( array( 'heading' => $heading ) );
		return function_exists( 'get_block_wrapper_attributes' )
			? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>'
			: $html;
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'heading' => '' ),
			is_array( $atts ) ? $atts : array(),
			'anderson_booking'
		);
		return self::render( $atts );
	}

	/**
	 * Widget markup. The script builds the calendar inside it; without JavaScript a short message shows.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render( array $atts ) {
		++self::$instance;
		wp_enqueue_style( 'aab-booking' );
		wp_enqueue_script( 'aab-booking' );

		$accent = sanitize_hex_color( (string) AAB_Settings::get( 'accent' ) );
		$config = array(
			'rest'      => esc_url_raw( rest_url( AAB_Rest::NS . '/' ) ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'weekStart' => (int) get_option( 'start_of_week', 1 ),
			'consent'   => (string) AAB_Settings::get( 'consent_text' ),
			'heading'   => (string) $atts['heading'],
			'i18n'      => self::strings(),
		);

		$style = $accent ? '--aab-accent:' . $accent . ';' : '';

		return sprintf(
			'<div class="aab" id="aab-%1$d" style="%2$s" data-aab-config="%3$s"><noscript>%4$s</noscript></div>',
			self::$instance,
			esc_attr( $style ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Turn on JavaScript to book an appointment.', 'anderson-appointment-booking' )
		);
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
			/* translators: %s: appointment start time */
			'book'         => __( 'Book %s', 'anderson-appointment-booking' ),
			'booking'      => __( 'Booking…', 'anderson-appointment-booking' ),
			'booked'       => __( 'Booked', 'anderson-appointment-booking' ),
			'joinCall'     => __( 'Video call link', 'anderson-appointment-booking' ),
			'bookAnother'  => __( 'Book another time', 'anderson-appointment-booking' ),
			'error'        => __( 'Something went wrong. Check your connection and try again.', 'anderson-appointment-booking' ),
			'retry'        => __( 'Try again', 'anderson-appointment-booking' ),
			'website'      => __( 'Leave this field empty', 'anderson-appointment-booking' ),
		);
	}

	/**
	 * Visitor cancel page: ?aab_cancel=ID&aab_token=TOKEN.
	 *
	 * GET shows a confirmation button (so link scanners in mail apps can't cancel); POST cancels.
	 */
	public static function cancel_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the emailed token authenticates the visitor.
		if ( ! isset( $_GET['aab_cancel'], $_GET['aab_token'] ) ) {
			return;
		}
		$id      = absint( $_GET['aab_cancel'] );
		$token   = sanitize_text_field( wp_unslash( $_GET['aab_token'] ) );
		$booking = AAB_Bookings::get( $id );
		$valid   = $booking && AAB_Bookings::verify_token( $id, $token );
		$posted  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['aab_confirm_cancel'] );
		// phpcs:enable

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$title = __( 'Cancel appointment', 'anderson-appointment-booking' );
		$body  = '';

		if ( $booking && 'cancelled' === $booking['status'] ) {
			$body = '<p>' . esc_html__( 'This appointment is already cancelled.', 'anderson-appointment-booking' ) . '</p>';
		} elseif ( ! $valid ) {
			$body = '<p>' . esc_html__( 'This cancel link is not valid. Reply to your confirmation email and we’ll sort it out.', 'anderson-appointment-booking' ) . '</p>';
		} elseif ( $booking['start'] <= time() ) {
			$body = '<p>' . esc_html__( 'This appointment has already started, so it can’t be cancelled online.', 'anderson-appointment-booking' ) . '</p>';
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
		} else {
			/* translators: 1: name, 2: date and time */
			$body = '<p>' . esc_html( sprintf( __( '%1$s, your appointment is on %2$s.', 'anderson-appointment-booking' ), $booking['name'], AAB_Bookings::format_time( $booking['start'] ) ) ) . '</p>'
				. '<form method="post"><input type="hidden" name="aab_confirm_cancel" value="1">'
				. '<button type="submit" class="aab-cancel__button">' . esc_html__( 'Cancel appointment', 'anderson-appointment-booking' ) . '</button></form>'
				. '<p class="aab-cancel__keep"><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Keep it and go back to the site', 'anderson-appointment-booking' ) . '</a></p>';
		}

		$accent = sanitize_hex_color( (string) AAB_Settings::get( 'accent' ) );
		status_header( 200 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' – ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( AAB_URL . 'assets/css/booking.css?ver=' . AAB_VERSION ); ?>"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page without wp_head(). ?>
</head>
<body class="aab-cancel-page">
<main class="aab aab-cancel" style="<?php echo esc_attr( $accent ? '--aab-accent:' . $accent : '' ); ?>">
<p class="aab-cancel__site"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></p>
<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part of $body is escaped where it is built above. ?>
</main>
</body>
</html>
		<?php
		exit;
	}
}
