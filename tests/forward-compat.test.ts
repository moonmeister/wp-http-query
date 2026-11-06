/**
 * Forward compatibility: never add what's already there. Issue #67.
 *
 * Decided 2026-11-05: the plugin must stay correct on a future core that
 * supports QUERY itself, so every feature checks for an existing provider
 * first, never a core version number. Each test fakes one provider on the
 * test fixture's already-provided route and checks the plugin neither
 * duplicates nor overwrites it:
 *
 * - QUERY already in the CORS list (gap 1),
 * - POST already in the parameter order (gap 3),
 * - QUERY already on a handler of an opted-in route (reach, ADR 0005),
 * - Accept-Query already set (RFC 10008 §3).
 *
 * The fakes and the observers that report what the plugin left live in
 * tests/fixtures/mu-plugins/, under "Already provided".
 *
 * Issue: #67
 */
import { beforeAll, describe, expect, it } from 'vitest';
import { CORE_CORS_METHODS, ORIGIN, rest, tokens } from './http';
import { sites } from './sites';

const ROUTE = '/wp-http-query-test/v1/already-provided';

/** What the fixture's observers saw after the plugin ran. */
interface Observed {
	methods: string;
	order: string[];
}

describe.each( sites )( 'forward compatibility: $label', ( site ) => {
	// One QUERY serves the three tests that read its response.
	let res: Response;
	let observed: Observed;

	beforeAll( async () => {
		res = await rest( site, 'QUERY', ROUTE, { json: {} } );
		expect( res.status ).toBe( 200 );
		observed = await res.json();
	} );

	it( 'keeps a CORS list that already has QUERY as it is', async () => {
		const preflight = await rest( site, 'OPTIONS', ROUTE, {
			headers: { origin: ORIGIN, 'access-control-request-method': 'QUERY' },
		} );
		expect( preflight.status ).toBe( 200 );

		// What the fixture's provider returned: core's list with QUERY after GET.
		const expected = [ ...CORE_CORS_METHODS ];
		expected.splice( expected.indexOf( 'GET' ) + 1, 0, 'QUERY' );

		// Core dedupes the list after the filter, so check what the filter returned too.
		expect( tokens( preflight.headers.get( 'x-wp-http-query-test-cors-methods' ) ) ).toEqual( expected );
		expect( tokens( preflight.headers.get( 'access-control-allow-methods' ) ) ).toEqual( expected );
	} );

	it( 'keeps a parameter order that already has POST as it is', () => {
		expect( observed.order ).toEqual( [ 'JSON', 'POST', 'GET', 'URL', 'defaults' ] );
	} );

	it( 'keeps an opted-in handler that already has QUERY as it is', () => {
		// The raw registration, untouched: not rewritten, QUERY not added again.
		expect( observed.methods ).toBe( 'GET, QUERY' );
	} );

	it( 'keeps an Accept-Query the response already has', () => {
		expect( res.headers.get( 'accept-query' ) ).toBe( '"application/jsonpath"' );
	} );
} );
