import { describe, it } from 'vitest';

/**
 * The WordPress sites every decision test runs against.
 *
 * From scripts/env.sh (`make up`): the nightly build, since the plugin needs
 * WordPress 7.2 or later.
 *
 * Limit with SITES, e.g. SITES=nightly. Override the URL with WP_NIGHTLY_URL
 * to point the suite at another install.
 *
 * Two more sites, never run by default, are the multisite network from
 * `scripts/env.sh start multisite` (issue #46): its main site and the /sub/
 * subsite, the plugin network-activated. SITES=multisite,multisite-sub runs
 * the same suite against them. WP_MULTISITE_URL overrides the network's URL.
 *
 * An overridden site is HTTP-only: WP-CLI always reaches the local wp-env
 * container, never the overridden install, so anything needing WP-CLI
 * (seeding, option changes, plugin activation) is skipped for it. See
 * `localCli`, describeLocalSites() and the guard in tests/seed.ts.
 */
export interface Site {
	key: 'nightly' | 'multisite' | 'multisite-sub';
	label: string;
	url: string;
	/** True when `url` is the local wp-env site, so WP-CLI (scripts/env.sh) targets the same install. */
	localCli: boolean;
	/** A site of the multisite network: scripts/env.sh's `multisite` env, the plugin network-activated. */
	network?: boolean;
}

const MULTISITE_URL = process.env.WP_MULTISITE_URL ?? 'http://localhost:8876';

const all: Site[] = [
	{
		key: 'nightly',
		label: 'nightly',
		url: process.env.WP_NIGHTLY_URL ?? 'http://localhost:8872',
		localCli: ! process.env.WP_NIGHTLY_URL,
	},
];

export const networkSites: Site[] = [
	{
		key: 'multisite',
		label: 'multisite nightly, main site (network-active)',
		url: MULTISITE_URL,
		localCli: ! process.env.WP_MULTISITE_URL,
		network: true,
	},
	{
		key: 'multisite-sub',
		label: 'multisite nightly, /sub/ subsite (network-active)',
		url: `${ MULTISITE_URL }/sub`,
		localCli: ! process.env.WP_MULTISITE_URL,
		network: true,
	},
];

const wanted = process.env.SITES?.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );

export const sites = wanted?.length ? [ ...all, ...networkSites ].filter( ( s ) => wanted.includes( s.key ) ) : all;

/**
 * describe.each( sites ) for tests that need WP-CLI: a site whose URL is
 * overridden gets one skipped test saying why, instead of the block.
 */
export function describeLocalSites( name: string, fn: ( site: Site ) => void ): void {
	describe.each( sites )( name, ( site ) => {
		if ( ! site.localCli ) {
			it.skip( 'skipped: URL overridden, and WP-CLI only reaches the local wp-env site', () => {} );
			return;
		}
		fn( site );
	} );
}
