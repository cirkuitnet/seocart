/**
 * The payment section spec: the checkout's payment host, built and served by the site, with a fake
 * island for the stand-in gateway, on the payment fixture page (support/payments-page.ts).
 *
 * Every test is a guest's: a browser context with no session, whose cart is built through the
 * page's own request context, so the page shares its cart cookie. The spec creates a product with
 * stock as the administrator and trashes it afterwards. Each test records every request the page
 * sends and prints the Store API's on a failure, or always when SEOCART_E2E_LEDGER=1.
 *
 * The Store API allows one client five placements and resumes an hour (checkout.place), and every
 * test here is the same client. A case sends at most three, so each case passes on a site whose
 * rate counters are empty; a run of the whole spec needs them emptied between the cases.
 *
 * The thirteen cases:
 *
 *  1. an island registers once whether its script runs before or after the module; the ready event
 *     fires once; a second island for the same id is refused with a warning;
 *  2. a method that needs an island and has none is shown disabled, with a warning, until one
 *     registers late;
 *  3. an approval hands the order over once, its key in no URL, and the same key answers the same
 *     order;
 *  4. a decline is shown in the island, and the next attempt is approved with a fresh key;
 *  5. the island completes the step the stand-in asks for, and the resume approves; a reload in
 *     the middle of the step finds the key, and the same request takes the step again;
 *  6. an island that takes no step leaves the host to send the shopper to the stand-in's address,
 *     which carries the payment's uuid alone; back on the page with it, the resume approves and
 *     the kept placement gives the order key back;
 *  7. a webhook settles the payment before the shopper is back: the resume answers duplicate;
 *  8. a change of the amount or of the currency mounts the island again, once; a version that
 *     moves with the same amount does not;
 *  9. a cart changed behind the host is stale: the island is told nothing, and the next attempt is
 *     approved;
 * 10. a placement whose answer is lost is sent again with the same key, and one order is placed;
 * 11. the page sends the Store API the host's writes and no other;
 * 12. the payment section passes axe idle, mounted and declined;
 * 13. the module the site serves is within its budget.json ceiling.
 *
 * The stand-in answers every step with a redirect to the site's address, so the step of a
 * provider's script (`sdk`) is proved by the module's own unit tests and by a payment plugin's
 * browser test, not here. The currency of case 8 is the one tests/E2E/seed.php enables, named in
 * SEOCART_E2E_CURRENCY; without it that step is skipped, or fails when seeds are required.
 */

import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { APIRequestContext, Page } from '@playwright/test';
import { test, expect } from '../fixtures';
import { describeAssetByteReport } from '../fixtures/asset-bytes';
import { fileBudgetBytes } from '../support/budget';
import { siteBaseURL } from '../support/environment';
import {
	FIXTURE_PATH,
	expectNoSecretInUrls,
	expectOnlyHostWrites,
	expectRemountOn,
	hostEvents,
	hostState,
	islandScriptUrl,
	placedOrders,
	restUrlOf,
	servePaymentsPage,
	storeRequests,
	waitForHost,
	type IslandScript,
	type PaymentMethodConfig,
	type PlacedOrder,
	type RequestLedger,
} from '../support/payments-page';

/** The fake island's options, as fake-island.js reads them. */
interface FakeIslandOptions {
	token?: string | string[];
	action?: 'completed' | 'failed' | 'left' | 'decline';
	hold?: boolean;
	mutate?: boolean;
}

/** One call made to the fake island. */
interface FakeIslandCall {
	name: string;
	ctx?: { amountMinor: number; currency: string; cartVersion: number };
	type?: string;
	handle?: string | null;
	resume?: { intentUuid: string; returnUrl: string };
	error?: { code: string; message: string };
}

declare global {
	interface Window {
		__fakeIslandOptions?: FakeIslandOptions;
		__fakeIsland?: { calls: FakeIslandCall[]; release?: () => void };
	}
}

/** The fake island's source. */
const FAKE_ISLAND = readFileSync(
	resolve( __dirname, '..', 'support', 'fake-island.js' ),
	'utf8'
);

/** The Store API's namespace. */
const STORE = '/seocart/store/v1';

