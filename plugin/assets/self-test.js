/**
 * Settings → HTTP QUERY: the "Test your server" button.
 *
 * Runs four checks and shows each result in plain language:
 *
 * 1. Browser → WordPress: a real fetch() with method QUERY to the echo route,
 *    signed in (cookie and X-WP-Nonce), carrying a random marker in the body.
 * 2. Browser → cache: two different QUERY bodies to the public fingerprint
 *    route, same URL, signed out (credentials: 'omit'), to see whether a cache
 *    on the way answers the second with the first's response.
 * 3 and 4. The same two checks from the server itself (loopback), run in PHP.
 *
 * What a response means is decided in PHP, once (diagnose_echo_response(),
 * diagnose_cache_probe()): this file only gathers what the browser saw and
 * posts it to the diagnose route. The only verdicts made here are the ones
 * no HTTP response exists for: the browser refusing to build a QUERY request,
 * the request failing on the network, and an opaque redirect.
 *
 * Config and translated strings come from wp_localize_script(), as
 * window.wpHttpQuerySelfTest. Everything is written with textContent.
 */
( function () {
	'use strict';

	var config = window.wpHttpQuerySelfTest;
	var button = document.getElementById( 'wp-http-query-self-test' );
	var output = document.getElementById( 'wp-http-query-self-test-results' );

	if ( ! config || ! button || ! output ) {
		return;
	}

	var i18n = config.i18n;

	function marker() {
		var bytes = new Uint8Array( 12 );
		window.crypto.getRandomValues( bytes );
		return Array.prototype.map
			.call( bytes, function ( b ) {
				return ( '0' + b.toString( 16 ) ).slice( -2 );
			} )
			.join( '' );
	}

	/** What the browser saw: status, headers (lower-cased names) and body text. */
	async function observe( res ) {
		var headers = {};
		res.headers.forEach( function ( value, name ) {
			headers[ name.toLowerCase() ] = value;
		} );
		return { status: res.status, headers: headers, body: await res.text() };
	}

	function local( verdict, status, message ) {
		return { verdict: verdict, status: status, http_status: 0, message: message };
	}

	function withUrl( result, url ) {
		result.url = url;
		return result;
	}

	/** Asks PHP what an observation means. */
	async function diagnose( payload ) {
		var res = await fetch( config.diagnoseUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( payload ),
		} );
		if ( ! res.ok ) {
			throw new Error( String( res.status ) );
		}
		return res.json();
	}

	/**
	 * Builds a QUERY request, or returns null if the browser refuses to.
	 * new Request() throws a TypeError for a method the browser won't send,
	 * before anything goes on the network.
	 */
	function queryRequest( url, init ) {
		try {
			return new Request( url, Object.assign( { method: 'QUERY', redirect: 'manual' }, init ) );
		} catch ( e ) {
			return null;
		}
	}

	async function send( request ) {
		var res;
		try {
			res = await fetch( request );
		} catch ( e ) {
			return { error: local( 'network_error', 'recommended', i18n.browserNetworkError ) };
		}
		if ( 'opaqueredirect' === res.type ) {
			return { error: local( 'redirect', 'critical', i18n.browserRedirect ) };
		}
		return { observation: await observe( res ) };
	}

	async function browserEcho() {
		var value = marker();
		var body = {};
		body[ config.markerKey ] = value;

		var request = queryRequest( config.echoUrl, {
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} );
		if ( ! request ) {
			return local( 'browser_unsupported', 'recommended', i18n.browserUnsupported );
		}

		var sent = await send( request );
		if ( sent.error ) {
			return sent.error;
		}
		return withUrl( await diagnose( { check: 'echo', marker: value, response: sent.observation } ), config.echoUrl );
	}

	async function browserCache() {
		var responses = [];

		for ( var n = 0; n < 2; n++ ) {
			var body = JSON.stringify( { probe: marker() } );
			// Signed out, and no cache-busting request headers: caches usually
			// skip requests with cookies, so a signed-in probe would always
			// look clean.
			var request = queryRequest( config.fingerprintUrl, {
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json' },
				body: body,
			} );
			if ( ! request ) {
				return local( 'browser_unsupported', 'recommended', i18n.browserUnsupported );
			}

			var sent = await send( request );
			if ( sent.error ) {
				return sent.error;
			}
			responses.push( Object.assign( { sent: body }, sent.observation ) );
		}

		return withUrl( await diagnose( { check: 'cache', first: responses[ 0 ], second: responses[ 1 ] } ), config.fingerprintUrl );
	}

	async function serverCheck( url ) {
		var res = await fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		} );
		if ( ! res.ok ) {
			throw new Error( String( res.status ) );
		}
		return ( await res.json() ).check;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	var NOTICE = { good: 'notice-success', recommended: 'notice-warning', critical: 'notice-error' };

	function render( title, result ) {
		var box = el( 'div', 'notice inline ' + ( NOTICE[ result.status ] || 'notice-info' ) );
		box.setAttribute( 'data-check', title.id );
		box.setAttribute( 'data-verdict', result.verdict );

		var heading = el( 'p' );
		heading.appendChild( el( 'strong', '', title.label + ': ' + ( i18n.status[ result.status ] || result.status ) ) );
		var statuses = result.http_statuses || ( result.http_status ? [ result.http_status ] : [] );
		if ( statuses.length ) {
			heading.appendChild( document.createTextNode( ' ' + i18n.httpStatus.replace( '%s', statuses.join( ', ' ) ) ) );
		}
		box.appendChild( heading );
		box.appendChild( el( 'p', '', result.message ) );

		if ( result.url ) {
			box.appendChild( el( 'p', 'description', i18n.urlTested.replace( '%s', result.url ) ) );
		}

		var seen = result.cache_headers || [];
		if ( seen.length ) {
			box.appendChild( el( 'p', '', i18n.cacheHeaders ) );
			var list = el( 'ul', 'ul-disc' );
			seen.forEach( function ( h ) {
				var item = el( 'li' );
				item.appendChild( el( 'code', '', ( h.response ? '#' + h.response + ' ' : '' ) + h.header + ': ' + h.value ) );
				if ( h.hint ) {
					item.appendChild( document.createTextNode( ' (' + h.hint + ')' ) );
				}
				list.appendChild( item );
			} );
			box.appendChild( list );
		}

		return box;
	}

	var CHECKS = [
		{ id: 'browser-echo', label: i18n.checks.browserEcho, run: browserEcho },
		{ id: 'browser-cache', label: i18n.checks.browserCache, run: browserCache },
		{ id: 'server-echo', label: i18n.checks.serverEcho, run: function () { return serverCheck( config.serverUrl ); } },
		{ id: 'server-cache', label: i18n.checks.serverCache, run: function () { return serverCheck( config.cacheUrl ); } },
	];

	button.addEventListener( 'click', async function () {
		button.disabled = true;
		output.textContent = '';
		output.appendChild( el( 'p', '', i18n.running ) );

		var boxes = [];
		for ( var n = 0; n < CHECKS.length; n++ ) {
			var result;
			try {
				result = await CHECKS[ n ].run();
			} catch ( e ) {
				result = local( 'test_failed', 'recommended', i18n.testFailed.replace( '%s', e.message ) );
			}
			boxes.push( render( CHECKS[ n ], result ) );
		}

		output.textContent = '';
		boxes.forEach( function ( box ) {
			output.appendChild( box );
		} );
		output.appendChild( el( 'p', 'description', i18n.limits ) );
		button.disabled = false;
	} );
} )();
