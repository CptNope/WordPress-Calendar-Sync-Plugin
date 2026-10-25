/* Anderson Appointment Booking – appointment type editor. */
( function () {
	'use strict';

	var list = document.getElementById( 'aab-questions' );
	var template = document.getElementById( 'aab-question-template' );
	var add = document.getElementById( 'aab-add-question' );

	// Hours table is only relevant when the type uses its own hours.
	var hoursTable = document.querySelector( '.aab-type-hours' );
	var radios = document.querySelectorAll( 'input[name="type[custom_hours]"]' );
	function syncHours() {
		var own = document.querySelector( 'input[name="type[custom_hours]"]:checked' );
		if ( hoursTable ) {
			hoursTable.classList.toggle( 'is-off', ! own || own.value !== '1' );
		}
	}
	Array.prototype.forEach.call( radios, function ( r ) {
		r.addEventListener( 'change', syncHours );
	} );
	syncHours();

	if ( ! list || ! template || ! add ) {
		return;
	}

	function syncKind( row ) {
		var kind = row.querySelector( '.aab-question__kind' );
		if ( kind ) {
			row.setAttribute( 'data-kind', kind.value );
		}
	}

	add.addEventListener( 'click', function () {
		var i = parseInt( list.getAttribute( 'data-next' ) || '0', 10 );
		list.setAttribute( 'data-next', String( i + 1 ) );
		var html = template.innerHTML.replace( /__i__/g, 'n' + i );
		var holder = document.createElement( 'div' );
		holder.innerHTML = html;
		var row = holder.firstElementChild;
		list.appendChild( row );
		syncKind( row );
		var input = row.querySelector( 'input[type="text"]' );
		if ( input ) {
			input.focus();
		}
	} );

	list.addEventListener( 'change', function ( ev ) {
		if ( ev.target.classList.contains( 'aab-question__kind' ) ) {
			syncKind( ev.target.closest( '.aab-question' ) );
		}
	} );

	list.addEventListener( 'click', function ( ev ) {
		var row = ev.target.closest( '.aab-question' );
		if ( ! row ) {
			return;
		}
		if ( ev.target.classList.contains( 'aab-question__remove' ) ) {
			row.parentNode.removeChild( row );
			add.focus();
		} else if ( ev.target.classList.contains( 'aab-question__up' ) && row.previousElementSibling ) {
			// Field names carry their index, but PHP keeps POST order, so moving the element is enough.
			list.insertBefore( row, row.previousElementSibling );
			ev.target.focus();
		}
	} );

	Array.prototype.forEach.call( list.querySelectorAll( '.aab-question' ), syncKind );
} )();