/** The header every Store API write carries. */
const STORE_HEADER = { 'X-SEOCart-Store': '1' };

/** The argument the stand-in's return address carries the payment's uuid in. */
const RETURN_ARG = 'seocart_payment';

/** The stand-in's webhook signing secret: a published constant, since the stand-in moves no money. */
const STUB_WEBHOOK_SECRET = 'stub-webhook-signing-secret';

/** A uuid. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** The product's price, in minor units. */
const PRICE = 1999;

/** A currency the site under test sells in besides its base currency, if any. */
const PLANTED_CURRENCY = ( process.env.SEOCART_E2E_CURRENCY ?? '' ).trim();

/** Set by continuous integration: a missing seed then fails the test that needs it. */
const SEEDS_REQUIRED = '1' === process.env.SEOCART_E2E_REQUIRE_SEEDS;

/** Returns the site's home URL, with its trailing slash. */
function home(): string {
	return String( siteBaseURL() ).replace( /\/?$/, '/' );
}

/**
 * Returns the URL of a Store API route.
 *
 * @param route The route under the namespace, such as `/cart`.
 */
function storeUrl( route: string ): string {
	return restUrlOf( home(), `${ STORE }${ route }` );
}

/**
 * Returns the stand-in as the payment configuration lists it.
 *
 * @param island Optional. Whether the configuration says the method needs an island. Default false.
 */
function stubMethod( island = false ): PaymentMethodConfig {
	return {
		id: 'stub',
		label: 'Test payment',
		mode: 'test',
		island,
		settings: {},
		currencies: [ 'USD', 'GBP', 'EUR' ],
		instructions: null,
		paysOnDelivery: false,
	};
}

/** A method paid outside any provider, shown with its instructions. */
const BANK: PaymentMethodConfig = {
	id: 'bank_transfer',
	label: 'Bank transfer',
	mode: 'test',
	island: false,
	settings: {},
	currencies: [ 'USD', 'GBP', 'EUR' ],
	instructions: 'Pay by transfer, quoting your order number.',
	paysOnDelivery: false,
};

/**
 * Writes the cart's checkout: the addresses, and the stand-in as its payment method.
 *
 * @param request The page's request context.
 * @param version The cart's version.
 */
async function writeCheckout(
	request: APIRequestContext,
	version: number
): Promise< void > {
	const address = {
		country: 'GB',
		first_name: 'Ada',
		last_name: 'Lovelace',
		line1: "12 St James's Square",
		city: 'London',
		postcode: 'SW1Y 4JH',
	};
	const written = await request.put( storeUrl( '/checkout' ), {
		headers: STORE_HEADER,
		data: {
			cart_version: version,
			billing_address: { ...address, email: 'ada@example.com' },
			shipping_address: address,
			payment_method_key: 'stub',
		},
	} );

	expect( written.status(), await written.text() ).toBe( 200 );
}

/**
 * Returns the cart's version, read through the page's request context.
 *
 * @param request The page's request context.
 */
async function cartVersion( request: APIRequestContext ): Promise< number > {
	return ( await ( await request.get( storeUrl( '/cart' ) ) ).json() )
		.version;
}

/**
 * Adds units of the product to the page's cart.
 *
 * @param request   The page's request context.
 * @param variantId The product's variant.
 * @param quantity  How many.
 */
async function addLine(
	request: APIRequestContext,
	variantId: number,
	quantity: number
): Promise< void > {
	const version = await cartVersion( request );
	const added = await request.post( storeUrl( '/cart/lines' ), {
		headers: STORE_HEADER,
		data: {
			lines: [ { variant_id: variantId, quantity } ],
			...( version > 0 ? { cart_version: version } : {} ),
		},
	} );

	expect( added.status(), await added.text() ).toBe( 200 );
}

/**
 * Builds the guest's cart: one unit of the product, and a complete checkout paid with the stand-in.
 *
 * @param page      The page, whose request context shares its cookies.
 * @param variantId The product's variant.
 */
async function readyCart( page: Page, variantId: number ): Promise< void > {
	await addLine( page.request, variantId, 1 );
	await writeCheckout( page.request, await cartVersion( page.request ) );
}

