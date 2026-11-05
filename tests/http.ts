import type { Site } from './sites';

/**
 * The plugin's diagnostic route. Registered with 'methods' => 'QUERY' only.
 * Admins only unless wp_http_query_public_demo_route returns true: the test
 * fixture makes it public.
 */
export const ECHO = '/wp-http-query/v1/echo';

export const ORIGIN = 'http://example.test';

/** Core's default Access-Control-Allow-Methods list. */
export const CORE_CORS_METHODS = [ 'OPTIONS', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];

/** The plugin's 'core-collections' preset, as listed in plugin/wp-http-query.php presets(). */
export const CORE_COLLECTIONS = [
	'/wp/v2/posts',
	'/wp/v2/pages',
	'/wp/v2/media',
	'/wp/v2/comments',
	'/wp/v2/categories',
	'/wp/v2/tags',
	'/wp/v2/users',
	'/wp/v2/search',
];

/** A core route the test fixture opts in through wp_http_query_routes. */
export const FIXTURE_OPTED_IN_ROUTE = '/wp/v2/taxonomies';

export interface Echo {
	method: string;
	json_params: Record< string, unknown > | null;
	merged_params: Record< string, unknown >;
	advertised_types: string[];
	via_override: boolean;
}

/** Sends a request to a REST route, e.g. rest( site, 'QUERY', ECHO, { json: {...} } ). */
export function rest(
	site: Site,
	method: string,
	route: string,
	opts: { json?: unknown; form?: Record< string, string >; headers?: Record< string, string > } = {}
): Promise< Response > {
	// No keep-alive. The lifecycle suite spends seconds in WP-CLI between requests,
	// and a pooled socket Apache closed meanwhile fails the next fetch with "other
	// side closed". A network's sites share one origin, hence one pool, so there
	// it bites on the first request to the second site.
	const headers: Record< string, string > = { connection: 'close', ...opts.headers };
	let body: string | undefined;

	if ( opts.json !== undefined ) {
		headers[ 'content-type' ] = 'application/json';
		body = JSON.stringify( opts.json );
	} else if ( opts.form !== undefined ) {
		headers[ 'content-type' ] = 'application/x-www-form-urlencoded';
		body = new URLSearchParams( opts.form ).toString();
	}

	return fetch( `${ site.url }/wp-json${ route }`, { method, headers, body } );
}

/** Comma-separated header value as a list of trimmed, upper-cased tokens. */
export function tokens( value: string | null ): string[] {
	return ( value ?? '' )
		.split( ',' )
		.map( ( t ) => t.trim().toUpperCase() )
		.filter( Boolean );
}
