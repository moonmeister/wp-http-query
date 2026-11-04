/**
 * The WordPress environments every decision test runs against.
 *
 * Both come from scripts/env.sh (`make up`). They differ in the one place core
 * changed under the plugin: 7.2 added the `rest_allowed_cors_methods` filter
 * (r64730), so the two exercise different CORS code paths in the plugin.
 *
 * Limit to one with SITES=stable or SITES=nightly. Override a URL with
 * WP_STABLE_URL / WP_NIGHTLY_URL to point the suite at another install.
 */
export interface Site {
	key: 'stable' | 'nightly';
	label: string;
	url: string;
}

const all: Site[] = [
	{
		key: 'stable',
		label: '7.1.4 (CORS fallback handler)',
		url: process.env.WP_STABLE_URL ?? 'http://localhost:8871',
	},
	{
		key: 'nightly',
		label: 'nightly (rest_allowed_cors_methods)',
		url: process.env.WP_NIGHTLY_URL ?? 'http://localhost:8872',
	},
];

const wanted = process.env.SITES?.split( ',' ).map( ( s ) => s.trim() );

export const sites = wanted ? all.filter( ( s ) => wanted.includes( s.key ) ) : all;
