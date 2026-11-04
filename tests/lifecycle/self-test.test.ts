/**
 * The self-test: Settings → HTTP QUERY's "Test your server" button, its REST
 * routes, the two Site Health tests, and the pure functions that turn a
 * response into a verdict.
 *
 * Over real HTTP as an admin where a request is involved; through `wp eval`
 * for the pure functions, fed fabricated responses for every failure mode the
 * matrix and cache survey document. The loopback checks themselves can't run
 * from WP-CLI: the CLI container can't reach the site's own URL. They run
 * through the REST routes, the same ones Site Health calls for async tests.
 *
 * The echo route is put back to its default, admins only, so the server
 * check has to carry the admin's sign-in through the loopback. Restored in
 * afterAll. Every request sends Connection: close (see session.ts).
 */
import { createHash } from 'node:crypto';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { rest } from '../http';
import { wp } from '../seed';
import { NO_LOCAL_CLI, sites } from '../sites';
import { FRESH, Session } from './session';
import { FIXTURE_DEMO_ROUTE_OFF, setOption, snapshotOption } from './site-state';

const PAGE = '/wp-admin/options-general.php?page=wp-http-query';
const SERVER = '/wp-http-query/v1/self-test/server';
const CACHE = '/wp-http-query/v1/self-test/cache';
const DIAGNOSE = '/wp-http-query/v1/self-test/diagnose';
const FINGERPRINT = '/wp-http-query/v1/self-test/fingerprint';

interface Check {
	verdict: string;
	status: string;
	http_status: number;
	http_statuses?: number[];
	message: string;
	url?: string;
	cache_headers?: { response?: number; header: string; value: string; hint: string }[];
}

interface HealthResult {
	label: string;
	status: string;
	badge: { label: string; color: string };
	description: string;
	actions: string;
	test: string;
	check: Check;
}

const sha256 = ( s: string ) => createHash( 'sha256' ).update( s ).digest( 'hex' );

/**
 * Runs PHP through `wp eval` and parses the JSON it prints. The cases go in as
 * JSON too, so nothing needs escaping into PHP source.
 */
function evalJson< T >( site: Parameters< typeof wp >[ 0 ], php: string, input: unknown ): T {
	const encoded = Buffer.from( JSON.stringify( input ) ).toString( 'base64' );
	const out = wp( site, [ 'eval', `$input = json_decode( base64_decode( '${ encoded }' ), true ); ${ php }` ] );
	return JSON.parse( out.slice( out.indexOf( '{' ), out.lastIndexOf( '}' ) + 1 ) );
}

const MARKER = 'fabricated-marker';
const ECHO_OK = JSON.stringify( { method: 'QUERY', body_bytes: 40, merged_params: { wp_http_query_self_test: MARKER } } );
const AQ = { 'Accept-Query': '"application/json"' };

