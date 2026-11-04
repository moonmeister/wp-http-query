/**
 * The example plugin's feature detection, examples/query-route-example/.
 *
 * With wp-http-query deactivated, the example route still answers GET and
 * does not register QUERY, so a QUERY gets core's 404 instead of a request
 * whose form-encoded body is silently dropped. Backs the "Feature detection"
 * section of docs/integrating.md.
 *
 * Runs in the serial lifecycle suite because it deactivates the plugin.
 * afterAll reactivates it even if a test fails.
 *
 * Guide: docs/integrating.md
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { rest, tokens } from '../http';
import { sites } from '../sites';
import { activatePlugin, deactivatePlugin } from './site-state';

const BOOKS = '/query-route-example/v1/books';

describe.each( sites )( 'example plugin without wp-http-query: $label', ( site ) => {
	beforeAll( () => deactivatePlugin( site ) );
	afterAll( () => activatePlugin( site ) );

	it( 'still answers GET', async () => {
		const res = await fetch( `${ site.url }/wp-json${ BOOKS }?search=dune` );
		expect( res.status ).toBe( 200 );
		expect( await res.json() ).toEqual( [ { title: 'Dune', author: 'Frank Herbert', year: 1965 } ] );
	} );

	it( 'does not register QUERY', async () => {
		const query = await rest( site, 'QUERY', BOOKS, { json: { search: 'dune' } } );
		expect( query.status ).toBe( 404 );

		const get = await rest( site, 'GET', BOOKS );
		expect( tokens( get.headers.get( 'allow' ) ) ).not.toContain( 'QUERY' );
	} );
} );
