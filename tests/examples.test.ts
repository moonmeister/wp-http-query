/**
 * The example plugin, examples/query-route-example/. Not a project decision:
 * these tests back the code samples in docs/integrating.md, so the guide
 * shows code that runs.
 *
 * The example registers GET and QUERY on one read handler with schema args,
 * a POST write handler on the same route, and a shared permission callback
 * that checks for a safe method rather than for GET (#38).
 *
 * Guide: docs/integrating.md   Issue: #38
 */
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { BOOKS, rest, tokens } from './http';
import { sites } from './sites';

interface Book {
	title: string;
	author: string;
	year: number;
}

const titles = ( books: Book[] ) => books.map( ( b ) => b.title );

describe.each( sites )( 'example plugin: $label', ( site ) => {
	it( 'answers QUERY with a JSON body', async () => {
		const res = await rest( site, 'QUERY', BOOKS, { json: { search: 'the', per_page: 2 } } );
		expect( res.status ).toBe( 200 );

		const books: Book[] = await res.json();
		expect( titles( books ) ).toEqual( [ 'The Left Hand of Darkness', 'The Dispossessed' ] );
	} );

	it( 'reads an array param from a JSON body', async () => {
		const res = await rest( site, 'QUERY', BOOKS, { json: { author: [ 'Octavia E. Butler' ] } } );
		expect( res.status ).toBe( 200 );

		const books: Book[] = await res.json();
		expect( titles( books ) ).toEqual( [ 'Kindred', 'Parable of the Sower' ] );
	} );

	it( 'answers QUERY with a form-encoded body', async () => {
		const res = await rest( site, 'QUERY', BOOKS, {
			form: { 'author[]': 'Ursula K. Le Guin', per_page: '1' },
		} );
		expect( res.status ).toBe( 200 );

		const books: Book[] = await res.json();
		expect( titles( books ) ).toEqual( [ 'The Left Hand of Darkness' ] );
	} );

	it( 'gives QUERY with a body the same answer as GET with a query string', async () => {
		const get = await rest( site, 'GET', `${ BOOKS }?search=dune` );
		const query = await rest( site, 'QUERY', BOOKS, { json: { search: 'dune' } } );

		expect( get.status ).toBe( 200 );
		expect( query.status ).toBe( 200 );
		expect( await query.json() ).toEqual( await get.json() );
	} );

	it( 'rejects an out-of-range JSON body value with 400', async () => {
		const res = await rest( site, 'QUERY', BOOKS, { json: { per_page: 100 } } );
		expect( res.status ).toBe( 400 );

		const error = await res.json();
		expect( error.code ).toBe( 'rest_invalid_param' );
		expect( error.data.params ).toHaveProperty( 'per_page' );
	} );

	it( 'rejects an out-of-range form-encoded value with 400', async () => {
		const res = await rest( site, 'QUERY', BOOKS, { form: { per_page: '0' } } );
		expect( res.status ).toBe( 400 );

		const error = await res.json();
		expect( error.code ).toBe( 'rest_invalid_param' );
	} );

	it( 'treats QUERY as a read, not a write', async () => {
		// Unauthenticated. The write path needs edit_posts, so reaching it would 401.
		const query = await rest( site, 'QUERY', BOOKS, { json: { title: 'Not a new book' } } );
		expect( query.status ).toBe( 200 );
		expect( Array.isArray( await query.json() ) ).toBe( true );

		// Control: the write handler on the same route does refuse anonymous POST.
		const post = await rest( site, 'POST', BOOKS, { json: { title: 'Not a new book' } } );
		expect( post.status ).toBe( 401 );
	} );

	it( 'lists GET and QUERY in Allow, and sends Accept-Query', async () => {
		const res = await rest( site, 'QUERY', BOOKS, { json: {} } );

		expect( tokens( res.headers.get( 'allow' ) ) ).toEqual( expect.arrayContaining( [ 'GET', 'QUERY' ] ) );
		expect( res.headers.get( 'accept-query' ) ).toBe( '"application/json"' );
	} );
} );

/**
 * The guide's full-route samples are copied from the example. Fail if they
 * drift, so the docs never show code the tests above don't run.
 */
describe( 'docs/integrating.md', () => {
	const doc = readFileSync( new URL( '../docs/integrating.md', import.meta.url ), 'utf8' );
	const source = readFileSync(
		new URL( '../examples/query-route-example/query-route-example.php', import.meta.url ),
		'utf8'
	);

	// Blocks that define code (functions, the registration), not snippets.
	const samples = [ ...doc.matchAll( /```php\n([\s\S]*?)```/g ) ]
		.map( ( [ , code ] ) => code )
		.filter( ( code ) => /^(function |add_action\()/.test( code ) );

	it( 'has samples from the example', () => {
		expect( samples.length ).toBeGreaterThanOrEqual( 4 );
	} );

	it.each( samples.map( ( code ) => [ code.split( '\n' )[ 0 ], code ] ) )(
		'quotes the example verbatim: %s',
		( _first, code ) => {
			expect( source ).toContain( code );
		}
	);
} );
