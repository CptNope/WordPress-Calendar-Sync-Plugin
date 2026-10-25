<?php
/**
 * Plugin Name:       Anderson Appointment Booking
 * Plugin URI:        https://github.com/CptNope/WordPress-Calendar-Sync-Plugin
 * Description:       Let visitors book appointments with you from your WordPress site. Availability is read live from your Google Calendar and every booking is added to it.
 * Version:           1.4.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Jeremy Anderson
 * Author URI:        https://jeremyanderson.tech
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       anderson-appointment-booking
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

// Another copy is already loaded (e.g. a GitHub download in a differently named folder):
// stay out of the way instead of crashing on duplicate classes, and say so.
if ( defined( 'AAB_VERSION' ) ) {
	$aab_duplicate = __FILE__;
	add_action(
		'admin_notices',
		function () use ( $aab_duplicate ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: 1: running version, 2: plugin folder of the extra copy */
					__( 'Two copies of Anderson Appointment Booking are active. Version %1$s is running; the copy in “%2$s” is doing nothing. Deactivate the one you don’t want. To remove an old copy, delete its folder rather than using Delete here, which would also disconnect Google Calendar in older versions.', 'anderson-appointment-booking' ),
					AAB_VERSION,
					basename( dirname( $aab_duplicate ) )
				)
			) . '</p></div>';
		}
	);
	return;
}

define( 'AAB_VERSION', '1.4.2' );
define( 'AAB_FILE', __FILE__ );
define( 'AAB_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAB_URL', plugin_dir_url( __FILE__ ) );

require_once AAB_DIR . 'includes/class-aab-crypto.php';
require_once AAB_DIR . 'includes/class-aab-settings.php';
require_once AAB_DIR . 'includes/class-aab-types.php';
require_once AAB_DIR . 'includes/class-aab-style.php';
require_once AAB_DIR . 'includes/class-aab-google.php';
require_once AAB_DIR . 'includes/class-aab-microsoft.php';
require_once AAB_DIR . 'includes/class-aab-stripe.php';
require_once AAB_DIR . 'includes/class-aab-payments.php';
require_once AAB_DIR . 'includes/class-aab-availability.php';
require_once AAB_DIR . 'includes/class-aab-bookings.php';
require_once AAB_DIR . 'includes/class-aab-rest.php';
require_once AAB_DIR . 'includes/class-aab-frontend.php';
require_once AAB_DIR . 'includes/class-aab-maintenance.php';
require_once AAB_DIR . 'includes/class-aab-admin.php';
require_once AAB_DIR . 'includes/class-aab-admin-types.php';

add_action( 'plugins_loaded', array( 'AAB_Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'AAB_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AAB_Maintenance', 'unschedule' ) );

/**
 * Wires the plugin together.
 */
final class AAB_Plugin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Runs on init (not now): it creates translated default names.
		add_action( 'init', array( 'AAB_Types', 'maybe_migrate' ), 1 );
		AAB_Bookings::init();
		AAB_Rest::init();
		AAB_Frontend::init();
		AAB_Maintenance::init();
		if ( is_admin() ) {
			AAB_Admin::init();
			AAB_Admin_Types::init();
		}
	}

	/**
	 * Activation: seed defaults and register the post type so rewrite rules are correct.
	 */
	public static function activate() {
		if ( false === get_option( AAB_Settings::OPTION ) ) {
			add_option( AAB_Settings::OPTION, AAB_Settings::defaults(), '', false );
		}
		add_option( 'aab_installed', time(), '', false );
		AAB_Types::maybe_migrate();
		AAB_Bookings::register_post_type();
		AAB_Maintenance::schedule();
	}
}
