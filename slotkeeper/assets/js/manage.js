/**
 * SlotKeeper self-service cancellation page — Flow 06.
 * Reuses the widget.css design system (same tokens, same `.sk-*` classes)
 * but is its own small state machine — a different flow, own JS entry point.
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

	function Manage( root ) {
		this.root = root;
		this.cfg = JSON.parse( root.getAttribute( 'data-sk-config' ) || '{}' );
		this.t = this.cfg.i18n || {};
		this.screen = 'loading';
		this.booking = null;
		this.confirming = false;
		this.init();
	}

	Manage.prototype.init = function () {
		if ( ! this.cfg.token ) {
			this.screen = 'not-found';
			this.render();
			return;
		}
		this.lookup();
	};

	Manage.prototype.api = function ( path, opts ) {
		opts = opts || {};
		return fetch( this.cfg.restBase + path, opts ).then( function ( res ) {
			return res.json().then( function ( body ) {
				if ( ! res.ok ) {
					var err = new Error( ( body && body.message ) || 'Request failed' );
					err.status = res.status;
					throw err;
				}
				return body;
			} );
		} );
	};

	Manage.prototype.lookup = function () {
		var self = this;
		this.screen = 'loading';
		this.render();
		this.api( '/bookings/lookup?token=' + encodeURIComponent( this.cfg.token ) )
			.then( function ( booking ) {
				self.booking = booking;
				self.screen = self.deriveScreen( booking );
				self.render();
			} )
			.catch( function ( err ) {
				self.screen = ( err.status === 404 ) ? 'not-found' : 'error';
				self.render();
			} );
	};

	Manage.prototype.deriveScreen = function ( booking ) {
		if ( 'cancelled' === booking.status ) { return 'cancelled'; }
		if ( ! booking.cancellable ) { return 'past'; }
		return 'found';
	};

	Manage.prototype.render = function () {
		this.root.innerHTML = '<div class="sk-widget"><div class="sk-panel">' + this.renderScreen() + '</div></div>';
		this.wire();
	};

	Manage.prototype.renderScreen = function () {
		var t = this.t;
		switch ( this.screen ) {
			case 'loading':
				return '<div class="sk-loading"><div class="sk-spin-lg"></div><p class="sk-hint">' + esc( t.loading ) + '</p></div>';
			case 'not-found':
				return '<div class="sk-empty"><div class="sk-glyph">🔍</div><h3>' + esc( t.not_found_title ) + '</h3><p>' + esc( t.not_found_body ) + '</p></div>';
			case 'cancelled':
				return '<div class="sk-empty"><div class="sk-glyph">—</div><h3>' + esc( t.cancelled_title ) + '</h3><p>' + esc( t.cancelled_body ) + '</p></div>' + this.summaryHtml();
			case 'past':
				return '<div class="sk-empty"><div class="sk-glyph">📅</div><h3>' + esc( t.past_title ) + '</h3><p>' + esc( t.past_body ) + '</p></div>' + this.summaryHtml();
			case 'found':
				return '<h3>' + esc( t.manage_title ) + '</h3>' + this.summaryHtml() +
					( this.confirming ? this.confirmHtml() : '<button type="button" class="sk-btn" data-action="ask-cancel">' + esc( t.cancel_button ) + '</button>' );
			case 'cancelling':
				return '<div class="sk-loading"><div class="sk-spin-lg"></div><p class="sk-hint">' + esc( t.cancelling ) + '</p></div>';
			case 'done':
				return '<div class="sk-success"><div class="sk-glyph">✓</div><h3>' + esc( t.done_title ) + '</h3><p>' + esc( t.done_body ) + '</p></div>' + this.summaryHtml();
			case 'error':
				return '<div class="sk-banner">' + esc( t.error_generic ) + '</div><button type="button" class="sk-btn" data-action="retry">' + esc( t.retry ) + '</button>';
			default:
				return '';
		}
	};

	Manage.prototype.summaryHtml = function () {
		var b = this.booking, t = this.t;
		if ( ! b ) { return ''; }
		return '<div class="sk-summary">' +
			'<div class="sk-line"><span class="sk-k">' + esc( t.with ) + '</span><span class="sk-v">' + esc( b.resource_name ) + '</span></div>' +
			'<div class="sk-line"><span class="sk-k">' + esc( t.when ) + '</span><span class="sk-v sk-mono">' + esc( b.when || '' ) + '</span></div>' +
			( b.seats > 1 ? '<div class="sk-line"><span class="sk-k">' + esc( t.seats ) + '</span><span class="sk-v sk-mono">' + b.seats + '</span></div>' : '' ) +
			'<div class="sk-line"><span class="sk-k">' + esc( t.status ) + '</span><span class="sk-v">' + esc( b.status ) + '</span></div>' +
		'</div>';
	};

	Manage.prototype.confirmHtml = function () {
		var t = this.t;
		return '<div class="sk-banner">' + esc( t.confirm_prompt ) + '</div>' +
			'<div style="display:flex; gap:10px;">' +
				'<button type="button" class="sk-btn" data-action="confirm-cancel" style="background:var(--sk-error);">' + esc( t.confirm_yes ) + '</button>' +
				'<button type="button" class="sk-btn" data-action="deny-cancel" style="background:transparent; border:1px solid var(--sk-line); color:var(--sk-ink);">' + esc( t.confirm_no ) + '</button>' +
			'</div>';
	};

	Manage.prototype.wire = function () {
		var self = this;
		var root = this.root;

		function on( sel, evt, fn ) {
			var el = root.querySelector( sel );
			if ( el ) { el.addEventListener( evt, fn ); }
		}

		on( '[data-action="ask-cancel"]', 'click', function () {
			self.confirming = true;
			self.render();
		} );
		on( '[data-action="deny-cancel"]', 'click', function () {
			self.confirming = false;
			self.render();
		} );
		on( '[data-action="confirm-cancel"]', 'click', function () { self.doCancel(); } );
		on( '[data-action="retry"]', 'click', function () { self.lookup(); } );
	};

	Manage.prototype.doCancel = function () {
		var self = this;
		this.screen = 'cancelling';
		this.render();
		this.api( '/bookings/cancel', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { token: this.cfg.token } ),
		} )
			.then( function ( booking ) {
				self.booking = booking;
				self.screen = 'done';
				self.confirming = false;
				self.render();
			} )
			.catch( function () {
				self.screen = 'error';
				self.render();
			} );
	};

	function mountAll() {
		var el = document.getElementById( 'sk-manage-root' );
		if ( el && ! el.getAttribute( 'data-sk-mounted' ) ) {
			el.setAttribute( 'data-sk-mounted', '1' );
			new Manage( el ); // eslint-disable-line no-new
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mountAll );
	} else {
		mountAll();
	}
} )();
