/**
 * CORS: the plugin respects a site that turned REST CORS off, or another CORS
 * handler.
 *
 * The plugin only adds QUERY through core's rest_allowed_cors_methods filter.
 * This checks the site's own CORS setup still decides: core's handler removed
 * means no CORS at all, and a CORS callback that runs after core's, as a CORS
 * plugin's might, still wins.
 *
 * Each scenario also runs the self-test's CORS check (the loopback behind
 * Site Health's wp_http_query_cors test), which must report what the
 * preflight shows: QUERY allowed, REST CORS off, or a list without QUERY.
 *
 * Runs in the serial lifecycle suite because it changes site state: the test
 * fixture's CORS option, set over WP-CLI and put back in afterAll. Every
 * request sends Connection: close (see session.ts).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, CORE_CORS_METHODS, ORIGIN, rest, tokens } from '../http';
import { wp } from '../seed';
import { NO_LOCAL_CLI, type Site, sites } from '../sites';
import { FRESH, Session } from './session';
import { FIXTURE_CORS, setOption, snapshotOption } from './site-state';

const ROUTE = CORE_COLLECTIONS[ 0 ];

/** The self-test's CORS check, which Site Health also calls. */
const CORS_CHECK = '/wp-http-query/v1/self-test/cors';

/** Sets the fixture's CORS scenario for the length of fn, then deletes it in one WP-CLI call. */
async function withCorsScenario< T >( site: Site, value: Record< string, unknown >, fn: () => Promise< T > ): Promise< T > {
	setOption( site, FIXTURE_CORS, value );
	try {
		return await fn();
	} finally {
		wp( site, [ 'option', 'delete', FIXTURE_CORS ] );
	}
}

/**
 * The Access-Control-Allow-* headers CORS handlers send. Not Allow-Headers:
 * WP_REST_Server::serve_request() sends it on every REST response, CORS on or off.
 */
function allowHeaders( res: Response ): string[] {
	return [ ...res.headers.keys() ].filter(
		( name ) => name.startsWith( 'access-control-allow-' ) && name !== 'access-control-allow-headers'
	);
}

describe.each( sites )( 'CORS, as the site configured it: $label', ( site ) => {
	if ( ! site.localCli ) {
		it.skip( NO_LOCAL_CLI, () => {} );
		return;
	}

	const admin = new Session( site );
	let nonce = '';
	let restore = () => {};
	beforeAll( async () => {
		restore = snapshotOption( site, FIXTURE_CORS );
		await admin.logIn();
		nonce = await admin.restNonce();
	} );
	afterAll( () => restore() );

	/** The CORS check's verdict, status and message, as the self-test and Site Health get them. */
	const corsCheck = async (): Promise< { verdict: string; status: string; message: string } > =>
		( await ( await admin.rest( 'GET', CORS_CHECK, { nonce } ) ).json() ).check;

	/** Under a CORS scenario: what fn gets, and what the CORS check reports. */
	const withCheck = < T >( scenario: Record< string, unknown >, fn: () => Promise< T > ) =>
		withCorsScenario( site, scenario, () => Promise.all( [ fn(), corsCheck() ] ) );

	const preflight = () =>
		rest( site, 'OPTIONS', ROUTE, { headers: { ...FRESH, origin: ORIGIN, 'access-control-request-method': 'QUERY' } } );
	const get = () => rest( site, 'GET', ROUTE, { headers: { ...FRESH, origin: ORIGIN } } );
	const bothRequests = () => Promise.all( [ preflight(), get() ] );

	it( 'adds QUERY when core’s handler is where core put it', async () => {
		const res = await preflight();
		expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( ORIGIN );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toContain( 'QUERY' );
		expect( await corsCheck() ).toMatchObject( { verdict: 'cors_query', status: 'good' } );
	} );

	it( 'leaves REST CORS off when the site removed core’s handler', async () => {
		const [ responses, check ] = await withCheck( { scenario: 'remove-late' }, bothRequests );
		for ( const res of responses ) {
			expect( allowHeaders( res ) ).toEqual( [] );
		}
		// Explained, not flagged: the site's choice, which the plugin respects.
		expect( check ).toMatchObject( { verdict: 'cors_off', status: 'recommended' } );
		expect( check.message ).toContain( 'the plugin respects that' );
	} );

	it( 'lets a CORS callback that runs after core’s win', async () => {
		const competing = { scenario: 'competing', origin: 'https://allowed.example', methods: 'GET, OPTIONS' };
		const [ responses, check ] = await withCheck( competing, bothRequests );
		for ( const res of responses ) {
			expect( res.headers.get( 'access-control-allow-origin' ) ).toBe( competing.origin );
			expect( res.headers.get( 'access-control-allow-methods' ) ).toBe( competing.methods );
			expect( res.headers.get( 'access-control-allow-credentials' ) ).toBeNull();
		}

		// That callback's list has no QUERY, so the CORS check flags it and
		// names a plugin sending its own list among the likely causes.
		expect( check ).toMatchObject( { verdict: 'cors_no_query', status: 'critical' } );
		expect( check.message ).toContain( `(${ competing.methods }) leaves out QUERY` );
		expect( check.message ).toContain( 'another plugin or a server rule that sends its own' );
	} );

	it( 'flags a rest_allowed_cors_methods callback that takes QUERY out', async () => {
		const [ res, check ] = await withCheck( { scenario: 'filter-removes-query' }, preflight );
		expect( tokens( res.headers.get( 'access-control-allow-methods' ) ) ).toEqual( CORE_CORS_METHODS );
		expect( check ).toMatchObject( { verdict: 'cors_no_query', status: 'critical' } );
		expect( check.message ).toContain( 'rest_allowed_cors_methods callback' );
	} );
} );