/**
 * Serves the fixture page with the fake island, and opens it.
 *
 * @param page           The page.
 * @param pluginBasePath The plugin's own directory.
 * @param setup          What the page holds.
 * @param setup.methods  Optional. The payment methods; the stand-in by default.
 * @param setup.at       Optional. Where each copy of the fake island loads; `before` by default.
 * @param setup.options  Optional. The fake island's options.
 * @return The page's address.
 */
async function openPage(
	page: Page,
	pluginBasePath: string,
	setup: {
		methods?: PaymentMethodConfig[];
		at?: IslandScript[ 'at' ] | IslandScript[ 'at' ][];
		options?: FakeIslandOptions;
	} = {}
): Promise< string > {
	await page.addInitScript( ( options ) => {
		window.__fakeIslandOptions = options;
	}, setup.options ?? {} );

	const address = await servePaymentsPage( page, {
		baseURL: home(),
		pluginBasePath,
		config: {
			contract: '0.1.0',
			restRoot: `${ home() }?rest_route=/`,
			returnQueryArg: RETURN_ARG,
			locale: 'en_GB',
			methods: setup.methods ?? [ stubMethod() ],
		},
		islands: [ setup.at ?? 'before' ]
			.flat()
			.map( ( at ) => ( { source: FAKE_ISLAND, at } ) ),
	} );

	await page.goto( address );
	await waitForHost( page );

	return address;
}

/**
 * Returns the calls made to the fake island on the page.
 *
 * @param page The page.
 */
function islandCalls( page: Page ): Promise< FakeIslandCall[] > {
	return page.evaluate( () => window.__fakeIsland?.calls ?? [] );
}

/**
 * Returns the names of the calls made to the fake island, in order.
 *
 * @param page The page.
 */
async function callNames( page: Page ): Promise< string[] > {
	return ( await islandCalls( page ) ).map( ( call ) => call.name );
}

/**
 * Has the host place the order, and waits for the flow to rest.
 *
 * @param page The page.
 */
async function place( page: Page ): Promise< void > {
	await page.evaluate( () => window.__seocartPayments.host?.place() );
}

/**
 * Waits until the host has handed over an order, and returns it.
 *
 * @param page The page.
 */
async function awaitPlaced( page: Page ): Promise< PlacedOrder > {
	await expect
		.poll( async () => ( await placedOrders( page ) ).length )
		.toBe( 1 );

	return ( await placedOrders( page ) )[ 0 ];
}

/**
 * Hands the host the cart as the Store API has it now.
 *
 * @param page The page.
 */
async function updateHost( page: Page ): Promise< void > {
	await page.evaluate( async () => {
		const state = window.__seocartPayments;

		state.host?.update( await state.readCart() );
	} );
}

/**
 * Returns the placements and resumes the page sent, in order: route, idempotency key, status.
 *
 * @param ledger What the page sent.
 */
function hostWrites(
	ledger: RequestLedger
): Array< [ string, string | null, number ] > {
	return ledger.store
		.filter( ( entry ) => 'POST' === entry.method )
		.map( ( entry ): [ string, string | null, number ] => [
			entry.route,
			entry.idempotencyKey,
			entry.status,
		] );
}

/**
 * Skips the running test when the site has no seed for it, or fails it when seeds are required.
 *
 * @param value   What the seed names; empty when it did not run.
 * @param message What to plant.
 */
function needsSeed( value: string, message: string ): void {
	if ( SEEDS_REQUIRED ) {
		expect( value, message ).not.toBe( '' );
	} else {
		test.skip( '' === value, message );
	}
}

