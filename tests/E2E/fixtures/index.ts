/**
 * The `test` every site spec imports: the WordPress fixtures (`admin`, `editor`, `pageUtils`,
 * `requestUtils`, ...) plus SEOCart's own.
 *
 * Further fixtures are merged here, each from its own file in this directory. The asset-byte
 * fixture, which holds each template to its byte budget, is the next one expected.
 */

import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	expectNoAccessibilityViolations,
	type AccessibilityCheckOptions,
} from '../support/axe';
import { createRequestUtils } from '../support/request-utils';

interface SEOCartFixtures {
	/** `expectNoAccessibilityViolations()` from support/axe.ts, bound to the test's page. */
	expectNoAccessibilityViolations: (
		options?: AccessibilityCheckOptions
	) => Promise< void >;
}

export const test = base.extend< SEOCartFixtures >( {
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
} );

export { expect };
