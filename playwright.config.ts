/**
 * Playwright configuration for the end-to-end suite.
 *
 * The suite runs from the workstation against a real WordPress site: a disposable
 * per-worktree instance during development, never wp-env or Playground as the primary target
 * (docs/phase-0/test-strategy.md section 2.4). The site comes from WP_BASE_URL, WP_USERNAME
 * and WP_PASSWORD; tests/E2E/support/environment.ts documents where those come from.
 *
 * A missing site is refused in tests/E2E/global-setup.ts rather than here, so that
 * `playwright test --list` and editor integrations can still load this file without one.
 *
 * tests/E2E/selftest/ holds the harness's own tests. They need no site and also run on their
 * own: `npx playwright test --config tests/E2E/selftest`.
 */

import { defineConfig, devices } from '@playwright/test';
import {
	STORAGE_STATE_PATH,
	ignoreHTTPSErrors,
	loadSiteEnvironment,
	siteBaseURL,
} from './tests/E2E/support/environment';

// Before anything imports @wordpress/e2e-test-utils-playwright; see support/environment.ts.
loadSiteEnvironment();

const isCI = Boolean( process.env.CI );

export default defineConfig( {
	testDir: './tests/E2E',
	outputDir: './test-results/e2e',
	globalSetup: require.resolve( './tests/E2E/global-setup' ),

	// One shared, stateful WordPress site: specs that write to it must not interleave.
	fullyParallel: false,
	workers: 1,

	forbidOnly: isCI,
	retries: isCI ? 2 : 0,
	reporter: isCI
		? [ [ 'github' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ], [ 'html', { open: 'never' } ] ],

	use: {
		baseURL: siteBaseURL(),
		storageState: STORAGE_STATE_PATH,
		ignoreHTTPSErrors: ignoreHTTPSErrors(),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
