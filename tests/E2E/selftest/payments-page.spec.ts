/**
 * The payment fixture's checks, proved without a site: the request ledger passes the host's two
 * writes and the fixture's read, and flags an island's write to the cart and a placement an island
 * sends itself; it prints the payment data's member names and never their values, and names the
 * trusted proxies on a 429; the secret scan finds a secret, a provider's handle and a card number,
 * and passes a page with none; the storage check passes a kept placement of seven members, and
 * flags anything more and a secret in any item of either storage; a client address is one of the
 * documentation range.
 *
 * The page is set with setContent() at a made-up origin whose every request is answered here, so
 * its requests are same-origin as on the fixture page, and nothing reaches a network. A stand-in
 * for the host's module sends the host's writes, as the module's own calls of `fetch`.
 */

import { test, expect, type Page } from '@playwright/test';
import {
	clientAddress,
	expectNoSecretInUrls,
	expectNothingSecretKept,
	expectOnlyHostWrites,
	hostWriteCounter,
	shownBody,
	storeRequests,
	storeRouteOf,
	type RequestLedger,
} from '../support/payments-page';

/** The made-up shop. */
const SHOP = 'https://shop.test/';

/** The stand-in for the host's module, at the address the fixture loads the module from. */
const HOST_MODULE = `${ SHOP }build/storefront/payments.js`;

/** The stand-in's source: it sends requests as the host does, each a call of `fetch` of its own. */
const HOST_SOURCE = `window.__hostSend = async ( shop, list ) => {
	for ( const [ path, method, headers, body ] of list ) {
		await fetch( shop + path, { method, headers, body } );
	}
};`;

/** A published test card number, the only kind a test may carry. */
const TEST_CARD = '4242424242424242';

/**
 * Sets a page at the shop's origin, and starts recording its requests.
 *
 * @param page The page.
 */
