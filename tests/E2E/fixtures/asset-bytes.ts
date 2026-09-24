/**
 * The asset-byte fixture: holds one template to its performance budget by recording every
 * response a page load causes, attributing each to the plugin by URL, and summing transferred
 * (compressed, as sent) bytes. Backs the assertions in specs/asset-budgets.spec.ts.
 *
 * Every response under the plugin's own directory counts, whatever kind it is: a `<link
 * rel="prefetch">` of a plugin file, a font, an image, a background `fetch()` -- there is no
 * allow-list of resource types to slip past.
 *
 * Attribution and summing (`summarizePluginBytes()`) are pure and take plain recorded values,
 * not live Playwright objects, so selftest/asset-bytes.spec.ts can prove them without a site.
 * Only `createAssetBytes()` and `measureResponseBytes()` touch a real `Page`.
 */

import type { Page, Request, Response } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/** One request the summing logic saw, already reduced to what attribution and summing need. */
export interface RecordedResponse {
	url: string;
	/** As `Request.resourceType()` reports it: descriptive only, no longer a filter. */
	resourceType: string;
	/** Transferred (compressed, as sent) bytes; see `measureResponseBytes()`. */
	bytes: number;
}

/** One plugin URL a template's load caused, or merely printed (see the import-map note below). */
export interface PluginURLHit {
	url: string;
	bytes: number;
}

/** What one template's load cost, ready to print and to assert against a budget. */
export interface AssetByteReport {
	template: string;
	totalBytes: number;
	/** Every plugin URL seen, heaviest first; empty today. */
	hits: PluginURLHit[];
}

/**
 * Whether a URL falls under the plugin's own directory.
 *
 * `pluginBasePath` always ends in a slash, so a same-prefix sibling plugin (`seocart-legacy`
 * next to `seocart`) is never mistaken for the plugin under test: the character after the
 * shared prefix must be the slash the base path itself ends with, not `-` or anything else.
 *
 * @param url            The response or import-map URL to classify.
 * @param pluginBasePath The plugin's own directory, e.g. "/wp-content/plugins/seocart/".
 */
export function isPluginURL( url: string, pluginBasePath: string ): boolean {
	let pathname: string;
	try {
		pathname = new URL( url ).pathname;
	} catch {
		return false;
	}
	return pathname.startsWith( pluginBasePath );
}

/**
 * Attribution and summing, fed already-recorded responses and any URLs the page printed in an
 * import map: no live `Page` or `Response` is touched here.
 *
 * A URL from the import map is counted even at 0 bytes when nothing fetched it, so a module the
 * plugin prints its own URL for is caught before the browser ever requests it. A URL seen both
 * ways keeps its measured bytes.
 *
 * @param template       The name printed in the report and in failure messages.
 * @param responses      Every request the page's load caused, under the plugin's directory or
 *                       not: attribution decides which count.
 * @param importMapURLs  Every URL the page's `<script type="importmap">` names.
 * @param pluginBasePath The plugin's own directory; see `isPluginURL()`.
 */
export function summarizePluginBytes(
	template: string,
	responses: RecordedResponse[],
	importMapURLs: string[],
	pluginBasePath: string
): AssetByteReport {
	const hits = new Map< string, number >();

	for ( const response of responses ) {
		if ( ! isPluginURL( response.url, pluginBasePath ) ) {
			continue;
		}
		hits.set(
			response.url,
			( hits.get( response.url ) ?? 0 ) + response.bytes
		);
	}

	for ( const url of importMapURLs ) {
		if ( ! isPluginURL( url, pluginBasePath ) ) {
			continue;
		}
		if ( ! hits.has( url ) ) {
			hits.set( url, 0 );
		}
	}

	const orderedHits = [ ...hits.entries() ]
		.map( ( [ url, bytes ] ) => ( { url, bytes } ) )
		.sort( ( a, b ) => b.bytes - a.bytes || a.url.localeCompare( b.url ) );

	return {
		template,
		totalBytes: orderedHits.reduce(
			( total, hit ) => total + hit.bytes,
			0
		),
		hits: orderedHits,
	};
}

