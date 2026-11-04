import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import type { Site } from './sites';

const ENV_SH = fileURLToPath( new URL( '../scripts/env.sh', import.meta.url ) );

/**
 * Runs WP-CLI on a test site through scripts/env.sh and returns stdout.
 *
 * Needs the wp-env sites: it won't work against a WP_*_URL override that
 * points at another install.
 */
export function wp( site: Site, args: string[] ): string {
	if ( ! site.localCli ) {
		// scripts/env.sh always targets the local container; running it for an
		// overridden URL would read or change the wrong install.
		throw new Error( `WP-CLI is unavailable for ${ site.key }: its URL is overridden (${ site.url }).` );
	}
	return execFileSync( ENV_SH, [ 'wp', site.key, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

/**
 * Published posts the tests rely on, each with a search term no other post
 * contains. Found by slug, so seeding is idempotent.
 */
export const SEED_POSTS = [
	{ slug: 'wphq-seed-zebra', term: 'wphqzebra' },
	{ slug: 'wphq-seed-yak', term: 'wphqyak' },
] as const;

/**
 * Creates SEED_POSTS on a site unless they already exist.
 *
 * One `wp eval` per site rather than a `wp post list` / `wp post create` pair
 * per post: each WP-CLI call through wp-env costs about a second.
 */
export function seedPosts( site: Site ): void {
	const php = `
		foreach ( json_decode( '${ JSON.stringify( SEED_POSTS ) }', true ) as $p ) {
			if ( get_page_by_path( $p['slug'], OBJECT, 'post' ) ) { continue; }
			wp_insert_post( array(
				'post_name'    => $p['slug'],
				'post_title'   => 'Seed post ' . $p['term'],
				'post_content' => 'Fixture for the decision tests. Unique term: ' . $p['term'] . '.',
				'post_status'  => 'publish',
			), true );
		}`;

	wp( site, [ 'eval', php ] );
}

/** A seeded post's ID, looked up over REST. */
export async function seededPostId( site: Site, slug: string ): Promise< number > {
	const res = await fetch( `${ site.url }/wp-json/wp/v2/posts?slug=${ slug }&_fields=id` );
	const [ post ]: { id: number }[] = await res.json();
	if ( ! post ) {
		throw new Error( `Seed post ${ slug } missing on ${ site.key }; global setup should have created it.` );
	}
	return post.id;
}
