/**
 * Runs the harness's own tests without a WordPress site:
 *
 *     npx playwright test --config tests/E2E/selftest
 *
 * The root configuration runs these specs too, but it logs in to a site first. This one has
 * no global setup, so the accessibility gate can be shown to fail and to pass on any machine
 * with a browser installed, CI included.
 */

import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: __dirname,
	outputDir: '../../../test-results/e2e-selftest',
	forbidOnly: Boolean( process.env.CI ),
	reporter: process.env.CI ? 'github' : 'list',
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
