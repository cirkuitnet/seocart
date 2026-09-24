/**
 * Self-test of the asset-byte fixture: attribution, summing, active-plugin resolution and byte
 * measurement, all without a WordPress site.
 *
 * summarizePluginBytes() and isPluginURL() are pure, so most cases feed them plain recorded
 * values directly, including a chunked response with no content-length and an import-map-only
 * module. measureResponseBytes() is proved separately, against a real chunked response of a
 * known, exact compressed size from a throwaway local HTTP server: content-length is absent by
 * construction there, which is exactly the case response.headers() cannot answer.
 */

import { createServer, type Server } from 'node:http';
import { gzipSync } from 'node:zlib';
import { test, expect } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import {
	isPluginURL,
	summarizePluginBytes,
	measureResponseBytes,
	resolvePluginBasePath,
	type RecordedResponse,
} from '../fixtures/asset-bytes';

const PLUGIN_BASE_PATH = '/wp-content/plugins/seocart/';

test.describe( 'isPluginURL()', () => {
	test( "matches a URL under the plugin's own directory", () => {
		expect(
			isPluginURL(
				'https://site.example/wp-content/plugins/seocart/build/blocks/cart/index.js',
				PLUGIN_BASE_PATH
			)
		).toBe( true );
	} );

	test( "does not match another plugin's directory, even one whose name shares this one's name as a prefix", () => {
		// startsWith("/wp-content/plugins/seocart") without the trailing slash would wrongly
		// match "seocart-legacy" too; the trailing slash in PLUGIN_BASE_PATH is what rules it
		// out.
		expect(
			isPluginURL(
				'https://site.example/wp-content/plugins/seocart-legacy/script.js',
				PLUGIN_BASE_PATH
			)
		).toBe( false );
	} );

	test( 'does not match an unrelated plugin', () => {
		expect(
			isPluginURL(
				'https://site.example/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js',
				PLUGIN_BASE_PATH
			)
		).toBe( false );
	} );

	test( 'returns false, not a throw, for a URL that cannot be parsed', () => {
		expect( isPluginURL( 'not a url', PLUGIN_BASE_PATH ) ).toBe( false );
	} );
} );

test.describe( 'summarizePluginBytes()', () => {
	function response(
		url: string,
		resourceType: string,
		bytes: number
	): RecordedResponse {
		return { url, resourceType, bytes };
	}

	test( 'sums every response under the plugin directory, per URL, whatever resource type it is', () => {
		const report = summarizePluginBytes(
			'example template',
			[
				response(
					'https://site.example/wp-content/plugins/seocart/build/blocks/cart/index.js',
					'script',
					4000
				),
				response(
					'https://site.example/wp-content/plugins/seocart/build/blocks/cart/style.css',
					'stylesheet',
					800
				),
				// Another plugin: excluded by attribution, even though its own byte count is
				// larger than the plugin's total -- proof this is not merely a "biggest wins".
				response(
					'https://site.example/wp-content/plugins/woocommerce/assets/frontend.js',
					'script',
					9999
				),
				// The plugin's own file, of a resource type ("media") a page load rarely
				// causes on purpose -- there is no allow-list of types excluding it, so a
				// prefetched or background-loaded file the plugin serves still counts.
				response(
					'https://site.example/wp-content/plugins/seocart/build/demo.mp4',
					'media',
					50000
				),
			],
			[],
			PLUGIN_BASE_PATH
		);

		expect( report.template ).toBe( 'example template' );
		expect( report.totalBytes ).toBe( 54800 );
		expect( report.hits ).toEqual( [
			{
				url: 'https://site.example/wp-content/plugins/seocart/build/demo.mp4',
				bytes: 50000,
			},
			{
				url: 'https://site.example/wp-content/plugins/seocart/build/blocks/cart/index.js',
				bytes: 4000,
			},
			{
				url: 'https://site.example/wp-content/plugins/seocart/build/blocks/cart/style.css',
				bytes: 800,
			},
		] );
	} );

	test( 'sums repeated responses to the same URL', () => {
		const report = summarizePluginBytes(
			'example template',
			[
				response(
					'https://site.example/wp-content/plugins/seocart/build/x.js',
					'script',
					100
				),
				response(
					'https://site.example/wp-content/plugins/seocart/build/x.js',
					'script',
					100
				),
			],
			[],
			PLUGIN_BASE_PATH
		);

		expect( report.totalBytes ).toBe( 200 );
		expect( report.hits ).toEqual( [
			{
				url: 'https://site.example/wp-content/plugins/seocart/build/x.js',
				bytes: 200,
			},
		] );
	} );

	test( 'a module the import map prints counts even before anything fetches it', () => {
		const report = summarizePluginBytes(
			'example template',
			[], // Nothing was fetched: the browser never imported either specifier.
			[
				'https://site.example/wp-content/plugins/seocart/build/blocks/cart/view.js',
				'https://site.example/wp-content/plugins/woocommerce/build/view.js',
			],
			PLUGIN_BASE_PATH
		);

		expect( report.hits ).toEqual( [
			{
				url: 'https://site.example/wp-content/plugins/seocart/build/blocks/cart/view.js',
				bytes: 0,
			},
		] );
		expect( report.totalBytes ).toBe( 0 );
	} );

	test( 'a module seen both in the import map and fetched keeps its measured bytes, counted once', () => {
		const url =
			'https://site.example/wp-content/plugins/seocart/build/blocks/cart/view.js';
		const report = summarizePluginBytes(
			'example template',
			[ response( url, 'script', 1234 ) ],
			[ url ],
			PLUGIN_BASE_PATH
		);

		expect( report.hits ).toEqual( [ { url, bytes: 1234 } ] );
		expect( report.totalBytes ).toBe( 1234 );
	} );

	test( 'an empty page load reports zero bytes and an empty URL list', () => {
		const report = summarizePluginBytes(
			'empty template',
			[],
			[],
			PLUGIN_BASE_PATH
		);

		expect( report.totalBytes ).toBe( 0 );
		expect( report.hits ).toEqual( [] );
	} );
} );