/**
 * The transferred (compressed, as sent) size of one finished request's response body.
 *
 * `response.headers()['content-length']` is absent for a chunked response -- every dynamic
 * WordPress page is one -- so it is never read here. `Request.sizes().responseBodySize` is
 * Playwright's name for the same encoded-length figure Chrome DevTools' network panel shows,
 * sourced from the underlying protocol rather than from a header the server may not send. For
 * a chunked response it is the bytes actually on the wire, chunk framing included, which is
 * greater than or equal to the compressed body alone -- selftest/asset-bytes.spec.ts proves
 * this against a real chunked response of an exactly known compressed size.
 *
 * A response Chrome served from its disk or back-forward cache reports a *negative* encoded
 * size (its way of saying no bytes crossed the network this time), which is clamped to 0
 * rather than returned as-is or thrown away: it transferred no plugin bytes, which is a fact
 * worth keeping, not an error worth discarding.
 *
 * @param response A response the page's `'response'` event delivered.
 */
export async function measureResponseBytes(
	response: Response
): Promise< number > {
	try {
		const { responseBodySize } = await response.request().sizes();
		return Math.max( 0, responseBodySize );
	} catch {
		// A request that errored before it finished (aborted, blocked, no timing/size record)
		// also transferred no measurable plugin bytes.
		return 0;
	}
}

/**
 * Every URL a page's printed `<script type="importmap">` names, resolved against the page's
 * own address. Reads the DOM directly rather than the network log, so a specifier the browser
 * has not fetched yet -- because nothing on the page imports it -- still counts.
 *
 * @param page The page to read the import map from, already navigated.
 */
async function extractImportMapURLs( page: Page ): Promise< string[] > {
	return page.evaluate( () => {
		const script = document.querySelector( 'script[type="importmap"]' );
		if ( ! script?.textContent ) {
			return [];
		}

		let map: {
			imports?: Record< string, string >;
			scopes?: Record< string, Record< string, string > >;
		};
		try {
			map = JSON.parse( script.textContent );
		} catch {
			return [];
		}

		const urls = new Set< string >();
		const addAll = ( specifiers: Record< string, string > | undefined ) => {
			for ( const value of Object.values( specifiers ?? {} ) ) {
				if ( typeof value === 'string' ) {
					try {
						urls.add( new URL( value, document.baseURI ).href );
					} catch {
						// Not resolvable to a URL (a bare specifier with no matching scope): not
						// a plugin URL either way.
					}
				}
			}
		};
		addAll( map.imports );
		for ( const scope of Object.values( map.scopes ?? {} ) ) {
			addAll( scope );
		}
		return [ ...urls ];
	} );
}

/**
 * The plugin's own directory under `wp-content/plugins/`, as this site actually serves it --
 * never hard-coded to "seocart", because a worktree instance can serve the plugin through a
 * symlink named for the worktree rather than the plugin.
 *
 * `/wp/v2/plugins` is WordPress core's own inventory of what is installed; the entry is found
 * by `textdomain`, which the plugin header fixes at "seocart" regardless of the directory name,
 * and its `plugin` field ("<folder>/<main file>") names the real folder.
 *
 * The plugin must be active (`status` "active" or "network-active"): a deactivated plugin
 * enqueues nothing on any template, which would let every budget in the report pass while
 * measuring nothing at all -- a false green, not a proof.
 *
 * @param requestUtils An authenticated administrator's REST client (`activate_plugins` reads
 *                     this endpoint).
 */
export async function resolvePluginBasePath(
	requestUtils: RequestUtils
): Promise< string > {
	const plugins = await requestUtils.rest<
		Array< {
			plugin: string;
			textdomain?: string;
			name?: string;
			status?: string;
		} >
	>( {
		path: '/wp/v2/plugins',
	} );
	const plugin = plugins.find(
		( candidate ) => candidate.textdomain === 'seocart'
	);
	if ( ! plugin ) {
		throw new Error(
			'/wp/v2/plugins lists no plugin with text domain "seocart". Is SEOCart installed on this instance, and is the REST user an administrator?'
		);
	}
	if ( plugin.status !== 'active' && plugin.status !== 'network-active' ) {
		throw new Error(
			`SEOCart is installed on this instance but not active (status: "${ plugin.status }"). A deactivated plugin loads nothing on any template, so every budget below would pass without measuring anything real; activate it before running this suite.`
		);
	}
	const folder = plugin.plugin.split( '/' )[ 0 ];
	const baseURL = requestUtils.baseURL ?? '/';
	return new URL( `wp-content/plugins/${ folder }/`, baseURL ).pathname;
}

