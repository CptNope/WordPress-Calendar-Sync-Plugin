<?php
/**
 * Settings card: Microsoft 365 / Outlook conflict calendars.
 *
 * Included inside the settings form from settings.php; $s holds the settings.
 * Connect/disconnect buttons submit separate forms via the HTML form attribute.
 *
 * @package AndersonAppointmentBooking
 *
 * @var array $s
 */

defined( 'ABSPATH' ) || exit;

$aab_ms_connected = AAB_Microsoft::is_connected();
$aab_ms_cals      = $aab_ms_connected ? AAB_Microsoft::calendars( isset( $_GET['refresh'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache refresh.
?>
<div class="aab-card" id="aab-outlook">
	<h2><?php esc_html_e( 'Outlook / Microsoft 365 calendars', 'anderson-appointment-booking' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Optional. If some of your meetings live in Outlook (work or personal), connect it so they block times too. Only busy/free is read; bookings are still created in Google Calendar.', 'anderson-appointment-booking' ); ?></p>

	<?php if ( $aab_ms_connected ) : ?>
		<p class="aab-connection aab-connection--on">
			<?php
			echo '' !== AAB_Microsoft::account()
				/* translators: %s: Microsoft account */
				? wp_kses( sprintf( __( 'Connected as %s.', 'anderson-appointment-booking' ), '<strong>' . esc_html( AAB_Microsoft::account() ) . '</strong>' ), array( 'strong' => array() ) )
				: esc_html__( 'Connected.', 'anderson-appointment-booking' );
			?>
		</p>
		<?php if ( is_wp_error( $aab_ms_cals ) ) : ?>
			<p class="aab-error">
				<?php
				/* translators: %s: error */
				printf( esc_html__( 'Your Outlook calendars could not be loaded: %s', 'anderson-appointment-booking' ), esc_html( $aab_ms_cals->get_error_message() ) );
				?>
			</p>
		<?php else : ?>
			<input type="hidden" name="aab[ms_calendars_present]" value="1">
			<fieldset class="aab-calendar-list">
				<legend class="aab-control__label"><?php esc_html_e( 'Block times that are busy in', 'anderson-appointment-booking' ); ?></legend>
				<?php foreach ( $aab_ms_cals as $aab_cal ) : ?>
					<label>
						<input type="checkbox" name="aab[ms_calendars][]" value="<?php echo esc_attr( $aab_cal['id'] ); ?>" <?php checked( in_array( $aab_cal['id'], (array) $s['ms_calendars'], true ) ); ?>>
						<?php echo esc_html( $aab_cal['name'] . ( $aab_cal['default'] ? ' ' . __( '(default)', 'anderson-appointment-booking' ) : '' ) ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Events shown as Busy, Tentative or Out of office block times; Free and Working elsewhere don’t. Recurring meetings are included.', 'anderson-appointment-booking' ); ?></p>
		<?php endif; ?>
		<p><button type="submit" form="aab-ms-disconnect" class="button"><?php esc_html_e( 'Disconnect Outlook', 'anderson-appointment-booking' ); ?></button></p>
	<?php else : ?>
		<details class="aab-steps">
			<summary><?php esc_html_e( 'How to connect Outlook (about 5 minutes)', 'anderson-appointment-booking' ); ?></summary>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: link to Microsoft Entra admin center */
						esc_html__( 'In %s, go to App registrations → New registration.', 'anderson-appointment-booking' ),
						'<a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Microsoft Entra</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Supported account types: “Accounts in any organizational directory and personal Microsoft accounts”. Redirect URI: platform Web, with this exact address:', 'anderson-appointment-booking' ); ?>
					<span class="aab-copy">
						<input type="text" readonly value="<?php echo esc_attr( AAB_Microsoft::redirect_uri() ); ?>" class="large-text code" id="aab-ms-redirect">
						<button type="button" class="button" data-aab-copy="aab-ms-redirect"><?php esc_html_e( 'Copy', 'anderson-appointment-booking' ); ?></button>
					</span>
				</li>
				<li><?php esc_html_e( 'Under API permissions, add Microsoft Graph delegated permissions Calendars.Read, User.Read and offline_access.', 'anderson-appointment-booking' ); ?></li>
				<li><?php esc_html_e( 'Under Certificates & secrets, create a client secret. Paste the Application (client) ID and the secret’s Value below, save, then click Connect Outlook. Secrets expire (24 months at most), so note the date.', 'anderson-appointment-booking' ); ?></li>
			</ol>
		</details>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="aab-ms-client-id"><?php esc_html_e( 'Application (client) ID', 'anderson-appointment-booking' ); ?></label></th>
				<td>
					<?php if ( defined( 'AAB_MS_CLIENT_ID' ) ) : ?>
						<code><?php esc_html_e( 'Set in wp-config.php', 'anderson-appointment-booking' ); ?></code>
					<?php else : ?>
						<input type="text" id="aab-ms-client-id" name="aab[ms_client_id]" value="<?php echo esc_attr( $s['ms_client_id'] ); ?>" class="regular-text code" autocomplete="off">
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aab-ms-client-secret"><?php esc_html_e( 'Client secret', 'anderson-appointment-booking' ); ?></label></th>
				<td>
					<?php if ( defined( 'AAB_MS_CLIENT_SECRET' ) ) : ?>
						<code><?php esc_html_e( 'Set in wp-config.php', 'anderson-appointment-booking' ); ?></code>
					<?php else : ?>
						<input type="password" id="aab-ms-client-secret" name="aab[ms_client_secret]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo '' !== AAB_Microsoft::client_secret() ? esc_attr__( 'Saved. Leave blank to keep it.', 'anderson-appointment-booking' ) : ''; ?>">
						<p class="description"><?php esc_html_e( 'Stored encrypted. You can instead define AAB_MS_CLIENT_ID and AAB_MS_CLIENT_SECRET in wp-config.php.', 'anderson-appointment-booking' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php if ( AAB_Microsoft::has_credentials() ) : ?>
			<p><button type="submit" form="aab-ms-connect" class="button button-primary"><?php esc_html_e( 'Connect Outlook', 'anderson-appointment-booking' ); ?></button></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