test.describe( 'resolvePluginBasePath()', () => {
	/**
	 * A `RequestUtils` stub that answers `/wp/v2/plugins` with a fixed list: only the parts
	 * `resolvePluginBasePath()` reads (`rest()` and `baseURL`) need to exist.
	 *
	 * @param plugins The list `/wp/v2/plugins` would return.
	 * @param baseURL The site's root; defaults to a plain example.
	 */
	function fakeRequestUtils(
		plugins: unknown[],
		baseURL = 'https://site.example/'
	): RequestUtils {
		return {
			baseURL,
			rest: async () => plugins,
		} as unknown as RequestUtils;
	}

	test( "resolves the active plugin's own directory from /wp/v2/plugins", async () => {
		const requestUtils = fakeRequestUtils( [
			{
				plugin: 'seocart/seocart',
				textdomain: 'seocart',
				status: 'active',
			},
		] );

		await expect( resolvePluginBasePath( requestUtils ) ).resolves.toBe(
			'/wp-content/plugins/seocart/'
		);
	} );

	test( 'resolves a network-active plugin the same way', async () => {
		const requestUtils = fakeRequestUtils( [
			{
				plugin: 'seocart/seocart',
				textdomain: 'seocart',
				status: 'network-active',
			},
		] );

		await expect( resolvePluginBasePath( requestUtils ) ).resolves.toBe(
			'/wp-content/plugins/seocart/'
		);
	} );

	test( 'refuses a deactivated plugin instead of measuring nothing and calling it zero', async () => {
		const requestUtils = fakeRequestUtils( [
			{
				plugin: 'seocart/seocart',
				textdomain: 'seocart',
				status: 'inactive',
			},
		] );

		await expect( resolvePluginBasePath( requestUtils ) ).rejects.toThrow(
			/not active \(status: "inactive"\)/
		);
	} );

	test( 'refuses when /wp/v2/plugins lists no plugin with the expected text domain', async () => {
		const requestUtils = fakeRequestUtils( [
			{
				plugin: 'hello-dolly/hello',
				textdomain: 'hello-dolly',
				status: 'active',
			},
		] );

		await expect( resolvePluginBasePath( requestUtils ) ).rejects.toThrow(
			/lists no plugin with text domain "seocart"/
		);
	} );
} );

test.describe( 'measureResponseBytes()', () => {
	let server: Server;
	let baseURL: string;
	// A large, compressible, exactly-known payload: gzip's ratio on it is high enough that the
	// transferred (compressed) size this proves against is clearly smaller than the decoded
	// text, not merely equal to it by coincidence.
	const decoded = Buffer.from( 'x'.repeat( 20_000 ), 'utf8' );
	const compressed = gzipSync( decoded );

	test.beforeAll( async () => {
		server = createServer( ( _request, response ) => {
			// No Content-Length is set: Node's http module then sends the body chunked, the
			// exact case response.headers()['content-length'] cannot answer and the one
			// measureResponseBytes() is written to handle instead.
			response.writeHead( 200, {
				'content-encoding': 'gzip',
				'content-type': 'text/plain; charset=utf-8',
			} );
			response.end( compressed );
		} );
		await new Promise< void >( ( resolveListening ) =>
			server.listen( 0, '127.0.0.1', resolveListening )
		);
		const address = server.address();
		if ( address === null || typeof address === 'string' ) {
			throw new Error( 'The throwaway server did not report a port.' );
		}
		baseURL = `http://127.0.0.1:${ address.port }/`;
	} );

	test.afterAll( async () => {
		await new Promise< void >( ( resolveClosed, reject ) =>
			server.close( ( error ) =>
				error ? reject( error ) : resolveClosed()
			)
		);
	} );

	test( "reads a known file's transferred (compressed) size from a chunked response with no content-length header", async ( {
		page,
	} ) => {
		const response = await page.goto( baseURL );
		expect(
			response,
			'The throwaway server did not answer.'
		).not.toBeNull();
		expect(
			response!.headers()[ 'content-length' ],
			'This response now carries Content-Length; the chunked case this proof depends on no longer holds.'
		).toBeUndefined();

		const measured = await measureResponseBytes( response! );

		// At least the compressed body itself; Chrome's encoded-length figure also counts the
		// chunk-framing bytes (the hex size, the CRLFs, the terminating chunk) the compressed
		// bytes actually travelled inside, so "at least" rather than "equal to" is the correct
		// proof, not a looser stand-in for it.
		expect( measured ).toBeGreaterThanOrEqual( compressed.length );
		// The proof this fixture measures the transferred (compressed) size, not the decoded
		// one: gzip shrank 20,000 bytes of one repeated character by more than half, and even
		// with chunk framing added back the transferred size stays far below the decoded size.
		expect( measured ).toBeLessThan( decoded.length / 2 );
	} );

	test( 'clamps a negative encoded size (a cached response) to 0, not a negative byte count', async () => {
		const cachedResponse = {
			request: () => ( {
				sizes: async () => ( {
					requestBodySize: 0,
					requestHeadersSize: 0,
					// Chrome reports this for a response it served from its disk or
					// back-forward cache: no bytes actually crossed the network this time.
					responseBodySize: -451,
					responseHeadersSize: 0,
				} ),
			} ),
		} as unknown as Parameters< typeof measureResponseBytes >[ 0 ];

		await expect( measureResponseBytes( cachedResponse ) ).resolves.toBe(
			0
		);
	} );
} );