/** Fabricated echo responses: [ name, status, headers, body, authenticated, error ] => expected verdict. */
const ECHO_CASES: Record< string, { args: [ number, Record< string, string >, string, boolean?, string? ]; verdict: string; status: string; says?: string } > = {
	pass: { args: [ 200, AQ, ECHO_OK ], verdict: 'pass', status: 'good' },
	'405 nginx root': { args: [ 405, { Server: 'nginx' }, '<html>405 Not Allowed</html>' ], verdict: 'not_allowed', status: 'critical', says: '/index.php' },
	'403 hardening rule': { args: [ 403, {}, '<html>Forbidden</html>' ], verdict: 'forbidden', status: 'critical', says: 'limit_except GET POST' },
	'501 Varnish': { args: [ 501, { 'X-Varnish': '1234' }, '' ], verdict: 'not_implemented', status: 'critical', says: 'This response has Varnish headers.' },
	'501 php -S': { args: [ 501, {}, '' ], verdict: 'not_implemented', status: 'critical', says: 'php -S' },
	'302 redirect': { args: [ 302, { Location: 'https://example.com/wp-json/' }, '' ], verdict: 'redirect', status: 'critical', says: 'https://example.com/wp-json/' },
	'301 redirect': { args: [ 301, { Location: 'https://example.com/' }, '' ], verdict: 'redirect', status: 'critical' },
	'method arrives as GET': { args: [ 200, AQ, JSON.stringify( { method: 'GET', body_bytes: 0, merged_params: {} } ) ], verdict: 'method_changed', status: 'critical', says: 'as GET' },
	'method changed, no route': { args: [ 404, {}, JSON.stringify( { code: 'rest_no_route', message: 'No route' } ) ], verdict: 'method_changed', status: 'critical' },
	'body missing': { args: [ 200, AQ, JSON.stringify( { method: 'QUERY', body_bytes: 0, merged_params: {} } ) ], verdict: 'body_lost', status: 'critical', says: 'empty body' },
	'marker lost': { args: [ 200, AQ, JSON.stringify( { method: 'QUERY', body_bytes: 12, merged_params: { other: 1 } } ) ], verdict: 'body_lost', status: 'critical', says: 'arrived changed' },
	'Accept-Query stripped': { args: [ 200, {}, ECHO_OK ], verdict: 'no_accept_query', status: 'recommended' },
	'network error': { args: [ 0, {}, '', true, 'cURL error 7: Failed to connect' ], verdict: 'network_error', status: 'recommended', says: 'cURL error 7' },
	'signed-out 401 from the route': { args: [ 401, {}, JSON.stringify( { code: 'rest_forbidden', message: 'Sorry' } ), false ], verdict: 'reached', status: 'good' },
	'signed-in 403 bad nonce': { args: [ 403, {}, JSON.stringify( { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' } ) ], verdict: 'wordpress_error', status: 'recommended', says: 'rest_cookie_invalid_nonce' },
	'500 from WordPress': { args: [ 500, {}, JSON.stringify( { code: 'internal_server_error', message: 'Critical error' } ) ], verdict: 'wordpress_error', status: 'recommended' },
	'200 from something else': { args: [ 200, {}, '<html>cached page</html>' ], verdict: 'unexpected', status: 'recommended' },
	'502 bad gateway': { args: [ 502, {}, '<html>Bad Gateway</html>' ], verdict: 'unexpected', status: 'recommended' },
};

const fingerprint = ( sent: string, fresh: string, headers: Record< string, string > = {}, status = 200 ) => ( {
	status,
	headers,
	sent,
	body: JSON.stringify( { body_sha256: sha256( sent ), fresh } ),
} );
const A = '{"probe":"a"}';
const B = '{"probe":"b"}';

/** Fabricated probe pairs => expected verdict. */
const CACHE_CASES: Record< string, { first: object; second: object; verdict: string; status: string; says?: string } > = {
	'distinct answers': { first: fingerprint( A, 'f1' ), second: fingerprint( B, 'f2' ), verdict: 'clean', status: 'good' },
	'second gets the first hash': {
		first: fingerprint( A, 'f1' ),
		second: { ...fingerprint( A, 'f2' ), sent: B },
		verdict: 'stored',
		status: 'critical',
		says: 'without keying on the body',
	},
	'fresh value repeats (Varnish)': {
		first: fingerprint( A, 'f1', { 'X-Varnish': '1' } ),
		second: { ...fingerprint( A, 'f1', { 'X-Varnish': '2 1', Age: '3' } ), sent: B },
		verdict: 'stored',
		status: 'critical',
		says: 'point to Varnish',
	},
	'stored, LiteSpeed headers': {
		first: fingerprint( A, 'f1', { 'X-LiteSpeed-Cache': 'miss' } ),
		second: { ...fingerprint( A, 'f1', { 'X-LiteSpeed-Cache': 'hit' } ), sent: B },
		verdict: 'stored',
		status: 'critical',
		says: 'hasn’t tested LiteSpeed',
	},
	'probe refused': { first: fingerprint( A, 'f1', {}, 501 ), second: fingerprint( B, 'f2' ), verdict: 'inconclusive', status: 'recommended' },
	'network error': {
		first: { status: 0, headers: {}, body: '', sent: A, error: 'timed out' },
		second: fingerprint( B, 'f2' ),
		verdict: 'inconclusive',
		status: 'recommended',
		says: 'timed out',
	},
	'body altered': { first: { ...fingerprint( 'x', 'f1' ), sent: A }, second: fingerprint( B, 'f2' ), verdict: 'altered', status: 'recommended' },
};

/** Fabricated headers => hints expected among cache_header_hints(). */
const HINT_CASES: Record< string, { headers: Record< string, string >; hints: Record< string, string > } > = {
	LiteSpeed: { headers: { 'X-LiteSpeed-Cache': 'hit', Server: 'LiteSpeed' }, hints: { 'x-litespeed-cache': 'LiteSpeed', server: 'LiteSpeed' } },
	Varnish: { headers: { 'X-Varnish': '32770 3', Via: '1.1 varnish (Varnish/7.5)' }, hints: { 'x-varnish': 'Varnish', via: 'Varnish' } },
	Cloudflare: { headers: { 'CF-Cache-Status': 'DYNAMIC', Server: 'cloudflare' }, hints: { 'cf-cache-status': 'Cloudflare', server: 'Cloudflare' } },
	'generic Age': { headers: { Age: '12' }, hints: { age: 'set by a cache: seconds it has held this response' } },
	'nginx FastCGI cache': { headers: { 'X-FastCGI-Cache': 'HIT' }, hints: { 'x-fastcgi-cache': 'an nginx proxy or FastCGI cache, as many hosts configure it' } },
	Kinsta: { headers: { 'X-Kinsta-Cache': 'HIT' }, hints: { 'x-kinsta-cache': 'Kinsta' } },
	'Cache-Control shown, no vendor': { headers: { 'Cache-Control': 'max-age=60' }, hints: { 'cache-control': '' } },
	'unrelated headers': { headers: { Server: 'Apache/2.4', 'Content-Type': 'application/json' }, hints: {} },
};

describe.each( sites )( 'self-test: $label', ( site ) => {
	if ( ! site.localCli ) {
		it.skip( NO_LOCAL_CLI, () => {} );
		return;
	}

	const admin = new Session( site );
	let restoreDemoSwitch = () => {};

	beforeAll( async () => {
		restoreDemoSwitch = snapshotOption( site, FIXTURE_DEMO_ROUTE_OFF );
		setOption( site, FIXTURE_DEMO_ROUTE_OFF, 1 ); // The echo route at its default: admins only.
		await admin.logIn();
	} );

	afterAll( () => {
		restoreDemoSwitch();
	} );

	describe( 'the settings page', () => {
		it( 'has the button and loads the script with the URLs and a REST nonce', async () => {
			const page = await admin.html( PAGE );
			expect( page ).toContain( '<button type="button" class="button" id="wp-http-query-self-test">Test your server</button>' );
			expect( page ).toContain( 'id="wp-http-query-self-test-results"' );
			const tag = page.match( /<script[^>]*id="wp-http-query-self-test-js"[^>]*>/ )?.[ 0 ] ?? '';
			expect( tag ).toMatch( / src="[^"]*\/wp-content\/plugins\/wp-http-query\/assets\/self-test\.js\?ver=\d+"/ );

			const data = JSON.parse( page.match( /var wpHttpQuerySelfTest = (\{.*?\});\n/ )?.[ 1 ] ?? 'null' );
			expect( data.echoUrl ).toBe( `${ site.url }/wp-json/wp-http-query/v1/echo` );
			expect( data.fingerprintUrl ).toBe( `${ site.url }/wp-json${ FINGERPRINT }` );
			expect( data.serverUrl ).toBe( `${ site.url }/wp-json${ SERVER }` );
			expect( data.cacheUrl ).toBe( `${ site.url }/wp-json${ CACHE }` );
			expect( data.diagnoseUrl ).toBe( `${ site.url }/wp-json${ DIAGNOSE }` );
			expect( data.markerKey ).toBe( 'wp_http_query_self_test' );
			expect( data.i18n.browserUnsupported ).toContain( 'browser can’t send QUERY' );

			// The nonce is a working wp_rest nonce for this session.
			expect( data.nonce ).toMatch( /^[0-9a-f]{10}$/ );
			expect( ( await admin.rest( 'GET', SERVER, { nonce: data.nonce } ) ).status ).toBe( 200 );
		} );

		it( 'loads the script on that page only', async () => {
			expect( await admin.html( '/wp-admin/options-general.php' ) ).not.toContain( 'self-test.js' );
		} );

		it( 'lists the fingerprint route as the public self-test probe', async () => {
			const page = await admin.html( PAGE );
			expect( page ).toContain(
				`<tr><td><code>${ FINGERPRINT }</code></td><td>Self-test cache probe: public, answers only with a hash of the body</td></tr>`
			);
		} );
	} );

	describe( 'the server check (loopback)', () => {
		it( 'passes for an admin, carrying the sign-in to the admins-only echo route', async () => {
			const res = await admin.rest( 'GET', SERVER );
			expect( res.status ).toBe( 200 );
			const result: HealthResult = await res.json();
			expect( result.check ).toMatchObject( {
				verdict: 'pass',
				status: 'good',
				http_status: 200,
				url: `${ site.url }/wp-json/wp-http-query/v1/echo`,
			} );
			expect( result.check.message ).toBe( 'QUERY reached WordPress with its method and body intact, and the Accept-Query header came back.' );
		} );

		it( 'refuses a signed-out caller', async () => {
			const res = await rest( site, 'GET', SERVER, { headers: FRESH } );
			expect( res.status ).toBe( 401 );
		} );

		it( 'refuses a bad nonce', async () => {
			const res = await admin.rest( 'GET', SERVER, { nonce: '0123456789' } );
			expect( res.status ).toBe( 403 );
			expect( ( await res.json() ).code ).toBe( 'rest_cookie_invalid_nonce' );
		} );
	} );

	describe( 'the cache check (loopback)', () => {
		it( 'reports no caching on the test site, which has no cache in front', async () => {
			const res = await admin.rest( 'GET', CACHE );
			expect( res.status ).toBe( 200 );
			const result: HealthResult = await res.json();
			expect( result.check ).toMatchObject( { verdict: 'clean', status: 'good', http_statuses: [ 200, 200 ] } );
			expect( result.check.message ).toContain( 'No caching detected' );
		} );

		it( 'refuses a signed-out caller and a bad nonce', async () => {
			expect( ( await rest( site, 'GET', CACHE, { headers: FRESH } ) ).status ).toBe( 401 );
			expect( ( await admin.rest( 'GET', CACHE, { nonce: '0123456789' } ) ).status ).toBe( 403 );
		} );
	} );

	describe( 'the fingerprint route', () => {
		it( 'answers signed-out QUERYs with a hash of each body and a fresh value, echoing nothing', async () => {
			const a = JSON.stringify( { probe: 'wphq-secret-alpha' } );
			const b = JSON.stringify( { probe: 'wphq-secret-beta' } );
			const send = ( body: string ) =>
				fetch( `${ site.url }/wp-json${ FINGERPRINT }`, {
					method: 'QUERY',
					headers: { ...FRESH, 'content-type': 'application/json' },
					body,
				} );

			const [ ra, rb ] = [ await send( a ), await send( b ) ];
			expect( ra.status ).toBe( 200 );
			expect( rb.status ).toBe( 200 );
			// The plugin sends no Cache-Control of its own (ADR 0003).
			expect( ra.headers.get( 'cache-control' ) ).toBeNull();

			const [ ta, tb ] = [ await ra.text(), await rb.text() ];
			expect( ta ).not.toContain( 'wphq-secret-alpha' );
			expect( tb ).not.toContain( 'wphq-secret-beta' );

			const [ ja, jb ] = [ JSON.parse( ta ), JSON.parse( tb ) ];
			expect( Object.keys( ja ).sort() ).toEqual( [ 'body_sha256', 'fresh' ] );
			expect( ja.body_sha256 ).toBe( sha256( a ) );
			expect( jb.body_sha256 ).toBe( sha256( b ) );
			expect( ja.body_sha256 ).not.toBe( jb.body_sha256 );
			expect( ja.fresh ).not.toBe( jb.fresh );
		} );

		it( 'answers QUERY only', async () => {
			expect( ( await rest( site, 'GET', FINGERPRINT, { headers: FRESH } ) ).status ).toBe( 404 );
		} );
	} );

	describe( 'Site Health', () => {
		it( 'registers both tests as async REST tests', () => {
			const tests = evalJson< Record< string, { label: string; test: string; has_rest: boolean; direct: boolean } > >(
				site,
				`require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
				$out = array();
				foreach ( WP_Site_Health::get_tests()['async'] as $key => $t ) {
					if ( str_starts_with( $key, 'wp_http_query_' ) ) {
						$out[ $key ] = array( 'label' => $t['label'], 'test' => $t['test'], 'has_rest' => $t['has_rest'], 'direct' => is_callable( $t['async_direct_test'] ) );
					}
				}
				echo wp_json_encode( $out );`,
				null
			);
			expect( tests ).toEqual( {
				'wp_http_query_reaches_wordpress': {
					label: 'HTTP QUERY requests reach WordPress',
					test: `${ site.url }/wp-json${ SERVER }`,
					'has_rest': true,
					direct: true,
				},
				'wp_http_query_cache': {
					label: 'No cache stores HTTP QUERY responses',
					test: `${ site.url }/wp-json${ CACHE }`,
					'has_rest': true,
					direct: true,
				},
			} );
		} );

		it( 'returns good from the endpoints Site Health calls, in its result shape', async () => {
			for ( const [ route, key, label ] of [
				[ SERVER, 'wp_http_query_reaches_wordpress', 'HTTP QUERY requests reach WordPress' ],
				[ CACHE, 'wp_http_query_cache', 'No cache was seen storing HTTP QUERY responses' ],
			] ) {
				const result: HealthResult = await ( await admin.rest( 'GET', `${ route }?_locale=user` ) ).json();
				expect( result ).toMatchObject( { label, status: 'good', test: key, badge: { label: 'HTTP QUERY', color: 'blue' } } );
				expect( result.description ).toContain( 'HTTP status: 200' );
				expect( result.actions ).toContain( 'options-general.php?page=wp-http-query' );
			}
		} );
	} );

	describe( 'the diagnose route (browser results)', () => {
		it( 'classifies what the browser saw', async () => {
			const res = await admin.rest( 'POST', DIAGNOSE, {
				json: { check: 'echo', marker: MARKER, response: { status: 405, headers: { server: 'nginx' }, body: '' } },
			} );
			expect( res.status ).toBe( 200 );
			expect( await res.json() ).toMatchObject( { verdict: 'not_allowed', status: 'critical', http_status: 405 } );

			const cache = await admin.rest( 'POST', DIAGNOSE, {
				json: { check: 'cache', first: fingerprint( A, 'f1' ), second: fingerprint( B, 'f2' ) },
			} );
			expect( await cache.json() ).toMatchObject( { verdict: 'clean', status: 'good' } );
		} );

		it( 'refuses a signed-out caller and a bad nonce', async () => {
			const json = { check: 'echo', marker: MARKER, response: { status: 200 } };
			expect( ( await rest( site, 'POST', DIAGNOSE, { json, headers: FRESH } ) ).status ).toBe( 401 );
			expect( ( await admin.rest( 'POST', DIAGNOSE, { json, nonce: '0123456789' } ) ).status ).toBe( 403 );
		} );
	} );

	describe( 'classification (pure functions, fabricated responses)', () => {
		it( 'maps each echo outcome to a verdict and message', () => {
			const out = evalJson< Record< string, Check > >(
				site,
				`$out = array();
				foreach ( $input['cases'] as $name => $c ) {
					list( $status, $headers, $body ) = $c;
					$out[ $name ] = \\WPHttpQuery\\diagnose_echo_response( $status, (array) $headers, $body, $input['marker'], $c[3] ?? true, $c[4] ?? '' );
				}
				echo wp_json_encode( $out );`,
				{ marker: MARKER, cases: Object.fromEntries( Object.entries( ECHO_CASES ).map( ( [ k, v ] ) => [ k, v.args ] ) ) }
			);

			for ( const [ name, c ] of Object.entries( ECHO_CASES ) ) {
				expect( out[ name ], name ).toMatchObject( { verdict: c.verdict, status: c.status, http_status: c.args[ 0 ] } );
				expect( out[ name ].message, name ).toMatch( /\S/ );
				if ( c.says ) {
					expect( out[ name ].message, name ).toContain( c.says );
				}
			}
		} );

		it( 'flags a repeated hash or fresh value as a cache storing QUERY, and passes distinct answers', () => {
			const out = evalJson< Record< string, Check > >(
				site,
				`$out = array();
				foreach ( $input as $name => $c ) {
					$out[ $name ] = \\WPHttpQuery\\diagnose_cache_probe( $c['first'], $c['second'] );
				}
				echo wp_json_encode( $out );`,
				Object.fromEntries( Object.entries( CACHE_CASES ).map( ( [ k, v ] ) => [ k, { first: v.first, second: v.second } ] ) )
			);

			for ( const [ name, c ] of Object.entries( CACHE_CASES ) ) {
				expect( out[ name ], name ).toMatchObject( { verdict: c.verdict, status: c.status } );
				if ( c.says ) {
					expect( out[ name ].message, name ).toContain( c.says );
				}
			}
			// The headers seen are reported per response.
			expect( out[ 'fresh value repeats (Varnish)' ].cache_headers ).toEqual( [
				{ response: 1, header: 'x-varnish', value: '1', hint: 'Varnish' },
				{ response: 2, header: 'x-varnish', value: '2 1', hint: 'Varnish' },
				{ response: 2, header: 'age', value: '3', hint: 'set by a cache: seconds it has held this response' },
			] );
		} );

		it( 'hints at the cache layer from response headers', () => {
			const out = evalJson< Record< string, { header: string; hint: string }[] > >(
				site,
				`$out = array();
				foreach ( $input as $name => $headers ) {
					$out[ $name ] = \\WPHttpQuery\\cache_header_hints( (array) $headers );
				}
				echo wp_json_encode( $out, JSON_FORCE_OBJECT );`,
				Object.fromEntries( Object.entries( HINT_CASES ).map( ( [ k, v ] ) => [ k, v.headers ] ) )
			);

			for ( const [ name, c ] of Object.entries( HINT_CASES ) ) {
				const hints = Object.fromEntries( Object.values( out[ name ] ).map( ( h ) => [ h.header, h.hint ] ) );
				expect( hints, name ).toEqual( c.hints );
			}
		} );
	} );
} );
