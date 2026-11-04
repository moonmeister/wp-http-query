import type { Site } from './sites';

/** The plugin's diagnostic route. Registered with 'methods' => 'QUERY' only. */
export const ECHO = '/wp-http-query/v1/echo';

export const ORIGIN = 'http://example.test';

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
	const headers: Record< string, string > = { ...opts.headers };
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
