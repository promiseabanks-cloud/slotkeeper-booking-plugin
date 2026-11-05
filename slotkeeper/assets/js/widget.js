/**
 * SlotKeeper booking widget — production runtime.
 *
 * Ported from the approved flow-prototype (see PROJECT_BIBLE.md decision
 * log): same states, same container/motion decisions, real fetch() calls
 * against `slotkeeper/v1` instead of mock data. No framework, no build step
 * — plain ES2017, matching the plugin's no-runtime-dependency architecture.
 *
 * @package SlotKeeper
 */
( function () {
	'use strict';

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function money( minor, currency ) {
		var symbols = { NGN: '₦', USD: '$', GBP: '£', EUR: '€' };
		var symbol = symbols[ currency ] || ( currency + ' ' );
		return symbol + Math.round( minor / 100 ).toLocaleString();
	}

	function Widget( root ) {
		this.root = root;
		this.cfg = JSON.parse( root.getAttribute( 'data-sk-config' ) || '{}' );
		this.t = this.cfg.i18n || {};
		this.state = {
			screen: 'loading',
			resources: [],
			openCategory: null,
			resource: null,
			days: [],
			dayIdx: 0,
			time: null,
			range: { start: '', end: '' },
			seats: 1,
			customer: { name: '', email: '', phone: '', notes: '' },
		};
		this.init();
	}

	Widget.prototype.init = function () {
		var self = this;
		window.addEventListener( 'offline', function () { self.setOffline( true ); } );
		window.addEventListener( 'online', function () { self.setOffline( false ); } );

		// Returning from a Paystack redirect — the webhook (not this page)
		// is the real source of truth; this just gives the customer a
		// status to look at instead of landing back on an empty widget.
		var paidToken = new URLSearchParams( window.location.search ).get( 'sk_paid_token' );
		if ( paidToken ) {
			this.checkPaymentReturn( paidToken, 0 );
			return;
		}

		if ( this.cfg.resourceId ) {
			this.loadSingleResource( this.cfg.resourceId );
		} else {
			this.loadResourceList();
		}
	};

	Widget.prototype.checkPaymentReturn = function ( token, attempt ) {
		var self = this;
		this.state.screen = 'confirming-payment';
		this.render();

		this.api( '/bookings/lookup?token=' + encodeURIComponent( token ) )
			.then( function ( booking ) {
				if ( 'confirmed' === booking.status ) {
					self.lastCode = 'SK-' + booking.booking_id;
					self.state.screen = 'success';
					self.render();
				} else if ( 'cancelled' === booking.status ) {
					self.state.screen = 'payment-issue';
					self.render();
				} else if ( attempt < 6 ) {
					// Webhooks are usually near-instant but not guaranteed
					// to have landed yet — a few short retries covers the
					// normal case without polling forever.
					setTimeout( function () { self.checkPaymentReturn( token, attempt + 1 ); }, 2000 );
				} else {
					self.state.screen = 'payment-pending-slow';
					self.render();
				}
			} )
			.catch( function () {
				self.state.screen = 'payment-issue';
				self.render();
			} );
	};

	Widget.prototype.setOffline = function ( isOffline ) {
		this.offline = isOffline;
		this.render();
	};

	// ---------------------------------------------------------------- fetch

	Widget.prototype.api = function ( path, opts ) {
		opts = opts || {};
		return fetch( this.cfg.restBase + path, opts ).then( function ( res ) {
			return res.json().then( function ( body ) {
				if ( ! res.ok ) {
					var err = new Error( ( body && body.message ) || 'Request failed' );
					err.status = res.status;
					err.code = body && body.code;
					throw err;
				}
				return body;
			} );
		} );
	};

	Widget.prototype.loadResourceList = function () {
		var self = this;
		this.state.screen = 'loading';
		this.render();
		this.api( '/resources' )
			.then( function ( list ) {
				self.state.resources = list;
				self.state.screen = 'choose';
				self.render();
			} )
			.catch( function () {
				self.state.screen = 'error-generic';
				self.retryFn = function () { self.loadResourceList(); };
				self.render();
			} );
	};

	Widget.prototype.loadSingleResource = function ( id ) {
		var self = this;
		this.state.screen = 'loading';
		this.render();
		this.api( '/resources?resource_id=' + encodeURIComponent( id ) )
			.then( function ( list ) {
				self.state.resources = list;
				self.selectResource( list[ 0 ] );
			} )
			.catch( function () {
				self.state.screen = 'error-generic';
				self.retryFn = function () { self.loadSingleResource( id ); };
				self.render();
			} );
	};

	/**
	 * @param {Object} resource
	 * @param {boolean} [afterRaceLoss] When true (called after a 409 on
	 *   submit), the refreshed data lands on 'error-taken' instead of a bare
	 *   'when', so the explanation banner is still there once the fetch
	 *   resolves — not overwritten by it.
	 */
	Widget.prototype.selectResource = function ( resource, afterRaceLoss ) {
		var self = this;
		this.state.resource = resource;
		this.state.dayIdx = 0;
		this.state.time = null;
		this.state.seats = 1;

		if ( 'class_session' === resource.type || 'rental_item' === resource.type ) {
			this.state.screen = afterRaceLoss ? 'error-taken' : 'when';
			this.render();
			return;
		}

		this.state.screen = 'loading';
		this.render();
		var from = this.todayIso();
		var to = this.todayIso( 14 );
		this.api( '/availability?resource_id=' + resource.id + '&from=' + from + '&to=' + to )
			.then( function ( res ) {
				self.state.days = res.days || [];
				self.state.screen = self.state.days.length ? ( afterRaceLoss ? 'error-taken' : 'when' ) : 'empty';
				self.render();
			} )
			.catch( function () {
				self.state.screen = 'error-generic';
				self.retryFn = function () { self.selectResource( resource, afterRaceLoss ); };
				self.render();
			} );
	};

	Widget.prototype.todayIso = function ( plusDays ) {
		var d = new Date();
		if ( plusDays ) { d.setDate( d.getDate() + plusDays ); }
		return d.toISOString().slice( 0, 10 );
	};

	// ---------------------------------------------------------------- render

	Widget.prototype.render = function () {
		var d = this.cfg;
		var s = this.state;

		var stepIdx = { choose: 0, loading: 0, when: 1, empty: 1, details: 2, submitting: 2, success: 2, 'error-taken': 1, 'error-generic': 1 }[ s.screen ];
		if ( stepIdx === undefined ) { stepIdx = 0; }

		var html =
			'<div class="sk-widget">' +
				'<div class="sk-steps">' +
					[ 0, 1, 2 ].map( function ( i ) {
						return '<div class="sk-seg ' + ( i < stepIdx ? 'sk-done' : ( i === stepIdx ? 'sk-current' : '' ) ) + '"></div>';
					} ).join( '' ) +
				'</div>' +
				'<div class="sk-panel">' +
					( this.offline ? '<div class="sk-banner"><span>You\'re offline — we\'ll keep your details, try again once you\'re back.</span></div>' : '' ) +
					this.renderScreen() +
				'</div>' +
			'</div>';

		this.root.innerHTML = html;
		this.wire();
	};

	Widget.prototype.renderScreen = function () {
		var s = this.state;
		switch ( s.screen ) {
			case 'loading': return '<div class="sk-loading"><div class="sk-spin-lg"></div></div>';
			case 'choose': return this.screenChoose();
			case 'when': return this.screenWhen();
			case 'empty': return this.screenEmpty();
			case 'details': return this.screenDetails();
			case 'submitting': return '<div class="sk-loading"><div class="sk-spin-lg"></div><p class="sk-hint">' + esc( this.t.submitting ) + '</p></div>';
			case 'success': return this.screenSuccess();
			case 'error-taken': return this.screenErrorTaken();
			case 'error-generic': return this.screenErrorGeneric();
			case 'confirming-payment': return '<div class="sk-loading"><div class="sk-spin-lg"></div><p class="sk-hint">' + esc( this.t.confirming_payment ) + '</p></div>';
			case 'payment-pending-slow': return '<div class="sk-empty"><div class="sk-glyph">⏳</div><h3>' + esc( this.t.payment_pending_title ) + '</h3><p>' + esc( this.t.payment_pending_body ) + '</p></div>';
			case 'payment-issue': return '<div class="sk-empty"><div class="sk-glyph">⚠</div><h3>' + esc( this.t.payment_issue_title ) + '</h3><p>' + esc( this.t.payment_issue_body ) + '</p></div>';
			default: return '';
		}
	};

	// Preferred display order for salon categories; anything unlisted falls to
	// the end, alphabetically. Categories themselves come from the data
	// (resource.category) — this only decides the order they're shown in.
	var CATEGORY_ORDER = [
		'Knotless Braids', 'Boho Braids', 'Box Braids', 'Cornrows', 'Twists',
		'Bantu Knots', 'Locs', 'Weaves & Sew-Ins', 'Kids', 'Consultation & Extras',
	];

	Widget.prototype.groupByCategory = function ( resources ) {
		var map = {};
		resources.forEach( function ( r ) {
			var c = r.category || 'Styles';
			( map[ c ] = map[ c ] || [] ).push( r );
		} );
		return Object.keys( map ).sort( function ( a, b ) {
			var ia = CATEGORY_ORDER.indexOf( a ), ib = CATEGORY_ORDER.indexOf( b );
			if ( ia === -1 && ib === -1 ) { return a.localeCompare( b ); }
			if ( ia === -1 ) { return 1; }
			if ( ib === -1 ) { return -1; }
			return ia - ib;
		} ).map( function ( c ) {
			var items = map[ c ];
			var prices = items.map( function ( r ) { return r.price; } ).filter( function ( p ) { return p; } );
			return { category: c, items: items, minPrice: prices.length ? Math.min.apply( null, prices ) : null };
		} );
	};

	Widget.prototype.styleMeta = function ( r ) {
		var parts = [];
		if ( r.duration_minutes ) { parts.push( r.duration_minutes + ' min' ); }
		if ( r.price ) { parts.push( money( r.price, this.cfg.currency ) ); }
		else if ( ! r.requires_payment ) { parts.push( this.t.free_label || 'Free' ); }
		return parts.join( ' · ' );
	};

	Widget.prototype.screenChoose = function () {
		var self = this;
		var t = this.t;
		var groups = this.groupByCategory( this.state.resources );
		var single = groups.length === 1;

		var rows = groups.map( function ( g ) {
			var isOpen = single || g.category === self.state.openCategory;
			var count = g.items.length + ' ' + ( g.items.length === 1 ? ( t.style_one || 'style' ) : ( t.style_many || 'styles' ) );
			var aside = esc( count ) + ( g.minPrice ? ' · ' + esc( ( t.from_label || 'from' ) + ' ' + money( g.minPrice, self.cfg.currency ) ) : '' );

			var head = single ? '' :
				'<button type="button" class="sk-cat-head' + ( isOpen ? ' sk-open' : '' ) + '" data-cat="' + esc( g.category ) + '" aria-expanded="' + ( isOpen ? 'true' : 'false' ) + '">' +
					'<span class="sk-cat-name">' + esc( g.category ) + '</span>' +
					'<span class="sk-cat-aside"><span class="sk-cat-meta">' + aside + '</span><span class="sk-chev" aria-hidden="true"></span></span>' +
				'</button>';

			var body = '';
			if ( isOpen ) {
				var styles = g.items.map( function ( r ) {
					return '<button type="button" class="sk-style-row" data-rid="' + r.id + '">' +
						'<span class="sk-style-name">' + esc( r.name ) + '</span>' +
						'<span class="sk-style-aside"><span class="sk-style-meta">' + esc( self.styleMeta( r ) ) + '</span><span class="sk-arrow" aria-hidden="true"></span></span>' +
					'</button>';
				} ).join( '' );
				body = '<div class="sk-cat-body">' + styles + '</div>';
			}
			return '<div class="sk-cat' + ( isOpen ? ' sk-open' : '' ) + '">' + head + body + '</div>';
		} ).join( '' );

		return '<p class="sk-eyebrow">' + esc( t.eyebrow_choose || 'Step 1 of 3' ) + '</p>' +
			'<h3>' + esc( t.choose_title ) + '</h3>' +
			'<p class="sk-hint">' + esc( t.choose_hint || 'Pick a category, then choose your style.' ) + '</p>' +
			'<div class="sk-cat-list">' + rows + '</div>';
	};

	Widget.prototype.screenWhen = function () {
		var s = this.state;
		var r = s.resource;
		var t = this.t;
		var back = '<button type="button" class="sk-back" data-action="back-to-choose">' + esc( t.back ) + '</button>';

		if ( 'rental_item' === r.type ) {
			return back + '<h3>' + esc( t.range_title ) + '</h3><p class="sk-hint">' + esc( r.name ) + ' · ' + esc( r.meta || '' ) + '</p>' +
				'<div class="sk-row2">' +
					'<div class="sk-field"><label>' + esc( t.pick_up ) + '</label><input type="date" id="sk-range-start" value="' + esc( s.range.start ) + '"></div>' +
					'<div class="sk-field"><label>' + esc( t.return ) + '</label><input type="date" id="sk-range-end" value="' + esc( s.range.end ) + '"></div>' +
				'</div>' +
				'<button type="button" class="sk-btn" data-action="range-next">' + esc( t.continue ) + '</button>';
		}

		if ( 'class_session' === r.type ) {
			return back + '<h3>' + esc( r.name ) + '</h3><p class="sk-hint">' + esc( r.meta || '' ) + '</p>' +
				this.seatStepperHtml( t.seats, r.remaining ) +
				'<button type="button" class="sk-btn" data-action="seat-next" style="margin-top:16px;">' + esc( t.continue ) + '</button>';
		}

		var day = s.days[ s.dayIdx ] || { slots: [] };
		var chips = s.days.map( function ( dday, i ) {
			return '<button type="button" class="sk-day-chip ' + ( i === s.dayIdx ? 'sk-active' : '' ) + '" data-day="' + i + '">' +
				'<div class="sk-dow">' + esc( dday.label ) + '</div>' +
			'</button>';
		} ).join( '' );
		var times = day.slots.map( function ( slot ) {
			return '<button type="button" class="sk-time-btn ' + ( slot.start === s.time ? 'sk-selected' : '' ) + '" data-start="' + esc( slot.start ) + '" data-end="' + esc( slot.end ) + '">' + esc( slot.label ) + '</button>';
		} ).join( '' );

		return back + '<p class="sk-eyebrow">' + esc( t.eyebrow_when || '' ) + '</p><h3>' + esc( t.when_title ) + '</h3><p class="sk-hint">' + esc( r.name ) + ' · ' + esc( this.styleMeta( r ) ) + '</p>' +
			'<div class="sk-day-rail">' + chips + '</div>' +
			'<div class="sk-time-grid">' + times + '</div>' +
			( 'table' === r.type ? this.seatStepperHtml( t.party_size, r.capacity ) : '' ) +
			'<button type="button" class="sk-btn" data-action="time-next" style="margin-top:16px;" ' + ( s.time ? '' : 'disabled' ) + '>' + esc( t.continue ) + '</button>';
	};

	Widget.prototype.seatStepperHtml = function ( label, max ) {
		return '<div class="sk-field"><label>' + esc( label ) + '</label>' +
			'<div class="sk-stepper">' +
				'<button type="button" data-action="seat-minus">−</button>' +
				'<span class="sk-n sk-mono" id="sk-seat-n">' + this.state.seats + '</span>' +
				'<button type="button" data-action="seat-plus">+</button>' +
				( max ? '<span class="sk-hint" style="margin:0;">max ' + max + '</span>' : '' ) +
			'</div></div>';
	};

	Widget.prototype.screenEmpty = function () {
		return '<button type="button" class="sk-back" data-action="back-to-choose">' + esc( this.t.back ) + '</button>' +
			'<div class="sk-empty"><div class="sk-glyph">📅</div><h3>' + esc( this.t.no_slots ) + '</h3></div>';
	};

	Widget.prototype.screenDetails = function () {
		var s = this.state, t = this.t, r = s.resource;
		var when = this.whenSummary();
		var price = r.price || null;

		// Rentals charge per day — show the real total for the selected
		// range, not just the per-day rate, so nothing surprises the
		// customer at checkout. This is a preview only; the server computes
		// the actual charge independently in start_checkout().
		if ( price && 'rental_item' === r.type && s.range.start && s.range.end ) {
			var days = Math.max( 1, Math.round( ( new Date( s.range.end ) - new Date( s.range.start ) ) / 86400000 ) + 1 );
			price = price * days;
		}

		return '<button type="button" class="sk-back" data-action="back-from-details">' + esc( t.back ) + '</button>' +
			'<p class="sk-eyebrow">' + esc( t.eyebrow_details || '' ) + '</p>' +
			'<h3>' + esc( t.details_title ) + '</h3>' +
			'<div class="sk-field"><label>' + esc( t.field_name ) + ' <span class="sk-req">*</span></label><input type="text" id="sk-f-name" value="' + esc( s.customer.name ) + '"></div>' +
			'<div class="sk-field"><label>' + esc( t.field_email ) + ' <span class="sk-req">*</span></label><input type="email" id="sk-f-email" value="' + esc( s.customer.email ) + '"></div>' +
			'<div class="sk-field"><label>' + esc( t.field_phone ) + ' <span class="sk-req">*</span></label><input type="text" id="sk-f-phone" value="' + esc( s.customer.phone ) + '" placeholder="+234..."></div>' +
			'<div class="sk-field"><label>' + esc( t.field_notes ) + '</label><textarea id="sk-f-notes" rows="2">' + esc( s.customer.notes ) + '</textarea></div>' +
			'<input type="text" id="sk-f-website" class="sk-hp" tabindex="-1" autocomplete="off" aria-hidden="true">' +
			'<div class="sk-summary">' +
				'<div class="sk-line"><span class="sk-k">' + esc( r.name ) + '</span><span class="sk-v">' + esc( when ) + '</span></div>' +
				( ( r.type === 'table' || r.type === 'class_session' ) ? '<div class="sk-line"><span class="sk-k">' + ( r.type === 'table' ? esc( t.party_size ) : esc( t.seats ) ) + '</span><span class="sk-v sk-mono">' + s.seats + '</span></div>' : '' ) +
				( price ? '<div class="sk-line"><span class="sk-k">' + esc( r.requires_payment ? t.price_due : t.price_label ) + '</span><span class="sk-v sk-mono">' + money( price, this.cfg.currency ) + '</span></div>' : '' ) +
			'</div>' +
			'<button type="button" class="sk-btn" data-action="submit">' + esc( r.requires_payment ? t.continue_to_payment : t.submit ) + '</button>';
	};

	Widget.prototype.whenSummary = function () {
		var s = this.state, r = s.resource;
		if ( 'rental_item' === r.type ) { return s.range.start + ' → ' + s.range.end; }
		if ( 'class_session' === r.type ) { return r.meta || ''; }
		var day = s.days[ s.dayIdx ];
		var slot = day && day.slots.filter( function ( sl ) { return sl.start === s.time; } )[ 0 ];
		return day ? day.label + ( slot ? ' · ' + slot.label : '' ) : '';
	};

	Widget.prototype.screenSuccess = function () {
		var t = this.t;
		return '<div class="sk-success"><div class="sk-glyph">✓</div><h3>' + esc( t.success_title ) + '</h3>' +
			'<p>' + esc( t.success_note ) + '</p>' +
			( this.lastCode ? '<div class="sk-confo sk-mono">' + esc( this.lastCode ) + '</div>' : '' ) +
			'</div>';
	};

	Widget.prototype.screenErrorTaken = function () {
		return '<div class="sk-banner">' + esc( this.t.error_taken ) + '</div>' + this.screenWhen();
	};

	Widget.prototype.screenErrorGeneric = function () {
		return '<div class="sk-banner">' + esc( this.lastErrorMessage || this.t.error_generic ) + '</div>' +
			'<button type="button" class="sk-btn" data-action="retry">' + esc( this.t.retry ) + '</button>';
	};

	// ---------------------------------------------------------------- wiring

	Widget.prototype.wire = function () {
		var self = this;
		var root = this.root;

		function on( sel, evt, fn ) {
			var els = root.querySelectorAll( sel );
			for ( var i = 0; i < els.length; i++ ) { els[ i ].addEventListener( evt, fn ); }
		}

		on( '.sk-cat-head', 'click', function () {
			var c = this.getAttribute( 'data-cat' );
			self.state.openCategory = ( self.state.openCategory === c ) ? null : c;
			self.render();
		} );

		on( '.sk-style-row', 'click', function () {
			var id = parseInt( this.getAttribute( 'data-rid' ), 10 );
			var r = self.state.resources.filter( function ( x ) { return x.id === id; } )[ 0 ];
			if ( r ) { self.selectResource( r ); }
		} );

		on( '[data-action="back-to-choose"]', 'click', function () {
			self.state.screen = 'choose';
			self.render();
		} );

		on( '[data-action="back-from-details"]', 'click', function () {
			self.state.screen = 'when';
			self.render();
		} );

		on( '.sk-day-chip', 'click', function () {
			self.state.dayIdx = parseInt( this.getAttribute( 'data-day' ), 10 );
			self.state.time = null;
			self.render();
		} );

		on( '.sk-time-btn', 'click', function () {
			self.state.time = this.getAttribute( 'data-start' );
			self.state.timeEnd = this.getAttribute( 'data-end' );
			self.render();
		} );

		on( '[data-action="time-next"]', 'click', function () {
			if ( self.state.time ) { self.state.screen = 'details'; self.render(); }
		} );

		on( '[data-action="seat-minus"]', 'click', function () {
			self.state.seats = Math.max( 1, self.state.seats - 1 );
			var n = root.querySelector( '#sk-seat-n' ); if ( n ) { n.textContent = self.state.seats; }
		} );
		on( '[data-action="seat-plus"]', 'click', function () {
			var max = ( self.state.resource.type === 'class_session' ) ? self.state.resource.remaining : self.state.resource.capacity;
			self.state.seats = Math.min( max || 99, self.state.seats + 1 );
			var n = root.querySelector( '#sk-seat-n' ); if ( n ) { n.textContent = self.state.seats; }
		} );
		on( '[data-action="seat-next"]', 'click', function () {
			self.state.screen = 'details';
			self.render();
		} );

		on( '#sk-range-start', 'change', function () { self.state.range.start = this.value; } );
		on( '#sk-range-end', 'change', function () { self.state.range.end = this.value; } );
		on( '[data-action="range-next"]', 'click', function () {
			var start = root.querySelector( '#sk-range-start' ).value;
			var end = root.querySelector( '#sk-range-end' ).value;
			if ( ! start || ! end ) { return; }
			self.state.range = { start: start, end: end };
			self.state.screen = 'details';
			self.render();
		} );

		[ 'name', 'email', 'phone', 'notes' ].forEach( function ( key ) {
			on( '#sk-f-' + key, 'input', function () { self.state.customer[ key ] = this.value; } );
		} );

		on( '[data-action="submit"]', 'click', function () { self.submit(); } );
		on( '[data-action="retry"]', 'click', function () {
			if ( self.retryFn ) { self.retryFn(); }
		} );
	};

	// ---------------------------------------------------------------- submit

	Widget.prototype.submit = function () {
		var self = this;
		var s = this.state;

		var validName = !! s.customer.name;
		var validEmail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( s.customer.email );
		var validPhone = /^\+?[0-9]{10,14}$/.test( s.customer.phone );
		if ( ! validName || ! validEmail || ! validPhone ) {
			// Re-render details with inline guidance rather than a full
			// invalid-state screen — matches the approved prototype's
			// "correct field, resubmit" recovery, kept lightweight here.
			var msg = ! validName ? this.t.err_name : ( ! validEmail ? this.t.err_email : this.t.err_phone );
			var banner = document.createElement( 'div' );
			banner.className = 'sk-banner';
			banner.textContent = msg;
			var panel = this.root.querySelector( '.sk-panel' );
			panel.insertBefore( banner, panel.firstChild );
			return;
		}

		var website = this.root.querySelector( '#sk-f-website' );
		var body = {
			resource_id: s.resource.id,
			name: s.customer.name,
			email: s.customer.email,
			phone: s.customer.phone,
			notes: s.customer.notes,
			seats: s.seats,
			website: website ? website.value : '',
			// The server appends its own query param on top of this and
			// sends the browser back here after payment — never trusted for
			// anything but where to land the customer afterward.
			page_url: window.location.href.split( '?' )[ 0 ],
		};

		if ( 'rental_item' === s.resource.type ) {
			body.start = s.range.start + ' 00:00:00';
			body.end = s.range.end + ' 23:59:59';
		} else if ( 'class_session' === s.resource.type ) {
			// server derives start/end from the fixed session — nothing to send.
		} else {
			body.start = s.time;
			body.end = s.timeEnd;
		}

		s.screen = 'submitting';
		this.render();

		this.api( '/bookings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} )
			.then( function ( res ) {
				if ( res.checkout_url ) {
					// Full-page redirect to Paystack's hosted checkout — no
					// client-side payment SDK, matching the plugin's
					// no-runtime-dependency architecture. The booking already
					// exists as pending/unpaid and holds the slot.
					window.location.href = res.checkout_url;
					return;
				}
				self.lastCode = res.booking_id ? ( 'SK-' + res.booking_id ) : '';
				s.screen = 'success';
				self.render();
			} )
			.catch( function ( err ) {
				if ( err.status === 409 ) {
					// The exact race proven live in this project: refetch so
					// the taken option is gone, not just visually struck out.
					// selectResource renders its own loading/result states and
					// lands on 'error-taken' once the fresh data is in, so the
					// banner isn't clobbered by the refetch that follows it.
					self.selectResource( s.resource, true );
				} else {
					// Prefer the server's own explanation (e.g. a 503 "not
					// available for online booking" from a misconfigured
					// gateway) over the generic network-failure wording —
					// only a real network-level failure has no message.
					self.lastErrorMessage = err.message || self.t.error_generic;
					self.retryFn = function () { self.submit(); };
					s.screen = 'error-generic';
					self.render();
				}
			} );
	};

	// ---------------------------------------------------------------- mount

	function mountAll() {
		var roots = document.querySelectorAll( '.sk-widget-root:not([data-sk-mounted])' );
		for ( var i = 0; i < roots.length; i++ ) {
			roots[ i ].setAttribute( 'data-sk-mounted', '1' );
			new Widget( roots[ i ] ); // eslint-disable-line no-new
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mountAll );
	} else {
		mountAll();
	}
} )();
