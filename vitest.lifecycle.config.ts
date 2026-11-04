import { defineConfig } from 'vitest/config';

/**
 * Tests that change shared site state: activate/deactivate the plugin,
 * uninstall it, write options. One file at a time, and never alongside the
 * main suite: `make test` runs this after vitest.config.ts finishes.
 */
export default defineConfig( {
	test: {
		include: [ 'tests/lifecycle/**/*.test.ts' ],
		globalSetup: [ 'tests/lifecycle/global-setup.ts' ],
		fileParallelism: false,
		// Every step is a WP-CLI call through wp-env, about a second each.
		testTimeout: 60_000,
		hookTimeout: 60_000,
	},
} );
