<?php
/**
 * Appearance: reads the active theme's design tokens and turns settings into CSS variables.
 *
 * Every visual choice the widget makes goes through a CSS custom property on the
 * widget element (--aab-accent, --aab-radius, …). "Match my theme" fills those
 * from theme.json (block themes) or the editor color palette (classic themes),
 * pointing at the theme's own --wp--preset--* variables so style variations and
 * dark palettes carry through automatically.
 *
 * @package AndersonAppointmentBooking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Style resolver.
 */
final class AAB_Style {

	/**
	 * Cached theme tokens for this request.
	 *
	 * @var array|null
	 */
	private static $tokens = null;

	/**
	 * Corner choices.
	 *
	 * @return array<string,string> key => CSS length ('' = take it from the theme).
	 */
	public static function radii() {
		return array(
			'auto'    => '',
			'square'  => '0px',
			'soft'    => '4px',
			'rounded' => '10px',
			'round'   => '18px',
		);
	}

	/**
	 * Spacing multipliers.
	 *
	 * @return array<string,string>
	 */
	public static function densities() {
		return array(
			'compact'     => '0.75',
			'comfortable' => '1',
			'roomy'       => '1.3',
		);
	}

	/**
	 * Allowed values for each style option; the first is the default.
	 *
	 * @return array<string,string[]>
	 */
	public static function choices() {
		return array(
			'style_accent'     => array( 'theme', 'custom' ),
			'style_buttons'    => array( 'theme', 'plugin' ),
			'style_radius'     => array_keys( self::radii() ),
			'style_background' => array( 'none', 'theme', 'custom' ),
			'style_density'    => array( 'comfortable', 'compact', 'roomy' ),
			'style_headings'   => array( 'theme', 'inherit' ),
		);
	}

	/**
	 * Clamp a style value to its allowed choices.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	public static function clean( $key, $value ) {
		$choices = self::choices();
		if ( ! isset( $choices[ $key ] ) ) {
			return '';
		}
		$value = sanitize_key( (string) $value );
		return in_array( $value, $choices[ $key ], true ) ? $value : $choices[ $key ][0];
	}

	/**
	 * Design tokens read from the active theme.
	 *
	 * @return array{
	 *   source:string, accent:?string, accent_hex:?string, on_accent:?string, on_accent_hex:?string,
	 *   base:?string, base_hex:?string, radius:?string, heading_font:?string, palette:array<string,string>
	 * }
	 */
	public static function theme_tokens() {
		if ( null !== self::$tokens ) {
			return self::$tokens;
		}

		$t = array(
			'source'        => 'none',
			'accent'        => null,
			'accent_hex'    => null,
			'on_accent'     => null,
			'on_accent_hex' => null,
			'base'          => null,
			'base_hex'      => null,
			'radius'        => null,
			'heading_font'  => null,
			'palette'       => self::palette(),
		);

		$has_json = function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json();

		if ( $has_json && function_exists( 'wp_get_global_styles' ) ) {
			$t['source'] = 'theme.json';

			// The theme's primary button is the most reliable "brand action" color it defines.
			$bg   = wp_get_global_styles( array( 'elements', 'button', 'color', 'background' ) );
			$text = wp_get_global_styles( array( 'elements', 'button', 'color', 'text' ) );
			list( $t['accent'], $t['accent_hex'] )       = self::color_ref( $bg );
			list( $t['on_accent'], $t['on_accent_hex'] ) = self::color_ref( $text );

			$page_bg                         = wp_get_global_styles( array( 'color', 'background' ) );
			list( $t['base'], $t['base_hex'] ) = self::color_ref( $page_bg );

			$radius = wp_get_global_styles( array( 'elements', 'button', 'border', 'radius' ) );
			if ( is_string( $radius ) && self::is_css_length( $radius ) ) {
				$t['radius'] = $radius;
			}

			$font = wp_get_global_styles( array( 'elements', 'heading', 'typography', 'fontFamily' ) );
			if ( is_string( $font ) && '' !== $font && 'inherit' !== $font ) {
				$t['heading_font'] = self::preset_to_var( $font );
			}
		} elseif ( $t['palette'] ) {
			$t['source'] = 'palette';
		}

		// No button color? Fall back to a palette color with a conventional "brand" name.
		if ( null === $t['accent'] && $t['palette'] ) {
			foreach ( array( 'primary', 'accent', 'accent-1', 'brand', 'secondary', 'theme-3', 'contrast', 'foreground' ) as $slug ) {
				if ( isset( $t['palette'][ $slug ] ) ) {
					$t['accent']     = 'var(--wp--preset--color--' . $slug . ', ' . $t['palette'][ $slug ] . ')';
					$t['accent_hex'] = $t['palette'][ $slug ];
					break;
				}
			}
		}

		// Text on the accent: the theme's button text, else whichever of white/near-black reads better.
		if ( null !== $t['accent_hex'] && ( null === $t['on_accent_hex'] || self::contrast( $t['accent_hex'], $t['on_accent_hex'] ) < 3 ) ) {
			$t['on_accent_hex'] = self::readable_on( $t['accent_hex'] );
			$t['on_accent']     = $t['on_accent_hex'];
		}

		/**
		 * Filter the design tokens read from the theme.
		 *
		 * @param array $tokens Tokens.
		 */
		self::$tokens = (array) apply_filters( 'aab_theme_tokens', $t );
		return self::$tokens;
	}

