import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		include: [ 'tests/**/*.test.ts' ],
		// Mutates site state; run serially by vitest.lifecycle.config.ts.
		exclude: [ ...configDefaults.exclude, 'tests/lifecycle/**' ],
		globalSetup: [ 'tests/global-setup.ts' ],
		testTimeout: 15_000,
	},
} );
