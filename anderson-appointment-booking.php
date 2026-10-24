<?php
/**
 * Plugin Name:       Anderson Appointment Booking
 * Plugin URI:        https://github.com/CptNope/WordPress-Calendar-Sync-Plugin
 * Description:       Let visitors book appointments with you from your WordPress site. Availability is read live from your Google Calendar and every booking is added to it.
 * Version:           1.1.0
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

define( 'AAB_VERSION', '1.1.0' );
define( 'AAB_FILE', __FILE__ );
define( 'AAB_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAB_URL', plugin_dir_url( __FILE__ ) );

require_once AAB_DIR . 'includes/class-aab-crypto.php';
require_once AAB_DIR . 'includes/class-aab-settings.php';
require_once AAB_DIR . 'includes/class-aab-style.php';
require_once AAB_DIR . 'includes/class-aab-google.php';
require_once AAB_DIR . 'includes/class-aab-availability.php';
require_once AAB_DIR . 'includes/class-aab-bookings.php';
require_once AAB_DIR . 'includes/class-aab-rest.php';
require_once AAB_DIR . 'includes/class-aab-frontend.php';
require_once AAB_DIR . 'includes/class-aab-admin.php';

add_action( 'plugins_loaded', array( 'AAB_Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'AAB_Plugin', 'activate' ) );

/**
 * Wires the plugin together.
 */
final class AAB_Plugin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		AAB_Bookings::init();
		AAB_Rest::init();
		AAB_Frontend::init();
		if ( is_admin() ) {
			AAB_Admin::init();
		}
	}

	/**
	 * Activation: seed defaults and register the post type so rewrite rules are correct.
	 */
	public static function activate() {
		if ( false === get_option( AAB_Settings::OPTION ) ) {
			add_option( AAB_Settings::OPTION, AAB_Settings::defaults(), '', false );
		}
		AAB_Bookings::register_post_type();
	}
}
