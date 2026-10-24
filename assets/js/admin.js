/* Anderson Appointment Booking – settings screen. */
( function ( $ ) {
	'use strict';

	var data = window.aabAdmin || {};
	var tokens = data.tokens || {};

	function luminance( hex ) {
		var m = /^#?([0-9a-f]{6})/i.exec( hex || '' );
		if ( ! m ) {
			return 0;
		}
		var c = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( m[ 1 ].substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	}

	function contrast( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function readableOn( bg ) {
		return contrast( bg, '#ffffff' ) >= contrast( bg, '#111111' ) ? '#ffffff' : '#111111';
	}

	function val( name ) {
		var field = $( '[name="aab[' + name + ']"]' );
		if ( field.is( ':radio' ) ) {
			return field.filter( ':checked' ).val() || '';
		}
		return field.val() || '';
	}

	// Mirrors AAB_Style::resolve() so the preview updates as you click.
	function resolve() {
		var vars = {};
		var accentMode = val( 'style_accent' );
		var custom = val( 'accent' ) || '#2e7d4f';
		if ( accentMode === 'theme' && tokens.accent ) {
			vars[ '--aab-accent' ] = tokens.accent;
			vars[ '--aab-on-accent' ] = tokens.onAccent || '#ffffff';
		} else {
			vars[ '--aab-accent' ] = custom;
			vars[ '--aab-on-accent' ] = readableOn( custom );
		}

		var radius = val( 'style_radius' );
		vars[ '--aab-radius' ] = radius === 'auto' || ! data.radii[ radius ] ? tokens.radius || '10px' : data.radii[ radius ];
		vars[ '--aab-space' ] = data.densities[ val( 'style_density' ) ] || '1';

		var bg = val( 'style_background' );
		if ( bg === 'theme' ) {
			vars[ '--aab-surface' ] = tokens.base || 'transparent';
		} else if ( bg === 'custom' ) {
			vars[ '--aab-surface' ] = val( 'style_bg_color' ) || 'transparent';
		}
		if ( val( 'style_headings' ) === 'theme' && tokens.headingFont ) {
			vars[ '--aab-heading-font' ] = tokens.headingFont;
		}

		var classes = [ 'aab--buttons-' + ( val( 'style_buttons' ) || 'theme' ), 'aab--density-' + ( val( 'style_density' ) || 'comfortable' ) ];
		if ( tokens.json ) {
			classes.push( 'aab--wp-buttons' );
		}
		if ( accentMode === 'theme' && ( ! tokens.accent || ! tokens.json ) ) {
			classes.push( 'aab--probe-accent' );
		}
		if ( radius === 'auto' && ! tokens.radius && ! tokens.json ) {
			classes.push( 'aab--probe-radius' );
		}

		return {
			style: Object.keys( vars ).map( function ( k ) {
				return k + ':' + vars[ k ];
			} ).join( ';' ),
			classes: classes,
		};
	}

	var frame = document.getElementById( 'aab-preview' );
	var pending = null;

	function sync() {
		$( '.aab-custom-accent' ).toggleClass( 'is-off', val( 'style_accent' ) !== 'custom' );
		$( '.aab-custom-bg' ).toggleClass( 'is-off', val( 'style_background' ) !== 'custom' );
		if ( ! frame || ! frame.contentWindow ) {
			return;
		}
		clearTimeout( pending );
		pending = setTimeout( function () {
			var r = resolve();
			r.type = 'aab-style';
			r.css = $( '#aab-custom-css' ).length ? $( '#aab-custom-css' ).val() : null;
			if ( r.css === null ) {
				delete r.css;
			}
			try {
				frame.contentWindow.postMessage( r, data.origin || '*' );
			} catch ( e ) {}
		}, 60 );
	}

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.aab-color' ).wpColorPicker( {
				change: function ( event, ui ) {
					$( event.target ).val( ui.color.toString() );
					var target = $( event.target ).data( 'aab-target' );
					if ( target === 'accent' ) {
						$( '[name="aab[style_accent]"][value="custom"]' ).prop( 'checked', true );
					} else if ( target === 'bg' ) {
						$( '[name="aab[style_background]"][value="custom"]' ).prop( 'checked', true );
					}
					sync();
				},
				clear: sync,
			} );
		}

		$( '.aab-swatch--pick' ).on( 'click', function () {
			var color = $( this ).data( 'color' );
			var input = $( '#' + $( this ).data( 'target' ) );
			if ( input.wpColorPicker ) {
				input.wpColorPicker( 'color', color );
			} else {
				input.val( color );
			}
			$( '[name="aab[style_accent]"][value="custom"]' ).prop( 'checked', true );
			sync();
		} );

		$( '#aab-appearance' ).on( 'change input', 'input, select, textarea', sync );

		if ( frame ) {
			frame.addEventListener( 'load', function () {
				// Size the frame to its content so the preview never needs its own scrollbar.
				var fit = function () {
					try {
						var doc = frame.contentDocument;
						frame.style.height = Math.max( 420, doc.documentElement.scrollHeight ) + 'px';
					} catch ( e ) {}
				};
				fit();
				try {
					new ResizeObserver( fit ).observe( frame.contentDocument.body );
				} catch ( e ) {}
				sync();
			} );
		}

		$( '[data-aab-width]' ).on( 'click', function () {
			$( '[data-aab-width]' ).removeClass( 'is-pressed' ).attr( 'aria-pressed', 'false' );
			$( this ).addClass( 'is-pressed' ).attr( 'aria-pressed', 'true' );
			$( '.aab-preview-frame' ).css( 'max-width', $( this ).data( 'aab-width' ) );
		} );

		sync();

		$( '[data-aab-copy]' ).on( 'click', function () {
			var button = $( this );
			var field = document.getElementById( button.data( 'aab-copy' ) );
			if ( ! field ) {
				return;
			}
			var done = function () {
				var label = button.text();
				button.text( data.copied || 'Copied' );
				setTimeout( function () {
					button.text( label );
				}, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done );
			} else {
				field.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );
} )( jQuery );
