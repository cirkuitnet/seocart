/**
 * Runs once before any worker starts: refuses to run without a configured site, logs the
 * administrator in over HTTP and saves the session that every browser context then reuses.
 *
 * A site that is not configured, an address that does not answer, that answers from another
 * origin or that is not WordPress, and a login that is refused: each ends at once in one
 * message that says what to change. `setupRest()` runs only after those are ruled out,
 * because it retries for a minute before it reports anything, and then only that it failed.
 */

import { rm } from 'node:fs/promises';
import {
	STORAGE_STATE_PATH,
	SiteEnvironmentError,
	requireSiteEnvironment,
} from './support/environment';
import { createRequestUtils } from './support/request-utils';

/** Long enough for a cold PHP worker on the dev server; short enough not to look like a hang. */
const REACHABILITY_TIMEOUT_MS = 15_000;

// Playwright appends its call log to the message; the first line is the cause.
function firstLine( error: Error ): string {
	return error.message.split( '\n' )[ 0 ];
}

export default async function globalSetup(): Promise< void > {
	const { baseURL, username } = requireSiteEnvironment();

	// A session saved for an instance that has since been torn down must not leak into this run.
	await rm( STORAGE_STATE_PATH, { force: true } );

	const requestUtils = await createRequestUtils();
	try {
		const response = await requestUtils.request
			.head( baseURL, { timeout: REACHABILITY_TIMEOUT_MS } )
			.catch( ( error: Error ) => {
				throw new SiteEnvironmentError(
					`No answer from ${ baseURL }: ${ firstLine(
						error
					) }\nCheck WP_BASE_URL and that the instance still exists. For a development certificate this workstation does not trust, set SEOCART_E2E_IGNORE_HTTPS_ERRORS=1.`
				);
			} );

		// The origin only: a multilingual site may send its home URL on to a language home.
		const answeredFrom = new URL( response.url() ).origin;
		if ( answeredFrom !== new URL( baseURL ).origin ) {
			throw new SiteEnvironmentError(
				`${ baseURL } redirects to ${ answeredFrom }. Set WP_BASE_URL to the address the site answers from: the redirect turns the login POST into a GET, and the session would belong to the other origin.`
			);
		}
		if (
			! response.headers().link?.includes( 'rel="https://api.w.org/"' )
		) {
			throw new SiteEnvironmentError(
				`${ baseURL } answered HTTP ${ response.status() } without the REST API Link header WordPress sends. WP_BASE_URL must be the home URL of a WordPress site.`
			);
		}

		// WordPress answers a rejected login with HTTP 200 and only the nonce request that
		// follows with an error, so the session cookie is the evidence, not the status.
		const refusal = await requestUtils.login().then( () => '', firstLine );
		const { cookies } = await requestUtils.request.storageState();
		if (
			! cookies.some( ( cookie ) =>
				cookie.name.startsWith( 'wordpress_logged_in_' )
			)
		) {
			throw new SiteEnvironmentError(
				`${ baseURL } did not log WP_USERNAME "${ username }" in${
					refusal && ` (${ refusal })`
				}.\nA 400 on the GET is WordPress refusing the REST nonce after it rejected the credentials: check WP_USERNAME and WP_PASSWORD against the instance file. An error on the POST means wp-login.php itself was refused before WordPress saw the credentials.`
			);
		}

		// Logs in, discovers the REST root and writes STORAGE_STATE_PATH.
		await requestUtils.setupRest();
	} finally {
		await requestUtils.request.dispose();
	}
}
