import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { rest } from './http';
import type { Site } from './sites';

const ENV_SH = fileURLToPath( new URL( '../scripts/env.sh', import.meta.url ) );

/**
 * scripts/env.sh arguments that run WP-CLI on a site. A network's sites share
 * one env, so --url picks the site.
 */
function wpArgs( site: Site, args: string[] ): string[] {
	return site.network ? [ 'wp', 'multisite', `--url=${ site.url }/`, ...args ] : [ 'wp', site.key, ...args ];
}

/**
 * Runs WP-CLI on a test site through scripts/env.sh and returns what it wrote
 * to stdout and stderr. Throws if it exits non-zero.
 *
 * Needs the wp-env sites: it won't work against a WP_*_URL override that
 * points at another install.
 */
export function wpWithStderr( site: Site, args: string[] ): { stdout: string; stderr: string } {
	if ( ! site.localCli ) {
		// scripts/env.sh always targets the local container; running it for an
		// overridden URL would read or change the wrong install.
		throw new Error( `WP-CLI is unavailable for ${ site.key }: its URL is overridden (${ site.url }).` );
	}
	const { status, stdout, stderr, error } = spawnSync( ENV_SH, wpArgs( site, args ), { encoding: 'utf8' } );
	if ( error || status !== 0 ) {
		throw new Error( `wp ${ args[ 0 ] } failed on ${ site.key } (exit ${ status }): ${ error?.message ?? stderr }` );
	}
	return { stdout, stderr };
}

/** Runs WP-CLI on a test site through scripts/env.sh and returns stdout. See wpWithStderr(). */
export function wp( site: Site, args: string[] ): string {
	return wpWithStderr( site, args ).stdout;
}

/** Marks where evalJson()'s result starts, after anything else PHP printed. */
const RESULT = '__WPHQ_RESULT__';

/**
 * Runs PHP through one `wp eval` and returns what it `return`s, as JSON.
 * `input` reaches the PHP as `$input`, decoded from JSON, so nothing needs
 * escaping into PHP source. Each WP-CLI call through wp-env costs about a
 * second, so batch what one step needs into one call.
 */
export function evalJson< T = unknown >( site: Site, php: string, input: unknown = null ): T {
	const encoded = Buffer.from( JSON.stringify( input ) ).toString( 'base64' );
	const out = wp( site, [
		'eval',
		`$input = json_decode( base64_decode( '${ encoded }' ), true );
		$result = ( function () use ( $input ) { ${ php } } )();
		echo '${ RESULT }' . wp_json_encode( $result );`,
	] );
	return JSON.parse( out.slice( out.lastIndexOf( RESULT ) + RESULT.length ) );
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
	evalJson(
		site,
		`foreach ( $input as $p ) {
			if ( get_page_by_path( $p['slug'], OBJECT, 'post' ) ) { continue; }
			wp_insert_post( array(
				'post_name'    => $p['slug'],
				'post_title'   => 'Seed post ' . $p['term'],
				'post_content' => 'Fixture for the decision tests. Unique term: ' . $p['term'] . '.',
				'post_status'  => 'publish',
			), true );
		}`,
		SEED_POSTS
	);
}

/** A seeded post's ID, looked up over REST. */
export async function seededPostId( site: Site, slug: string ): Promise< number > {
	const res = await rest( site, 'GET', `/wp/v2/posts?slug=${ slug }&_fields=id` );
	const [ post ]: { id: number }[] = await res.json();
	if ( ! post ) {
		throw new Error( `Seed post ${ slug } missing on ${ site.key }; global setup should have created it.` );
	}
	return post.id;
}
