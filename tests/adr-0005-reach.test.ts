/**
 * Reach: which routes accept QUERY. ADR 0005, issue #1.
 *
 * Decided 2026-11-04 for the plugin: reach is opt-in, NEVER every route by
 * default. A route that registers QUERY gets it; existing read routes do not,
 * unless a site opts them in. The opt-in options themselves are still being
 * designed.
 *
 * The plugin's current rest_endpoints filter is a placeholder that adds QUERY
 * to every GET route, so the default-reach tests below are marked `.fails`:
 * they assert the decision and currently fail. When #1 replaces the
 * placeholder they will start passing, vitest will report the `.fails` as an
 * error, and the marker should be removed.
 *
 * Why it matters: #38 found plugins (Jetpack, 3M installs) whose GET-route
 * callbacks treat "not GET" as "write", so blanket reach turns a QUERY into a write.
 *
 * Decision: docs/decisions/0005-reach-for-existing-read-routes.md
 */
import { describe, expect, it } from 'vitest';
import { ECHO, rest, tokens } from './http';
import { sites } from './sites';

/** A core read route nobody has opted in. */
const CORE_READ_ROUTE = '/wp/v2/types';

describe.each( sites )( 'ADR 0005 reach: $label', ( site ) => {
	it( 'dispatches QUERY on a route that registered it', async () => {
		const res = await rest( site, 'QUERY', ECHO, { json: {} } );
		expect( res.status ).toBe( 200 );
		expect( tokens( res.headers.get( 'allow' ) ) ).toContain( 'QUERY' );
	} );

	it.fails( 'does not add QUERY to an existing GET route by default', async () => {
		const res = await rest( site, 'QUERY', CORE_READ_ROUTE );
		expect( res.status ).toBe( 404 );
	} );

	it.fails( "leaves an existing GET route's Allow header unchanged by default", async () => {
		const res = await rest( site, 'GET', CORE_READ_ROUTE );
		expect( tokens( res.headers.get( 'allow' ) ) ).not.toContain( 'QUERY' );
	} );

	it.todo( 'adds QUERY to a route a site has opted in (once #1 defines the options)' );
} );
