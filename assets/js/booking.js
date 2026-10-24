/**
 * Anderson Appointment Booking – visitor widget.
 *
 * Plain JavaScript, no dependencies. Each .aab[data-aab-config] element becomes a booking calendar.
 */
( function () {
	'use strict';

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// Dates are handled as Y-m-d strings in the site's timezone; Date objects below are UTC-anchored
	// so day arithmetic never shifts with the visitor's timezone.
	function parseYmd( s ) {
		var p = s.split( '-' );
		return new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] ) );
	}

	function ymd( d ) {
		return d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() );
	}

	function sprintf( str, value ) {
		return String( str ).replace( /%[sd]/, value );
	}

	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				var v = attrs[ key ];
				if ( v === null || v === undefined || v === false ) {
					return;
				}
				if ( key === 'text' ) {
					node.textContent = v;
				} else if ( key === 'class' ) {
					node.className = v;
				} else if ( key.indexOf( 'on' ) === 0 ) {
					node.addEventListener( key.slice( 2 ), v );
				} else {
					node.setAttribute( key, v === true ? '' : v );
				}
			} );
		}
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function Widget( root, cfg ) {
		this.root = root;
		this.cfg = cfg || JSON.parse( root.getAttribute( 'data-aab-config' ) || '{}' );
		this.t = this.cfg.i18n || {};
		this.locale = this.cfg.locale || undefined;
		this.cache = {};
		this.selected = null;
		this.slot = null;
		this.view = 'times';
		this.notice = '';
		this.autoAdvance = 2;

		try {
			this.userTz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {
			this.userTz = '';
		}
		this.timeFmt = new Intl.DateTimeFormat( this.locale, { hour: 'numeric', minute: '2-digit' } );
		this.monthFmt = new Intl.DateTimeFormat( this.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' } );
		this.dayFmt = new Intl.DateTimeFormat( this.locale, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' } );
		this.shortDayFmt = new Intl.DateTimeFormat( this.locale, { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' } );
		this.weekdayFmt = new Intl.DateTimeFormat( this.locale, { weekday: 'narrow', timeZone: 'UTC' } );
		this.weekdayLongFmt = new Intl.DateTimeFormat( this.locale, { weekday: 'long', timeZone: 'UTC' } );

		var now = new Date();
		this.month = now.getFullYear() + '-' + pad( now.getMonth() + 1 );

		this.probeTheme();
		this.build();
		this.load();
	}

	function parseColor( value ) {
		var m = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+%?))?/.exec( value || '' );
		if ( ! m ) {
			return null;
		}
		var alpha = m[ 4 ] === undefined ? 1 : ( m[ 4 ].indexOf( '%' ) > 0 ? parseFloat( m[ 4 ] ) / 100 : parseFloat( m[ 4 ] ) );
		return { r: +m[ 1 ], g: +m[ 2 ], b: +m[ 3 ], a: alpha, hex: '#' + [ m[ 1 ], m[ 2 ], m[ 3 ] ].map( function ( n ) {
			return pad16( +n );
		} ).join( '' ) };
	}

	function pad16( n ) {
		var s = n.toString( 16 );
		return s.length < 2 ? '0' + s : s;
	}

	function lum( c ) {
		return [ c.r, c.g, c.b ].map( function ( v ) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} ).reduce( function ( acc, v, i ) {
			return acc + v * [ 0.2126, 0.7152, 0.0722 ][ i ];
		}, 0 );
	}

	function ratio( a, b ) {
		var x = lum( a );
		var y = lum( b );
		return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
	}

	/*
	 * For themes that don't publish design tokens (most classic themes), read the theme's
	 * real button and link styles from the page: a hidden probe button and link are placed
	 * where the widget sits, so the theme's contextual selectors apply to them.
	 */
	Widget.prototype.probeTheme = function () {
		var root = this.root;
		var wantAccent = root.classList.contains( 'aab--probe-accent' );
		var wantRadius = root.classList.contains( 'aab--probe-radius' );
		if ( ! ( wantAccent || wantRadius ) || ! window.getComputedStyle ) {
			return;
		}
		var host = root.parentNode || document.body;
		var box = document.createElement( 'div' );
		box.setAttribute( 'aria-hidden', 'true' );
		box.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;height:0;overflow:hidden;';
		var p = document.createElement( 'p' );
		var a = document.createElement( 'a' );
		a.href = '#';
		a.textContent = 'a';
		p.appendChild( a );
		var form = document.createElement( 'form' );
		var b = document.createElement( 'button' );
		b.type = 'submit';
		b.textContent = 'b';
		form.appendChild( b );
		box.appendChild( p );
		box.appendChild( form );
		host.insertBefore( box, root );

		var bs = window.getComputedStyle( b );
		var bg = parseColor( bs.backgroundColor );
		var fg = parseColor( bs.color );
		var link = parseColor( window.getComputedStyle( a ).color );
		var text = parseColor( window.getComputedStyle( root ).color );
		var radius = bs.borderTopLeftRadius;
		host.removeChild( box );

		// Browser-default grey buttons and see-through ones mean the theme doesn't style buttons.
		var defaults = [ '#efefef', '#f0f0f0', '#e9e9ed', '#dddddd', '#e0e0e0', '#ededed' ];
		var themedButton = !! ( bg && bg.a > 0.5 && defaults.indexOf( bg.hex ) === -1 );

		if ( wantAccent ) {
			var accent = null;
			var on = null;
			if ( themedButton ) {
				accent = bg;
				on = fg && ratio( bg, fg ) >= 3 ? fg : null;
			} else if ( link && ( ! text || link.hex !== text.hex ) ) {
				accent = link;
			}
			if ( accent ) {
				var white = { r: 255, g: 255, b: 255 };
				var dark = { r: 17, g: 17, b: 17 };
				root.style.setProperty( '--aab-accent', accent.hex );
				root.style.setProperty( '--aab-on-accent', on ? on.hex : ( ratio( accent, white ) >= ratio( accent, dark ) ? '#ffffff' : '#111111' ) );
			}
		}
		if ( wantRadius && themedButton && /^[\d.]+px$/.test( radius || '' ) ) {
			root.style.setProperty( '--aab-radius', radius );
		}
	};

	Widget.prototype.endpoint = function ( path, params ) {
		var base = this.cfg.rest;
		var url = new URL( base, window.location.href );
		// Sites with plain permalinks use ?rest_route=/aab/v1/ instead of /wp-json/aab/v1/.
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ).replace( /\/?$/, '/' ) + path );
		} else {
			url.pathname = url.pathname.replace( /\/?$/, '/' ) + path;
		}
		Object.keys( params || {} ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		return url.toString();
	};

	Widget.prototype.build = function () {
		var self = this;
		this.root.textContent = '';
		this.root.classList.add( 'aab--ready' );

		if ( this.cfg.heading ) {
			this.root.appendChild( h( 'h2', { class: 'aab__heading', text: this.cfg.heading } ) );
		}

		this.monthLabel = h( 'h3', { class: 'aab__month-label', 'aria-live': 'polite' } );
		this.prevBtn = h( 'button', { type: 'button', class: 'aab__nav', 'aria-label': this.t.prevMonth, onclick: function () {
			self.shiftMonth( -1 );
		} }, [ h( 'span', { 'aria-hidden': 'true', text: '‹' } ) ] );
		this.nextBtn = h( 'button', { type: 'button', class: 'aab__nav', 'aria-label': this.t.nextMonth, onclick: function () {
			self.shiftMonth( 1 );
		} }, [ h( 'span', { 'aria-hidden': 'true', text: '›' } ) ] );

		this.grid = h( 'div', { class: 'aab__grid', role: 'group', 'aria-label': this.t.pickDay } );
		this.panel = h( 'div', { class: 'aab__panel', 'aria-live': 'polite' } );
		this.tzNote = h( 'p', { class: 'aab__tz' } );

		this.root.appendChild(
			h( 'div', { class: 'aab__body' }, [
				h( 'div', { class: 'aab__cal' }, [
					h( 'div', { class: 'aab__month' }, [ this.prevBtn, this.monthLabel, this.nextBtn ] ),
					this.grid,
				] ),
				this.panel,
			] )
		);
		this.root.appendChild( this.tzNote );
		if ( this.userTz ) {
			this.tzNote.textContent = sprintf( this.t.timezone, this.userTz.replace( /_/g, ' ' ) );
		}
	};

	Widget.prototype.monthRange = function ( key ) {
		var p = key.split( '-' );
		var first = new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, 1 ) );
		var last = new Date( Date.UTC( +p[ 0 ], +p[ 1 ], 0 ) );
		return { from: ymd( first ), to: ymd( last ) };
	};

	Widget.prototype.shiftMonth = function ( delta, keepAdvance ) {
		var p = this.month.split( '-' );
		var d = new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1 + delta, 1 ) );
		this.month = d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 );
		this.selected = null;
		this.slot = null;
		this.view = 'times';
		this.notice = '';
		this.autoAdvance = keepAdvance || 0;
		this.load();
	};

	Widget.prototype.load = function ( force ) {
		var self = this;
		var key = this.month;
		this.monthLabel.textContent = this.monthFmt.format( parseYmd( key + '-01' ) );

		if ( ! force && this.cache[ key ] ) {
			this.data = this.cache[ key ];
			this.render();
			return;
		}

		this.data = null;
		this.root.classList.add( 'aab--loading' );
		this.render();

		var range = this.monthRange( key );
		var request = this.cfg.demo
			? Promise.resolve( this.demoData( range ) )
			: fetch( this.endpoint( 'availability', range ), { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
				.then( function ( res ) {
					return res.json().then( function ( body ) {
						if ( ! res.ok ) {
							throw body;
						}
						return body;
					} );
				} );
		request
			.then( function ( body ) {
				if ( key !== self.month ) {
					return;
				}
				self.cache[ key ] = body;
				self.data = body;
				self.error = '';
				self.root.classList.remove( 'aab--loading' );

				var hasAny = Object.keys( body.days || {} ).some( function ( d ) {
					return body.days[ d ].length > 0;
				} );
				// First visit: skip ahead to the first month with open times.
				if ( ! hasAny && self.autoAdvance > 0 && body.last > self.monthRange( key ).to ) {
					self.shiftMonth( 1, self.autoAdvance - 1 );
					return;
				}
				self.autoAdvance = 0;
				if ( ! self.selected ) {
					self.selected = self.firstOpenDay();
				}
				self.render();
			} )
			.catch( function ( err ) {
				if ( key !== self.month ) {
					return;
				}
				self.root.classList.remove( 'aab--loading' );
				self.error = ( err && err.message ) || self.t.error;
				self.render();
			} );
	};

	// Sample availability for previews: weekdays from tomorrow, a few times taken, two months ahead.
	Widget.prototype.demoData = function ( range ) {
		var today = new Date();
		var first = ymd( new Date( Date.UTC( today.getFullYear(), today.getMonth(), today.getDate() ) ) );
		var lastD = new Date( Date.UTC( today.getFullYear(), today.getMonth() + 2, today.getDate() ) );
		var step = Math.max( 15, +this.cfg.duration || 30 ) * 60;
		var days = {};
		for ( var d = parseYmd( range.from ); ymd( d ) <= range.to; d.setUTCDate( d.getUTCDate() + 1 ) ) {
			var key = ymd( d );
			var wd = d.getUTCDay();
			days[ key ] = [];
			if ( key <= first || key > ymd( lastD ) || wd === 0 || wd === 6 ) {
				continue;
			}
			var local = new Date( d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), 9, 0, 0 ).getTime() / 1000;
			for ( var t = local, i = 0; t < local + 8 * 3600; t += step, i++ ) {
				if ( ( i + d.getUTCDate() ) % 4 !== 1 ) {
					days[ key ].push( t );
				}
			}
		}
		return { demo: true, duration: +this.cfg.duration || 30, first: first, last: ymd( lastD ), days: days };
	};

	Widget.prototype.firstOpenDay = function () {
		var days = ( this.data && this.data.days ) || {};
		var keys = Object.keys( days ).sort();
		for ( var i = 0; i < keys.length; i++ ) {
			if ( days[ keys[ i ] ].length ) {
				return keys[ i ];
			}
		}
		return null;
	};

	Widget.prototype.render = function () {
		this.renderGrid();
		this.renderPanel();
	};

	Widget.prototype.renderGrid = function () {
		var self = this;
		var data = this.data;
		var days = ( data && data.days ) || {};
		var range = this.monthRange( this.month );
		var start = parseYmd( range.from );
		var end = parseYmd( range.to );
		var weekStart = +this.cfg.weekStart || 0;

		this.prevBtn.disabled = ! data || range.from <= data.first;
		this.nextBtn.disabled = ! data || range.to >= data.last;

		var max = 0;
		Object.keys( days ).forEach( function ( d ) {
			max = Math.max( max, days[ d ].length );
		} );

		this.grid.textContent = '';
		// Weekday header.
		for ( var w = 0; w < 7; w++ ) {
			var ref = new Date( Date.UTC( 2024, 0, 7 + ( ( weekStart + w ) % 7 ) ) ); // 2024-01-07 is a Sunday.
			this.grid.appendChild( h( 'span', { class: 'aab__wd', title: this.weekdayLongFmt.format( ref ), 'aria-hidden': 'true', text: this.weekdayFmt.format( ref ) } ) );
		}

		var lead = ( start.getUTCDay() - weekStart + 7 ) % 7;
		for ( var i = 0; i < lead; i++ ) {
			this.grid.appendChild( h( 'span', { class: 'aab__blank', 'aria-hidden': 'true' } ) );
		}

		for ( var d = new Date( start.getTime() ); d <= end; d.setUTCDate( d.getUTCDate() + 1 ) ) {
			var key = ymd( d );
			var count = days[ key ] ? days[ key ].length : 0;
			var open = count > 0;
			var label = this.dayFmt.format( d ) + ( open ? ', ' + ( count === 1 ? this.t.openOne : sprintf( this.t.openCount, count ) ) : '' );
			var bar = h( 'span', { class: 'aab__bar', 'aria-hidden': 'true' }, [ h( 'i' ) ] );
			bar.firstChild.style.width = open && max ? Math.max( 12, Math.round( ( count / max ) * 100 ) ) + '%' : '0';

			this.grid.appendChild(
				h( 'button', {
					type: 'button',
					class: 'aab__day' + ( open ? ' is-open' : '' ) + ( key === this.selected ? ' is-selected' : '' ),
					'data-date': key,
					disabled: ! open,
					'aria-pressed': key === this.selected ? 'true' : 'false',
					'aria-label': label,
					onclick: ( function ( date ) {
						return function () {
							self.selectDay( date );
						};
					} )( key ),
				}, [ h( 'span', { class: 'aab__num', text: String( d.getUTCDate() ) } ), bar ] )
			);
		}
	};

	Widget.prototype.selectDay = function ( date ) {
		this.selected = date;
		this.slot = null;
		this.view = 'times';
		this.notice = '';
		this.render();
		var first = this.panel.querySelector( '.aab__time' );
		if ( first && window.matchMedia( '(max-width: 640px)' ).matches ) {
			this.panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};

	Widget.prototype.renderPanel = function () {
		this.panel.textContent = '';
		if ( this.view === 'form' && this.slot ) {
			return this.renderForm();
		}
		if ( this.view === 'done' ) {
			return this.renderDone();
		}
		return this.renderTimes();
	};

	Widget.prototype.renderTimes = function () {
		var self = this;
		var panel = this.panel;

		if ( this.error ) {
			panel.appendChild( h( 'p', { class: 'aab__message aab__message--error', role: 'alert', text: this.error } ) );
			panel.appendChild( h( 'button', { type: 'button', class: 'aab__link', text: this.t.retry, onclick: function () {
				self.error = '';
				self.load( true );
			} } ) );
			return;
		}
		if ( ! this.data ) {
			panel.appendChild( h( 'p', { class: 'aab__message aab__message--quiet', text: this.t.loading } ) );
			return;
		}
		if ( this.notice ) {
			panel.appendChild( h( 'p', { class: 'aab__message aab__message--error', role: 'alert', text: this.notice } ) );
		}
		if ( ! this.selected ) {
			panel.appendChild( h( 'p', { class: 'aab__message aab__message--quiet', text: this.firstOpenDay() ? this.t.noDay : this.t.noTimes } ) );
			return;
		}

		var slots = this.data.days[ this.selected ] || [];
		panel.appendChild( h( 'h3', { class: 'aab__panel-title', text: this.dayFmt.format( parseYmd( this.selected ) ) } ) );
		if ( this.data.duration ) {
			panel.appendChild( h( 'p', { class: 'aab__duration', text: sprintf( this.t.minutes, this.data.duration ) } ) );
		}
		if ( ! slots.length ) {
			panel.appendChild( h( 'p', { class: 'aab__message aab__message--quiet', text: this.t.dayFull } ) );
			return;
		}

		var list = h( 'div', { class: 'aab__times', role: 'group', 'aria-label': this.t.pickTime } );
		slots.forEach( function ( start ) {
			list.appendChild( h( 'button', {
				type: 'button',
				class: 'aab__time',
				text: self.timeFmt.format( new Date( start * 1000 ) ),
				onclick: function () {
					self.slot = start;
					self.view = 'form';
					self.notice = '';
					self.renderPanel();
				},
			} ) );
		} );
		panel.appendChild( list );
	};

	Widget.prototype.slotLabel = function ( start ) {
		var s = new Date( start * 1000 );
		var e = new Date( ( start + ( this.data.duration || 0 ) * 60 ) * 1000 );
		return this.shortDayFmt.format( parseYmd( this.selected ) ) + ', ' + this.timeFmt.format( s ) + '–' + this.timeFmt.format( e );
	};

	Widget.prototype.field = function ( name, label, type, attrs ) {
		var id = this.root.id + '-' + name;
		var input = h( type === 'textarea' ? 'textarea' : 'input', Object.assign( { id: id, name: name, class: 'aab__input' }, type === 'textarea' ? { rows: '3' } : { type: type }, attrs || {} ) );
		var error = h( 'span', { class: 'aab__field-error', id: id + '-error' } );
		return h( 'div', { class: 'aab__field' }, [ h( 'label', { for: id, text: label } ), input, error ] );
	};

	Widget.prototype.renderForm = function () {
		var self = this;
		var t = this.t;
		var shownAt = Date.now();
		var timeText = this.timeFmt.format( new Date( this.slot * 1000 ) );

		var back = h( 'button', { type: 'button', class: 'aab__link aab__back', text: t.changeTime, onclick: function () {
			self.view = 'times';
			self.renderPanel();
		} } );

		var form = h( 'form', { class: 'aab__form', novalidate: true } );
		form.appendChild( this.field( 'name', t.name, 'text', { autocomplete: 'name', required: true, maxlength: '100' } ) );
		form.appendChild( this.field( 'email', t.email, 'email', { autocomplete: 'email', required: true } ) );
		form.appendChild( this.field( 'notes', t.notes, 'textarea', { maxlength: '2000' } ) );

		// Honeypot: hidden from people, tempting to bots.
		form.appendChild( h( 'div', { class: 'aab__hp', 'aria-hidden': 'true' }, [
			h( 'label', { for: this.root.id + '-website', text: t.website } ),
			h( 'input', { id: this.root.id + '-website', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' } ),
		] ) );

		if ( this.cfg.consent ) {
			var cid = this.root.id + '-consent';
			form.appendChild( h( 'div', { class: 'aab__field aab__field--check' }, [
				h( 'input', { type: 'checkbox', id: cid, name: 'consent', value: '1' } ),
				h( 'label', { for: cid, text: this.cfg.consent } ),
				h( 'span', { class: 'aab__field-error', id: cid + '-error' } ),
			] ) );
		}

		var status = h( 'p', { class: 'aab__message aab__message--error', role: 'alert', hidden: true } );
		// In "match my theme" mode on block themes the button also takes the theme's button class.
		var themed = this.root.classList.contains( 'aab--buttons-theme' ) && this.root.classList.contains( 'aab--wp-buttons' );
		var submit = h( 'button', { type: 'submit', class: 'aab__submit' + ( themed ? ' wp-element-button' : '' ), text: sprintf( t.book, timeText ) } );
		form.appendChild( status );
		form.appendChild( submit );

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			self.submit( form, submit, status, shownAt, timeText );
		} );

		this.panel.appendChild( h( 'div', { class: 'aab__summary' }, [
			h( 'h3', { class: 'aab__panel-title', text: this.slotLabel( this.slot ) } ),
			back,
		] ) );
		this.panel.appendChild( form );
		form.querySelector( 'input' ).focus( { preventScroll: true } );
	};

	Widget.prototype.submit = function ( form, submit, status, shownAt, timeText ) {
		var self = this;
		var t = this.t;
		var get = function ( name ) {
			var el = form.elements[ name ];
			return el ? ( el.type === 'checkbox' ? el.checked : el.value ) : '';
		};

		// Clear previous errors.
		Array.prototype.forEach.call( form.querySelectorAll( '.aab__field-error' ), function ( el ) {
			el.textContent = '';
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid]' ), function ( el ) {
			el.removeAttribute( 'aria-invalid' );
			el.removeAttribute( 'aria-describedby' );
		} );
		status.hidden = true;

		var showErrors = function ( fields ) {
			var firstBad = null;
			Object.keys( fields ).forEach( function ( name ) {
				var input = form.elements[ name ];
				var err = form.querySelector( '#' + self.root.id + '-' + name + '-error' );
				if ( input && err ) {
					err.textContent = fields[ name ];
					input.setAttribute( 'aria-invalid', 'true' );
					input.setAttribute( 'aria-describedby', err.id );
					firstBad = firstBad || input;
				}
			} );
			if ( firstBad ) {
				firstBad.focus();
			}
			return !! firstBad;
		};

		// Check the obvious things here; the server checks everything again.
		var local = {};
		if ( ! get( 'name' ).trim() ) {
			local.name = t.needName;
		}
		if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( get( 'email' ).trim() ) ) {
			local.email = t.needEmail;
		}
		if ( this.cfg.consent && ! get( 'consent' ) ) {
			local.consent = t.needConsent;
		}
		if ( showErrors( local ) ) {
			return;
		}

		submit.disabled = true;
		submit.textContent = t.booking;

		var reset = function () {
			submit.disabled = false;
			submit.textContent = sprintf( t.book, timeText );
		};

		// The server ignores forms sent within 3 seconds (a bot signal), so wait out any remainder.
		var wait = Math.max( 0, 3200 - ( Date.now() - shownAt ) );
		setTimeout( function () {
			self.send( form, status, shownAt, get, showErrors, reset );
		}, wait );
	};

	Widget.prototype.send = function ( form, status, shownAt, get, showErrors, reset ) {
		var self = this;
		var t = this.t;
		var payload = {
			start: this.slot,
			name: get( 'name' ).trim(),
			email: get( 'email' ).trim(),
			notes: get( 'notes' ).trim(),
			consent: !! get( 'consent' ),
			website: get( 'website' ),
			elapsed: Math.round( ( Date.now() - shownAt ) / 1000 ),
		};

		if ( this.cfg.demo ) {
			setTimeout( function () {
				self.result = { start: payload.start, message: self.cfg.message || '', meet: '' };
				self.view = 'done';
				self.renderPanel();
			}, 500 );
			return;
		}

		fetch( this.endpoint( 'bookings' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( payload ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					return { ok: res.ok, status: res.status, body: body };
				} );
			} )
			.then( function ( r ) {
				if ( r.ok ) {
					self.result = r.body;
					self.view = 'done';
					delete self.cache[ self.month ];
					self.renderPanel();
					return;
				}
				var body = r.body || {};
				if ( r.status === 409 ) {
					// Slot was taken meanwhile: reload times and explain.
					self.notice = body.message || t.error;
					self.view = 'times';
					self.slot = null;
					self.load( true );
					return;
				}
				var fields = body.data && body.data.fields;
				if ( ! fields || ! showErrors( fields ) ) {
					status.textContent = body.message || t.error;
					status.hidden = false;
				}
				reset();
			} )
			.catch( function () {
				status.textContent = t.error;
				status.hidden = false;
				reset();
			} );
	};

	Widget.prototype.renderDone = function () {
		var self = this;
		var r = this.result || {};
		var done = h( 'div', { class: 'aab__done', tabindex: '-1' }, [
			h( 'p', { class: 'aab__done-badge', text: this.t.booked } ),
			h( 'h3', { class: 'aab__panel-title', text: this.slotLabel( r.start || this.slot ) } ),
			r.message ? h( 'p', { class: 'aab__done-text', text: r.message } ) : null,
			r.meet ? h( 'p', null, [ h( 'a', { href: r.meet, target: '_blank', rel: 'noopener', text: this.t.joinCall } ) ] ) : null,
			h( 'button', { type: 'button', class: 'aab__link', text: this.t.bookAnother, onclick: function () {
				self.view = 'times';
				self.slot = null;
				self.selected = null;
				self.load( true );
			} } ),
		] );
		this.panel.appendChild( done );
		done.focus( { preventScroll: true } );
	};

	// Public hook so the block editor (and developers) can mount a widget on any element.
	window.AABBooking = {
		mount: function ( root, cfg ) {
			return new Widget( root, cfg );
		},
		// Re-read the theme's colors after the widget's classes change (used by previews).
		probe: function ( root ) {
			Widget.prototype.probeTheme.call( { root: root } );
		},
	};

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.aab[data-aab-config]' ), function ( root ) {
			if ( ! root.classList.contains( 'aab--ready' ) && window.fetch && window.Intl && window.URL ) {
				new Widget( root ); // eslint-disable-line no-new
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
