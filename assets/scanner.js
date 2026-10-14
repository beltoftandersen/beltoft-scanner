/**
 * Scan to order.
 *
 * Vanilla ES2020. WooCommerce loads jQuery and this uses it for exactly one
 * thing - firing wc_fragments_refreshed so the inline cart redraws the way it
 * does for every other cart change - and works without it if it is not there.
 */
( function () {
	'use strict';

	var cfg = window.BeltoftScannerData;
	if ( ! cfg ) { return; }

	var t = cfg.i18n;

	/**
	 * How long a barcode must be OUT OF SIGHT before it reads as a new scan.
	 *
	 * Not a cooldown between reads. The camera decodes continuously, so a box
	 * resting in front of it is decoded over and over, and the question a
	 * repeat guard has to answer is "did this leave and come back?" - not "has
	 * a second passed?". Measured from the last frame that saw the code, so a
	 * box held in view never satisfies it however long it is held.
	 *
	 * Tuned above the gap between successful decodes of a code that IS in view
	 * - blur and refocus mean plenty of frames decode nothing - and below the
	 * time it takes a person to move one box away and present another face of
	 * it. Too low and a held box counts up on its own, which is what this
	 * replaces; too high and a deliberate quick re-scan is missed, which the
	 * operator sees immediately and can repeat.
	 */
	var ABSENT_MS = 900;

	/** How long to wait before resending scans whose request failed. */
	var RETRY_MS = 5000;

	/** Where scans wait out a lost connection or a lost session. */
	var QUEUE_KEY = 'bscan.queue';

	var state = {
		open: false,
		stream: null,
		track: null,
		detector: null,
		reader: null,
		rafId: 0,
		lastCode: '',
		lastSeen: 0,
		// Scans go to the server one at a time, in the order they were read.
		chain: Promise.resolve(),
		retryTimer: 0,
		torchOn: false,
		wakeLock: null,
		queue: readQueue()
	};

	var el = {};

	/* ---------------------------------------------------------------- utils */

	function $( id ) { return document.getElementById( id ); }

	function sprintf( str, args ) {
		var i = 0;
		return String( str )
			.replace( /%(\d)\$s/g, function ( _, n ) { return args[ n - 1 ]; } )
			.replace( /%s/g, function () { return args[ i++ ]; } );
	}

	function readQueue() {
		try { return JSON.parse( sessionStorage.getItem( QUEUE_KEY ) || '[]' ); } catch ( e ) { return []; }
	}

	function writeQueue() {
		try { sessionStorage.setItem( QUEUE_KEY, JSON.stringify( state.queue ) ); } catch ( e ) {}
	}

	function api( path, options ) {
		var opts = options || {};
		return fetch( cfg.restUrl + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign(
				{ 'X-WP-Nonce': cfg.nonce },
				opts.body ? { 'Content-Type': 'application/json' } : {}
			),
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( res.ok ) { return data; }
				var err = new Error( data.message || 'error' );
				err.status = res.status;
				err.code = data.code || '';
				err.data = data.data || {};
				throw err;
			} );
		} );
	}

	/* --------------------------------------------------------------- toasts */

	function toast( text, actions, tone ) {
		var box = document.createElement( 'div' );
		box.className = 'bscan-toast' + ( tone ? ' bscan-toast--' + tone : '' );

		var msg = document.createElement( 'span' );
		msg.className = 'bscan-toast__text';
		msg.textContent = text;
		box.appendChild( msg );

		( actions || [] ).forEach( function ( action ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'bscan-toast__action';
			btn.textContent = action.label;
			btn.addEventListener( 'click', function () {
				action.run();
				box.remove();
			} );
			box.appendChild( btn );
		} );

		el.toasts.appendChild( box );

		// Long enough to read and act on, short enough not to stack up while
		// someone is scanning a shelf.
		setTimeout( function () { box.classList.add( 'is-going' ); }, 5200 );
		setTimeout( function () { box.remove(); }, 5800 );

		return box;
	}

	function status( text ) {
		el.status.textContent = text || '';
	}

	/* --------------------------------------------------- feedback on success */

	var audio = null;

	function beep() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctx ) { return; }
			audio = audio || new Ctx();
			if ( 'suspended' === audio.state ) { audio.resume(); }

			var osc = audio.createOscillator();
			var gain = audio.createGain();
			osc.type = 'sine';
			osc.frequency.value = 880;
			gain.gain.value = 0.05;
			osc.connect( gain ).connect( audio.destination );
			osc.start();
			osc.stop( audio.currentTime + 0.08 );
		} catch ( e ) {}
	}

	function buzz() {
		if ( navigator.vibrate ) { navigator.vibrate( 40 ); }
	}

	/* ------------------------------------------------------------- the list */

	/**
	 * The row for a product, if it happens to be on screen.
	 *
	 * data-product-id is put on the loop item by the ordering page; Bricks'
	 * own row classes are regenerated whenever the page is re-saved in the
	 * builder, so matching on those would break silently at some later date.
	 */
	function rowFor( id ) {
		return document.querySelector( '[data-product-id="' + String( id ).replace( /"/g, '' ) + '"]' );
	}

	/**
	 * Show the new quantity on the row and mark it, without redrawing the list.
	 * With a hundred rows on screen a full re-render per scan would be visible.
	 */
	function markRow( id, quantity ) {
		var row = rowFor( id );
		if ( ! row ) { return false; }

		var input = row.querySelector( '.beltoft-loop-qty__input, input.qty' );
		if ( input && null !== quantity && quantity > 0 ) { input.value = quantity; }

		row.classList.remove( 'bscan-hit' );
		// Reflow so the animation restarts when the same row is scanned twice.
		void row.offsetWidth;
		row.classList.add( 'bscan-hit' );

		row.scrollIntoView( { block: 'center', behavior: 'smooth' } );

		return true;
	}

	/**
	 * Tell the page behind the panel that its cart changed.
	 *
	 * NOT `wc_fragment_refresh`, which is what this used to fire. That is a
	 * REQUEST, handled by wc-cart-fragments.js - and that script is not loaded
	 * on the ordering page. Nothing was listening, so nothing happened: the
	 * scan wrote to the real cart correctly, the panel showed the right
	 * numbers, and the cart behind it kept its page-load markup until a
	 * reload. That is the bug this fixes, and it looked exactly like the
	 * scanner having a cart of its own.
	 *
	 * NOT `added_to_cart` either, which is what the product list fires.
	 * Frontend\CartOffcanvas opens the cart panel on that event below the
	 * stacking breakpoint - correct for a buyer tapping "add" in the list, and
	 * wrong here, where it would throw the cart over the scanner on every
	 * single scan. It is also a lie about what happened when the quantity went
	 * down.
	 *
	 * `wc_fragments_refreshed` is the one that says "the cart markup has
	 * changed" without asking anyone to open anything. Bricks subscribes to it
	 * and re-renders its cart elements.
	 */
	function refreshCart() {
		fetch( '/?wc-ajax=get_refreshed_fragments', { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data || ! data.fragments ) { return; }

				Object.keys( data.fragments ).forEach( function ( sel ) {
					document.querySelectorAll( sel ).forEach( function ( node ) {
						node.outerHTML = data.fragments[ sel ];
					} );
				} );

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger(
						'wc_fragments_refreshed',
						[ data.fragments, data.cart_hash ]
					);
				}
			} )
			.catch( function () {} );
	}


	/* ------------------------------------------------------- the order list */

	/**
	 * One DOM node per line, kept in a map and updated in place.
	 *
	 * Redrawing the whole list on every scan would be visible on a long order
	 * and would throw away the input the partner might be holding, so a scan
	 * touches exactly one row.
	 */
	var lines = new Map();

	function renderOrder( data ) {
		var seen = new Set();

		( data.lines || [] ).forEach( function ( line ) {
			seen.add( line.product_id );
			upsertLine( line );
		} );

		lines.forEach( function ( node, id ) {
			if ( ! seen.has( id ) ) {
				node.remove();
				lines.delete( id );
			}
		} );

		var count = data.count || 0;
		el.count.textContent = count
			? sprintf( 1 === count ? t.oneLine : t.nLines, [ count ] )
			: '';
		el.subtotal.textContent = count ? data.subtotal : '';
		el.empty.hidden = count > 0;
		el.go.hidden = ! count;
		el.go.href = data.cartUrl || '#';
	}

	function upsertLine( line ) {
		var node = lines.get( line.product_id );

		if ( ! node ) {
			node = buildLine( line );
			lines.set( line.product_id, node );
		}

		/*
		 * Appended in the order the server sent, which is newest first. Putting
		 * each one at the top instead would reverse that list on every redraw,
		 * leaving the oldest scan on top - exactly where it is least useful.
		 * appendChild moves a node that is already here, so this also reorders.
		 */
		el.lines.appendChild( node );

		node.querySelector( '.bscan-line__qty' ).value = line.quantity;
		node.querySelector( '.bscan-line__total' ).textContent = line.line_total;

		var note = node.querySelector( '.bscan-line__note' );
		if ( null !== line.stock && line.stock <= line.quantity ) {
			note.textContent = sprintf( t.available, [ line.stock ] );
			note.hidden = false;
		} else {
			note.hidden = true;
		}

		flash( node );

		return node;
	}

	function buildLine( line ) {
		var li = document.createElement( 'li' );
		li.className = 'bscan-line';
		li.dataset.productId = line.product_id;

		li.innerHTML =
			'<div class="bscan-line__thumb">' +
				( line.thumbnail ? '<img src="" alt="" loading="lazy">' : '' ) +
			'</div>' +
			'<div class="bscan-line__body">' +
				'<p class="bscan-line__name"></p>' +
				'<p class="bscan-line__note" hidden></p>' +
				'<div class="bscan-line__controls">' +
					'<button type="button" class="bscan-step" data-step="-1" aria-label="' + t.less + '">&minus;</button>' +
					'<input type="number" class="bscan-line__qty" min="0" step="1" inputmode="numeric" aria-label="' + t.qty + '">' +
					'<button type="button" class="bscan-step" data-step="1" aria-label="' + t.more + '">+</button>' +
					'<button type="button" class="bscan-line__remove" aria-label="' + t.remove + '">&times;</button>' +
				'</div>' +
			'</div>' +
			'<span class="bscan-line__total"></span>';

		li.querySelector( '.bscan-line__name' ).textContent = line.name;

		if ( line.thumbnail ) {
			li.querySelector( 'img' ).src = line.thumbnail;
		}

		li.querySelector( '[data-step="-1"]' ).addEventListener( 'click', function () {
			adjust( line.product_id, { delta: -1 } );
		} );
		li.querySelector( '[data-step="1"]' ).addEventListener( 'click', function () {
			adjust( line.product_id, { delta: 1 } );
		} );
		li.querySelector( '.bscan-line__remove' ).addEventListener( 'click', function () {
			adjust( line.product_id, { set: 0 } );
		} );
		li.querySelector( '.bscan-line__qty' ).addEventListener( 'change', function ( e ) {
			var value = parseInt( e.target.value, 10 );
			adjust( line.product_id, { set: isNaN( value ) || value < 0 ? 0 : value } );
		} );

		return li;
	}

	function flash( node ) {
		node.classList.remove( 'is-hit' );
		void node.offsetWidth;
		node.classList.add( 'is-hit' );
	}

	/** Any change to a line, typed or tapped, goes the same way as a scan. */
	function adjust( productId, change ) {
		var body = { product_id: productId };
		if ( 'set' in change ) { body.set = change.set; } else { body.delta = change.delta; }

		return api( '/cart', { method: 'POST', body: body } )
			.then( function ( res ) {
				markRow( productId, res.quantity );
				renderOrder( res );
				refreshCart();
			} )
			.catch( function ( err ) {
				// Not a scan: nothing to queue or replay, only to explain.
				if ( sessionLost( err ) ) {
					askToSignIn();
				} else {
					toast( isNetworkError( err ) ? t.offline : err.message, null, 'error' );
				}
			} );
	}

	function loadOrder() {
		return api( '/cart' )
			.then( function ( data ) {
				renderOrder( data );
				refreshCart();
				return data;
			} )
			.catch( function () {} );
	}

	/* ------------------------------------------------------------ the scans */

	function handleCode( code ) {
		if ( ! code ) { return; }

		var now  = Date.now();
		var same = code === state.lastCode;

		/*
		 * Three scans of the same box a second apart is still a quantity of
		 * three - customers do that, and this keeps it. What it no longer
		 * counts is the same box sitting in front of the lens.
		 *
		 * The previous guard compared against the last ACCEPTED read and left
		 * that timestamp alone when it rejected one, so the window slid: a
		 * barcode held in view was accepted once a second, for as long as it
		 * was held. Deleting the line changed nothing, because the camera was
		 * still looking at the barcode and put it back a second later.
		 *
		 * Every decode refreshes `lastSeen`, accepted or not, so the gap below
		 * is time the code was genuinely absent from the frame.
		 */
		var away = now - state.lastSeen;

		if ( same ) {
			state.lastSeen = now;

			if ( away < ABSENT_MS ) { return; }
		}

		state.lastCode = code;
		state.lastSeen = now;

		if ( ! navigator.onLine ) {
			queueCode( code );
			return;
		}

		lookup( code );
	}

	/** `quiet` for a replayed scan going back: it was announced the first time. */
	function queueCode( code, quiet ) {
		state.queue.push( { code: code, at: Date.now() } );
		writeQueue();
		if ( ! quiet ) { toast( code + ' · ' + t.pending, null, 'pending' ); }
	}

	/*
	 * A scan that arrives while the previous one is still on its way waits its
	 * turn rather than being dropped. Working along a shelf, the next box is
	 * often read before the last reply is back, and a scan that vanished
	 * without a beep or a message was a unit missing from the order.
	 */
	function lookup( code, replay ) {
		state.chain = state.chain
			.then( function () { return send( code, replay ); } )
			.catch( function () {} ); // One bad reply must not stop every scan after it.
	}

	function send( code, replay ) {
		// POST with add=1 resolves the code and puts the unit on the order in one call.
		return api( '/lookup', { method: 'POST', body: { code: code, add: true } } )
			.then( function ( data ) {
				if ( 'out_of_stock' === data.status ) {
					outOfStock( data.product );
					return;
				}

				if ( 'discontinued' === data.status ) {
					discontinued( code, data );
					return;
				}

				applied( data.product, data, 'backorder' === data.status );
			} )
			.catch( function ( err ) { failed( code, err, replay ); } );
	}

	/** Used when the partner accepts a replacement, which needs its own add. */
	function addToOrder( product, backorder ) {
		api( '/cart', { method: 'POST', body: { product_id: product.id, delta: 1 } } )
			.then( function ( res ) { applied( product, res, backorder ); } )
			.catch( function ( err ) { failed( product.gtin || product.sku, err ); } );
	}

	/**
	 * A unit went on the order. The reply already carries the whole order, so
	 * the panel redraws from it without asking again.
	 */
	function applied( product, res, backorder ) {
		buzz();
		beep();
		markRow( product.id, res.quantity );
		renderOrder( res );
		refreshCart();

		/*
		 * No toast on a good scan. The line appears at the top of the order
		 * with its own quantity control, which says more than a message that
		 * disappears - and a toast per scan stacked three deep over the thing
		 * the partner was trying to read. Only the cases that need words get
		 * them.
		 */
		if ( backorder ) {
			toast( product.name + ' · ' + t.noStock, null, 'warn' );
		} else if ( res.capped ) {
			toast( product.name + ' · ' + sprintf( t.available, [ res.stock ] ), null, 'warn' );
		}
	}

	function outOfStock( product ) {
		if ( ! cfg.canNotify ) {
			toast( product.name + ' · ' + t.noStock, null, 'warn' );
			return;
		}

		toast( product.name + ' · ' + t.noStock, [ {
			label: t.notify,
			run: function () {
				api( '/notify', { method: 'POST', body: { product_id: product.id } } )
					.then( function ( res ) { toast( res.message, null, 'ok' ); } )
					.catch( function () {} );
			}
		} ], 'warn' );
	}

	function discontinued( code, data ) {
		if ( data.replacement ) {
			toast( data.product.name + ' → ' + data.replacement.name, [ {
				label: t.add,
				run: function () { addToOrder( data.replacement, false ); }
			} ], 'warn' );
			return;
		}

		notFound( code );
	}

	function notFound( code ) {
		toast( sprintf( t.notFound || 'Code not found: %s', [ code ] ), [ {
			label: t.report,
			run: function () {
				api( '/report', { method: 'POST', body: { code: code } } )
					.then( function ( res ) { toast( res.message, null, 'ok' ); } )
					.catch( function () {} );
			}
		} ], 'error' );
	}

	/*
	 * Signed out, or the nonce expired - WordPress answers the latter with 403
	 * rest_cookie_invalid_nonce. Any other 403 is the scanner refusing this
	 * account, which signing in again would not change.
	 */
	function sessionLost( err ) {
		return 401 === err.status || ( 403 === err.status && 'rest_cookie_invalid_nonce' === err.code );
	}

	/** fetch() itself failed: no reply at all, whatever navigator.onLine says. */
	function isNetworkError( err ) {
		return ! err.status;
	}

	function askToSignIn() {
		toast( t.sessionLost, [ {
			label: t.signIn,
			run: function () { window.location.href = cfg.loginUrl; }
		} ], 'error' );
	}

	function failed( code, err, replay ) {
		if ( sessionLost( err ) ) {
			// Keep what has not been applied yet, then send them to sign in.
			queueCode( code, replay );
			askToSignIn();
			return;
		}

		/*
		 * navigator.onLine only knows about the device's own link. On warehouse
		 * Wi-Fi it says "online" while requests fail, so a failed request is
		 * itself the sign: keep the code and try again shortly.
		 */
		if ( isNetworkError( err ) ) {
			queueCode( code, replay );
			retrySoon();
			return;
		}

		if ( 404 === err.status ) {
			notFound( err.data && err.data.code_scanned ? err.data.code_scanned : code );
			return;
		}

		toast( err.message || String( code ), null, 'error' );
	}

	function retrySoon() {
		if ( state.retryTimer ) { return; }

		state.retryTimer = setTimeout( function () {
			state.retryTimer = 0;
			drainQueue( true );
		}, RETRY_MS );
	}

	/**
	 * Anything held while offline or signed out, replayed in order - each one
	 * after the previous reply, through the same chain as a live scan. Codes
	 * that fail again on the network go back on the queue for the next try.
	 */
	function drainQueue( retry ) {
		if ( ! state.queue.length || ! navigator.onLine ) { return; }

		var pending = state.queue.slice();
		state.queue = [];
		writeQueue();

		// A timed retry stays silent; coming back online or signed in is news.
		if ( true !== retry ) { toast( t.restored, null, 'ok' ); }

		pending.forEach( function ( item ) { lookup( item.code, true ); } );
	}

	/* ------------------------------------------------------------- decoding */

	function inAppWebview() {
		var ua = navigator.userAgent || '';
		return /FBAN|FBAV|Instagram|WhatsApp|Line\//i.test( ua );
	}

	function startCamera() {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			status( inAppWebview() ? t.webview : t.noCamera );
			showExternalHint();
			return Promise.resolve( false );
		}

		status( t.starting );

		return navigator.mediaDevices.getUserMedia( {
			audio: false,
			video: {
				facingMode: { ideal: 'environment' },
				width: { ideal: 1920 },
				height: { ideal: 1080 }
			}
		} ).then( function ( stream ) {
			state.stream = stream;
			state.track = stream.getVideoTracks()[ 0 ];
			el.video.srcObject = stream;
			status( '' );
			setUpTorch();
			keepAwake();
			return el.video.play().then( function () { return true; } );
		} ).catch( function ( err ) {
			if ( inAppWebview() ) {
				status( t.webview );
				showExternalHint();
			} else if ( 'NotAllowedError' === err.name || 'SecurityError' === err.name ) {
				status( t.denied );
			} else {
				status( t.noCamera );
			}
			return false;
		} );
	}

	function showExternalHint() {
		var link = document.createElement( 'a' );
		link.className = 'bscan-external';
		link.href = window.location.href;
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = t.openExternal;
		el.status.appendChild( link );
	}

	function setUpTorch() {
		var caps = state.track && state.track.getCapabilities ? state.track.getCapabilities() : {};

		// Android Chrome only. Hidden rather than shown-and-broken elsewhere.
		if ( ! caps || ! caps.torch ) { return; }

		el.torch.hidden = false;
		el.torch.addEventListener( 'click', function () {
			state.torchOn = ! state.torchOn;
			state.track.applyConstraints( { advanced: [ { torch: state.torchOn } ] } ).catch( function () {} );
			el.torch.classList.toggle( 'is-on', state.torchOn );
		} );
	}

	function keepAwake() {
		if ( ! navigator.wakeLock ) { return; }

		navigator.wakeLock.request( 'screen' )
			.then( function ( lock ) { state.wakeLock = lock; } )
			.catch( function () {} );
	}

	function startDecoding() {
		if ( 'BarcodeDetector' in window ) {
			return window.BarcodeDetector.getSupportedFormats()
				.then( function ( formats ) {
					var wanted = [ 'ean_13', 'ean_8' ].filter( function ( f ) { return formats.indexOf( f ) > -1; } );
					if ( ! wanted.length ) { return loadZxing(); }

					state.detector = new window.BarcodeDetector( { formats: wanted } );
					nativeLoop();
				} )
				.catch( loadZxing );
		}

		return loadZxing();
	}

	function nativeLoop() {
		if ( ! state.open || ! state.detector ) { return; }

		state.detector.detect( el.video )
			.then( function ( codes ) {
				if ( codes && codes.length ) { handleCode( codes[ 0 ].rawValue ); }
			} )
			.catch( function () {} )
			.finally( function () {
				state.rafId = requestAnimationFrame( nativeLoop );
			} );
	}

	/** Only fetched where the browser has no detector of its own - iOS, mostly. */
	function loadZxing() {
		return new Promise( function ( resolve ) {
			if ( window.ZXing ) { return resolve(); }

			var s = document.createElement( 'script' );
			s.src = cfg.zxingUrl;
			s.onload = function () { resolve(); };
			s.onerror = function () { status( t.noCamera ); resolve(); };
			document.head.appendChild( s );
		} ).then( function () {
			if ( ! window.ZXing || ! state.open ) { return; }

			var hints = new Map();
			hints.set(
				window.ZXing.DecodeHintType.POSSIBLE_FORMATS,
				[ window.ZXing.BarcodeFormat.EAN_13, window.ZXing.BarcodeFormat.EAN_8 ]
			);

			state.reader = new window.ZXing.BrowserMultiFormatReader( hints );
			state.reader.decodeFromVideoElement( el.video, function ( result ) {
				if ( result ) { handleCode( result.getText() ); }
			} ).catch( function () {} );
		} );
	}

	function stopDecoding() {
		if ( state.rafId ) { cancelAnimationFrame( state.rafId ); state.rafId = 0; }

		if ( state.reader ) {
			try { state.reader.reset(); } catch ( e ) {}
			state.reader = null;
		}

		state.detector = null;
	}

	function stopCamera() {
		stopDecoding();

		if ( state.stream ) {
			state.stream.getTracks().forEach( function ( track ) { track.stop(); } );
			state.stream = null;
			state.track = null;
		}

		if ( state.wakeLock ) {
			try { state.wakeLock.release(); } catch ( e ) {}
			state.wakeLock = null;
		}

		el.video.srcObject = null;
		state.torchOn = false;
		el.torch.hidden = true;
		el.torch.classList.remove( 'is-on' );
	}

	/* ----------------------------------------------------------- open/close */

	function open() {
		if ( state.open ) { return; }

		state.open = true;
		el.panel.hidden = false;
		el.panel.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'bscan-open' );

		document.querySelectorAll( '[data-bscan-open]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-expanded', 'true' );
		} );

		// Must be inside the tap: iOS will not grant the camera otherwise.
		startCamera().then( function ( ok ) {
			if ( ok ) { startDecoding(); }
		} );

		loadOrder();
		drainQueue();
	}

	function close() {
		if ( ! state.open ) { return; }

		state.open = false;
		stopCamera();

		el.panel.hidden = true;
		el.panel.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'bscan-open' );
		status( '' );

		document.querySelectorAll( '[data-bscan-open]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-expanded', 'false' );
		} );
	}

	/* ------------------------------------------------------------------ init */

	function init() {
		el.panel = $( 'bscan-panel' );
		el.video = $( 'bscan-video' );
		el.status = $( 'bscan-status' );
		el.torch = $( 'bscan-torch' );
		el.toasts = $( 'bscan-toasts' );
		el.manual = $( 'bscan-manual' );
		el.manualInput = $( 'bscan-manual-input' );
		el.lines = $( 'bscan-lines' );
		el.empty = $( 'bscan-empty' );
		el.count = $( 'bscan-count' );
		el.subtotal = $( 'bscan-subtotal' );
		el.go = $( 'bscan-go' );

		if ( ! el.panel ) { return; }

		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest( '[data-bscan-open]' );
			if ( trigger ) { e.preventDefault(); open(); }
		} );

		$( 'bscan-close' ).addEventListener( 'click', close );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && state.open ) { close(); }
		} );

		el.manual.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var code = el.manualInput.value.trim();
			if ( ! code ) { return; }
			el.manualInput.value = '';
			// Typed codes bypass the repeat guard: typing it twice is intent.
			state.lastCode = '';
			handleCode( code );
		} );

		/*
		 * A phone that locks, or the partner switching to another app, ends the
		 * camera track. Holding a dead track means a black rectangle on return,
		 * so it is dropped and reacquired.
		 */
		document.addEventListener( 'visibilitychange', function () {
			if ( ! state.open ) { return; }

			if ( document.hidden ) {
				stopCamera();
			} else {
				startCamera().then( function ( ok ) { if ( ok ) { startDecoding(); } } );
			}
		} );

		window.addEventListener( 'online', drainQueue );
		window.addEventListener( 'offline', function () { toast( t.offline, null, 'warn' ); } );

		// Scans held from before a sign-in are replayed once the page is back.
		if ( state.queue.length ) { drainQueue(); }
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
