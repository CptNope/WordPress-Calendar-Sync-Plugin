<?php
/**
 * Appearance settings with a live preview rendered inside the site's theme.
 *
 * Included from settings.php; $s holds the current settings.
 *
 * @package AndersonAppointmentBooking
 *
 * @var array $s
 */

defined( 'ABSPATH' ) || exit;

$aab_tokens  = AAB_Style::theme_tokens();
$aab_palette = $aab_tokens['palette'];
$aab_theme   = wp_get_theme();
$aab_radii   = array(
	'auto'    => null !== $aab_tokens['radius']
		/* translators: %s: CSS length such as 8px */
		? sprintf( __( 'Match my theme (%s)', 'anderson-appointment-booking' ), $aab_tokens['radius'] )
		: __( 'Match my theme', 'anderson-appointment-booking' ),
	'square'  => __( 'Square', 'anderson-appointment-booking' ),
	'soft'    => __( 'Slightly rounded', 'anderson-appointment-booking' ),
	'rounded' => __( 'Rounded', 'anderson-appointment-booking' ),
	'round'   => __( 'Very rounded', 'anderson-appointment-booking' ),
);
$aab_can_css = current_user_can( 'unfiltered_html' );
$aab_preview = add_query_arg( 'aab_preview', '1', home_url( '/' ) );
?>
<div class="aab-card aab-card--wide" id="aab-appearance">
	<h2><?php esc_html_e( 'Appearance', 'anderson-appointment-booking' ); ?></h2>
	<p class="description">
		<?php
		if ( 'theme.json' === $aab_tokens['source'] ) {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. “Match my theme” also pulls colors, buttons, corners and heading font from %s.', 'anderson-appointment-booking' ), '<strong>' . esc_html( $aab_theme->get( 'Name' ) ) . '</strong>' );
		} elseif ( 'palette' === $aab_tokens['source'] ) {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. %s is a classic theme, so “Match my theme” uses its color palette, or reads its button and link colors from the page, and leaves buttons and fields to its stylesheet.', 'anderson-appointment-booking' ), '<strong>' . esc_html( $aab_theme->get( 'Name' ) ) . '</strong>' );
		} else {
			/* translators: %s: theme name */
			printf( esc_html__( 'The calendar uses your page’s fonts and text color everywhere. %s doesn’t publish a color palette, so “Match my theme” reads its button and link colors from the page and leaves buttons and fields to its stylesheet.', 'anderson-appointment-booking' ), '<strong>' . esc_html( $aab_theme->get( 'Name' ) ) . '</strong>' );
		}
		?>
	</p>

	<div class="aab-appearance">
		<div class="aab-appearance__controls">
			<fieldset class="aab-control">
				<legend><?php esc_html_e( 'Accent color', 'anderson-appointment-booking' ); ?></legend>
				<label class="aab-choice">
					<input type="radio" name="aab[style_accent]" value="theme" <?php checked( $s['style_accent'], 'theme' ); ?>>
					<?php esc_html_e( 'Match my theme', 'anderson-appointment-booking' ); ?>
					<?php if ( null !== $aab_tokens['accent_hex'] ) : ?>
						<span class="aab-swatch" style="background:<?php echo esc_attr( $aab_tokens['accent_hex'] ); ?>" title="<?php echo esc_attr( $aab_tokens['accent_hex'] ); ?>"></span>
					<?php else : ?>
						<span class="aab-hint"><?php esc_html_e( '(read from your theme’s buttons and links on the page; the color below is the fallback)', 'anderson-appointment-booking' ); ?></span>
					<?php endif; ?>
				</label>
				<label class="aab-choice">
					<input type="radio" name="aab[style_accent]" value="custom" <?php checked( $s['style_accent'], 'custom' ); ?>>
					<?php esc_html_e( 'Choose a color', 'anderson-appointment-booking' ); ?>
				</label>
				<div class="aab-custom-accent">
					<input type="text" id="aab-accent" name="aab[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" class="aab-color" data-default-color="#2e7d4f" data-aab-target="accent">
					<?php if ( $aab_palette ) : ?>
						<div class="aab-palette" role="group" aria-label="<?php esc_attr_e( 'Your theme’s colors', 'anderson-appointment-booking' ); ?>">
							<?php foreach ( array_slice( $aab_palette, 0, 16, true ) as $aab_slug => $aab_hex ) : ?>
								<button type="button" class="aab-swatch aab-swatch--pick" style="background:<?php echo esc_attr( $aab_hex ); ?>" data-color="<?php echo esc_attr( $aab_hex ); ?>" data-target="aab-accent" title="<?php echo esc_attr( $aab_slug . ' ' . $aab_hex ); ?>"><span class="screen-reader-text"><?php echo esc_html( $aab_slug ); ?></span></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</fieldset>

			<fieldset class="aab-control">
				<legend><?php esc_html_e( 'Booking button and form fields', 'anderson-appointment-booking' ); ?></legend>
				<label class="aab-choice"><input type="radio" name="aab[style_buttons]" value="theme" <?php checked( $s['style_buttons'], 'theme' ); ?>> <?php esc_html_e( 'Use my theme’s buttons and fields', 'anderson-appointment-booking' ); ?></label>
				<label class="aab-choice"><input type="radio" name="aab[style_buttons]" value="plugin" <?php checked( $s['style_buttons'], 'plugin' ); ?>> <?php esc_html_e( 'Use the calendar’s own style (accent color)', 'anderson-appointment-booking' ); ?></label>
			</fieldset>

			<div class="aab-control">
				<label for="aab-style-radius" class="aab-control__label"><?php esc_html_e( 'Corners', 'anderson-appointment-booking' ); ?></label>
				<select id="aab-style-radius" name="aab[style_radius]">
					<?php foreach ( $aab_radii as $aab_key => $aab_label ) : ?>
						<option value="<?php echo esc_attr( $aab_key ); ?>" <?php selected( $s['style_radius'], $aab_key ); ?>><?php echo esc_html( $aab_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="aab-control">
				<label for="aab-style-density" class="aab-control__label"><?php esc_html_e( 'Spacing', 'anderson-appointment-booking' ); ?></label>
				<select id="aab-style-density" name="aab[style_density]">
					<option value="compact" <?php selected( $s['style_density'], 'compact' ); ?>><?php esc_html_e( 'Compact', 'anderson-appointment-booking' ); ?></option>
					<option value="comfortable" <?php selected( $s['style_density'], 'comfortable' ); ?>><?php esc_html_e( 'Comfortable', 'anderson-appointment-booking' ); ?></option>
					<option value="roomy" <?php selected( $s['style_density'], 'roomy' ); ?>><?php esc_html_e( 'Roomy', 'anderson-appointment-booking' ); ?></option>
				</select>
			</div>

			<fieldset class="aab-control">
				<legend><?php esc_html_e( 'Calendar background', 'anderson-appointment-booking' ); ?></legend>
				<label class="aab-choice"><input type="radio" name="aab[style_background]" value="none" <?php checked( $s['style_background'], 'none' ); ?>> <?php esc_html_e( 'None (shows the page behind it)', 'anderson-appointment-booking' ); ?></label>
				<?php if ( null !== $aab_tokens['base'] ) : ?>
					<label class="aab-choice"><input type="radio" name="aab[style_background]" value="theme" <?php checked( $s['style_background'], 'theme' ); ?>> <?php esc_html_e( 'My theme’s page color', 'anderson-appointment-booking' ); ?></label>
				<?php endif; ?>
				<label class="aab-choice"><input type="radio" name="aab[style_background]" value="custom" <?php checked( $s['style_background'], 'custom' ); ?>> <?php esc_html_e( 'Choose a color', 'anderson-appointment-booking' ); ?></label>
				<div class="aab-custom-bg">
					<input type="text" id="aab-bg" name="aab[style_bg_color]" value="<?php echo esc_attr( $s['style_bg_color'] ); ?>" class="aab-color" data-default-color="#ffffff" data-aab-target="bg">
				</div>
			</fieldset>

			<fieldset class="aab-control">
				<legend><?php esc_html_e( 'Headings', 'anderson-appointment-booking' ); ?></legend>
				<label class="aab-choice"><input type="radio" name="aab[style_headings]" value="theme" <?php checked( $s['style_headings'], 'theme' ); ?> <?php disabled( null === $aab_tokens['heading_font'] ); ?>> <?php esc_html_e( 'My theme’s heading font', 'anderson-appointment-booking' ); ?><?php echo null === $aab_tokens['heading_font'] ? ' <span class="aab-hint">' . esc_html__( '(same as body text in this theme)', 'anderson-appointment-booking' ) . '</span>' : ''; ?></label>
				<label class="aab-choice"><input type="radio" name="aab[style_headings]" value="inherit" <?php checked( 'inherit' === $s['style_headings'] || null === $aab_tokens['heading_font'] ); ?>> <?php esc_html_e( 'Same font as the text around it', 'anderson-appointment-booking' ); ?></label>
			</fieldset>

			<details class="aab-control aab-css" <?php echo '' !== trim( (string) $s['custom_css'] ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Custom CSS', 'anderson-appointment-booking' ); ?></summary>
				<?php if ( $aab_can_css ) : ?>
					<textarea id="aab-custom-css" name="aab[custom_css]" rows="8" class="large-text code" spellcheck="false" placeholder=".aab {&#10;  --aab-accent: #0b5fff;&#10;  --aab-radius: 4px;&#10;}"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Loaded only on pages with the calendar. Useful variables:', 'anderson-appointment-booking' ); ?>
						<code>--aab-accent</code> <code>--aab-on-accent</code> <code>--aab-radius</code> <code>--aab-space</code> <code>--aab-surface</code> <code>--aab-line</code> <code>--aab-muted</code> <code>--aab-heading-font</code> <code>--aab-max-width</code>.
						<?php esc_html_e( 'Parts:', 'anderson-appointment-booking' ); ?>
						<code>.aab__day</code> <code>.aab__time</code> <code>.aab__submit</code> <code>.aab__input</code> <code>.aab__panel</code>.
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Only users allowed to post unfiltered HTML can edit custom CSS.', 'anderson-appointment-booking' ); ?></p>
				<?php endif; ?>
			</details>
		</div>

		<div class="aab-appearance__preview">
			<div class="aab-preview-bar">
				<span><?php esc_html_e( 'Preview on your theme, with sample times', 'anderson-appointment-booking' ); ?></span>
				<span class="aab-preview-sizes" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'anderson-appointment-booking' ); ?>">
					<button type="button" class="button button-small is-pressed" data-aab-width="100%" aria-pressed="true"><?php esc_html_e( 'Wide', 'anderson-appointment-booking' ); ?></button>
					<button type="button" class="button button-small" data-aab-width="390px" aria-pressed="false"><?php esc_html_e( 'Phone', 'anderson-appointment-booking' ); ?></button>
				</span>
			</div>
			<div class="aab-preview-frame">
				<iframe id="aab-preview" src="<?php echo esc_url( $aab_preview ); ?>" title="<?php esc_attr_e( 'Booking calendar preview', 'anderson-appointment-booking' ); ?>" loading="lazy"></iframe>
			</div>
		</div>
	</div>
</div>