/** What `createAssetBytes()` returns: one template measurement, bound to a page and a session. */
export interface AssetBytes {
	/**
	 * Records the plugin's transferred bytes across everything `load()` causes.
	 *
	 * Recording starts before `load()` runs, so a request that starts mid-navigation is never
	 * missed, and ends once the page settles: `load()` return plus a bounded network-idle
	 * wait, since a WordPress admin screen's heartbeat requests would otherwise keep a strict
	 * idle wait from ever resolving.
	 *
	 * @param template The name printed in the report and used in failure messages.
	 * @param load     Navigates (or otherwise causes) the page load to measure.
	 */
	measure: (
		template: string,
		load: () => Promise< void >
	) => Promise< AssetByteReport >;
}

export function createAssetBytes(
	page: Page,
	pluginBasePath: string
): AssetBytes {
	return {
		async measure( template, load ) {
			// Keyed on the Request object itself, and populated the moment a request is seen
			// -- not when it succeeds -- so three cases a response-only listener would miss
			// are all covered by the one map: a request that fails outright (no 'response'
			// event at all, only 'requestfailed'), a request still in flight when the
			// listeners below detach, and a normal response, which only updates the bytes an
			// already-recorded entry starts at 0.
			const hits = new Map< Request, RecordedResponse >();
			const pendingByteUpdates: Array< Promise< void > > = [];

			const recordRequest = ( request: Request ) => {
				if ( hits.has( request ) ) {
					return;
				}
				if ( ! isPluginURL( request.url(), pluginBasePath ) ) {
					return;
				}
				hits.set( request, {
					url: request.url(),
					resourceType: request.resourceType(),
					bytes: 0,
				} );
			};

			const onRequest = ( request: Request ) => recordRequest( request );
			const onRequestFailed = ( request: Request ) =>
				recordRequest( request );
			const onResponse = ( response: Response ) => {
				const request = response.request();
				recordRequest( request );
				const entry = hits.get( request );
				if ( ! entry ) {
					return;
				}
				pendingByteUpdates.push(
					measureResponseBytes( response ).then( ( bytes ) => {
						entry.bytes = bytes;
					} )
				);
			};

			page.on( 'request', onRequest );
			page.on( 'requestfailed', onRequestFailed );
			page.on( 'response', onResponse );
			try {
				await load();
				// A bounded grace period for late fetches, never a hang on wp-admin's
				// heartbeat: a template that is still loading plugin assets 5s after its own
				// navigation finished belongs in the report either way.
				await page
					.waitForLoadState( 'networkidle', { timeout: 5_000 } )
					.catch( () => undefined );
			} finally {
				page.off( 'request', onRequest );
				page.off( 'requestfailed', onRequestFailed );
				page.off( 'response', onResponse );
			}

			await Promise.all( pendingByteUpdates );

			const responses = [ ...hits.values() ];
			const importMapURLs = await extractImportMapURLs( page );

			return summarizePluginBytes(
				template,
				responses,
				importMapURLs,
				pluginBasePath
			);
		},
	};
}

/**
 * Renders a report for the console and for an assertion failure alike: the template, the
 * budget, the total and every offending URL with its bytes, so a contributor sees at a glance
 * what to remove or re-budget.
 *
 * @param report      What `AssetBytes.measure()` returned.
 * @param budgetBytes The ceiling `report.totalBytes` is held to.
 */
export function describeAssetByteReport(
	report: AssetByteReport,
	budgetBytes: number
): string {
	const lines = [
		`${ report.template }: ${ report.totalBytes } byte(s) of the plugin's own assets (budget: ${ budgetBytes } byte(s)).`,
	];
	if ( report.hits.length === 0 ) {
		lines.push( '  (no plugin URLs were seen)' );
	} else {
		for ( const hit of report.hits ) {
			lines.push( `  - ${ hit.bytes } byte(s)  ${ hit.url }` );
		}
	}
	return lines.join( '\n' );
}
