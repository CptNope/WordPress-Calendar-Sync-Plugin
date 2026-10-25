<?php
/**
 * Settings page markup.
 *
 * Variables come from AAB_Admin::render().
 *
 * @package AndersonAppointmentBooking
 *
 * @var array            $s
 * @var bool             $connected
 * @var array            $account
 * @var array|false      $notice
 * @var array|WP_Error   $calendars
 * @var array            $day_names
 * @var bool             $id_locked
 * @var bool             $secret_locked
 */

defined( 'ABSPATH' ) || exit;

$aab_has_secret = $secret_locked || '' !== AAB_Settings::client_secret();
?>
<div class="wrap aab-admin">
	<h1><?php esc_html_e( 'Appointment booking', 'anderson-appointment-booking' ); ?></h1>

	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' === (string) get_option( 'timezone_string' ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Your site timezone is a fixed UTC offset, which ignores daylight saving time, so booking hours would shift by an hour twice a year.', 'anderson-appointment-booking' ); ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Choose a city under Settings → General', 'anderson-appointment-booking' ); ?></a>
		</p></div>
	<?php endif; ?>

	<?php if ( ! AAB_Crypto::available() ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The OpenSSL PHP extension is missing, so your Google credentials are stored without encryption. Ask your host to enable OpenSSL.', 'anderson-appointment-booking' ); ?></p></div>
	<?php endif; ?>

	<div class="aab-card">
		<h2><?php esc_html_e( 'Google Calendar', 'anderson-appointment-booking' ); ?></h2>

		<?php if ( $connected ) : ?>
			<p class="aab-connection aab-connection--on">
				<?php
				if ( $account['email'] ) {
					/* translators: %s: Google account email */
					printf( esc_html__( 'Connected as %s.', 'anderson-appointment-booking' ), '<strong>' . esc_html( $account['email'] ) . '</strong>' );
				} else {
					esc_html_e( 'Connected.', 'anderson-appointment-booking' );
				}
				?>
				<?php esc_html_e( 'Visitors can book any open time in your hours below.', 'anderson-appointment-booking' ); ?>
			</p>
			<?php
			$aab_last   = (int) get_option( AAB_Maintenance::LAST_RUN );
			$aab_cal_tz = AAB_Google::calendar_timezone( (string) $s['booking_calendar'] );
			?>
			<p class="description">
				<?php
				echo $aab_last
					/* translators: %s: human time difference, e.g. "20 mins" */
					? esc_html( sprintf( __( 'Last checked Google Calendar for moved or deleted appointments %s ago.', 'anderson-appointment-booking' ), human_time_diff( $aab_last ) ) )
					: esc_html__( 'The hourly check for moved or deleted appointments hasn’t run yet.', 'anderson-appointment-booking' );
				?>
			</p>
			<?php if ( '' !== $aab_cal_tz && '' !== (string) get_option( 'timezone_string' ) && $aab_cal_tz !== wp_timezone_string() ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: 1: Google Calendar timezone, 2: site timezone */
						esc_html__( 'Your Google Calendar uses %1$s but this site uses %2$s. Booking hours below follow the site timezone; appointments still land at the right moment in Google Calendar.', 'anderson-appointment-booking' ),
						'<strong>' . esc_html( $aab_cal_tz ) . '</strong>',
						'<strong>' . esc_html( wp_timezone_string() ) . '</strong>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<div class="aab-inline-forms">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aab_run_now">
					<?php wp_nonce_field( 'aab_run_now' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Check Google Calendar now', 'anderson-appointment-booking' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aab_disconnect">
					<?php wp_nonce_field( 'aab_disconnect' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Disconnect Google Calendar', 'anderson-appointment-booking' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<p class="aab-connection"><?php esc_html_e( 'Not connected. Visitors see “Online booking is unavailable” until you connect.', 'anderson-appointment-booking' ); ?></p>

			<details class="aab-steps" <?php echo AAB_Google::has_credentials() ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How to get a Google client ID and secret (about 5 minutes)', 'anderson-appointment-booking' ); ?></summary>
				<ol>
					<li>
						<?php
						printf(
							/* translators: %s: link to Google Cloud Console */
							esc_html__( 'In %s, create a project (or pick one) and enable the Google Calendar API.', 'anderson-appointment-booking' ),
							'<a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Google Cloud Console</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Open “Google Auth Platform” (OAuth consent screen), choose External, and fill in the app name and your email.', 'anderson-appointment-booking' ); ?></li>
					<li><?php esc_html_e( 'Under Audience, click “Publish app” so it is In production. Apps left in Testing lose access after 7 days and booking stops. You don’t need Google’s verification to use it with your own account; you will see an “unverified app” screen once while connecting.', 'anderson-appointment-booking' ); ?></li>
					<li>
						<?php esc_html_e( 'Under Clients, create an OAuth client of type “Web application” and add this exact Authorized redirect URI:', 'anderson-appointment-booking' ); ?>
						<span class="aab-copy">
							<input type="text" readonly value="<?php echo esc_attr( AAB_Google::redirect_uri() ); ?>" class="large-text code" id="aab-redirect-uri">
							<button type="button" class="button" data-aab-copy="aab-redirect-uri"><?php esc_html_e( 'Copy', 'anderson-appointment-booking' ); ?></button>
						</span>
					</li>
					<li><?php esc_html_e( 'Paste the client ID and client secret below, save, then click Connect Google Calendar.', 'anderson-appointment-booking' ); ?></li>
				</ol>
			</details>

			<?php if ( AAB_Google::has_credentials() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="aab_connect">
					<?php wp_nonce_field( 'aab_connect' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect Google Calendar', 'anderson-appointment-booking' ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aab-settings-form">
		<input type="hidden" name="action" value="aab_save_settings">
		<?php wp_nonce_field( 'aab_save_settings' ); ?>

		<div class="aab-card">
			<h2><?php esc_html_e( 'Google credentials', 'anderson-appointment-booking' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aab-client-id"><?php esc_html_e( 'Client ID', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<?php if ( $id_locked ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'anderson-appointment-booking' ); ?></code>
						<?php else : ?>
							<input type="text" id="aab-client-id" name="aab[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" class="large-text code" autocomplete="off">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-client-secret"><?php esc_html_e( 'Client secret', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<?php if ( $secret_locked ) : ?>
							<code><?php esc_html_e( 'Set in wp-config.php', 'anderson-appointment-booking' ); ?></code>
						<?php else : ?>
							<input type="password" id="aab-client-secret" name="aab[client_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo $aab_has_secret ? esc_attr__( 'Saved. Leave blank to keep it.', 'anderson-appointment-booking' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Stored encrypted. You can instead define AAB_GOOGLE_CLIENT_ID and AAB_GOOGLE_CLIENT_SECRET in wp-config.php.', 'anderson-appointment-booking' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( $connected ) : ?>
			<div class="aab-card">
				<h2><?php esc_html_e( 'Calendars', 'anderson-appointment-booking' ); ?></h2>
				<?php if ( is_wp_error( $calendars ) ) : ?>
					<p class="aab-error">
						<?php
						/* translators: %s: error message */
						printf( esc_html__( 'Your calendars could not be loaded: %s', 'anderson-appointment-booking' ), esc_html( $calendars->get_error_message() ) );
						?>
					</p>
				<?php else : ?>
					<input type="hidden" name="aab[calendars_present]" value="1">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="aab-booking-calendar"><?php esc_html_e( 'Add bookings to', 'anderson-appointment-booking' ); ?></label></th>
							<td>
								<select id="aab-booking-calendar" name="aab[booking_calendar]">
									<?php foreach ( $calendars as $cal ) : ?>
										<?php
										if ( ! $cal['writable'] ) {
											continue;
										}
										$aab_value = $cal['primary'] ? 'primary' : $cal['id'];
										?>
										<option value="<?php echo esc_attr( $aab_value ); ?>" <?php selected( in_array( $s['booking_calendar'], array( $aab_value, $cal['id'] ), true ) ); ?>>
											<?php echo esc_html( $cal['summary'] . ( $cal['primary'] ? ' ' . __( '(primary)', 'anderson-appointment-booking' ) : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'New appointments are created here. Busy times on this calendar always block slots.', 'anderson-appointment-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Also check for conflicts', 'anderson-appointment-booking' ); ?></th>
							<td>
								<fieldset class="aab-calendar-list">
									<?php foreach ( $calendars as $cal ) : ?>
										<?php $aab_value = $cal['primary'] ? 'primary' : $cal['id']; ?>
										<label>
											<input type="checkbox" name="aab[conflict_calendars][]" value="<?php echo esc_attr( $aab_value ); ?>" <?php checked( in_array( $aab_value, (array) $s['conflict_calendars'], true ) || in_array( $cal['id'], (array) $s['conflict_calendars'], true ) ); ?>>
											<?php echo esc_html( $cal['summary'] ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description">
									<?php esc_html_e( 'Busy events on checked calendars hide those times. Only free/busy is read; event details stay private.', 'anderson-appointment-booking' ); ?>
									<a href="<?php echo esc_url( AAB_Admin::page_url( array( 'refresh' => 1 ) ) ); ?>"><?php esc_html_e( 'Refresh list', 'anderson-appointment-booking' ); ?></a>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="aab-card">
			<h2><?php esc_html_e( 'When people can book', 'anderson-appointment-booking' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the appointment types screen */
					esc_html__( 'Appointment length, start times and booking questions are set per %s. Types use these hours unless you give them their own.', 'anderson-appointment-booking' ),
					'<a href="' . esc_url( AAB_Admin_Types::url() ) . '">' . esc_html__( 'appointment type', 'anderson-appointment-booking' ) . '</a>'
				);
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: timezone name */
					esc_html__( 'Hours use 24-hour time in your site timezone (%s). Separate breaks with commas, e.g. 09:00-12:00, 13:00-17:00. Leave a day empty to close it.', 'anderson-appointment-booking' ),
					'<strong>' . esc_html( wp_timezone_string() ) . '</strong>'
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<?php foreach ( $day_names as $aab_key => $aab_label ) : ?>
					<tr>
						<th scope="row"><label for="aab-hours-<?php echo esc_attr( $aab_key ); ?>"><?php echo esc_html( $aab_label ); ?></label></th>
						<td><input type="text" id="aab-hours-<?php echo esc_attr( $aab_key ); ?>" name="aab[hours][<?php echo esc_attr( $aab_key ); ?>]" value="<?php echo esc_attr( isset( $s['hours'][ $aab_key ] ) ? $s['hours'][ $aab_key ] : '' ); ?>" class="regular-text code" placeholder="<?php esc_attr_e( 'Closed', 'anderson-appointment-booking' ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<table class="form-table" role="presentation">
				<?php
				$aab_numbers = array(
					'buffer_before' => array( __( 'Free time before', 'anderson-appointment-booking' ), __( 'minutes', 'anderson-appointment-booking' ), __( 'Keeps this much time clear before each appointment.', 'anderson-appointment-booking' ) ),
					'buffer_after'  => array( __( 'Free time after', 'anderson-appointment-booking' ), __( 'minutes', 'anderson-appointment-booking' ), '' ),
					'min_notice'    => array( __( 'Minimum notice', 'anderson-appointment-booking' ), __( 'hours', 'anderson-appointment-booking' ), __( 'No bookings sooner than this from now.', 'anderson-appointment-booking' ) ),
					'max_days'      => array( __( 'Book up to', 'anderson-appointment-booking' ), __( 'days ahead', 'anderson-appointment-booking' ), '' ),
					'daily_limit'   => array( __( 'Most bookings per day', 'anderson-appointment-booking' ), '', __( '0 means no limit.', 'anderson-appointment-booking' ) ),
					'max_per_email' => array( __( 'Upcoming bookings per person', 'anderson-appointment-booking' ), '', __( 'How many future appointments one email address can hold at once. 0 means no limit.', 'anderson-appointment-booking' ) ),
					'change_cutoff' => array( __( 'Changes allowed until', 'anderson-appointment-booking' ), __( 'hours before', 'anderson-appointment-booking' ), __( 'Visitors can reschedule or cancel online until this long before the appointment. 0 means any time before it starts.', 'anderson-appointment-booking' ) ),
					'max_reschedules' => array( __( 'Reschedules per booking', 'anderson-appointment-booking' ), '', __( 'How many times a visitor can move one appointment online. 0 means no limit.', 'anderson-appointment-booking' ) ),
				);
				foreach ( $aab_numbers as $aab_key => $aab_meta ) :
					?>
					<tr>
						<th scope="row"><label for="aab-<?php echo esc_attr( $aab_key ); ?>"><?php echo esc_html( $aab_meta[0] ); ?></label></th>
						<td>
							<input type="number" min="0" id="aab-<?php echo esc_attr( $aab_key ); ?>" name="aab[<?php echo esc_attr( $aab_key ); ?>]" value="<?php echo esc_attr( $s[ $aab_key ] ); ?>" class="small-text"> <?php echo esc_html( $aab_meta[1] ); ?>
							<?php if ( $aab_meta[2] ) : ?>
								<p class="description"><?php echo esc_html( $aab_meta[2] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="aab-days-off"><?php esc_html_e( 'Days off', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<textarea id="aab-days-off" name="aab[days_off]" rows="4" class="regular-text code" placeholder="2026-11-26  # Thanksgiving&#10;2026-12-24 to 2027-01-02"><?php echo esc_textarea( $s['days_off'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One date or range per line (YYYY-MM-DD, or YYYY-MM-DD to YYYY-MM-DD). Nothing can be booked on these days.', 'anderson-appointment-booking' ); ?></p>
						<p class="description"><?php esc_html_e( 'Tip: all-day events in Google Calendar (vacations, holidays) are marked “Free” by default, so they don’t block bookings. Set them to “Busy” in Google Calendar, or list the dates here.', 'anderson-appointment-booking' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="aab-card">
			<h2><?php esc_html_e( 'The appointment', 'anderson-appointment-booking' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aab-event-title"><?php esc_html_e( 'Calendar event title', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<input type="text" id="aab-event-title" name="aab[event_title]" value="<?php echo esc_attr( $s['event_title'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( '{name} is replaced with the visitor’s name, {type} with the appointment type, {site} with your site name.', 'anderson-appointment-booking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-location"><?php esc_html_e( 'Location', 'anderson-appointment-booking' ); ?></label></th>
					<td><input type="text" id="aab-location" name="aab[location]" value="<?php echo esc_attr( $s['location'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional: an address or phone number', 'anderson-appointment-booking' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Video call', 'anderson-appointment-booking' ); ?></th>
					<td><label><input type="checkbox" name="aab[add_meet]" value="1" <?php checked( $s['add_meet'] ); ?>> <?php esc_html_e( 'Add a Google Meet link to each appointment', 'anderson-appointment-booking' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Calendar invite', 'anderson-appointment-booking' ); ?></th>
					<td><label><input type="checkbox" name="aab[invite_guest]" value="1" <?php checked( $s['invite_guest'] ); ?>> <?php esc_html_e( 'Add the visitor as a guest so Google sends them an invite and updates', 'anderson-appointment-booking' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-phone-field"><?php esc_html_e( 'Phone number', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<select id="aab-phone-field" name="aab[phone_field]">
							<option value="off" <?php selected( $s['phone_field'], 'off' ); ?>><?php esc_html_e( 'Don’t ask', 'anderson-appointment-booking' ); ?></option>
							<option value="optional" <?php selected( $s['phone_field'], 'optional' ); ?>><?php esc_html_e( 'Ask (optional)', 'anderson-appointment-booking' ); ?></option>
							<option value="required" <?php selected( $s['phone_field'], 'required' ); ?>><?php esc_html_e( 'Ask (required)', 'anderson-appointment-booking' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-notify-email"><?php esc_html_e( 'Notify me at', 'anderson-appointment-booking' ); ?></label></th>
					<td><input type="email" id="aab-notify-email" name="aab[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-reminder"><?php esc_html_e( 'Reminder email', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<input type="number" min="0" max="168" id="aab-reminder" name="aab[reminder_hours]" value="<?php echo esc_attr( $s['reminder_hours'] ); ?>" class="small-text"> <?php esc_html_e( 'hours before', 'anderson-appointment-booking' ); ?>
						<p class="description"><?php esc_html_e( '0 turns reminders off. Google Calendar also reminds invited guests.', 'anderson-appointment-booking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-confirmation"><?php esc_html_e( 'Message after booking', 'anderson-appointment-booking' ); ?></label></th>
					<td><textarea id="aab-confirmation" name="aab[confirmation_message]" rows="2" class="large-text"><?php echo esc_textarea( $s['confirmation_message'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-consent"><?php esc_html_e( 'Consent checkbox', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<textarea id="aab-consent" name="aab[consent_text]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional, e.g. I agree to the privacy policy.', 'anderson-appointment-booking' ); ?>"><?php echo esc_textarea( $s['consent_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'When filled in, visitors must tick a box with this text before booking.', 'anderson-appointment-booking' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php include AAB_DIR . 'includes/views/appearance.php'; ?>

		<div class="aab-card">
			<h2><?php esc_html_e( 'Show the booking calendar', 'anderson-appointment-booking' ); ?></h2>
			<p><?php esc_html_e( 'Add the “Appointment Booking” block to any page, or paste this shortcode:', 'anderson-appointment-booking' ); ?></p>
			<p><code>[anderson_booking]</code></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When uninstalling', 'anderson-appointment-booking' ); ?></th>
					<td><label><input type="checkbox" name="aab[delete_data]" value="1" <?php checked( $s['delete_data'] ); ?>> <?php esc_html_e( 'Delete all settings and booking records (events stay in Google Calendar)', 'anderson-appointment-booking' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="aab-retention"><?php esc_html_e( 'Keep booking records', 'anderson-appointment-booking' ); ?></label></th>
					<td>
						<input type="number" min="0" id="aab-retention" name="aab[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days after the appointment', 'anderson-appointment-booking' ); ?>
						<p class="description"><?php esc_html_e( 'Older records (names, emails, notes) are deleted from this site automatically. 0 keeps them. Google Calendar events are not touched.', 'anderson-appointment-booking' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save settings', 'anderson-appointment-booking' ) ); ?>
	</form>

	<?php $aab_log = get_option( AAB_Google::LOG_OPTION, array() ); ?>
	<?php if ( is_array( $aab_log ) && $aab_log ) : ?>
		<div class="aab-card">
			<h2><?php esc_html_e( 'Recent problems talking to Google', 'anderson-appointment-booking' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Occasional timeouts are normal and are retried automatically. Repeated errors usually mean the connection needs attention.', 'anderson-appointment-booking' ); ?></p>
			<table class="widefat striped aab-log">
				<thead><tr><th><?php esc_html_e( 'When', 'anderson-appointment-booking' ); ?></th><th><?php esc_html_e( 'Doing', 'anderson-appointment-booking' ); ?></th><th><?php esc_html_e( 'Error', 'anderson-appointment-booking' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( array_slice( $aab_log, 0, 10 ) as $aab_entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $aab_entry['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $aab_entry['context'] ); ?></code></td>
							<td><?php echo esc_html( $aab_entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aab-log-clear">
				<input type="hidden" name="action" value="aab_clear_log">
				<?php wp_nonce_field( 'aab_clear_log' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Clear', 'anderson-appointment-booking' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
</div>