async function shopPage( page: Page ): Promise< RequestLedger > {
	await page.route( `${ SHOP }**`, ( route ) => {
		if ( HOST_MODULE === route.request().url() ) {
			return route.fulfill( {
				contentType: 'text/javascript',
				body: HOST_SOURCE,
			} );
		}

		return 'document' === route.request().resourceType()
			? route.fulfill( {
					contentType: 'text/html',
					body: '<!doctype html><title>Shop</title>',
				} )
			: route.fulfill( {
					// A request whose address says `limited` is answered as a rate-limited one.
					status: route.request().url().includes( 'limited' )
						? 429
						: 200,
					contentType: 'application/json',
					body: '{}',
				} );
	} );
	await page.goto( SHOP );

	const ledger = storeRequests( page );

	await page.setContent(
		'<!doctype html><title>Payment</title><p>Payment</p>'
	);
	await page.addScriptTag( { content: hostWriteCounter( HOST_MODULE ) } );
	await page.addScriptTag( { url: HOST_MODULE } );

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

/**
 * Sends requests from the stand-in for the host's module, in order.
 *
 * @param page     The page.
 * @param requests Each `[ path, method, headers, body ]`, the path under the shop.
 */
async function sendAsHost(
	page: Page,
	requests: Array< [ string, string, Record< string, string >, string? ] >
): Promise< void > {
	await page.evaluate(
		( { shop, list } ) =>
			(
				window as unknown as {
					__hostSend: (
						at: string,
						sent: typeof list
					) => Promise< void >;
				}
			 ).__hostSend( shop, list ),
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

		await sendAsHost( page, HOST );

		expect( ledger.store.map( ( entry ) => entry.route ) ).toEqual( [
			'/seocart/store/v1/cart',
			'/seocart/store/v1/checkout',
			'/seocart/store/v1/checkout/resume',
		] );
		await expectOnlyHostWrites( page, ledger );
		expectNoSecretInUrls( ledger, [ 'an-order-key' ] );
	} );

	test( 'flag an island’s write to the cart', async ( { page } ) => {
		const ledger = await shopPage( page );

		await sendAsHost( page, HOST );
		await send( page, [
			[
				'?rest_route=/seocart/store/v1/cart/lines',
				'POST',
				HEADERS,
				'{}',
			],
		] );

		await expect( expectOnlyHostWrites( page, ledger ) ).rejects.toThrow(
			/POST \/seocart\/store\/v1\/cart\/lines is neither one of the host's writes/
		);
	} );

	test( 'flag a placement an island sends itself, though it carries the header and a key', async ( {
		page,
	} ) => {
		const ledger = await shopPage( page );

		await sendAsHost( page, HOST );
		await send( page, [ HOST[ 1 ] ] );

		await expect( expectOnlyHostWrites( page, ledger ) ).rejects.toThrow(
			/The page sent 3 placements and resumes, the host 2 of them/
		);
	} );

	test( 'flag a placement without its key, and a write without the Store API header', async ( {
		page,
	} ) => {
		const keyless = await shopPage( page );

		await send( page, [
			[ '?rest_route=/seocart/store/v1/checkout', 'POST', HEADERS, '{}' ],
		] );

		await expect( expectOnlyHostWrites( page, keyless ) ).rejects.toThrow(
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

		await expect( expectOnlyHostWrites( page, headless ) ).rejects.toThrow(
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

	test( 'print the payment data’s member names, never their values, and name the trusted proxies on a 429', async ( {
		page,
	} ) => {
		const ledger = await shopPage( page );

		await send( page, [
			[
				'?rest_route=/seocart/store/v1/checkout&limited',
				'POST',
				{ ...HEADERS, 'Idempotency-Key': 'k' },
				'{"cart_version":3,"payment_data":{"payment_token":"tok_sandbox_value"}}',
			],
		] );

		const printed = ledger.describe();

		expect( printed ).toContain( '"payment_data":["payment_token"]' );
		expect( printed ).not.toContain( 'tok_sandbox_value' );
		expect( printed ).toContain( 'SEOCART_TRUSTED_PROXIES' );
		expect( shownBody( 'not json' ) ).toBe( '(a body that is not JSON)' );
		expect( shownBody( '{"intent_uuid":"u"}' ) ).toBe(
			'{"intent_uuid":"u"}'
		);
	} );

	test( 'pass a kept placement of seven members, and flag one that keeps more or a secret', async ( {
		page,
	} ) => {
		await shopPage( page );
		await expectNothingSecretKept( page );

		const keep = ( value: object ) =>
			page.evaluate(
				( text ) =>
					window.sessionStorage.setItem( 'seocart:placement', text ),
				JSON.stringify( value )
			);
		const seven = {
			key: 'k',
			cartVersion: 3,
			amountMinor: 1999,
			currency: 'GBP',
			gatewayId: 'stub',
			intentUuid: null,
			shopper: null,
		};

		await keep( seven );
		await expectNothingSecretKept( page, [ 'tok_sandbox_value' ] );

		await keep( {
			...seven,
			paymentData: { payment_token: 'tok_sandbox_value' },
		} );
		await expect( expectNothingSecretKept( page ) ).rejects.toThrow(
			/seven members and nothing else/
		);

		await keep( { ...seven, key: 'pi_1_secret_2' } );
		await expect( expectNothingSecretKept( page ) ).rejects.toThrow(
			/keeps a secret/
		);
	} );

	test( 'flag a secret, a provider’s handle or a card number in any item of either storage', async ( {
		page,
	} ) => {
		await shopPage( page );

		const store = ( storage: string, name: string, value: string ) =>
			page.evaluate(
				( item ) => {
					window.sessionStorage.clear();
					window.localStorage.clear();
					( 'local' === item.storage
						? window.localStorage
						: window.sessionStorage
					).setItem( item.name, item.value );
				},
				{ storage, name, value }
			);

		await store( 'local', 'island:state', 'ready' );
		await expectNothingSecretKept( page, [ 'tok_sandbox_value' ] );

		await store( 'session', 'island:state', 'pi_1_secret_2' );
		await expect( expectNothingSecretKept( page ) ).rejects.toThrow(
			/keeps a secret in sessionStorage, in the item "island:state"/
		);

		await store( 'local', 'island:token', 'tok_sandbox_value' );
		await expect(
			expectNothingSecretKept( page, [ 'tok_sandbox_value' ] )
		).rejects.toThrow(
			/keeps a secret in localStorage, in the item "island:token"/
		);

		await store( 'local', 'island:card', `{"number":"${ TEST_CARD }"}` );
		await expect( expectNothingSecretKept( page ) ).rejects.toThrow(
			/keeps a card number in localStorage/
		);
	} );

	test( 'give each client an address of the documentation range', () => {
		const first = clientAddress();

		expect( first ).toMatch( /^2001:db8:[0-9a-f]{4}:[0-9a-f]{4}::1$/ );
		expect(
			new Set( Array.from( { length: 20 }, clientAddress ) ).size
		).toBeGreaterThan( 1 );
	} );
} );
