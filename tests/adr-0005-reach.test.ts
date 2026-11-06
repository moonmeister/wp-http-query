/**
 * Reach: which routes accept QUERY. ADR 0005, issue #1.
 *
 * Decided 2026-11-04 for the plugin: reach is opt-in, NEVER every route by
 * default. QUERY reaches a route only when:
 *
 * - the route registers it, with 'QUERY' in methods,
 * - a site lists the route key in the wp_http_query_routes filter, or
 * - a site enables a preset that lists it (wp_http_query_presets).
 *
 * Only handlers that accept GET gain QUERY. Write handlers on an opted-in
 * route never do.
 *
 * The opt-ins live in tests/fixtures/mu-plugins/, mounted on every test site.
 * The preset's other switch, the stored setting, mutates site state, so it is
 * tested serially in tests/lifecycle/adr-0005-preset-setting.test.ts.
 *
 * Why it matters: #38 found plugins (Jetpack, 3M installs) whose GET-route
 * callbacks treat "not GET" as "write", so blanket reach turns a QUERY into a write.
 *
 * Decision: docs/decisions/0005-reach-for-existing-read-routes.md   Issue: #1
 */
import { beforeAll, describe, expect, it } from 'vitest';
import { CORE_COLLECTIONS, ECHO, FIXTURE_OPTED_IN_ROUTE, rest, tokens } from './http';
import { SEED_POSTS, seededPostId } from './seed';
import { describeLocalSites, type Site, sites } from './sites';

/** A core read route nobody has opted in. */
const CORE_READ_ROUTE = '/wp/v2/types';

/** Methods per handler on a route, from its OPTIONS description. */
async function handlerMethods( site: Site, route: string ): Promise< string[][] > {
	const res = await rest( site, 'OPTIONS', route );
	expect( res.status ).toBe( 200 );
	const body: { endpoints: { methods: string[] }[] } = await res.json();
	return body.endpoints.map( ( e ) => e.methods );
}

describe.each( sites )( 'ADR 0005 reach: $label', ( site ) => {
	describe( 'default off', () => {
		it( 'dispatches QUERY on a route that registered it', async () => {
			const res = await rest( site, 'QUERY', ECHO, { json: {} } );
			expect( res.status ).toBe( 200 );
			expect( tokens( res.headers.get( 'allow' ) ) ).toContain( 'QUERY' );
		} );

		it( 'does not add QUERY to an existing GET route by default', async () => {
			const res = await rest( site, 'QUERY', CORE_READ_ROUTE );
			expect( res.status ).toBe( 404 );
		} );

		it( "leaves an existing GET route's Allow header unchanged by default", async () => {
			const res = await rest( site, 'GET', CORE_READ_ROUTE );
			expect( res.status ).toBe( 200 );
			expect( tokens( res.headers.get( 'allow' ) ) ).not.toContain( 'QUERY' );
		} );

		it( 'never reaches the write branch of a GET route that branches on the method', async () => {
			const get = await rest( site, 'GET', '/wp-http-query-test/v1/branches-on-method' );
			expect( await get.json() ).toEqual( { branch: 'read' } );

			const query = await rest( site, 'QUERY', '/wp-http-query-test/v1/branches-on-method', { json: {} } );
			expect( query.status ).toBe( 404 );
		} );
	} );

	describe( 'opt-in', () => {
		it( 'adds QUERY to a route a site listed in wp_http_query_routes', async () => {
			const res = await rest( site, 'QUERY', FIXTURE_OPTED_IN_ROUTE, { json: {} } );
			expect( res.status ).toBe( 200 );
			expect( tokens( res.headers.get( 'allow' ) ) ).toContain( 'QUERY' );
			expect( await res.json() ).toHaveProperty( 'category' );
		} );

		it( 'adds QUERY to every route in an enabled preset', async () => {
			await Promise.all(
				CORE_COLLECTIONS.map( async ( route ) => {
					const res = await rest( site, 'QUERY', route, { json: {} } );
					expect( res.status, route ).toBe( 200 );
					expect( Array.isArray( await res.json() ), route ).toBe( true );
				} )
			);
		} );

		it( 'dispatches QUERY on a route that lists QUERY in its methods', async () => {
			const res = await rest( site, 'QUERY', '/wp-http-query-test/v1/queryable', { json: { a: 1 } } );
			expect( res.status ).toBe( 200 );
			expect( await res.json() ).toMatchObject( { method: 'QUERY', params: { a: 1 } } );
		} );

		it( 'leaves a route outside every opt-in at 404 for QUERY', async () => {
			const res = await rest( site, 'QUERY', '/wp/v2/statuses', { json: {} } );
			expect( res.status ).toBe( 404 );
		} );
	} );
} );

/** The tests that need the posts tests/global-setup.ts seeds over WP-CLI. */
describeLocalSites( 'ADR 0005 reach, seeded posts: $label', ( site ) => {
	const [ zebra ] = SEED_POSTS;
	let id: number;

	beforeAll( async () => {
		id = await seededPostId( site, zebra.slug );
	} );

	it( 'matches a parameterized route by its registered regex key', async () => {
		const res = await rest( site, 'QUERY', `/wp/v2/posts/${ id }`, { json: {} } );
		expect( res.status ).toBe( 200 );
		expect( ( await res.json() ).id ).toBe( id );
	} );

	it( 'adds QUERY to the GET handler only, never to write handlers on the same route', async () => {
		// One GET handler, one EDITABLE (POST, PUT, PATCH), one DELETE.
		const item = await handlerMethods( site, `/wp/v2/posts/${ id }` );
		const collection = await handlerMethods( site, '/wp/v2/posts' );

		for ( const methods of [ ...item, ...collection ] ) {
			expect( methods.includes( 'QUERY' ), methods.join( ',' ) ).toBe( methods.includes( 'GET' ) );
		}
		expect( item.flat() ).toEqual( expect.arrayContaining( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] ) );
	} );

	describe( 'a real collection (gap 3 end to end, #22)', () => {
		it( 'filters QUERY /wp/v2/posts by a form-encoded search', async () => {
			const res = await rest( site, 'QUERY', '/wp/v2/posts', { form: { search: zebra.term } } );
			expect( res.status ).toBe( 200 );

			const posts: { id: number }[] = await res.json();
			expect( posts.map( ( p ) => p.id ) ).toEqual( [ id ] );
		} );
	} );
} );
