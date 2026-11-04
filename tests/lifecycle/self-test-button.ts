import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import type { Session } from './session';

/**
 * Runs the shipped plugin/assets/self-test.js in Node, against a real site:
 * a stub DOM with just what the script uses, and a fetch that behaves like a
 * same-origin browser fetch (the session's cookies unless credentials are
 * 'omit'). Clicks the button and returns the rendered results and every
 * request the script sent.
 */

const SCRIPT = readFileSync( new URL( '../../plugin/assets/self-test.js', import.meta.url ), 'utf8' );

/** Just enough of a DOM element for self-test.js. */
export class StubElement {
	className = '';
	disabled = false;
	children: StubElement[] = [];
	private attributes: Record< string, string > = {};
	private listeners: Record< string, () => Promise< void > > = {};
	private text = '';

	constructor( readonly tagName: string ) {}

	get textContent(): string {
		return this.text + this.children.map( ( c ) => c.textContent ).join( '' );
	}

	set textContent( value: string ) {
		this.text = value;
		this.children = [];
	}

	appendChild( child: StubElement ): StubElement {
		this.children.push( child );
		return child;
	}

	setAttribute( name: string, value: string ): void {
		this.attributes[ name ] = String( value );
	}

	getAttribute( name: string ): string | undefined {
		return this.attributes[ name ];
	}

	addEventListener( type: string, listener: () => Promise< void > ): void {
		this.listeners[ type ] = listener;
	}

	click(): Promise< void > {
		return this.listeners.click();
	}

	/** Every descendant, depth first, matching a predicate. */
	findAll( match: ( el: StubElement ) => boolean ): StubElement[] {
		return this.children.flatMap( ( c ) => [ ...( match( c ) ? [ c ] : [] ), ...c.findAll( match ) ] );
	}
}

export interface Sent {
	method: string;
	path: string;
	signedIn: boolean;
	start: number;
	end: number;
}

/**
 * Clicks "Test your server" with the script data from the settings page.
 * `intercept` may answer a request instead of the site, to fabricate a failure.
 */
export async function clickTestButton(
	session: Session,
	pageUrl: string,
	config: unknown,
	intercept: ( request: Request ) => Response | undefined = () => undefined
): Promise< { output: StubElement; sent: Sent[] } > {
	const button = new StubElement( 'button' );
	const output = new StubElement( 'div' );
	const sent: Sent[] = [];
	let clock = 0;

	const browserFetch = async ( input: Request | string, init?: RequestInit ): Promise< Response > => {
		const request = input instanceof Request ? input : new Request( input, init );
		const signedIn = request.credentials !== 'omit';
		const log: Sent = { method: request.method, path: new URL( request.url ).pathname, signedIn, start: clock++, end: -1 };
		sent.push( log );

		const fabricated = intercept( request );
		if ( fabricated ) {
			log.end = clock++;
			return fabricated;
		}

		const headers = new Headers( request.headers );
		if ( signedIn ) {
			headers.set( 'cookie', session.cookieHeader() );
		}
		const body = [ 'GET', 'HEAD' ].includes( request.method ) ? undefined : await request.text();
		const res = await fetch( request.url, { method: request.method, headers, body, redirect: request.redirect } );
		log.end = clock++;
		return res;
	};

	runInNewContext( SCRIPT, {
		window: { wpHttpQuerySelfTest: config, crypto: globalThis.crypto, location: { href: pageUrl } },
		document: {
			getElementById: ( id: string ) =>
				( { 'wp-http-query-self-test': button, 'wp-http-query-self-test-results': output } )[ id ] ?? null,
			createElement: ( tag: string ) => new StubElement( tag ),
			createTextNode: ( text: string ) => Object.assign( new StubElement( '#text' ), { textContent: text } ),
		},
		fetch: browserFetch,
		Request,
		URL,
	} );

	await button.click();
	return { output, sent };
}
