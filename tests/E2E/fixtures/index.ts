/**
 * The `test` every site spec imports: the WordPress fixtures (`admin`, `editor`, `pageUtils`,
 * `requestUtils`, ...) plus SEOCart's own.
 *
 * Further fixtures are merged here, each from its own file in this directory.
 */

import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	expectNoAccessibilityViolations,
	type AccessibilityCheckOptions,
} from '../support/axe';
import { createRequestUtils } from '../support/request-utils';
import {
	createAssetBytes,
	resolvePluginBasePath,
	type AssetBytes,
} from './asset-bytes';

interface SEOCartFixtures {
	/** `expectNoAccessibilityViolations()` from support/axe.ts, bound to the test's page. */
	expectNoAccessibilityViolations: (
		options?: AccessibilityCheckOptions
	) => Promise< void >;
	/** `AssetBytes.measure()` from fixtures/asset-bytes.ts, bound to the test's page. */
	assetBytes: AssetBytes;
}

interface SEOCartWorkerFixtures {
	/** The plugin's own directory on this site; see `resolvePluginBasePath()`. Resolved once. */
	pluginBasePath: string;
}

export const test = base.extend< SEOCartFixtures, SEOCartWorkerFixtures >( {
	// Replaces the package's fixture; support/request-utils.ts explains why. An override
	// given as a bare function keeps the original's options: worker scope, set up automatically.
	// Playwright reads a fixture's dependencies from the destructuring pattern, empty or not.
	requestUtils: async ( {}, provide ) => {
		const requestUtils = await createRequestUtils();
		await provide( requestUtils );
		await requestUtils.request.dispose();
	},

	expectNoAccessibilityViolations: async ( { page }, provide ) => {
		await provide( ( options ) =>
			expectNoAccessibilityViolations( page, options )
		);
	},

	// Worker-scoped: one REST lookup per worker, not one per test.
	pluginBasePath: [
		async ( { requestUtils }, provide ) => {
			await provide( await resolvePluginBasePath( requestUtils ) );
		},
		{ scope: 'worker' },
	],

	assetBytes: async ( { page, pluginBasePath }, provide ) => {
		await provide( createAssetBytes( page, pluginBasePath ) );
	},
} );

export { expect };