	/**
	 * The theme's color palette as slug => hex (theme colors first, then custom, then core defaults).
	 *
	 * @return array<string,string>
	 */
	public static function palette() {
		$out = array();
		if ( function_exists( 'wp_get_global_settings' ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				foreach ( isset( $palette[ $origin ] ) ? (array) $palette[ $origin ] : array() as $c ) {
					if ( isset( $c['slug'], $c['color'] ) && ! isset( $out[ $c['slug'] ] ) && self::hex( $c['color'] ) ) {
						$out[ $c['slug'] ] = self::hex( $c['color'] );
					}
				}
			}
			return $out;
		}
		$support = get_theme_support( 'editor-color-palette' );
		if ( is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ) {
			foreach ( $support[0] as $c ) {
				if ( isset( $c['slug'], $c['color'] ) && self::hex( $c['color'] ) ) {
					$out[ $c['slug'] ] = self::hex( $c['color'] );
				}
			}
		}
		return $out;
	}

	/**
	 * CSS variables and modifier classes for one widget.
	 *
	 * @param array $overrides Per-block or per-shortcode values (same keys as settings, plus 'accent' and 'background_color').
	 * @return array{vars:array<string,string>,classes:string[]}
	 */
	public static function resolve( array $overrides = array() ) {
		$s = AAB_Settings::all();
		foreach ( array_keys( self::choices() ) as $key ) {
			$s[ $key ] = self::clean( $key, isset( $overrides[ $key ] ) && '' !== $overrides[ $key ] ? $overrides[ $key ] : $s[ $key ] );
		}
		if ( ! empty( $overrides['accent'] ) && sanitize_hex_color( $overrides['accent'] ) ) {
			$s['accent']       = sanitize_hex_color( $overrides['accent'] );
			$s['style_accent'] = 'custom';
		}
		if ( ! empty( $overrides['background_color'] ) && sanitize_hex_color( $overrides['background_color'] ) ) {
			$s['style_bg_color']   = sanitize_hex_color( $overrides['background_color'] );
			$s['style_background'] = 'custom';
		}

		$t       = self::theme_tokens();
		$vars    = array();
		$classes = array();

		// Accent.
		$custom = sanitize_hex_color( (string) $s['accent'] );
		$custom = $custom ? $custom : '#2e7d4f';
		if ( 'theme' === $s['style_accent'] && null !== $t['accent'] ) {
			$vars['--aab-accent']    = $t['accent'];
			$vars['--aab-on-accent'] = (string) $t['on_accent'];
			if ( 'theme.json' !== $t['source'] ) {
				// Classic theme: the palette color is a guess from its name; the script confirms it from the page.
				$classes[] = 'aab--probe-accent';
			}
		} elseif ( 'theme' === $s['style_accent'] ) {
			// Theme publishes no colors (most classic themes): the custom color is a fallback and the
			// script reads the theme's real button/link color from the page once it loads.
			$vars['--aab-accent']    = $custom;
			$vars['--aab-on-accent'] = self::readable_on( $custom );
			$classes[]               = 'aab--probe-accent';
		} else {
			$vars['--aab-accent']    = $custom;
			$vars['--aab-on-accent'] = self::readable_on( $custom );
		}

		// Corners.
		$radii = self::radii();
		if ( 'auto' === $s['style_radius'] ) {
			$vars['--aab-radius'] = null !== $t['radius'] ? $t['radius'] : '10px';
			if ( null === $t['radius'] && 'theme.json' !== $t['source'] ) {
				$classes[] = 'aab--probe-radius';
			}
		} else {
			$vars['--aab-radius'] = $radii[ $s['style_radius'] ];
		}

		// Spacing.
		$vars['--aab-space'] = self::densities()[ $s['style_density'] ];

		// Background of the calendar box.
		if ( 'theme' === $s['style_background'] ) {
			$vars['--aab-surface'] = null !== $t['base'] ? $t['base'] : 'transparent';
		} elseif ( 'custom' === $s['style_background'] ) {
			$bg                    = sanitize_hex_color( (string) $s['style_bg_color'] );
			$vars['--aab-surface'] = $bg ? $bg : 'transparent';
		}

		// Headings.
		if ( 'theme' === $s['style_headings'] && null !== $t['heading_font'] ) {
			$vars['--aab-heading-font'] = $t['heading_font'];
		} elseif ( 'theme' === $s['style_headings'] && 'theme.json' !== $t['source'] ) {
			// Classic theme: the script reads the font the theme gives its headings.
			$classes[] = 'aab--probe-font';
		}

		$classes[] = 'aab--buttons-' . $s['style_buttons'];
		if ( 'theme.json' === $t['source'] ) {
			// Block themes style buttons through the .wp-element-button class; classic themes style plain <button>.
			$classes[] = 'aab--wp-buttons';
		}
		$classes[] = 'aab--density-' . $s['style_density'];

		/**
		 * Filter the CSS variables applied to a booking widget.
		 *
		 * @param array<string,string> $vars      Variables.
		 * @param array                $overrides Overrides passed to this widget.
		 */
		$vars = (array) apply_filters( 'aab_style_vars', $vars, $overrides );

		return array(
			'vars'    => $vars,
			'classes' => $classes,
		);
	}

