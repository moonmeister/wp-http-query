import { expect } from 'vitest';
import type { Site } from '../sites';

/**
 * Sent on every request from the lifecycle suite. Steps spend seconds in
 * WP-CLI between requests, and a pooled keep-alive socket that Apache closed
 * in the meantime fails the next fetch with "other side closed".
 */
export const FRESH = { connection: 'close' };

/**
 * A logged-in WordPress session over real HTTP: a cookie jar, and fetch that
 * doesn't follow redirects. Logs in through wp-login.php, as a browser does.
 */
export class Session {
	private cookies = new Map< string, string >();

	constructor(
		private site: Site,
		private user = 'admin',
		private password = 'password'
	) {}

	async request(
		path: string,
		init: { method?: string; form?: URLSearchParams; json?: unknown; headers?: Record< string, string > } = {}
	): Promise< Response > {
		const headers: Record< string, string > = { ...FRESH, ...init.headers };
		if ( this.cookies.size ) {
			headers.cookie = [ ...this.cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' );
		}
		let body: string | undefined;
		if ( init.form ) {
			headers[ 'content-type' ] = 'application/x-www-form-urlencoded';
			body = init.form.toString();
		} else if ( init.json !== undefined ) {
			headers[ 'content-type' ] = 'application/json';
			body = JSON.stringify( init.json );
		}

		const res = await fetch( `${ this.site.url }${ path }`, {
			method: init.method ?? ( body === undefined ? 'GET' : 'POST' ),
			headers,
			body,
			redirect: 'manual',
		} );

		for ( const line of res.headers.getSetCookie() ) {
			const [ pair ] = line.split( ';' );
			const eq = pair.indexOf( '=' );
			this.cookies.set( pair.slice( 0, eq ), pair.slice( eq + 1 ) );
		}

		return res;
	}

	async logIn(): Promise< void > {
		this.cookies.set( 'wordpress_test_cookie', 'WP%20Cookie%20check' );
		const res = await this.request( '/wp-login.php', {
			form: new URLSearchParams( {
				log: this.user,
				pwd: this.password,
				testcookie: '1',
				'redirect_to': '/wp-admin/',
			} ),
		} );
		if ( res.status !== 302 || ! [ ...this.cookies.keys() ].some( ( k ) => k.startsWith( 'wordpress_logged_in_' ) ) ) {
			throw new Error( `Login to ${ this.site.url } as ${ this.user } failed: ${ res.status }` );
		}
	}

	async html( path: string ): Promise< string > {
		const res = await this.request( path );
		expect( res.status, `GET ${ path }` ).toBe( 200 );
		return res.text();
	}

	/** A wp_rest nonce for this session, from core's rest-nonce admin-ajax action. */
	async restNonce(): Promise< string > {
		const res = await this.request( '/wp-admin/admin-ajax.php?action=rest-nonce' );
		const nonce = ( await res.text() ).trim();
		if ( res.status !== 200 || ! /^[0-9a-f]{10}$/.test( nonce ) ) {
			throw new Error( `No REST nonce from ${ this.site.url }: ${ res.status } ${ nonce }` );
		}
		return nonce;
	}

	/** A REST request with this session's cookies and, unless one is given, a fresh nonce. */
	async rest(
		method: string,
		route: string,
		opts: { json?: unknown; headers?: Record< string, string >; nonce?: string | null } = {}
	): Promise< Response > {
		const nonce = opts.nonce === undefined ? await this.restNonce() : opts.nonce;
		const headers: Record< string, string > = { ...opts.headers };
		if ( nonce !== null ) {
			headers[ 'x-wp-nonce' ] = nonce;
		}
		return this.request( `/wp-json${ route }`, { method, json: opts.json, headers } );
	}
}
