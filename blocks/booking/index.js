/* Appointment Booking block (no build step). The live calendar renders on the front end. */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'anderson-appointment-booking/booking', {
		edit: function ( props ) {
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
							value: props.attributes.heading,
							onChange: function ( value ) {
								props.setAttributes( { heading: value } );
							},
						} )
					)
				),
				el(
					components.Placeholder,
					{
						icon: 'calendar-alt',
						label: props.attributes.heading || __( 'Appointment Booking', 'anderson-appointment-booking' ),
						instructions: __( 'Visitors will see your open times from Google Calendar here and can book one. Hours and calendars are set under Appointments → Settings.', 'anderson-appointment-booking' ),
					}
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