test.describe( 'The payment section, as a guest', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	let product: { id: number; variantId: number };
	let ledger: RequestLedger;

	test.beforeAll( async ( { requestUtils } ) => {
		const created = await requestUtils.rest< {
			id: number;
			seocart: { variant_id: number };
		} >( {
			method: 'POST',
			path: '/wp/v2/seocart-products',
			data: {
				title: 'Payment spec: a product a guest pays for',
				status: 'publish',
				seocart: { sku: `E2E-PAY-${ Date.now() }`, price_minor: PRICE },
			},
		} );

		await requestUtils.rest( {
			method: 'POST',
			path: `/seocart/v1/stock-items/${ created.seocart.variant_id }/adjustments`,
			data: { delta: 200, reason: 'received' },
		} );

		product = { id: created.id, variantId: created.seocart.variant_id };
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// The trash, not a delete: the orders' allocations keep the variant from being deleted.
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/seocart-products/${ product.id }`,
		} );
	} );

	test.beforeEach( async ( { page } ) => {
		ledger = storeRequests( page );
	} );

	test.afterEach( async ( {}, testInfo ) => {
		if (
			testInfo.status !== testInfo.expectedStatus ||
			'1' === process.env.SEOCART_E2E_LEDGER
		) {
			// eslint-disable-next-line no-console
			console.log( ledger.describe() );
		}
	} );

	for ( const at of [ 'before', 'after' ] as const ) {
		test( `(1) an island whose script runs ${ at } the module registers once, and the ready event fires once`, async ( {
			page,
			pluginBasePath,
		} ) => {
			await readyCart( page, product.variantId );
			await openPage( page, pluginBasePath, { at } );

			await expect.poll( () => callNames( page ) ).toEqual( [ 'mount' ] );
			expect(
				await page.evaluate( () => ( {
					ids: window.seocart?.payments.ids(),
					queue: window.seocart?.payments.queue.length,
					refused: window.seocart?.payments.refused(),
					ready: window.__seocartPayments.ready,
				} ) )
			).toEqual( { ids: [ 'stub' ], queue: 0, refused: [], ready: 1 } );
		} );
	}

	test( '(1) a second island for the same id is refused with a warning, and the first is kept', async ( {
		page,
		pluginBasePath,
	} ) => {
		const warnings: string[] = [];

		page.on( 'console', ( message ) => {
			if ( 'warning' === message.type() ) {
				warnings.push( message.text() );
			}
		} );

		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath, { at: [ 'before', 'after' ] } );

		await expect.poll( () => callNames( page ) ).toEqual( [ 'mount' ] );
		expect(
			await page.evaluate( () => window.seocart?.payments.refused() )
		).toEqual( [
			{
				id: 'stub',
				reason: 'duplicate: an island with this id is registered',
			},
		] );
		expect( warnings ).toContain(
			'seocart.payments: island "stub" refused: duplicate: an island with this id is registered'
		);
	} );

	test( '(2) a method that needs an island and has none is shown disabled, with a warning, until one registers late', async ( {
		page,
		pluginBasePath,
	} ) => {
		const warnings: string[] = [];

		page.on( 'console', ( message ) => {
			if ( 'warning' === message.type() ) {
				warnings.push( message.text() );
			}
		} );

		await readyCart( page, product.variantId );

		const address = await openPage( page, pluginBasePath, {
			methods: [ stubMethod( true ) ],
			at: 'none',
		} );
		const choice = page.locator( '.seocart-choice[data-method="stub"]' );

		await expect( choice ).toHaveAttribute(
			'data-unavailable',
			'island_missing'
		);
		await expect( choice.getByRole( 'radio' ) ).toBeDisabled();
		expect( warnings ).toContain(
			'seocart.payments: method "stub" needs an island and none is registered: it is shown unavailable.'
		);

		await place( page );

		expect( ( await hostState( page ) )?.error ).toEqual( {
			code: 'no_method',
		} );
		expect( hostWrites( ledger ) ).toEqual( [] );

		await page.addScriptTag( { url: islandScriptUrl( address, 0 ) } );

		await expect( choice ).not.toHaveAttribute( 'data-unavailable' );
		await expect( choice.getByRole( 'radio' ) ).toBeChecked();
		await expect.poll( () => callNames( page ) ).toEqual( [ 'mount' ] );
		expect( await hostEvents( page ) ).toContainEqual( {
			type: 'select',
			id: 'stub',
		} );
	} );

	test( '(3) an approval hands the order over once, its key in no URL, and the same key answers the same order', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath );
		await page.getByRole( 'button', { name: 'Place order' } ).click();

		const order = await awaitPlaced( page );

		expect( order ).toMatchObject( {
			outcome: 'approved',
			status: 'processing',
			paymentStatus: 'authorized',
			intentUuid: null,
		} );
		expect( order.orderKey ).toEqual( expect.any( String ) );
		expect( order.orderUuid ).toMatch( UUID );

		const [ sent ] = ledger.store.filter(
			( entry ) => 'POST' === entry.method
		);

		expect( sent.idempotencyKey ).toMatch( UUID );
		expectOnlyHostWrites( ledger );
		expectNoSecretInUrls( ledger, [ String( order.orderKey ) ] );
		expect(
			await page.evaluate( () =>
				window.sessionStorage.getItem( 'seocart:placement' )
			)
		).toBeNull();

		// The same request with the same key, as a client that lost the answer sends it: the same order.
		const again = await page.request.post( storeUrl( '/checkout' ), {
			headers: {
				...STORE_HEADER,
				'Idempotency-Key': String( sent.idempotencyKey ),
			},
			data: JSON.parse( sent.body ),
		} );

		expect( again.status() ).toBe( 200 );
		expect( ( await again.json() ).order_uuid ).toBe( order.orderUuid );
	} );

	test( '(4) a decline is shown in the island, and the next attempt is approved with a fresh key', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath, {
			options: { token: [ 'stub:decline', 'stub:approve' ] },
		} );
		await place( page );

		expect( await hostState( page ) ).toEqual( {
			selected: 'stub',
			phase: 'idle',
			error: { code: 'declined', detail: 'checkout.payment_declined' },
		} );
		expect( await callNames( page ) ).toEqual( [
			'mount',
			'confirm',
			'showError',
		] );
		expect( ( await islandCalls( page ) )[ 2 ].error?.code ).toBe(
			'declined'
		);
		await expect(
			page.locator(
				'.seocart-island[data-gateway="stub"] .seocart-notice.is-error'
			)
		).toBeVisible();
		expect( await hostEvents( page ) ).toContainEqual( {
			type: 'stale',
			detail: { reason: 'declined', code: 'checkout.payment_declined' },
		} );

		await place( page );

		expect( ( await awaitPlaced( page ) ).outcome ).toBe( 'approved' );
		expect( await callNames( page ) ).toEqual( [
			'mount',
			'confirm',
			'showError',
			'confirm',
		] );

		const keys = hostWrites( ledger ).map( ( [ , key ] ) => key );

		expect( keys ).toHaveLength( 2 );
		expect( keys[ 1 ] ).not.toBe( keys[ 0 ] );
		expectOnlyHostWrites( ledger );
	} );

	test( '(5) the island completes the step, and the resume approves; a reload mid-step sends the same request again', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );

		// The shopper is in the middle of the step: the island says the page is being left.
		const address = await openPage( page, pluginBasePath, {
			options: { token: 'stub:requires_action', action: 'left' },
		} );

		await place( page );

		const [ first ] = ( await islandCalls( page ) ).filter(
			( call ) => 'action' === call.name
		);
		const intentUuid = String( first.resume?.intentUuid );

		expect( intentUuid ).toMatch( UUID );
		expect( first ).toMatchObject( {
			type: 'redirect',
			handle: `stub_cs_${ intentUuid }`,
			resume: {
				intentUuid,
				returnUrl: `${ address }?${ RETURN_ARG }=${ intentUuid }`,
			},
		} );
		expect( ( await hostState( page ) )?.phase ).toBe( 'acting' );

		// The page is reloaded: the tab keeps the key, and the island now completes the step.
		await page.addInitScript( () => {
			window.__fakeIslandOptions = {
				token: 'stub:requires_action',
				action: 'completed',
			};
		} );
		await page.reload();
		await waitForHost( page );
		await place( page );

		const order = await awaitPlaced( page );
		const [ second ] = ( await islandCalls( page ) ).filter(
			( call ) => 'action' === call.name
		);

		expect( second ).toMatchObject( {
			type: 'redirect',
			handle: `stub_cs_${ intentUuid }`,
			resume: { intentUuid },
		} );
		expect( await callNames( page ) ).toEqual( [ 'mount', 'action' ] );
		expect( order ).toMatchObject( {
			outcome: 'approved',
			status: 'processing',
			paymentStatus: 'authorized',
			intentUuid,
		} );
		expect( order.orderKey ).toEqual( expect.any( String ) );

		const writes = hostWrites( ledger );

		expect( writes.map( ( [ route ] ) => route ) ).toEqual( [
			`${ STORE }/checkout`,
			`${ STORE }/checkout`,
			`${ STORE }/checkout/resume`,
		] );
		expect( writes[ 1 ][ 1 ] ).toBe( writes[ 0 ][ 1 ] );
		expect(
			JSON.parse(
				ledger.store.filter( ( entry ) =>
					entry.route.endsWith( '/resume' )
				)[ 0 ].body
			)
		).toEqual( {
			intent_uuid: intentUuid,
		} );
		expectOnlyHostWrites( ledger );
		expectNoSecretInUrls(
			ledger,
			[ String( order.orderKey ) ],
			[ String( first.resume?.returnUrl ) ]
		);
	} );

	test( '(6) the host sends the shopper to the stand-in’s address, and back on the page the resume approves and the order key comes back', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );

		const address = await openPage( page, pluginBasePath, {
			options: { token: 'stub:requires_action', action: 'decline' },
		} );

		// The stand-in's page is the site's own address with the uuid: answered here, nothing loaded.
		await page.route(
			( url ) =>
				url.href.startsWith( home() ) &&
				url.searchParams.has( RETURN_ARG ) &&
				! url.pathname.endsWith( FIXTURE_PATH ),
			( route ) =>
				route.fulfill( {
					contentType: 'text/html; charset=utf-8',
					body: '<!doctype html><title>The provider</title>',
				} )
		);
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await page.waitForURL(
			( url ) =>
				url.searchParams.has( RETURN_ARG ) &&
				! url.pathname.endsWith( FIXTURE_PATH )
		);

		const landed = new URL( page.url() );
		const intentUuid = String( landed.searchParams.get( RETURN_ARG ) );

		expect( `${ landed.origin }${ landed.pathname }` ).toBe( home() );
		expect( [ ...landed.searchParams.keys() ] ).toEqual( [ RETURN_ARG ] );
		expect( intentUuid ).toMatch( UUID );

		// The provider sends the shopper back to the checkout, with the uuid.
		await page.goto( `${ address }?${ RETURN_ARG }=${ intentUuid }` );
		await waitForHost( page );

		const order = await awaitPlaced( page );

		expect( order ).toMatchObject( {
			outcome: 'approved',
			status: 'processing',
			intentUuid,
		} );
		expect( order.orderKey ).toEqual( expect.any( String ) );
		expect( page.url() ).toBe( address );

		const writes = hostWrites( ledger );

		expect( writes.map( ( [ route ] ) => route ) ).toEqual( [
			`${ STORE }/checkout`,
			`${ STORE }/checkout/resume`,
			`${ STORE }/checkout`,
		] );
		expect( writes[ 2 ][ 1 ] ).toBe( writes[ 0 ][ 1 ] );
		expect(
			await page.evaluate( () =>
				window.sessionStorage.getItem( 'seocart:placement' )
			)
		).toBeNull();
		expectOnlyHostWrites( ledger );
		expectNoSecretInUrls( ledger, [ String( order.orderKey ) ] );
	} );

	test( '(7) a webhook that settles the payment first leaves the resume to answer duplicate', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath, {
			options: {
				token: 'stub:requires_action',
				action: 'completed',
				hold: true,
			},
		} );
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect.poll( () => callNames( page ) ).toContain( 'action' );

		const action = ( await islandCalls( page ) ).find(
			( call ) => 'action' === call.name
		);
		const intentUuid = String( action?.resume?.intentUuid );
		const placement = JSON.parse(
			ledger.store[ ledger.store.length - 1 ].body
		);
		const created = Math.floor( Date.now() / 1000 );
		const body = JSON.stringify( {
			id: `evt-e2e-${ intentUuid }`,
			type: 'authorization.approved',
			created,
			data: {
				intent_uuid: intentUuid,
				amount_minor: placement.grand_total_minor,
				currency: placement.currency,
				provider_object_id: `stub-ch-${ intentUuid }`,
				provider_intent_id: `stub-pi-requires_action-${ intentUuid }`,
			},
		} );
		const signature = createHmac( 'sha256', STUB_WEBHOOK_SECRET )
			.update( `${ created }.${ body }` )
			.digest( 'hex' );
		const delivered = await page.request.post(
			restUrlOf( home(), '/seocart/v1/webhooks/stub/test' ),
			{
				headers: {
					'Content-Type': 'application/json',
					'X-Stub-Signature': `t=${ created },v1=${ signature }`,
				},
				data: body,
			}
		);

		expect( delivered.status(), await delivered.text() ).toBeLessThan(
			300
		);

		await page.evaluate( () => window.__fakeIsland?.release?.() );

		expect( await awaitPlaced( page ) ).toMatchObject( {
			outcome: 'duplicate',
			status: 'processing',
			paymentStatus: 'authorized',
			intentUuid,
		} );
		expect( hostWrites( ledger ).map( ( [ route ] ) => route ) ).toEqual( [
			`${ STORE }/checkout`,
			`${ STORE }/checkout/resume`,
		] );
	} );

	test( '(8) the island is mounted again once when the amount or the currency changes, and not when only the version moves', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath );
		await expect.poll( () => callNames( page ) ).toEqual( [ 'mount' ] );

		await expectRemountOn( page, 'stub', async () => {
			await addLine( page.request, product.variantId, 2 );
			await updateHost( page );
		} );

		const doubled = await page.evaluate( () =>
			window.__seocartPayments.readCart()
		);

		expect( await callNames( page ) ).toEqual( [
			'mount',
			'unmount',
			'mount',
		] );
		expect( ( await islandCalls( page ) )[ 2 ].ctx ).toMatchObject( {
			amountMinor: doubled.amountMinor,
			cartVersion: doubled.version,
		} );

		await expectRemountOn(
			page,
			'stub',
			async () => {
				await writeCheckout(
					page.request,
					await cartVersion( page.request )
				);
				await updateHost( page );
			},
			0
		);

		expect( await callNames( page ) ).toEqual( [
			'mount',
			'unmount',
			'mount',
		] );

		needsSeed(
			PLANTED_CURRENCY,
			'Run tests/E2E/seed.php on the site under test and name the currency it prints in SEOCART_E2E_CURRENCY.'
		);

		await expectRemountOn( page, 'stub', async () => {
			const switched = await page.request.post(
				storeUrl( '/cart/currency' ),
				{
					headers: STORE_HEADER,
					data: {
						cart_version: await cartVersion( page.request ),
						currency: PLANTED_CURRENCY,
					},
				}
			);

			expect( switched.status(), await switched.text() ).toBe( 200 );
			await updateHost( page );
		} );

		expect( await callNames( page ) ).toEqual( [
			'mount',
			'unmount',
			'mount',
			'unmount',
			'mount',
		] );
		expect( ( await islandCalls( page ) )[ 4 ].ctx?.currency ).toBe(
			PLANTED_CURRENCY
		);
	} );

	test( '(9) a cart changed behind the host is stale: the island is told nothing, and the next attempt is approved', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath );

		// Another tab writes the checkout again: the version moves on behind the host.
		await writeCheckout( page.request, await cartVersion( page.request ) );
		await place( page );

		expect( ( await hostState( page ) )?.error ).toEqual( {
			code: 'stale',
			detail: 'cart.version_stale',
		} );
		expect( await callNames( page ) ).toEqual( [ 'mount', 'confirm' ] );
		expect( await hostEvents( page ) ).toContainEqual( {
			type: 'stale',
			detail: { reason: 'stale', code: 'cart.version_stale' },
		} );

		await place( page );

		expect( ( await awaitPlaced( page ) ).outcome ).toBe( 'approved' );
		expect( await callNames( page ) ).toEqual( [
			'mount',
			'confirm',
			'confirm',
		] );

		const writes = hostWrites( ledger );

		expect( writes.map( ( [ , , status ] ) => status ) ).toEqual( [
			409, 200,
		] );
		expect( writes[ 1 ][ 1 ] ).not.toBe( writes[ 0 ][ 1 ] );
	} );

	test( '(10) a placement whose answer is lost is sent again with the same key, and one order is placed', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath );

		let lost: { order_uuid?: string } = {};

		// The store places the order; its answer never reaches the page.
		await page.route(
			( url ) =>
				`${ STORE }/checkout` ===
				new URL( url ).searchParams.get( 'rest_route' ),
			async ( route ) => {
				if ( 'POST' !== route.request().method() ) {
					await route.fallback();
					return;
				}

				lost = await ( await route.fetch() ).json();
				await route.abort( 'failed' );
			},
			{ times: 1 }
		);
		await place( page );

		expect( lost.order_uuid ).toMatch( UUID );
		expect( ( await hostState( page ) )?.error ).toEqual( {
			code: 'network',
		} );

		await page.evaluate( () => window.__seocartPayments.host?.retry() );

		const order = await awaitPlaced( page );
		const keys = hostWrites( ledger ).map( ( [ , key ] ) => key );

		expect( order ).toMatchObject( {
			outcome: 'approved',
			orderUuid: lost.order_uuid,
		} );
		expect( keys ).toHaveLength( 2 );
		expect( keys[ 1 ] ).toBe( keys[ 0 ] );
		expect( await callNames( page ) ).toEqual( [ 'mount', 'confirm' ] );
	} );

	test( '(11) the page sends the Store API the host’s writes and no other', async ( {
		page,
		pluginBasePath,
	} ) => {
		await readyCart( page, product.variantId );
		await openPage( page, pluginBasePath, { options: { mutate: false } } );
		await place( page );

		expectOnlyHostWrites( ledger );
		expect( ( await awaitPlaced( page ) ).outcome ).toBe( 'approved' );
		expect(
			ledger.store.map(
				( entry ) => `${ entry.method } ${ entry.route }`
			)
		).toEqual( [ `GET ${ STORE }/cart`, `POST ${ STORE }/checkout` ] );
	} );

	test( '(12) the payment section passes axe idle, mounted and declined', async ( {
		page,
		pluginBasePath,
		expectNoAccessibilityViolations,
	} ) => {
		await readyCart( page, product.variantId );

		const address = await openPage( page, pluginBasePath, {
			methods: [ stubMethod(), BANK ],
			at: 'none',
			options: { token: 'stub:decline' },
		} );

		await expect(
			page.locator( '.seocart-choice.is-selected' )
		).toHaveCount( 1 );
		await expectNoAccessibilityViolations( {
			include: '.seocart-payments',
		} );

		await page.addScriptTag( { url: islandScriptUrl( address, 0 ) } );
		await expect(
			page.locator( '.seocart-island[data-gateway="stub"] input' )
		).toBeVisible();
		await expectNoAccessibilityViolations( {
			include: '.seocart-payments',
		} );

		await place( page );
		await expect(
			page.locator( '.seocart-notice.is-error' )
		).toBeVisible();
		await expectNoAccessibilityViolations( {
			include: '.seocart-payments',
		} );
	} );

	test( '(13) the module the site serves is within its budget.json ceiling', async ( {
		page,
		pluginBasePath,
		assetBytes,
	} ) => {
		await readyCart( page, product.variantId );

		const address = await servePaymentsPage( page, {
			baseURL: home(),
			pluginBasePath,
			config: {
				contract: '0.1.0',
				restRoot: `${ home() }?rest_route=/`,
				returnQueryArg: RETURN_ARG,
				locale: 'en_GB',
				methods: [ BANK ],
			},
		} );
		const report = await assetBytes.measure(
			'payment fixture',
			async () => {
				await page.goto( address );
				await waitForHost( page );
			}
		);
		const budget = fileBudgetBytes( 'storefront/payments.js' ) ?? 0;
		const description = describeAssetByteReport( report, budget );

		// eslint-disable-next-line no-console
		console.log( description );

		expect(
			budget,
			'budget.json has a rule for the module.'
		).toBeGreaterThan( 0 );
		expect(
			report.hits.map( ( hit ) => new URL( hit.url ).pathname ),
			description
		).toEqual( [ `${ pluginBasePath }build/storefront/payments.js` ] );
		expect( report.hits[ 0 ].bytes, description ).toBeGreaterThan( 0 );
		expect( report.hits[ 0 ].bytes, description ).toBeLessThanOrEqual(
			budget
		);
	} );
} );