	/**
	 * Render variables as an inline style string.
	 *
	 * @param array<string,string> $vars Variables.
	 * @return string
	 */
	public static function inline( array $vars ) {
		$out = '';
		foreach ( $vars as $name => $value ) {
			$value = self::safe_value( (string) $value );
			if ( preg_match( '/^--aab-[a-z0-9-]+$/', $name ) && '' !== $value ) {
				$out .= $name . ':' . $value . ';';
			}
		}
		return $out;
	}

	/**
	 * Saved custom CSS, safe to print inside a <style> element.
	 *
	 * @return string
	 */
	public static function custom_css() {
		$css = (string) AAB_Settings::get( 'custom_css' );
		return trim( str_ireplace( '</style', '', wp_strip_all_tags( $css ) ) );
	}

	/**
	 * Resolve a theme.json color value to [css value, hex] (either may be null).
	 *
	 * @param mixed $value 'var:preset|color|slug', 'var(--wp--preset--color--slug)', a hex, or anything else.
	 * @return array{0:?string,1:?string}
	 */
	private static function color_ref( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array( null, null );
		}
		$palette = self::palette();
		$slug    = null;
		if ( preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m ) || preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i', $value, $m ) ) {
			$slug = strtolower( $m[1] );
		}
		if ( null !== $slug ) {
			$hex = isset( $palette[ $slug ] ) ? $palette[ $slug ] : null;
			return array( 'var(--wp--preset--color--' . $slug . ( $hex ? ', ' . $hex : '' ) . ')', $hex );
		}
		$hex = self::hex( $value );
		if ( $hex ) {
			return array( $hex, $hex );
		}
		return array( null, null );
	}

	/**
	 * Convert a theme.json preset reference to a CSS variable.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	private static function preset_to_var( $value ) {
		if ( preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/i', $value, $m ) ) {
			return 'var(--wp--preset--' . strtolower( $m[1] ) . '--' . strtolower( $m[2] ) . ')';
		}
		$safe = self::safe_value( $value );
		return '' === $safe ? null : $safe;
	}

	/**
	 * Strip anything that could break out of a CSS declaration.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function safe_value( $value ) {
		if ( preg_match( '/[;{}<>\\\\]|\/\*|url\s*\(|expression\s*\(/i', $value ) ) {
			return '';
		}
		return trim( $value );
	}

	/**
	 * Is this a plain CSS length?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_css_length( $value ) {
		return (bool) preg_match( '/^(0|\d*\.?\d+(px|em|rem|%|vw|vh))$/', trim( $value ) );
	}

	/**
	 * Normalize #rgb / #rrggbb / #rrggbbaa to #rrggbb (alpha dropped), else null.
	 *
	 * @param string $value Color.
	 * @return string|null
	 */
	public static function hex( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m ) ) {
			return '#' . $m[1];
		}
		return null;
	}

	/**
	 * Relative luminance (WCAG).
	 *
	 * @param string $hex #rrggbb.
	 * @return float
	 */
	private static function luminance( $hex ) {
		$hex = self::hex( $hex );
		if ( ! $hex ) {
			return 0.0;
		}
		$c = array();
		foreach ( array( 1, 3, 5 ) as $i ) {
			$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$c[] = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * WCAG contrast ratio between two colors.
	 *
	 * @param string $a Color.
	 * @param string $b Color.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * White or near-black, whichever reads better on a background.
	 *
	 * @param string $bg Background color.
	 * @return string
	 */
	public static function readable_on( $bg ) {
		return self::contrast( $bg, '#ffffff' ) >= self::contrast( $bg, '#111111' ) ? '#ffffff' : '#111111';
	}
}
