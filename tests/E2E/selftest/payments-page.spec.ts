/**
 * The payment fixture's checks, proved without a site: the request ledger passes the host's two
 * writes and the fixture's read, and flags an island's write to the cart; the secret scan finds a
 * secret, a provider's handle and a card number, and passes a page with none.
 *
 * The page is set with setContent() at a made-up origin whose every request is answered here, so
 * its requests are same-origin as on the fixture page, and nothing reaches a network.
 */

import { test, expect, type Page } from '@playwright/test';
import {
	expectNoSecretInUrls,
	expectOnlyHostWrites,
	storeRequests,
	storeRouteOf,
	type RequestLedger,
} from '../support/payments-page';

/** The made-up shop. */
const SHOP = 'https://shop.test/';

/** A published test card number, the only kind a test may carry. */
const TEST_CARD = '4242424242424242';

/**
 * Sets a page at the shop's origin, and starts recording its requests.
 *
 * @param page The page.
 */
async function shopPage( page: Page ): Promise< RequestLedger > {
	await page.route( `${ SHOP }**`, ( route ) =>
		'document' === route.request().resourceType()
			? route.fulfill( {
					contentType: 'text/html',
					body: '<!doctype html><title>Shop</title>',
				} )
			: route.fulfill( { contentType: 'application/json', body: '{}' } )
	);
	await page.goto( SHOP );

	const ledger = storeRequests( page );

	await page.setContent(
		'<!doctype html><title>Payment</title><p>Payment</p>'
	);

	return ledger;
}

/**
 * Sends requests from the page, in order.
 *
 * @param page     The page.
 * @param requests Each `[ path, method, headers, body ]`, the path under the shop.
 */
async function send(
	page: Page,
	requests: Array< [ string, string, Record< string, string >, string? ] >
): Promise< void > {
	await page.evaluate(
		async ( { shop, list } ) => {
			for ( const [ path, method, headers, body ] of list ) {
				await fetch( shop + path, { method, headers, body } );
			}
		},
		{ shop: SHOP, list: requests }
	);
}

/** The Store API's header, and a JSON body's type. */
const HEADERS = { 'Content-Type': 'application/json', 'X-SEOCart-Store': '1' };

/** The host's two writes and the fixture's read of the cart. */
const HOST: Array< [ string, string, Record< string, string >, string? ] > = [
	[ '?rest_route=/seocart/store/v1/cart', 'GET', {} ],
	[
		'?rest_route=/seocart/store/v1/checkout',
		'POST',
		{
			...HEADERS,
			'Idempotency-Key': '0192a4b3-7c5d-4e8f-9a0b-1c2d3e4f5a6b',
		},
		'{"cart_version":3}',
	],
	[ '?rest_route=/seocart/store/v1/checkout/resume', 'POST', HEADERS, '{}' ],
];

test.describe( 'The payment fixture’s checks', () => {
	test( 'name the Store API route of a URL under either permalink structure', () => {
		expect(
			storeRouteOf(
				`${ SHOP }?rest_route=%2Fseocart%2Fstore%2Fv1%2Fcheckout`
			)
		).toBe( '/seocart/store/v1/checkout' );
		expect(
			storeRouteOf( `${ SHOP }wp-json/seocart/store/v1/checkout/resume` )
		).toBe( '/seocart/store/v1/checkout/resume' );
		expect( storeRouteOf( `${ SHOP }wp-json/wp/v2/posts` ) ).toBeNull();
		expect( storeRouteOf( 'not a URL' ) ).toBeNull();
	} );

	test( 'pass a page that sends the host’s two writes and reads the cart', async ( {
		page,
	} ) => {
		const ledger = await shopPage( page );

		await send( page, HOST );

		expect( ledger.store.map( ( entry ) => entry.route ) ).toEqual( [
			'/seocart/store/v1/cart',
			'/seocart/store/v1/checkout',
			'/seocart/store/v1/checkout/resume',
		] );
		expectOnlyHostWrites( ledger );
		expectNoSecretInUrls( ledger, [ 'an-order-key' ] );
	} );

	test( 'flag an island’s write to the cart', async ( { page } ) => {
		const ledger = await shopPage( page );

		await send( page, [
			...HOST,
			[
				'?rest_route=/seocart/store/v1/cart/lines',
				'POST',
				HEADERS,
				'{}',
			],
		] );

		expect( () => expectOnlyHostWrites( ledger ) ).toThrow(
			/POST \/seocart\/store\/v1\/cart\/lines is neither one of the host's writes/
		);
	} );

	test( 'flag a placement without its key, and a write without the Store API header', async ( {
		page,
	} ) => {
		const keyless = await shopPage( page );

		await send( page, [
			[ '?rest_route=/seocart/store/v1/checkout', 'POST', HEADERS, '{}' ],
		] );

		expect( () => expectOnlyHostWrites( keyless ) ).toThrow(
			/The placement carried no Idempotency-Key/
		);

		const headless = storeRequests( page );

		await send( page, [
			[
				'?rest_route=/seocart/store/v1/checkout/resume',
				'POST',
				{},
				'{}',
			],
		] );

		expect( () => expectOnlyHostWrites( headless ) ).toThrow(
			/carried no X-SEOCart-Store header/
		);
	} );

	test( 'flag a secret, a provider’s handle or a card number in a URL or a Store API body, and not a long number of another kind', async ( {
		page,
	} ) => {
		const timestamp = await shopPage( page );

		await send( page, [ [ '?_=1699999999999', 'GET', {} ] ] );

		expectNoSecretInUrls( timestamp, [ 'an-order-key' ] );

		expect( () =>
			expectNoSecretInUrls(
				timestamp,
				[],
				[ `${ SHOP }done?key=an-order-key` ]
			)
		).not.toThrow();
		expect( () =>
			expectNoSecretInUrls(
				timestamp,
				[ 'an-order-key' ],
				[ `${ SHOP }done?key=an-order-key` ]
			)
		).toThrow( /carries a secret/ );

		const handle = storeRequests( page );

		await send( page, [
			[
				'?rest_route=/seocart/store/v1/checkout/resume',
				'POST',
				HEADERS,
				'{"note":"pi_1_secret_2"}',
			],
		] );

		expect( () => expectNoSecretInUrls( handle, [] ) ).toThrow(
			/carries a provider's handle/
		);

		const card = storeRequests( page );

		await send( page, [ [ `?card=${ TEST_CARD }`, 'GET', {} ] ] );

		expect( () => expectNoSecretInUrls( card, [] ) ).toThrow(
			/carries a card number/
		);
	} );
} );
