/* Anderson Appointment Booking – settings screen. */
( function ( $ ) {
	'use strict';

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.aab-color' ).wpColorPicker();
		}

		$( '[data-aab-copy]' ).on( 'click', function () {
			var button = $( this );
			var field = document.getElementById( button.data( 'aab-copy' ) );
			if ( ! field ) {
				return;
			}
			var done = function () {
				var label = button.text();
				button.text( 'Copied' );
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
