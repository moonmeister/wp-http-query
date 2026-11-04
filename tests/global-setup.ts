import { seedPosts } from './seed';
import { sites } from './sites';

/** Fail fast with a useful message instead of a wall of connection errors. */
export async function checkSites(): Promise< void > {
	const down: string[] = [];

	for ( const site of sites ) {
		try {
			const res = await fetch( `${ site.url }/wp-json/`, { signal: AbortSignal.timeout( 5_000 ) } );
			if ( ! res.ok ) {
				down.push( `${ site.key } (${ site.url }) answered ${ res.status }` );
			}
		} catch {
			down.push( `${ site.key } (${ site.url }) is not reachable` );
		}
	}

	if ( down.length ) {
		throw new Error( `WordPress environments not ready:\n  ${ down.join( '\n  ' ) }\nRun \`make up\` first.` );
	}
}

export default async function () {
	await checkSites();

	// Content for the real-collection test in adr-0005-reach.test.ts.
	for ( const site of sites ) {
		seedPosts( site );
	}
}
