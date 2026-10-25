<?php
/**
 * Appointments → Appointment types: list, add, edit, reorder, delete.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen for appointment types.
 */
final class AAB_Admin_Types {

	const SLUG = 'aab-types';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_aab_save_type', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_aab_delete_type', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_aab_move_type', array( __CLASS__, 'move' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page(
			AAB_Admin::SLUG,
			__( 'Appointment types', 'anderson-appointment-booking' ),
			__( 'Appointment types', 'anderson-appointment-booking' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			1
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * CSS/JS on this screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'aab-admin', AAB_URL . 'assets/css/admin.css', array(), AAB_VERSION );
		wp_enqueue_script( 'aab-admin-types', AAB_URL . 'assets/js/admin-types.js', array(), AAB_VERSION, true );
		wp_localize_script(
			'aab-admin-types',
			'aabTypes',
			array(
				'kinds'   => AAB_Types::question_kinds(),
				'i18n'    => array(
					'question' => __( 'Question', 'anderson-appointment-booking' ),
					'kind'     => __( 'Answer type', 'anderson-appointment-booking' ),
					'options'  => __( 'Choices (one per line)', 'anderson-appointment-booking' ),
					'required' => __( 'Required', 'anderson-appointment-booking' ),
					'remove'   => __( 'Remove', 'anderson-appointment-booking' ),
					'up'       => __( 'Move up', 'anderson-appointment-booking' ),
					'down'     => __( 'Move down', 'anderson-appointment-booking' ),
					'copied'   => __( 'Copied', 'anderson-appointment-booking' ),
				),
			)
		);
	}

	/**
	 * Permission + nonce check.
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
	 * Redirect with a notice.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @param array  $args    Extra query args.
	 */
	private static function back( $type, $message, array $args = array() ) {
		set_transient(
			'aab_types_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Save a type.
	 */
	public static function save() {
		self::guard( 'aab_save_type' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in AAB_Types::clean().
		$in       = isset( $_POST['type'] ) && is_array( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : array();
		$existing = isset( $_POST['existing'] ) ? sanitize_key( wp_unslash( $_POST['existing'] ) ) : '';
		if ( '' !== $existing && ! AAB_Types::get( $existing ) ) {
			$existing = '';
		}

		$result = AAB_Types::clean( $in, $existing );
		if ( $result['errors'] ) {
			// Keep what they typed so nothing is lost.
			set_transient( 'aab_type_draft_' . get_current_user_id(), $result['type'], 300 );
			self::back( 'error', implode( ' ', $result['errors'] ), array( 'edit' => '' !== $existing ? $existing : 'new' ) );
		}

		$type = $result['type'];
		if ( ! $type['active'] ) {
			$others = array_filter(
				AAB_Types::active(),
				static function ( $t ) use ( $type ) {
					return $t['id'] !== $type['id'];
				}
			);
			if ( ! $others ) {
				$type['active'] = 1;
				AAB_Types::save( $type );
				self::back( 'error', __( 'Saved, but kept visible: at least one appointment type must be bookable.', 'anderson-appointment-booking' ), array( 'edit' => $type['id'] ) );
			}
		}
		AAB_Types::save( $type );
		AAB_Availability::bust_cache();
		/* translators: %s: appointment type name */
		self::back( 'success', sprintf( __( '“%s” saved.', 'anderson-appointment-booking' ), $type['name'] ) );
	}

	/**
	 * Delete a type.
	 */
	public static function delete() {
		$id = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
		self::guard( 'aab_delete_type_' . $id );
		$type = AAB_Types::get( $id );
		if ( ! $type ) {
			self::back( 'error', __( 'That appointment type no longer exists.', 'anderson-appointment-booking' ) );
		}
		if ( count( AAB_Types::all() ) < 2 ) {
			self::back( 'error', __( 'You need at least one appointment type. Edit this one instead.', 'anderson-appointment-booking' ) );
		}
		AAB_Types::delete( $id );
		if ( ! AAB_Types::active() ) {
			$all              = AAB_Types::all();
			$all[0]['active'] = 1;
			AAB_Types::save_all( $all );
		}
		/* translators: %s: appointment type name */
		self::back( 'success', sprintf( __( '“%s” deleted. Existing bookings of this type are kept and can still be rescheduled.', 'anderson-appointment-booking' ), $type['name'] ) );
	}

	/**
	 * Reorder.
	 */
	public static function move() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked in guard().
		$id  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$dir = isset( $_GET['dir'] ) && 'up' === $_GET['dir'] ? -1 : 1;
		// phpcs:enable
		self::guard( 'aab_move_type_' . $id );
		AAB_Types::move( $id, $dir );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Render list or edit form.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( 'aab_types_notice_' . get_current_user_id() );
		delete_transient( 'aab_types_notice_' . get_current_user_id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';

		echo '<div class="wrap aab-admin">';
		if ( is_array( $notice ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $notice['type'] ? 'error' : 'success', esc_html( $notice['message'] ) );
		}
		if ( '' !== $edit ) {
			self::render_form( $edit );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	/**
	 * Hours summary for the list.
	 *
	 * @param array $t Type.
	 * @return string
	 */
	private static function hours_summary( array $t ) {
		return empty( $t['custom_hours'] ) ? __( 'Your usual hours', 'anderson-appointment-booking' ) : __( 'Own hours', 'anderson-appointment-booking' );
	}

	/**
	 * The list of types.
	 */
	private static function render_list() {
		$types = AAB_Types::all();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Appointment types', 'anderson-appointment-booking' ); ?></h1>
		<a href="<?php echo esc_url( self::url( array( 'edit' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add appointment type', 'anderson-appointment-booking' ); ?></a>
		<hr class="wp-header-end">
		<p class="description aab-types-intro"><?php esc_html_e( 'Visitors pick one of these first, then a time. Each type can have its own length, hours and questions; buffers, notice, days off and the booking window are shared and set under Settings.', 'anderson-appointment-booking' ); ?></p>

		<table class="widefat striped aab-types-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'anderson-appointment-booking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Length', 'anderson-appointment-booking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hours', 'anderson-appointment-booking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Questions', 'anderson-appointment-booking' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode for this type only', 'anderson-appointment-booking' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'anderson-appointment-booking' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $types as $i => $t ) : ?>
				<?php
				$edit_url   = self::url( array( 'edit' => $t['id'] ) );
				$delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'aab_delete_type', 'type' => $t['id'] ), admin_url( 'admin-post.php' ) ), 'aab_delete_type_' . $t['id'] );
				$move       = static function ( $dir ) use ( $t ) {
					return wp_nonce_url( add_query_arg( array( 'action' => 'aab_move_type', 'type' => $t['id'], 'dir' => $dir ), admin_url( 'admin-post.php' ) ), 'aab_move_type_' . $t['id'] );
				};
				$code       = '[anderson_booking type="' . $t['id'] . '"]';
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $t['name'] ); ?></a></strong>
						<?php if ( empty( $t['active'] ) ) : ?>
							<span class="aab-pill"><?php esc_html_e( 'Hidden', 'anderson-appointment-booking' ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $t['description'] ) : ?>
							<br><span class="description"><?php echo esc_html( wp_trim_words( $t['description'], 16 ) ); ?></span>
						<?php endif; ?>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'anderson-appointment-booking' ); ?></a></span>
							<?php if ( count( $types ) > 1 ) : ?>
								| <span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this appointment type? Existing bookings are kept.', 'anderson-appointment-booking' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'anderson-appointment-booking' ); ?></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td>
						<?php
						/* translators: %d: minutes */
						echo esc_html( sprintf( __( '%d min', 'anderson-appointment-booking' ), $t['duration'] ) );
						?>
					</td>
					<td><?php echo esc_html( self::hours_summary( $t ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( count( $t['questions'] ) ) ); ?></td>
					<td><code class="aab-code"><?php echo esc_html( $code ); ?></code></td>
					<td class="aab-order">
						<?php if ( $i > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $move( 'up' ) ); ?>" aria-label="<?php esc_attr_e( 'Move up', 'anderson-appointment-booking' ); ?>">↑</a>
						<?php endif; ?>
						<?php if ( $i < count( $types ) - 1 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $move( 'down' ) ); ?>" aria-label="<?php esc_attr_e( 'Move down', 'anderson-appointment-booking' ); ?>">↓</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The order here is the order visitors see. [anderson_booking] without a type shows all visible types.', 'anderson-appointment-booking' ); ?></p>
		<?php
	}

	/**
	 * Add/edit form.
	 *
	 * @param string $edit Type ID or 'new'.
	 */
	private static function render_form( $edit ) {
		$is_new = 'new' === $edit;
		$type   = $is_new ? AAB_Types::defaults() : AAB_Types::get( $edit );
		if ( ! $type ) {
			echo '<p>' . esc_html__( 'That appointment type no longer exists.', 'anderson-appointment-booking' ) . '</p>';
			return;
		}
		$draft = get_transient( 'aab_type_draft_' . get_current_user_id() );
		if ( is_array( $draft ) ) {
			delete_transient( 'aab_type_draft_' . get_current_user_id() );
			$type = array_merge( $type, $draft );
		}
		if ( $is_new ) {
			$type['hours'] = (array) AAB_Settings::get( 'hours' );
		}
		$days    = array(
			'mon' => __( 'Monday', 'anderson-appointment-booking' ),
			'tue' => __( 'Tuesday', 'anderson-appointment-booking' ),
			'wed' => __( 'Wednesday', 'anderson-appointment-booking' ),
			'thu' => __( 'Thursday', 'anderson-appointment-booking' ),
			'fri' => __( 'Friday', 'anderson-appointment-booking' ),
			'sat' => __( 'Saturday', 'anderson-appointment-booking' ),
			'sun' => __( 'Sunday', 'anderson-appointment-booking' ),
		);
		$global  = (array) AAB_Settings::get( 'hours' );
		$meet_on = (bool) AAB_Settings::get( 'add_meet' );
		$loc     = trim( (string) AAB_Settings::get( 'location' ) );
		?>
		<h1><?php echo $is_new ? esc_html__( 'Add appointment type', 'anderson-appointment-booking' ) : esc_html( $type['name'] ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All appointment types', 'anderson-appointment-booking' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aab-type-form">
			<input type="hidden" name="action" value="aab_save_type">
			<input type="hidden" name="existing" value="<?php echo esc_attr( $is_new ? '' : $type['id'] ); ?>">
			<?php wp_nonce_field( 'aab_save_type' ); ?>

			<div class="aab-card">
				<h2><?php esc_html_e( 'Basics', 'anderson-appointment-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aab-type-name"><?php esc_html_e( 'Name', 'anderson-appointment-booking' ); ?></label></th>
						<td>
							<input type="text" id="aab-type-name" name="type[name]" value="<?php echo esc_attr( $type['name'] ); ?>" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. 15-minute intro call', 'anderson-appointment-booking' ); ?>">
							<?php if ( ! $is_new ) : ?>
								<p class="description">
									<?php esc_html_e( 'Shortcode for this type only:', 'anderson-appointment-booking' ); ?>
									<code>[anderson_booking type="<?php echo esc_html( $type['id'] ); ?>"]</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aab-type-desc"><?php esc_html_e( 'Description', 'anderson-appointment-booking' ); ?></label></th>
						<td><textarea id="aab-type-desc" name="type[description]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Optional: shown to visitors under the name.', 'anderson-appointment-booking' ); ?>"><?php echo esc_textarea( $type['description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="aab-type-duration"><?php esc_html_e( 'Length', 'anderson-appointment-booking' ); ?></label></th>
						<td><input type="number" min="5" max="480" id="aab-type-duration" name="type[duration]" value="<?php echo esc_attr( $type['duration'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'anderson-appointment-booking' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="aab-type-interval"><?php esc_html_e( 'Start times every', 'anderson-appointment-booking' ); ?></label></th>
						<td>
							<input type="number" min="5" max="480" id="aab-type-interval" name="type[interval]" value="<?php echo esc_attr( $type['interval'] ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'anderson-appointment-booking' ); ?>
							<p class="description"><?php esc_html_e( 'E.g. 30 offers 9:00, 9:30, 10:00…', 'anderson-appointment-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visible', 'anderson-appointment-booking' ); ?></th>
						<td><label><input type="checkbox" name="type[active]" value="1" <?php checked( $type['active'] ); ?>> <?php esc_html_e( 'Visitors can book this type', 'anderson-appointment-booking' ); ?></label></td>
					</tr>
				</table>
			</div>

			<div class="aab-card">
				<h2><?php esc_html_e( 'When', 'anderson-appointment-booking' ); ?></h2>
				<fieldset class="aab-control">
					<legend class="screen-reader-text"><?php esc_html_e( 'Hours', 'anderson-appointment-booking' ); ?></legend>
					<label class="aab-choice"><input type="radio" name="type[custom_hours]" value="0" <?php checked( empty( $type['custom_hours'] ) ); ?>> <?php esc_html_e( 'Use my usual hours (from Settings)', 'anderson-appointment-booking' ); ?></label>
					<label class="aab-choice"><input type="radio" name="type[custom_hours]" value="1" <?php checked( ! empty( $type['custom_hours'] ) ); ?>> <?php esc_html_e( 'Use different hours for this type', 'anderson-appointment-booking' ); ?></label>
				</fieldset>
				<table class="form-table aab-type-hours" role="presentation">
					<?php foreach ( $days as $key => $label ) : ?>
						<tr>
							<th scope="row"><label for="aab-type-hours-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" id="aab-type-hours-<?php echo esc_attr( $key ); ?>" name="type[hours][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( isset( $type['hours'][ $key ] ) ? $type['hours'][ $key ] : '' ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( '' !== ( isset( $global[ $key ] ) ? $global[ $key ] : '' ) ? $global[ $key ] : __( 'Closed', 'anderson-appointment-booking' ) ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( '24-hour ranges, breaks separated by commas, e.g. 09:00-12:00, 13:00-17:00. Leave a day empty to close it for this type. Your other calendar commitments still block times as usual.', 'anderson-appointment-booking' ); ?></p>
			</div>

			<div class="aab-card">
				<h2><?php esc_html_e( 'Where', 'anderson-appointment-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aab-type-location"><?php esc_html_e( 'Location', 'anderson-appointment-booking' ); ?></label></th>
						<td>
							<input type="text" id="aab-type-location" name="type[location]" value="<?php echo esc_attr( $type['location'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( '' !== $loc ? $loc : __( 'Optional: an address or phone number', 'anderson-appointment-booking' ) ); ?>">
							<?php if ( '' !== $loc ) : ?>
								<p class="description"><?php esc_html_e( 'Leave empty to use the location from Settings.', 'anderson-appointment-booking' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aab-type-meet"><?php esc_html_e( 'Video call', 'anderson-appointment-booking' ); ?></label></th>
						<td>
							<select id="aab-type-meet" name="type[meet]">
								<option value="default" <?php selected( $type['meet'], 'default' ); ?>>
									<?php echo esc_html( $meet_on ? __( 'Default (add a Google Meet link)', 'anderson-appointment-booking' ) : __( 'Default (no video link)', 'anderson-appointment-booking' ) ); ?>
								</option>
								<option value="on" <?php selected( $type['meet'], 'on' ); ?>><?php esc_html_e( 'Add a Google Meet link', 'anderson-appointment-booking' ); ?></option>
								<option value="off" <?php selected( $type['meet'], 'off' ); ?>><?php esc_html_e( 'No video link (in person or phone)', 'anderson-appointment-booking' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<div class="aab-card">
				<h2><?php esc_html_e( 'Questions', 'anderson-appointment-booking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Asked after name and email. Answers go into the calendar event, your notification email and the booking record.', 'anderson-appointment-booking' ); ?></p>
				<div class="aab-questions" id="aab-questions" data-next="<?php echo esc_attr( count( $type['questions'] ) ); ?>">
					<?php foreach ( $type['questions'] as $i => $q ) : ?>
						<?php self::question_row( $i, $q ); ?>
					<?php endforeach; ?>
				</div>
				<template id="aab-question-template"><?php self::question_row( '__i__', array() ); ?></template>
				<p><button type="button" class="button" id="aab-add-question"><?php esc_html_e( 'Add question', 'anderson-appointment-booking' ); ?></button></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Notes box', 'anderson-appointment-booking' ); ?></th>
						<td><label><input type="checkbox" name="type[notes]" value="1" <?php checked( $type['notes'] ); ?>> <?php esc_html_e( 'Also show “Anything I should know?” (optional)', 'anderson-appointment-booking' ); ?></label></td>
					</tr>
				</table>
			</div>

			<?php submit_button( $is_new ? __( 'Add appointment type', 'anderson-appointment-booking' ) : __( 'Save appointment type', 'anderson-appointment-booking' ) ); ?>
		</form>
		<?php
	}

	/**
	 * One question row (also used as the JS template with index "__i__").
	 *
	 * @param int|string $i Index.
	 * @param array      $q Question.
	 */
	private static function question_row( $i, array $q ) {
		$q     = wp_parse_args(
			$q,
			array(
				'id'       => '',
				'label'    => '',
				'kind'     => 'text',
				'required' => 0,
				'options'  => array(),
			)
		);
		$name  = 'type[questions][' . $i . ']';
		$base  = 'aab-q-' . $i;
		?>
		<fieldset class="aab-question" data-kind="<?php echo esc_attr( $q['kind'] ); ?>">
			<legend class="screen-reader-text"><?php esc_html_e( 'Question', 'anderson-appointment-booking' ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $q['id'] ); ?>">
			<div class="aab-question__main">
				<label for="<?php echo esc_attr( $base ); ?>-label"><?php esc_html_e( 'Question', 'anderson-appointment-booking' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $base ); ?>-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $q['label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. What would you like to talk about?', 'anderson-appointment-booking' ); ?>">
			</div>
			<div>
				<label for="<?php echo esc_attr( $base ); ?>-kind"><?php esc_html_e( 'Answer type', 'anderson-appointment-booking' ); ?></label>
				<select id="<?php echo esc_attr( $base ); ?>-kind" name="<?php echo esc_attr( $name ); ?>[kind]" class="aab-question__kind">
					<?php foreach ( AAB_Types::question_kinds() as $kind => $label ) : ?>
						<option value="<?php echo esc_attr( $kind ); ?>" <?php selected( $q['kind'], $kind ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="aab-question__options">
				<label for="<?php echo esc_attr( $base ); ?>-options"><?php esc_html_e( 'Choices (one per line)', 'anderson-appointment-booking' ); ?></label>
				<textarea id="<?php echo esc_attr( $base ); ?>-options" name="<?php echo esc_attr( $name ); ?>[options]" rows="3"><?php echo esc_textarea( implode( "\n", (array) $q['options'] ) ); ?></textarea>
			</div>
			<div class="aab-question__actions">
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $q['required'] ); ?>> <?php esc_html_e( 'Required', 'anderson-appointment-booking' ); ?></label>
				<button type="button" class="button-link aab-question__up"><?php esc_html_e( 'Move up', 'anderson-appointment-booking' ); ?></button>
				<button type="button" class="button-link button-link-delete aab-question__remove"><?php esc_html_e( 'Remove', 'anderson-appointment-booking' ); ?></button>
			</div>
		</fieldset>
		<?php
	}
}
