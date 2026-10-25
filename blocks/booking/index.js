/*
 * Appointment Booking block (no build step).
 *
 * The editor shows the real widget with sample times, styled exactly as it will be on
 * the page. Appearance controls here override Appointments → Settings → Appearance for
 * this block only; "Default" keeps the site-wide setting.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var useRef = element.useRef;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var data = window.aabEditor || {};

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

	function readableOn( bg ) {
		var l = luminance( bg );
		return ( 1.05 / ( l + 0.05 ) ) >= ( ( l + 0.05 ) / ( luminance( '#111111' ) + 0.05 ) ) ? '#ffffff' : '#111111';
	}

	// Site-wide styles plus this block's overrides, as CSS variables and classes.
	function styleFor( a ) {
		var vars = Object.assign( {}, data.vars || {} );
		var classes = ( data.classes || [] ).slice();
		var theme = data.theme || {};

		if ( a.accent ) {
			vars[ '--aab-accent' ] = a.accent;
			vars[ '--aab-on-accent' ] = readableOn( a.accent );
		}
		if ( a.surface ) {
			vars[ '--aab-surface' ] = a.surface;
		}
		if ( a.radius ) {
			vars[ '--aab-radius' ] = a.radius === 'auto' ? theme.radius || '10px' : ( data.radii || {} )[ a.radius ] || vars[ '--aab-radius' ];
		}
		if ( a.density ) {
			vars[ '--aab-space' ] = ( data.spaces || {} )[ a.density ] || '1';
			classes = classes.filter( function ( c ) {
				return c.indexOf( 'aab--density-' ) !== 0;
			} ).concat( 'aab--density-' + a.density );
		}
		if ( a.headings === 'inherit' ) {
			delete vars[ '--aab-heading-font' ];
		} else if ( a.headings === 'theme' && theme.headingFont ) {
			vars[ '--aab-heading-font' ] = theme.headingFont;
		}
		if ( a.buttons ) {
			classes = classes.filter( function ( c ) {
				return c.indexOf( 'aab--buttons-' ) !== 0;
			} ).concat( 'aab--buttons-' + a.buttons );
		}
		return { vars: vars, classes: classes };
	}

	function Preview( props ) {
		var ref = useRef( null );
		var a = props.attributes;

		// Mount (and remount when the heading changes, since the widget draws it).
		useEffect( function () {
			var node = ref.current;
			if ( ! node || ! window.AABBooking || ! data.config ) {
				return;
			}
			var cfg = Object.assign( {}, data.config, { heading: a.heading || '', demo: true } );
			if ( a.type ) {
				var only = ( data.config.types || [] ).filter( function ( t ) {
					return t.id === a.type;
				} );
				cfg.types = only.length ? only : cfg.types;
			}
			node.className = 'aab';
			node.id = node.id || 'aab-editor-' + Math.random().toString( 36 ).slice( 2, 8 );
			window.AABBooking.mount( node, cfg );
		}, [ a.heading, a.type ] );

		// Restyle live.
		useEffect( function () {
			var node = ref.current;
			if ( ! node ) {
				return;
			}
			var s = styleFor( a );
			node.removeAttribute( 'style' );
			Object.keys( s.vars ).forEach( function ( k ) {
				node.style.setProperty( k, s.vars[ k ] );
			} );
			Array.prototype.slice.call( node.classList ).forEach( function ( c ) {
				if ( c.indexOf( 'aab--buttons-' ) === 0 || c.indexOf( 'aab--density-' ) === 0 ) {
					node.classList.remove( c );
				}
			} );
			s.classes.forEach( function ( c ) {
				node.classList.add( c );
			} );
			if ( ! a.accent && window.AABBooking ) {
				window.AABBooking.probe( node );
			}
			var submit = node.querySelector( '.aab__submit' );
			if ( submit ) {
				submit.classList.toggle( 'wp-element-button', node.classList.contains( 'aab--buttons-theme' ) && node.classList.contains( 'aab--wp-buttons' ) );
			}
		} );

		return el( 'div', { ref: ref, className: 'aab' } );
	}

	var opt = function ( value, label ) {
		return { value: value, label: label };
	};
	var DEFAULT = __( 'Default (site setting)', 'anderson-appointment-booking' );

	blocks.registerBlockType( 'anderson-appointment-booking/booking', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( value ) {
					var next = {};
					next[ key ] = value || '';
					props.setAttributes( next );
				};
			};
			var blockProps = blockEditor.useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Booking', 'anderson-appointment-booking' ) },
						el( components.TextControl, {
							label: __( 'Heading above the calendar', 'anderson-appointment-booking' ),
							help: __( 'Optional, e.g. Book a 30-minute call.', 'anderson-appointment-booking' ),
							value: a.heading,
							onChange: set( 'heading' ),
						} ),
						( data.types || [] ).length > 1 && el( components.SelectControl, {
							label: __( 'Appointment types', 'anderson-appointment-booking' ),
							value: a.type,
							options: [ opt( '', __( 'All visible types (visitor picks)', 'anderson-appointment-booking' ) ) ].concat( ( data.types || [] ).map( function ( t ) {
								return opt( t.id, t.name );
							} ) ),
							onChange: set( 'type' ),
						} ),
						el( 'p', { className: 'components-base-control__help' }, __( 'Types, hours and calendars are set under Appointments. The preview uses sample times.', 'anderson-appointment-booking' ) )
					),
					el(
						components.PanelBody,
						{ title: __( 'Calendar style', 'anderson-appointment-booking' ), initialOpen: false },
						el( 'p', { className: 'components-base-control__help' }, __( 'Overrides for this block only. Text color, background, font and spacing are under the Styles tab.', 'anderson-appointment-booking' ) ),
						el( components.SelectControl, {
							label: __( 'Booking button and fields', 'anderson-appointment-booking' ),
							value: a.buttons,
							options: [ opt( '', DEFAULT ), opt( 'theme', __( 'Use my theme’s style', 'anderson-appointment-booking' ) ), opt( 'plugin', __( 'Use the calendar’s style', 'anderson-appointment-booking' ) ) ],
							onChange: set( 'buttons' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Corners', 'anderson-appointment-booking' ),
							value: a.radius,
							options: [
								opt( '', DEFAULT ),
								opt( 'auto', __( 'Match my theme', 'anderson-appointment-booking' ) ),
								opt( 'square', __( 'Square', 'anderson-appointment-booking' ) ),
								opt( 'soft', __( 'Slightly rounded', 'anderson-appointment-booking' ) ),
								opt( 'rounded', __( 'Rounded', 'anderson-appointment-booking' ) ),
								opt( 'round', __( 'Very rounded', 'anderson-appointment-booking' ) ),
							],
							onChange: set( 'radius' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Spacing', 'anderson-appointment-booking' ),
							value: a.density,
							options: [ opt( '', DEFAULT ), opt( 'compact', __( 'Compact', 'anderson-appointment-booking' ) ), opt( 'comfortable', __( 'Comfortable', 'anderson-appointment-booking' ) ), opt( 'roomy', __( 'Roomy', 'anderson-appointment-booking' ) ) ],
							onChange: set( 'density' ),
						} ),
						el( components.SelectControl, {
							label: __( 'Headings', 'anderson-appointment-booking' ),
							value: a.headings,
							options: [ opt( '', DEFAULT ), opt( 'theme', __( 'My theme’s heading font', 'anderson-appointment-booking' ) ), opt( 'inherit', __( 'Same as the text around it', 'anderson-appointment-booking' ) ) ],
							onChange: set( 'headings' ),
						} )
					)
				),
				blockEditor.PanelColorSettings && el(
					blockEditor.InspectorControls,
					null,
					el( blockEditor.PanelColorSettings, {
						title: __( 'Calendar colors', 'anderson-appointment-booking' ),
						colorSettings: [
							{
								label: __( 'Accent', 'anderson-appointment-booking' ),
								value: a.accent,
								onChange: set( 'accent' ),
							},
							{
								label: __( 'Calendar box', 'anderson-appointment-booking' ),
								value: a.surface,
								onChange: set( 'surface' ),
							},
						],
					} )
				),
				el( Preview, { attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
