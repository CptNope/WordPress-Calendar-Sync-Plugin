<?php
/**
 * Admin screens: settings, Google connection, bookings list.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin integration.
 */
final class AAB_Admin {

	const SLUG = 'aab-settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notice' ) );
		add_action( 'admin_post_aab_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aab_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_aab_oauth', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_aab_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_aab_cancel_booking', array( __CLASS__, 'cancel_booking' ) );
		add_action( 'admin_post_aab_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_aab_ms_connect', array( __CLASS__, 'ms_connect' ) );
		add_action( 'admin_post_aab_ms_oauth', array( __CLASS__, 'ms_oauth' ) );
		add_action( 'admin_post_aab_ms_disconnect', array( __CLASS__, 'ms_disconnect' ) );
		add_action( 'admin_post_aab_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_post_aab_run_now', array( __CLASS__, 'run_now' ) );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'export_button' ) );
		add_filter( 'manage_' . AAB_Bookings::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . AAB_Bookings::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . AAB_Bookings::POST_TYPE, array( __CLASS__, 'meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AAB_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Appointments', 'anderson-appointment-booking' ),
			__( 'Appointments', 'anderson-appointment-booking' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page( self::SLUG, __( 'Appointment settings', 'anderson-appointment-booking' ), __( 'Settings', 'anderson-appointment-booking' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'anderson-appointment-booking' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Admin CSS/JS on our screen only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && AAB_Bookings::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'aab-admin', AAB_URL . 'assets/css/admin.css', array(), AAB_VERSION );
			return;
		}
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'aab-admin', AAB_URL . 'assets/css/admin.css', array(), AAB_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'aab-admin', AAB_URL . 'assets/js/admin.js', array( 'wp-color-picker' ), AAB_VERSION, true );
		$tokens = AAB_Style::theme_tokens();
		wp_localize_script(
			'aab-admin',
			'aabAdmin',
			array(
				'tokens'    => array(
					'accent'      => $tokens['accent'],
					'onAccent'    => $tokens['on_accent'],
					'base'        => $tokens['base'],
					'radius'      => $tokens['radius'],
					'headingFont' => $tokens['heading_font'],
					'json'        => 'theme.json' === $tokens['source'],
				),
				'radii'     => AAB_Style::radii(),
				'densities' => AAB_Style::densities(),
				'origin'    => untrailingslashit( home_url() ),
				'copied'    => __( 'Copied', 'anderson-appointment-booking' ),
			)
		);
	}

	/**
	 * Warn everywhere in wp-admin if Google access broke.
	 */
	public static function global_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$cancelled = isset( $_GET['aab_cancelled'] ) ? sanitize_key( $_GET['aab_cancelled'] ) : '';
		if ( 'ok' === $cancelled ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Appointment cancelled and removed from Google Calendar.', 'anderson-appointment-booking' ) . '</p></div>';
		} elseif ( 'error' === $cancelled ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The appointment could not be removed from Google Calendar, so it was not cancelled. Check the Google connection and try again.', 'anderson-appointment-booking' ) . '</p></div>';
		}

		if ( get_option( AAB_Microsoft::ERROR_OPTION ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Appointment booking is offline.', 'anderson-appointment-booking' ),
				esc_html__( 'Outlook calendar access expired, and availability can’t be checked without it.', 'anderson-appointment-booking' ),
				esc_url( self::page_url() . '#aab-outlook' ),
				esc_html__( 'Reconnect Outlook', 'anderson-appointment-booking' )
			);
		}
		$error = get_option( AAB_Google::ERROR_OPTION );
		if ( ! $error ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Appointment booking is offline.', 'anderson-appointment-booking' ),
			esc_html__( 'Google Calendar access was revoked or expired, so visitors cannot book.', 'anderson-appointment-booking' ),
			esc_url( self::page_url() ),
			esc_html__( 'Reconnect Google Calendar', 'anderson-appointment-booking' )
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Anderson Appointment Booking', 'anderson-appointment-booking' ),
			wp_kses_post( wpautop( __( 'When you book an appointment, we store the name, email address and notes you enter, together with the time you chose. This information is added to our Google Calendar as an event (Google acts as our calendar provider) and is used to confirm, run and if needed cancel your appointment. You receive a confirmation email with a link to cancel.', 'anderson-appointment-booking' ) ) )
		);
	}

	/**
	 * Redirect back to settings with a notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function back( $type, $message ) {
		set_transient(
			'aab_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Abort unless the current user may manage the plugin and the nonce matches.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'anderson-appointment-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		self::guard( 'aab_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$in  = isset( $_POST['aab'] ) && is_array( $_POST['aab'] ) ? wp_unslash( $_POST['aab'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$cur = AAB_Settings::all();
		$new = $cur;

		$int = static function ( $key, $min, $max ) use ( $in, $cur ) {
			if ( ! isset( $in[ $key ] ) ) {
				return $cur[ $key ];
			}
			return max( $min, min( $max, absint( $in[ $key ] ) ) );
		};

		if ( isset( $in['client_id'] ) ) {
			$new['client_id'] = sanitize_text_field( $in['client_id'] );
		}
		if ( ! empty( $in['client_secret'] ) ) {
			$new['client_secret'] = AAB_Crypto::encrypt( sanitize_text_field( $in['client_secret'] ) );
		}
		$credentials_changed = $new['client_id'] !== $cur['client_id'] || ! empty( $in['client_secret'] );

		if ( isset( $in['booking_calendar'] ) ) {
			$new['booking_calendar'] = sanitize_text_field( $in['booking_calendar'] );
		}
		if ( isset( $in['calendars_present'] ) ) {
			$new['conflict_calendars'] = isset( $in['conflict_calendars'] ) && is_array( $in['conflict_calendars'] )
				? array_values( array_map( 'sanitize_text_field', $in['conflict_calendars'] ) )
				: array();
		}

		$invalid_hours = array();
		foreach ( AAB_Settings::weekdays() as $key ) {
			if ( ! isset( $in['hours'][ $key ] ) ) {
				continue;
			}
			$raw                   = sanitize_text_field( $in['hours'][ $key ] );
			$new['hours'][ $key ]  = AAB_Settings::normalize_hours( $raw );
			if ( '' !== trim( $raw ) && '' === $new['hours'][ $key ] ) {
				$invalid_hours[] = $key;
			}
		}

		$new['duration']      = $int( 'duration', 5, 480 );
		$new['interval']      = $int( 'interval', 5, 480 );
		$new['buffer_before'] = $int( 'buffer_before', 0, 240 );
		$new['buffer_after']  = $int( 'buffer_after', 0, 240 );
		$new['min_notice']    = $int( 'min_notice', 0, 720 );
		$new['max_days']      = $int( 'max_days', 1, 365 );
		$new['daily_limit']   = $int( 'daily_limit', 0, 100 );
		$new['max_per_email'] = $int( 'max_per_email', 0, 50 );
		$new['change_cutoff'] = $int( 'change_cutoff', 0, 720 );
		$new['max_reschedules'] = $int( 'max_reschedules', 0, 20 );
		$new['reminder_hours'] = $int( 'reminder_hours', 0, 168 );
		$new['retention_days'] = $int( 'retention_days', 0, 3650 );

		$invalid_days = array();
		if ( isset( $in['days_off'] ) ) {
			$new['days_off'] = sanitize_textarea_field( $in['days_off'] );
			$parsed          = AAB_Settings::parse_days_off( $new['days_off'] );
			$invalid_days    = $parsed['invalid'];
		}
		if ( isset( $in['phone_field'] ) ) {
			$new['phone_field'] = in_array( $in['phone_field'], array( 'off', 'optional', 'required' ), true ) ? $in['phone_field'] : 'off';
		}

		foreach ( array( 'event_title', 'location' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = sanitize_text_field( $in[ $key ] );
			}
		}
		foreach ( array( 'confirmation_message', 'consent_text' ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = sanitize_textarea_field( $in[ $key ] );
			}
		}
		if ( isset( $in['notify_email'] ) ) {
			$email               = sanitize_email( $in['notify_email'] );
			$new['notify_email'] = is_email( $email ) ? $email : get_option( 'admin_email' );
		}
		if ( isset( $in['accent'] ) ) {
			$color         = sanitize_hex_color( $in['accent'] );
			$new['accent'] = $color ? $color : $cur['accent'];
		}
		foreach ( array_keys( AAB_Style::choices() ) as $key ) {
			if ( isset( $in[ $key ] ) ) {
				$new[ $key ] = AAB_Style::clean( $key, $in[ $key ] );
			}
		}
		if ( isset( $in['style_bg_color'] ) ) {
			$color                 = sanitize_hex_color( $in['style_bg_color'] );
			$new['style_bg_color'] = $color ? $color : $cur['style_bg_color'];
		}
		if ( ! empty( $in['stripe_clear'] ) ) {
			$new['stripe_secret']         = '';
			$new['stripe_webhook_secret'] = '';
		} else {
			foreach ( array( 'stripe_secret', 'stripe_webhook_secret' ) as $key ) {
				if ( ! empty( $in[ $key ] ) ) {
					$new[ $key ] = AAB_Crypto::encrypt( trim( sanitize_text_field( $in[ $key ] ) ) );
				}
			}
		}
		if ( isset( $in['currency'] ) && preg_match( '/^[A-Za-z]{3}$/', trim( $in['currency'] ) ) ) {
			$new['currency'] = strtolower( trim( $in['currency'] ) );
		}
		$new['refund_cutoff'] = $int( 'refund_cutoff', 0, 720 );
		if ( isset( $in['ms_client_id'] ) ) {
			$new['ms_client_id'] = sanitize_text_field( $in['ms_client_id'] );
		}
		if ( ! empty( $in['ms_client_secret'] ) ) {
			$new['ms_client_secret'] = AAB_Crypto::encrypt( sanitize_text_field( $in['ms_client_secret'] ) );
		}
		if ( isset( $in['ms_calendars_present'] ) ) {
			$new['ms_calendars'] = isset( $in['ms_calendars'] ) && is_array( $in['ms_calendars'] ) ? array_values( array_map( 'sanitize_text_field', $in['ms_calendars'] ) ) : array();
		}
		if ( isset( $in['custom_css'] ) && current_user_can( 'unfiltered_html' ) ) {
			$new['custom_css'] = trim( str_ireplace( '</style', '', wp_strip_all_tags( $in['custom_css'] ) ) );
		}
		foreach ( array( 'add_meet', 'invite_guest', 'delete_data' ) as $key ) {
			$new[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}
		// phpcs:enable

		AAB_Settings::save( $new );
		AAB_Availability::bust_cache();

		if ( $credentials_changed && AAB_Google::is_connected() ) {
			AAB_Google::disconnect();
			self::back( 'success', __( 'Settings saved. Your Google credentials changed, so connect Google Calendar again.', 'anderson-appointment-booking' ) );
		}
		if ( $invalid_days ) {
			/* translators: %s: lines that could not be read */
			self::back( 'error', sprintf( __( 'Settings saved, but these days-off lines could not be read and are ignored: %s. Use YYYY-MM-DD or YYYY-MM-DD to YYYY-MM-DD.', 'anderson-appointment-booking' ), implode( '; ', $invalid_days ) ) );
		}
		if ( $invalid_hours ) {
			self::back( 'error', __( 'Settings saved, but some hours could not be read and were cleared. Use 24-hour ranges like 09:00-12:00, 13:00-17:00.', 'anderson-appointment-booking' ) );
		}
		self::back( 'success', __( 'Settings saved.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Start the OAuth flow.
	 */
	public static function connect() {
		self::guard( 'aab_connect' );
		if ( ! AAB_Google::has_credentials() ) {
			self::back( 'error', __( 'Add your Google client ID and secret first.', 'anderson-appointment-booking' ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'aab_oauth_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( AAB_Google::auth_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external Google URL.
		exit;
	}

	/**
	 * OAuth redirect target.
	 */
	public static function oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'anderson-appointment-booking' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth uses the state parameter instead of a nonce.
		$key      = 'aab_oauth_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back( 'error', __( 'The connection request expired or did not match. Click Connect Google Calendar again.', 'anderson-appointment-booking' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			self::back( 'error', __( 'Google Calendar was not connected because access was not granted.', 'anderson-appointment-booking' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		if ( '' === $code ) {
			self::back( 'error', __( 'Google did not send an authorization code. Try connecting again.', 'anderson-appointment-booking' ) );
		}
		$result = AAB_Google::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error from Google */
			self::back( 'error', sprintf( __( 'Google Calendar could not be connected: %s', 'anderson-appointment-booking' ), $result->get_error_message() ) );
		}
		AAB_Availability::bust_cache();
		self::back( 'success', __( 'Google Calendar connected. Choose your calendars below, then save.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Disconnect Google.
	 */
	public static function disconnect() {
		self::guard( 'aab_disconnect' );
		AAB_Google::disconnect();
		self::back( 'success', __( 'Google Calendar disconnected. Visitors can no longer book until you connect again.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Cancel a booking from the list.
	 */
	public static function cancel_booking() {
		$id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		self::guard( 'aab_cancel_booking_' . $id );
		$result = AAB_Bookings::cancel( $id, 'admin' );
		$args   = array( 'post_type' => AAB_Bookings::POST_TYPE );
		$args['aab_cancelled'] = is_wp_error( $result ) ? 'error' : 'ok';
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * "Export CSV" button above the bookings list.
	 *
	 * @param string $which top|bottom.
	 */
	public static function export_button( $which ) {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen || AAB_Bookings::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=aab_export' ), 'aab_export' );
		echo '<div class="alignleft actions"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'anderson-appointment-booking' ) . '</a></div>';
	}

	/**
	 * Download all bookings as CSV.
	 */
	public static function export() {
		self::guard( 'aab_export' );
		$ids = get_posts(
			array(
				'post_type'      => AAB_Bookings::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bookings-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		// Spreadsheet apps would run a cell starting with = + - @ as a formula; neutralize those.
		$cell = static function ( $value ) {
			$value = (string) $value;
			return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
		};
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'Start', 'End', 'Timezone', 'Type', 'Name', 'Email', 'Phone', 'Answers', 'Notes', 'Status', 'Amount', 'Currency', 'Payment', 'Booked', 'Google Calendar event' ), ',', '"', '' );
		foreach ( $ids as $id ) {
			$b = AAB_Bookings::get( $id );
			if ( ! $b ) {
				continue;
			}
			fputcsv(
				$out,
				array_map(
					$cell,
					array(
						wp_date( 'Y-m-d H:i', $b['start'] ),
						wp_date( 'Y-m-d H:i', $b['end'] ),
						wp_timezone_string(),
						$b['type_name'],
						$b['name'],
						$b['email'],
						$b['phone'],
						implode( "\n", AAB_Bookings::answer_lines( $b['answers'] ) ),
						$b['notes'],
						$b['status'],
						$b['amount'] ? AAB_Stripe::from_minor( $b['amount'], $b['currency'] ) : '',
						$b['amount'] ? strtoupper( $b['currency'] ) : '',
						$b['amount'] ? ( $b['refunded'] ? 'refunded' : ( $b['paid_at'] ? 'paid' : 'unpaid' ) ) : '',
						get_post_time( 'Y-m-d H:i', false, $id ),
						$b['event_link'],
					)
				),
				',',
				'"',
				''
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Start Microsoft sign-in.
	 */
	public static function ms_connect() {
		self::guard( 'aab_ms_connect' );
		if ( ! AAB_Microsoft::has_credentials() ) {
			self::back( 'error', __( 'Add your Microsoft application (client) ID and client secret first.', 'anderson-appointment-booking' ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient( 'aab_ms_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( AAB_Microsoft::auth_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Microsoft sign-in.
		exit;
	}

	/**
	 * Microsoft sign-in redirect target.
	 */
	public static function ms_oauth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'anderson-appointment-booking' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state parameter instead of a nonce.
		$key      = 'aab_ms_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back( 'error', __( 'The Outlook connection request expired or did not match. Click Connect Outlook again.', 'anderson-appointment-booking' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			/* translators: %s: error from Microsoft */
			self::back( 'error', sprintf( __( 'Outlook was not connected: %s', 'anderson-appointment-booking' ), '' !== $desc ? strtok( $desc, "\r\n" ) : __( 'access was not granted.', 'anderson-appointment-booking' ) ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable
		$result = '' !== $code ? AAB_Microsoft::exchange_code( $code ) : new WP_Error( 'aab_ms_no_code', __( 'Microsoft did not send an authorization code.', 'anderson-appointment-booking' ) );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error */
			self::back( 'error', sprintf( __( 'Outlook could not be connected: %s', 'anderson-appointment-booking' ), $result->get_error_message() ) );
		}
		AAB_Availability::bust_cache();
		self::back( 'success', __( 'Outlook connected. Choose which of its calendars should block times, then save.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Disconnect Microsoft.
	 */
	public static function ms_disconnect() {
		self::guard( 'aab_ms_disconnect' );
		AAB_Microsoft::disconnect();
		$s                 = AAB_Settings::all();
		$s['ms_calendars'] = array();
		AAB_Settings::save( $s );
		AAB_Availability::bust_cache();
		self::back( 'success', __( 'Outlook disconnected. Its calendars no longer block times.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Clear the problem log.
	 */
	public static function clear_log() {
		self::guard( 'aab_clear_log' );
		delete_option( AAB_Google::LOG_OPTION );
		self::back( 'success', __( 'Problem log cleared.', 'anderson-appointment-booking' ) );
	}

	/**
	 * Run the hourly tasks now.
	 */
	public static function run_now() {
		self::guard( 'aab_run_now' );
		$stats = AAB_Maintenance::run();
		self::back(
			'success',
			sprintf(
				/* translators: 1: bookings checked, 2: cancelled, 3: moved, 4: reminders sent */
				__( 'Checked %1$d upcoming bookings against Google Calendar: %2$d cancelled there, %3$d moved. %4$d reminders sent.', 'anderson-appointment-booking' ),
				$stats['synced'],
				$stats['cancelled'],
				$stats['moved'],
				$stats['reminded']
			)
		);
	}

	/**
	 * Bookings list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'          => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'       => __( 'Booking', 'anderson-appointment-booking' ),
			'aab_when'    => __( 'When', 'anderson-appointment-booking' ),
			'aab_type'    => __( 'Type', 'anderson-appointment-booking' ),
			'aab_paid'    => __( 'Payment', 'anderson-appointment-booking' ),
			'aab_email'   => __( 'Email', 'anderson-appointment-booking' ),
			'aab_status'  => __( 'Status', 'anderson-appointment-booking' ),
			'aab_links'   => __( 'Links', 'anderson-appointment-booking' ),
		);
	}

	/**
	 * Column output.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $column, $post_id ) {
		$b = AAB_Bookings::get( $post_id );
		if ( ! $b ) {
			return;
		}
		switch ( $column ) {
			case 'aab_type':
				echo esc_html( $b['type_name'] );
				break;
			case 'aab_paid':
				if ( ! $b['amount'] ) {
					echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Free', 'anderson-appointment-booking' ) . '</span>';
				} elseif ( $b['refunded'] ) {
					/* translators: %s: amount */
					echo esc_html( sprintf( __( '%s refunded', 'anderson-appointment-booking' ), AAB_Stripe::format( $b['amount'], $b['currency'] ) ) );
				} elseif ( $b['refund_error'] ) {
					echo '<span class="aab-status--error">' . esc_html__( 'Refund failed: refund in Stripe', 'anderson-appointment-booking' ) . '</span>';
				} elseif ( $b['paid_at'] ) {
					/* translators: %s: amount */
					echo esc_html( sprintf( __( '%s paid', 'anderson-appointment-booking' ), AAB_Stripe::format( $b['amount'], $b['currency'] ) ) );
				} else {
					echo esc_html__( 'Not paid', 'anderson-appointment-booking' );
				}
				break;
			case 'aab_when':
				echo esc_html( AAB_Bookings::format_time( $b['start'] ) );
				break;
			case 'aab_email':
				echo '<a href="mailto:' . esc_attr( $b['email'] ) . '">' . esc_html( $b['email'] ) . '</a>';
				break;
			case 'aab_status':
				$labels = array(
					'confirmed' => __( 'Confirmed', 'anderson-appointment-booking' ),
					'cancelled' => __( 'Cancelled', 'anderson-appointment-booking' ),
					'pending'   => __( 'Not confirmed', 'anderson-appointment-booking' ),
					'awaiting_payment' => $b['paid_at'] ? __( 'Paid, adding to calendar', 'anderson-appointment-booking' ) : __( 'Awaiting payment', 'anderson-appointment-booking' ),
					'expired'   => __( 'Expired (not paid)', 'anderson-appointment-booking' ),
				);
				if ( 'cancelled' === $b['status'] && 'calendar' === get_post_meta( $post_id, '_aab_cancelled_by', true ) ) {
					$labels['cancelled'] = __( 'Deleted in Google Calendar', 'anderson-appointment-booking' );
				}
				$status = isset( $labels[ $b['status'] ] ) ? $b['status'] : 'pending';
				if ( 'confirmed' === $status && $b['end'] < time() ) {
					echo esc_html__( 'Past', 'anderson-appointment-booking' );
				} else {
					echo '<span class="aab-status aab-status--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
				}
				break;
			case 'aab_links':
				$links = array();
				if ( $b['event_link'] && 'cancelled' !== $b['status'] ) {
					$links[] = '<a href="' . esc_url( $b['event_link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Calendar event', 'anderson-appointment-booking' ) . '</a>';
				}
				if ( $b['meet_link'] && 'cancelled' !== $b['status'] ) {
					$links[] = '<a href="' . esc_url( $b['meet_link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Video call', 'anderson-appointment-booking' ) . '</a>';
				}
				echo wp_kses_post( implode( ' | ', $links ) );
				break;
		}
	}

	/**
	 * Replace row actions with View and Cancel.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( AAB_Bookings::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		$b = AAB_Bookings::get( $post );
		if ( $b && 'confirmed' === $b['status'] && $b['end'] > time() ) {
			$url               = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'aab_cancel_booking',
						'booking' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'aab_cancel_booking_' . $post->ID
			);
			$actions['aab_cancel'] = sprintf(
				'<a href="%1$s" class="aab-cancel-link" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $url ),
				esc_attr( wp_json_encode( __( 'Cancel this appointment? It will be removed from Google Calendar and the visitor will be emailed.', 'anderson-appointment-booking' ) ) ),
				esc_html__( 'Cancel appointment', 'anderson-appointment-booking' )
			);
		}
		return $actions;
	}

	/**
	 * Read-only details box on the booking edit screen.
	 */
	public static function meta_box() {
		add_meta_box(
			'aab-details',
			__( 'Appointment details', 'anderson-appointment-booking' ),
			static function ( $post ) {
				$b = AAB_Bookings::get( $post );
				if ( ! $b ) {
					return;
				}
				echo '<table class="form-table"><tbody>';
				$rows = array(
					__( 'When', 'anderson-appointment-booking' )   => AAB_Bookings::format_time( $b['start'] ) . ' – ' . wp_date( get_option( 'time_format' ), $b['end'] ),
					__( 'Name', 'anderson-appointment-booking' )   => $b['name'],
					__( 'Email', 'anderson-appointment-booking' )  => $b['email'],
					__( 'Phone', 'anderson-appointment-booking' )  => $b['phone'],
					__( 'Status', 'anderson-appointment-booking' ) => $b['status'],
					__( 'Type', 'anderson-appointment-booking' )   => $b['type_name'],
					__( 'Answers', 'anderson-appointment-booking' ) => implode( "\n", AAB_Bookings::answer_lines( $b['answers'] ) ),
					__( 'Notes', 'anderson-appointment-booking' )  => $b['notes'],
				);
				foreach ( $rows as $label => $value ) {
					echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			},
			AAB_Bookings::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = AAB_Settings::all();
		$connected = AAB_Google::is_connected();
		$account   = AAB_Google::account();
		$notice    = get_transient( 'aab_notice_' . get_current_user_id() );
		delete_transient( 'aab_notice_' . get_current_user_id() );
		$calendars = $connected ? AAB_Google::calendars( isset( $_GET['refresh'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache refresh.
		$day_names = array(
			'mon' => __( 'Monday', 'anderson-appointment-booking' ),
			'tue' => __( 'Tuesday', 'anderson-appointment-booking' ),
			'wed' => __( 'Wednesday', 'anderson-appointment-booking' ),
			'thu' => __( 'Thursday', 'anderson-appointment-booking' ),
			'fri' => __( 'Friday', 'anderson-appointment-booking' ),
			'sat' => __( 'Saturday', 'anderson-appointment-booking' ),
			'sun' => __( 'Sunday', 'anderson-appointment-booking' ),
		);
		$id_locked     = defined( 'AAB_GOOGLE_CLIENT_ID' );
		$secret_locked = defined( 'AAB_GOOGLE_CLIENT_SECRET' );
		include AAB_DIR . 'includes/views/settings.php';
	}
}
