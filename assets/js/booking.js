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
		this.types = this.cfg.types || [];
		this.moving = this.cfg.reschedule || null;
		// One type (or a pinned one): skip straight to the calendar.
		this.type = this.types.length === 1 ? this.types[ 0 ] : null;

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
		if ( this.type || ! this.types.length ) {
			this.load();
		} else {
			this.showPicker();
		}
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
		var wantFont = root.classList.contains( 'aab--probe-font' );
		if ( ! ( wantAccent || wantRadius || wantFont ) || ! window.getComputedStyle ) {
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
		// A heading inside the content area picks up rules like `.entry-content h3`.
		var heading = document.createElement( 'h3' );
		heading.textContent = 'h';
		box.appendChild( heading );
		box.appendChild( p );
		box.appendChild( form );
		host.insertBefore( box, root );

		var bs = window.getComputedStyle( b );
		var bg = parseColor( bs.backgroundColor );
		var fg = parseColor( bs.color );
		var link = parseColor( window.getComputedStyle( a ).color );
		var text = parseColor( window.getComputedStyle( root ).color );
		var radius = bs.borderTopLeftRadius;
		var headingFont = window.getComputedStyle( heading ).fontFamily;
		var bodyFont = window.getComputedStyle( root ).fontFamily;
		host.removeChild( box );

		if ( wantFont && headingFont && headingFont !== bodyFont ) {
			root.style.setProperty( '--aab-heading-font', headingFont );
		}

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

		this.grid = h( 'div', { class: 'aab__grid', role: 'group', 'aria-label': this.t.pickDay, onkeydown: function ( ev ) {
			self.onGridKey( ev );
		} } );
		this.panel = h( 'div', { class: 'aab__panel', 'aria-live': 'polite' } );
		this.tzNote = h( 'p', { class: 'aab__tz' } );
		this.picker = h( 'div', { class: 'aab__types', role: 'list', hidden: true } );
		this.typeBar = h( 'div', { class: 'aab__typebar', hidden: true } );

		this.root.appendChild( this.picker );
		this.root.appendChild( this.typeBar );
		this.root.appendChild(
			this.body = h( 'div', { class: 'aab__body' }, [
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

	// Short "30 min" / "Video call" / location description for a type.
	Widget.prototype.typeMeta = function ( type ) {
		var parts = [ sprintf( this.t.minutes, type.duration ) ];
		if ( type.video ) {
			parts.push( this.t.video );
		} else if ( type.location ) {
			parts.push( type.location );
		}
		return parts;
	};

	Widget.prototype.showPicker = function () {
		var self = this;
		this.type = null;
		this.body.hidden = true;
		this.typeBar.hidden = true;
		this.tzNote.hidden = true;
		this.picker.hidden = false;
		this.picker.textContent = '';
		this.picker.appendChild( h( 'h3', { class: 'aab__types-title', text: this.t.pickType } ) );
		this.types.forEach( function ( type ) {
			var meta = self.typeMeta( type );
			self.picker.appendChild(
				h( 'div', { role: 'listitem' }, [
					h( 'button', { type: 'button', class: 'aab__type', onclick: function () {
						self.picked = true;
						self.pickType( type );
					} }, [
						h( 'span', { class: 'aab__type-head' }, [
							h( 'span', { class: 'aab__type-name', text: type.name } ),
							h( 'span', { class: 'aab__type-len', text: meta[ 0 ] } ),
						] ),
						meta[ 1 ] ? h( 'span', { class: 'aab__type-where', text: meta[ 1 ] } ) : null,
						type.description ? h( 'span', { class: 'aab__type-desc', text: type.description } ) : null,
					] ),
				] )
			);
		} );
	};

	Widget.prototype.pickType = function ( type ) {
		var self = this;
		this.type = type;
		this.picker.hidden = true;
		this.body.hidden = false;
		this.tzNote.hidden = false;
		this.typeBar.textContent = '';
		this.typeBar.hidden = this.types.length < 2;
		if ( this.types.length > 1 ) {
			this.typeBar.appendChild( h( 'button', { type: 'button', class: 'aab__link aab__back', text: '‹ ' + this.t.allTypes, onclick: function () {
				self.showPicker();
				var first = self.picker.querySelector( '.aab__type' );
				if ( first ) {
					first.focus();
				}
			} } ) );
			this.typeBar.appendChild( h( 'span', { class: 'aab__typebar-name', text: type.name } ) );
		}
		this.selected = null;
		this.slot = null;
		this.view = 'times';
		this.notice = '';
		this.autoAdvance = 2;
		var now = new Date();
		this.month = now.getFullYear() + '-' + pad( now.getMonth() + 1 );
		this.load();
		// The button that was clicked is gone: move focus to the calendar heading.
		if ( this.picked ) {
			this.monthLabel.setAttribute( 'tabindex', '-1' );
			this.monthLabel.focus( { preventScroll: true } );
		}
		this.picked = true;
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
		var month = this.month;
		var key = ( this.type ? this.type.id : '' ) + '|' + month;
		this.monthLabel.textContent = this.monthFmt.format( parseYmd( month + '-01' ) );

		if ( ! force && this.cache[ key ] ) {
			this.data = this.cache[ key ];
			this.render();
			return;
		}

		this.data = null;
		this.root.classList.add( 'aab--loading' );
		this.render();

		var range = this.monthRange( month );
		var params = { from: range.from, to: range.to };
		if ( this.moving ) {
			params.booking = this.moving.id;
			params.token = this.moving.token;
		} else if ( this.type ) {
			params.type = this.type.id;
		}
		var request = this.cfg.demo
			? Promise.resolve( this.demoData( range ) )
			: fetch( this.endpoint( 'availability', params ), { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
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
				if ( month !== self.month ) {
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
				if ( ! hasAny && self.autoAdvance > 0 && body.last > self.monthRange( month ).to ) {
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
				if ( month !== self.month ) {
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
		var minutes = ( this.type && this.type.duration ) || +this.cfg.duration || 30;
		var step = Math.max( 15, minutes ) * 60;
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
		return { demo: true, duration: minutes, first: first, last: ymd( lastD ), days: days };
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
		this.rovingTabindex();
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

	// One tab stop for the whole grid (the selected or first open day); arrow keys move between open days.
	Widget.prototype.rovingTabindex = function () {
		var open = this.grid.querySelectorAll( '.aab__day.is-open' );
		var current = this.grid.querySelector( '.aab__day.is-selected' ) || open[ 0 ];
		Array.prototype.forEach.call( open, function ( b ) {
			b.tabIndex = b === current ? 0 : -1;
		} );
	};

	Widget.prototype.onGridKey = function ( ev ) {
		var btn = ev.target;
		if ( ! btn || ! btn.classList || ! btn.classList.contains( 'aab__day' ) ) {
			return;
		}
		var open = Array.prototype.slice.call( this.grid.querySelectorAll( '.aab__day.is-open' ) );
		var i = open.indexOf( btn );
		if ( i < 0 ) {
			return;
		}
		var date = btn.getAttribute( 'data-date' );
		var next = null;
		var shift = function ( days ) {
			var d = parseYmd( date );
			d.setUTCDate( d.getUTCDate() + days );
			return ymd( d );
		};
		switch ( ev.key ) {
			case 'ArrowRight':
				next = open[ i + 1 ];
				break;
			case 'ArrowLeft':
				next = open[ i - 1 ];
				break;
			case 'ArrowDown':
				var down = shift( 7 );
				next = open.filter( function ( b ) {
					return b.getAttribute( 'data-date' ) >= down;
				} )[ 0 ];
				break;
			case 'ArrowUp':
				var up = shift( -7 );
				next = open.filter( function ( b ) {
					return b.getAttribute( 'data-date' ) <= up;
				} ).pop();
				break;
			case 'Home':
				next = open[ 0 ];
				break;
			case 'End':
				next = open[ open.length - 1 ];
				break;
			default:
				return;
		}
		ev.preventDefault();
		if ( next ) {
			open.forEach( function ( b ) {
				b.tabIndex = b === next ? 0 : -1;
			} );
			next.focus();
		}
	};

	Widget.prototype.selectDay = function ( date ) {
		var hadFocus = this.grid.contains( document.activeElement );
		this.selected = date;
		this.slot = null;
		this.view = 'times';
		this.notice = '';
		this.render();
		if ( hadFocus ) {
			var again = this.grid.querySelector( '.aab__day.is-selected' );
			if ( again ) {
				again.focus( { preventScroll: true } );
			}
		}
		var first = this.panel.querySelector( '.aab__time' );
		if ( first && window.matchMedia( '(max-width: 640px)' ).matches ) {
			this.panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};

	Widget.prototype.renderPanel = function () {
		this.panel.textContent = '';
		if ( this.view === 'form' && this.slot ) {
			return this.moving ? this.renderMove() : this.renderForm();
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
				'aria-current': self.moving && start === self.moving.start ? 'true' : null,
				title: self.moving && start === self.moving.start ? self.t.currentTime : null,
				disabled: !! ( self.moving && start === self.moving.start ),
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

	// A booking question as a form field. Field names are "q_<id>", matching server error keys.
	Widget.prototype.questionField = function ( q ) {
		var t = this.t;
		var name = 'q_' + q.id;
		var id = this.root.id + '-' + name;
		var label = q.label + ( q.required || q.kind === 'checkbox' ? '' : ' ' + t.optional );
		var error = h( 'span', { class: 'aab__field-error', id: id + '-error' } );
		if ( q.kind === 'checkbox' ) {
			return h( 'div', { class: 'aab__field aab__field--check' }, [
				h( 'input', { type: 'checkbox', id: id, name: name, value: '1' } ),
				h( 'label', { for: id, text: q.label } ),
				error,
			] );
		}
		if ( q.kind === 'select' ) {
			var select = h( 'select', { id: id, name: name, class: 'aab__input', required: !! q.required }, [
				h( 'option', { value: '', text: t.choose } ),
			].concat( ( q.options || [] ).map( function ( o ) {
				return h( 'option', { value: o, text: o } );
			} ) ) );
			return h( 'div', { class: 'aab__field' }, [ h( 'label', { for: id, text: label } ), select, error ] );
		}
		return this.field( name, label, q.kind === 'textarea' ? 'textarea' : 'text', { required: !! q.required, maxlength: '2000' } );
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
		if ( this.cfg.phone === 'optional' || this.cfg.phone === 'required' ) {
			form.appendChild( this.field( 'phone', this.cfg.phone === 'required' ? t.phoneLabel : t.phoneOpt, 'tel', { autocomplete: 'tel', inputmode: 'tel', required: this.cfg.phone === 'required', maxlength: '40' } ) );
		}
		var questions = ( this.type && this.type.questions ) || [];
		questions.forEach( function ( q ) {
			form.appendChild( self.questionField( q ) );
		} );
		if ( ! this.type || this.type.notes !== false ) {
			form.appendChild( this.field( 'notes', t.notes, 'textarea', { maxlength: '2000' } ) );
		}

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
		if ( this.cfg.phone === 'required' && ! get( 'phone' ).trim() ) {
			local.phone = t.needPhone;
		}
		( ( this.type && this.type.questions ) || [] ).forEach( function ( q ) {
			var v = get( 'q_' + q.id );
			if ( q.required && ( q.kind === 'checkbox' ? ! v : ! String( v ).trim() ) ) {
				local[ 'q_' + q.id ] = q.kind === 'checkbox' ? t.needConsent : ( q.kind === 'select' ? t.needChoice : t.needAnswer );
			}
		} );
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
		var answers = {};
		( ( this.type && this.type.questions ) || [] ).forEach( function ( q ) {
			var v = get( 'q_' + q.id );
			answers[ q.id ] = q.kind === 'checkbox' ? !! v : String( v ).trim();
		} );
		var payload = {
			start: this.slot,
			name: get( 'name' ).trim(),
			email: get( 'email' ).trim(),
			notes: get( 'notes' ).trim(),
			phone: get( 'phone' ).trim(),
			consent: !! get( 'consent' ),
			type: this.type ? this.type.id : '',
			answers: answers,
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
					self.cache = {};
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

	// Rescheduling: confirm the new time, then move the booking.
	Widget.prototype.renderMove = function () {
		var self = this;
		var t = this.t;
		var timeText = this.timeFmt.format( new Date( this.slot * 1000 ) );
		var themed = this.root.classList.contains( 'aab--buttons-theme' ) && this.root.classList.contains( 'aab--wp-buttons' );
		var status = h( 'p', { class: 'aab__message aab__message--error', role: 'alert', hidden: true } );
		var button = h( 'button', { type: 'button', class: 'aab__submit' + ( themed ? ' wp-element-button' : '' ), text: sprintf( t.moveTo, timeText ) } );
		var current = new Date( this.moving.start * 1000 );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = t.moving;
			status.hidden = true;
			var done = function ( r ) {
				self.result = { start: r.start, meet: r.meet || '', moved: true };
				self.moving.start = r.start;
				self.view = 'done';
				self.cache = {};
				self.renderPanel();
			};
			if ( self.cfg.demo ) {
				setTimeout( function () {
					done( { start: self.slot } );
				}, 400 );
				return;
			}
			fetch( self.endpoint( 'bookings/' + self.moving.id + '/reschedule' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify( { start: self.slot, token: self.moving.token } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( body ) {
						return { ok: res.ok, status: res.status, body: body };
					} );
				} )
				.then( function ( r ) {
					if ( r.ok ) {
						done( r.body );
						return;
					}
					if ( r.status === 409 && r.body && r.body.code === 'aab_slot_taken' ) {
						self.notice = r.body.message || t.error;
						self.view = 'times';
						self.slot = null;
						self.load( true );
						return;
					}
					status.textContent = ( r.body && r.body.message ) || t.error;
					status.hidden = false;
					button.disabled = false;
					button.textContent = sprintf( t.moveTo, timeText );
				} )
				.catch( function () {
					status.textContent = t.error;
					status.hidden = false;
					button.disabled = false;
					button.textContent = sprintf( t.moveTo, timeText );
				} );
		} );

		this.panel.appendChild( h( 'div', { class: 'aab__summary' }, [
			h( 'h3', { class: 'aab__panel-title', text: this.slotLabel( this.slot ) } ),
			h( 'button', { type: 'button', class: 'aab__link aab__back', text: t.changeTime, onclick: function () {
				self.view = 'times';
				self.renderPanel();
			} } ),
		] ) );
		this.panel.appendChild( h( 'p', { class: 'aab__was' }, [
			h( 'span', { class: 'aab__was-label', text: t.currentTime + ': ' } ),
			h( 'del', { text: this.shortDayFmt.format( new Date( Date.UTC( current.getFullYear(), current.getMonth(), current.getDate() ) ) ) + ', ' + this.timeFmt.format( current ) } ),
		] ) );
		this.panel.appendChild( status );
		this.panel.appendChild( button );
		button.focus( { preventScroll: true } );
	};

	Widget.prototype.renderDone = function () {
		var self = this;
		var r = this.result || {};
		if ( r.moved ) {
			var moved = h( 'div', { class: 'aab__done', tabindex: '-1' }, [
				h( 'p', { class: 'aab__done-badge', text: this.t.moved } ),
				h( 'h3', { class: 'aab__panel-title', text: this.slotLabel( r.start ) } ),
				h( 'p', { class: 'aab__done-text', text: this.t.movedText } ),
				this.moving.manage ? h( 'a', { class: 'aab__link', href: this.moving.manage, text: this.t.backToBooking } ) : null,
			] );
			this.panel.appendChild( moved );
			moved.focus( { preventScroll: true } );
			return;
		}
		var done = h( 'div', { class: 'aab__done', tabindex: '-1' }, [
			h( 'p', { class: 'aab__done-badge', text: this.t.booked } ),
			h( 'h3', { class: 'aab__panel-title', text: this.slotLabel( r.start || this.slot ) } ),
			r.message ? h( 'p', { class: 'aab__done-text', text: r.message } ) : null,
			r.meet ? h( 'p', null, [ h( 'a', { href: r.meet, target: '_blank', rel: 'noopener', text: this.t.joinCall } ) ] ) : null,
			h( 'button', { type: 'button', class: 'aab__link', text: this.t.bookAnother, onclick: function () {
				self.view = 'times';
				self.slot = null;
				self.selected = null;
				if ( self.types.length > 1 ) {
					self.showPicker();
				} else {
					self.load( true );
				}
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
