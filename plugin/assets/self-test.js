/**
 * Settings → HTTP QUERY: the "Test your server" button.
 *
 * Runs five checks, in two groups, and shows each result in plain language:
 *
 * From your browser, through everything in front of the site:
 * 1. QUERY reaches WordPress: a real fetch() with method QUERY to the echo
 *    route, signed in (cookie and X-WP-Nonce), carrying a random marker in
 *    the body.
 * 2. No cache is storing QUERY responses: two different QUERY bodies to the
 *    public fingerprint route, same URL, signed out (credentials: 'omit'), to
 *    see whether a cache on the way answers the second with the first's
 *    response.
 *
 * From your server (a loopback, run in PHP): the same two checks, plus
 * whether CORS lets pages on other sites send QUERY: the preflight a browser
 * on another origin sends first, OPTIONS with Origin and
 * Access-Control-Request-Method: QUERY. Server only: this page's own requests
 * are same-origin, so the browser never preflights them.
 *
 * The two "reaches WordPress" checks and the CORS check run at the same
 * time; the two cache checks run after them, one after the other, because
 * their probe pairs go to the same URL and must not interleave. Results always render in the same
 * order.
 *
 * What a response means is decided in PHP, once (diagnose_echo_response(),
 * diagnose_cache_probe(), diagnose_cors_preflight()): this file only
 * reports what the browser saw to the diagnose route, including a network failure ({ status: 0, error }), an
 * opaque redirect ({ redirect: true }) and a browser that won't build a QUERY
 * request ({ unsupported: true }). The one verdict made here is test_failed,
 * when the diagnose or server route itself can't be reached.
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

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var n = 0;
		return template.replace( /%(?:(\d)\$)?s/g, function ( match, position ) {
			return String( args[ position ? position - 1 : n++ ] );
		} );
	}

	/** The path and query of a URL, as shown in a result. */
	function path( url ) {
		var parsed = new URL( url, window.location.href );
		return parsed.pathname + parsed.search;
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

	/**
	 * Sends a request and returns what the browser saw, for the diagnose
	 * route: status, headers (lower-cased names) and body, or what stopped it.
	 */
	async function observe( request ) {
		if ( ! request ) {
			return { unsupported: true, client: 'browser' };
		}
		var res;
		try {
			res = await fetch( request );
		} catch ( e ) {
			return { status: 0, error: e.message, client: 'browser' };
		}
		if ( 'opaqueredirect' === res.type ) {
			return { redirect: true, client: 'browser' };
		}
		var headers = {};
		res.headers.forEach( function ( value, name ) {
			headers[ name.toLowerCase() ] = value;
		} );
		return { status: res.status, headers: headers, body: await res.text(), client: 'browser' };
	}

	async function browserEcho() {
		var value = marker();
		var body = {};
		body[ config.markerKey ] = value;

		var seen = await observe(
			queryRequest( config.echoUrl, {
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body: JSON.stringify( body ),
			} )
		);
		var result = await diagnose( { check: 'echo', marker: value, response: seen } );
		result.url = config.echoUrl;
		result.signed_in = true;
		return result;
	}

	async function browserCache() {
		var responses = [];

		for ( var n = 0; n < 2; n++ ) {
			var body = JSON.stringify( { probe: marker() } );
			// Signed out, and no cache-busting request headers: caches usually
			// skip requests with cookies, so a signed-in probe would always
			// look clean.
			var seen = await observe(
				queryRequest( config.fingerprintUrl, {
					credentials: 'omit',
					headers: { 'Content-Type': 'application/json' },
					body: body,
				} )
			);
			seen.sent = body;
			responses.push( seen );
			if ( ! seen.status ) {
				break; // No response to compare; the diagnosis says why.
			}
		}

		var result = await diagnose( { check: 'cache', first: responses[ 0 ], second: responses[ 1 ] || {} } );
		result.url = config.fingerprintUrl;
		result.signed_in = false;
		return result;
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

	/** Runs a check; if the test itself breaks, says so instead. */
	async function attempt( check ) {
		try {
			return await check.run();
		} catch ( e ) {
			return {
				verdict: 'test_failed',
				status: config.statuses.test_failed,
				http_statuses: [],
				message: format( i18n.testFailed, e.message ),
			};
		}
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

	function statusLabel( result ) {
		return i18n.status[ result.status ] || result.status;
	}

	function render( check, result ) {
		var box = el( 'div', 'notice inline ' + ( NOTICE[ result.status ] || 'notice-info' ) );
		box.setAttribute( 'data-check', check.id );
		box.setAttribute( 'data-verdict', result.verdict );

		box.appendChild( el( 'h4', '', format( i18n.titleStatus, check.title, statusLabel( result ) ) ) );
		box.appendChild( el( 'p', 'description', check.description ) );

		var sent = el( 'p', '' );
		var url = result.url || check.url;
		var requested = check.sent;
		if ( 'string' !== typeof requested ) {
			requested = result.signed_in ? requested.signedIn : requested.anonymous;
		}
		sent.appendChild( el( 'code', '', format( requested, path( url ), config.corsOrigin ) ) );
		if ( result.http_statuses && result.http_statuses.length ) {
			sent.appendChild( document.createTextNode( ' ' + format( i18n.httpStatus, result.http_statuses.join( ', ' ) ) ) );
		}
		box.appendChild( sent );
		box.appendChild( el( 'p', '', result.message ) );

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

	var GROUPS = [
		{
			id: 'browser',
			title: i18n.groups.browser.title,
			description: i18n.groups.browser.description,
			checks: [
				{ id: 'browser-echo', key: 'browserEcho', check: 'echo', url: config.echoUrl, sent: i18n.sent.browserEcho, run: browserEcho },
				{ id: 'browser-cache', key: 'browserCache', check: 'cache', url: config.fingerprintUrl, sent: i18n.sent.browserCache, run: browserCache },
			],
		},
		{
			id: 'server',
			title: i18n.groups.server.title,
			description: i18n.groups.server.description,
			checks: [
				{
					id: 'server-echo',
					key: 'serverEcho',
					check: 'echo',
					url: config.echoUrl,
					sent: i18n.sent.serverEcho,
					run: function () {
						return serverCheck( config.serverUrl );
					},
				},
				{
					id: 'server-cache',
					key: 'serverCache',
					check: 'cache',
					url: config.fingerprintUrl,
					sent: i18n.sent.serverCache,
					run: function () {
						return serverCheck( config.cacheUrl );
					},
				},
				{
					id: 'server-cors',
					key: 'serverCors',
					check: 'cors',
					url: config.fingerprintUrl,
					sent: i18n.sent.serverCors,
					run: function () {
						return serverCheck( config.corsUrl );
					},
				},
			],
		},
	];

	GROUPS.forEach( function ( group ) {
		group.checks.forEach( function ( check ) {
			check.title = i18n.checks[ check.key ];
			check.description = i18n.descriptions[ check.check ];
		} );
	} );

	var browserEchoCheck = GROUPS[ 0 ].checks[ 0 ];
	var browserCacheCheck = GROUPS[ 0 ].checks[ 1 ];
	var serverEchoCheck = GROUPS[ 1 ].checks[ 0 ];
	var serverCacheCheck = GROUPS[ 1 ].checks[ 1 ];
	var serverCorsCheck = GROUPS[ 1 ].checks[ 2 ];

	function summary( checks ) {
		var problems = checks.filter( function ( check ) {
			return 'good' !== check.result.status;
		} );
		var wrap = el( 'div', 'wp-http-query-self-test-summary' );
		var line = el( 'p' );
		line.appendChild( el( 'strong', '', problems.length ? i18n.summary.problems[ problems.length ] : i18n.summary.passed ) );
		wrap.appendChild( line );
		if ( problems.length ) {
			var list = el( 'ul', 'ul-disc' );
			problems.forEach( function ( check ) {
				list.appendChild(
					el( 'li', '', format( i18n.titleStatus, check.title, statusLabel( check.result ) ) )
				);
			} );
			wrap.appendChild( list );
		}
		return wrap;
	}

	button.addEventListener( 'click', async function () {
		button.disabled = true;
		output.textContent = '';
		output.appendChild( el( 'p', '', i18n.running ) );

		var first = await Promise.all( [ attempt( browserEchoCheck ), attempt( serverEchoCheck ), attempt( serverCorsCheck ) ] );
		browserEchoCheck.result = first[ 0 ];
		serverEchoCheck.result = first[ 1 ];
		serverCorsCheck.result = first[ 2 ];
		browserCacheCheck.result = await attempt( browserCacheCheck );
		serverCacheCheck.result = await attempt( serverCacheCheck );

		output.textContent = '';
		output.appendChild( summary( [ browserEchoCheck, browserCacheCheck, serverEchoCheck, serverCacheCheck, serverCorsCheck ] ) );
		GROUPS.forEach( function ( group ) {
			var section = el( 'div', 'wp-http-query-self-test-group' );
			section.setAttribute( 'data-group', group.id );
			section.appendChild( el( 'h3', '', group.title ) );
			section.appendChild( el( 'p', '', group.description ) );
			group.checks.forEach( function ( check ) {
				section.appendChild( render( check, check.result ) );
			} );
			output.appendChild( section );
		} );
		output.appendChild( el( 'p', 'description', i18n.limits ) );
		button.disabled = false;
	} );
} )();
