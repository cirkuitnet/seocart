/**
 * Builds the WordPress `RequestUtils` for the site under test.
 *
 * `RequestUtils.setup()` from the package is not used, for two reasons. It opens its request
 * context without `ignoreHTTPSErrors`, and that context is created outside any test, where
 * Playwright does not apply the project's `use` options, so a development certificate would
 * fail every REST call. And it falls back to the package's default user; here the user always
 * comes from `requireSiteEnvironment()`, which has no default.
 *
 * Two consumers: `global-setup.ts` (logs in once) and the `requestUtils` worker fixture.
 */

import { readFile } from 'node:fs/promises';
import { request } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import {
	STORAGE_STATE_PATH,
	ignoreHTTPSErrors,
	requireSiteEnvironment,
} from './environment';

type SavedState = RequestUtils[ 'storageState' ];

async function readSavedState(): Promise< SavedState > {
	try {
		return JSON.parse( await readFile( STORAGE_STATE_PATH, 'utf-8' ) );
	} catch ( error ) {
		if ( ( error as NodeJS.ErrnoException ).code === 'ENOENT' ) {
			return undefined;
		}
		throw error;
	}
}

export async function createRequestUtils(): Promise< RequestUtils > {
	const { baseURL, username, password } = requireSiteEnvironment();
	const storageState = await readSavedState();

	const context = await request.newContext( {
		baseURL,
		ignoreHTTPSErrors: ignoreHTTPSErrors(),
		storageState: storageState && {
			cookies: storageState.cookies,
			origins: [],
		},
	} );

	return new RequestUtils( context, {
		user: { username, password },
		storageState,
		storageStatePath: STORAGE_STATE_PATH,
		baseURL,
	} );
}
