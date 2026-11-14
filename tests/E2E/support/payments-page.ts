/**
 * The payment fixture: a page at the site's own origin that hosts the checkout's payment section
 * with the real, built module, and the checks every payment island's browser test makes of it.
 *
 * No template ships for it. servePaymentsPage() fulfils `<site>/seocart-payments-fixture/` with a
 * page composed from the configuration a test gives: the root with that configuration, the islands'
 * scripts before or after the module, the module itself as the site serves it from the plugin's
 * build, a Place button, and an inline module that creates the host and records what it reports on
 * `window.__seocartPayments`. Being the site's own origin, the page sends the cart's cookie and
 * the Store API's header as the checkout will, with no cross-origin exception.
 *
 * The checks hold every island to the host's contract:
 *
 * - storeRequests() records every request the page sends; expectOnlyHostWrites() fails when any
 *   Store API write is not the host's placement or resume, so an island that writes to the cart
 *   fails the test;
 * - expectNoSecretInUrls() fails when a URL or a Store API request carries a secret given, a
 *   provider's handle (`_secret_`) or a card number;
 * - expectRemountOn() counts the island's mounts across a change of the cart.
 *
 * It depends on `@playwright/test` alone, so a payment plugin's own browser test imports it from a
 * SEOCart checkout beside its own.
 */

import { expect, type Page, type Request } from '@playwright/test';

/** Where the fixture page is served, under the site's home URL. */
export const FIXTURE_PATH = 'seocart-payments-fixture/';

/** The Store API's namespace, as routes name it. */
const STORE_ROUTE = '/seocart/store/v1';

/** One payment method, as the payment configuration lists it. */
export interface PaymentMethodConfig {
	id: string;
	label: string;
	mode: 'test' | 'live';
	island: boolean;
	settings: Record< string, string >;
	currencies: string[];
	instructions: string | null;
	paysOnDelivery: boolean;
}

/** The payment configuration a checkout page prints for the host. */
export interface PaymentsConfig {
	contract: string;
	restRoot: string;
	returnQueryArg: string;
	locale: string;
	methods: PaymentMethodConfig[];
}

/** The cart as the host takes it. */
export interface HostCart {
	version: number;
	amountMinor: number;
	currency: string;
	billingCountry: string | null;
}

/** What the host hands over once an order is placed. */
export interface PlacedOrder {
	outcome: string;
	orderUuid: string | null;
	orderNumber: string | null;
	orderKey: string | null;
	status: string | null;
	paymentStatus: string | null;
	intentUuid: string | null;
}

/** What the host reports through onChange(). */
export interface HostState {
	selected: string | null;
	phase: string;
	error: { code: string; detail?: string; message?: string } | null;
}

/** One call of the host's callbacks, as the fixture page records it. */
export type HostEvent =
	| { type: 'select'; id: string }
	| { type: 'stale'; detail: { reason: string; code: string | null } }
	| { type: 'placed'; result: PlacedOrder }
	| { type: 'change'; change: HostState };

/** The host, as the fixture page keeps it. */
export interface PaymentHost {
	update: ( cart: HostCart ) => void;
	select: ( id: string ) => void;
	place: () => Promise< boolean >;
	retry: () => Promise< boolean >;
	destroy: () => void;
}

/** What the fixture page keeps on `window.__seocartPayments`. */
export interface PaymentsFixtureState {
	ready: number;
	events: HostEvent[];
	host?: PaymentHost;
	readCart: () => Promise< HostCart >;
}

/** The registry, as a test reads it. */
export interface PaymentsRegistry {
	contractVersion: string;
	queue: unknown[];
	ids: () => string[];
	refused: () => Array< { id: string; reason: string } >;
}

declare global {
	interface Window {
		__seocartPayments: PaymentsFixtureState;
		seocart?: { payments: PaymentsRegistry };
	}
}

/**
 * Waits until the fixture page has created its host.
 *
 * @param page The fixture page.
 */
export async function waitForHost( page: Page ): Promise< void > {
	await page.waitForFunction( () => !! window.__seocartPayments?.host );
}

/**
 * Returns the calls of the host's callbacks so far.
 *
 * @param page The fixture page.
 */
export function hostEvents( page: Page ): Promise< HostEvent[] > {
	return page.evaluate( () => window.__seocartPayments.events );
}

/**
 * Returns what the host last reported through onChange().
 *
 * @param page The fixture page.
 */
export async function hostState(
	page: Page
): Promise< HostState | undefined > {
	const changes = ( await hostEvents( page ) ).flatMap( ( event ) =>
		'change' === event.type ? [ event.change ] : []
	);

	return changes.at( -1 );
}

/**
 * Returns the orders the host handed over.
 *
 * @param page The fixture page.
 */
export async function placedOrders( page: Page ): Promise< PlacedOrder[] > {
	return ( await hostEvents( page ) ).flatMap( ( event ) =>
		'placed' === event.type ? [ event.result ] : []
	);
}

/**
 * Returns the URL of a REST route on a site, whatever its permalink structure.
 *
 * @param baseURL The site's home URL, ending in a slash.
 * @param route   The route, such as `/seocart/store/v1/cart`.
 */
export function restUrlOf( baseURL: string, route: string ): string {
	return `${ baseURL }?rest_route=${ encodeURIComponent( route ) }`;
}

/** An island's script, and where the page loads it. */
export interface IslandScript {
	/** The script's source. */
	source: string;
	/**
	 * `before` the module, `after` it (once the registry is installed, before the host is created),
	 * or `none`: not on the page, for a test to add later.
	 */
	at: 'before' | 'after' | 'none';
}

/** What the fixture page is composed of. */
export interface PaymentsPageOptions {
	/** The site's home URL, ending in a slash. */
	baseURL: string;
	/** The plugin's own directory on the site, such as `/wp-content/plugins/seocart/`. */
	pluginBasePath: string;
	/** The configuration the page prints. */
	config: PaymentsConfig;
	/** The islands' scripts. */
	islands?: IslandScript[];
}

/**
 * Serves the fixture page at the site's origin for every load of its address, any query string
 * included, and returns the address.
 *
 * The page's inline module reads the cart from the Store API, creates the host with it, and keeps
 * on `window.__seocartPayments`: `host`, `ready` (the ready events heard), `events` (each callback's
 * call: `select`, `stale`, `placed`, `change`) and `readCart()`. Its Place button calls
 * `host.place()`.
 *
 * @param page    The page.
 * @param options What the page is composed of.
 */
export async function servePaymentsPage(
	page: Page,
	options: PaymentsPageOptions
): Promise< string > {
	const address = `${ options.baseURL }${ FIXTURE_PATH }`;
	const islands = options.islands ?? [];

	await page.route(
		( url ) => url.href.startsWith( address ),
		async ( route ) => {
			const island = /island-(\d+)\.js$/.exec(
				new URL( route.request().url() ).pathname
			);

			if ( island ) {
				await route.fulfill( {
					contentType: 'text/javascript',
					body: islands[ Number( island[ 1 ] ) ]?.source ?? '',
				} );
				return;
			}

			await route.fulfill( {
				contentType: 'text/html; charset=utf-8',
				body: composePage( options, address ),
			} );
		}
	);

	return address;
}

/**
 * Returns the address of one of the page's island scripts, for a test that adds it later.
 *
 * @param address The page's address.
 * @param index   The script's index in `islands`.
 */
export function islandScriptUrl( address: string, index: number ): string {
	return `${ address }island-${ index }.js`;
}

/**
 * Composes the fixture page.
 *
 * @param options What the page is composed of.
 * @param address The page's address.
 */
function composePage( options: PaymentsPageOptions, address: string ): string {
	const module = new URL(
		`${ options.pluginBasePath }build/storefront/payments.js`,
		options.baseURL
	).href;
	const scripts = ( at: IslandScript[ 'at' ] ) =>
		( options.islands ?? [] )
			.map( ( island, index ) =>
				island.at === at
					? `<script defer src="${ islandScriptUrl(
							address,
							index
						) }"></script>`
					: ''
			)
			.join( '' );
	// A configuration is JSON in a script element: no `<` may close it early.
	const config = JSON.stringify( options.config ).replace( /</g, '\\u003c' );

	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payment</title>
<script>
window.__seocartPayments = { ready: 0, events: [] };
document.addEventListener( 'seocart:payments:ready', () => window.__seocartPayments.ready++ );
</script>
</head>
<body>
<main>
<h1 id="seocart-payment-title">Payment</h1>
<div class="seocart-payments" data-labelledby="seocart-payment-title"><script type="application/json" class="seocart-payments-config">${ config }</script></div>
<button type="button" id="seocart-place">Place order</button>
</main>
${ scripts( 'before' ) }
<script type="module" src="${ module }"></script>
${ scripts( 'after' ) }
<script type="module">
import { createPaymentHost, readConfig } from ${ JSON.stringify( module ) };

const state = window.__seocartPayments;
const root = document.querySelector( '.seocart-payments' );
const config = readConfig( root );

state.readCart = async () => {
	const response = await fetch( config.restRoot + 'seocart/store/v1/cart', { credentials: 'same-origin' } );
	const cart = await response.json();

	return {
		version: cart.version,
		amountMinor: cart.totals.summary.grand_minor,
		currency: cart.totals.currency,
		billingCountry: null,
	};
};

state.host = createPaymentHost( root, {
	config,
	cart: await state.readCart(),
	onSelect: ( id ) => state.events.push( { type: 'select', id } ),
	onStale: async ( detail ) => {
		state.events.push( { type: 'stale', detail } );
		return state.readCart();
	},
	onPlaced: ( result ) => state.events.push( { type: 'placed', result } ),
	onChange: ( change ) => state.events.push( { type: 'change', change } ),
} );

document.getElementById( 'seocart-place' ).addEventListener( 'click', () => state.host.place() );
</script>
</body>
</html>
`;
}

/** One request the page sent to the Store API. */
export interface StoreRequest {
	method: string;
	/** The route, such as `/seocart/store/v1/checkout`. */
	route: string;
	/** The request's body, as sent. */
	body: string;
	/** Whether it carried the Store API's request header. */
	storeHeader: boolean;
	/** The idempotency key it carried, or null. */
	idempotencyKey: string | null;
	/** The answer's status, once it arrived; 0 for none. */
	status: number;
}

/** Everything a page requested, and its Store API requests in detail. */
export interface RequestLedger {
	/** Every URL the page requested, in order, navigations included. */
	urls: string[];
	/** The Store API requests, in order. */
	store: StoreRequest[];
	/** The ledger, printable: no cookie, no body but the Store API's, keys by their order of appearance. */
	describe: () => string;
}

/**
 * Returns the Store API route a URL names, or null for a URL of anything else.
 *
 * Plain permalinks name it in `rest_route`, pretty ones after `/wp-json`.
 *
 * @param url The URL.
 */
export function storeRouteOf( url: string ): string | null {
	let parsed: URL;

	try {
		parsed = new URL( url );
	} catch {
		return null;
	}

	const route =
		parsed.searchParams.get( 'rest_route' ) ??
		/\/wp-json(\/.*)$/.exec( parsed.pathname )?.[ 1 ] ??
		null;

	return route && route.startsWith( `${ STORE_ROUTE }/` ) ? route : null;
}

/**
 * Starts recording every request a page sends, across its navigations.
 *
 * @param page The page.
 */
export function storeRequests( page: Page ): RequestLedger {
	const urls: string[] = [];
	const store: StoreRequest[] = [];
	const byRequest = new Map< Request, StoreRequest >();

	page.on( 'request', ( request ) => {
		urls.push( request.url() );

		const route = storeRouteOf( request.url() );

		if ( null === route ) {
			return;
		}

		const headers = request.headers();
		const entry: StoreRequest = {
			method: request.method(),
			route,
			body: request.postData() ?? '',
			storeHeader: '1' === headers[ 'x-seocart-store' ],
			idempotencyKey: headers[ 'idempotency-key' ] ?? null,
			status: 0,
		};

		store.push( entry );
		byRequest.set( request, entry );
	} );
	page.on( 'response', ( response ) => {
		const entry = byRequest.get( response.request() );

		if ( entry ) {
			entry.status = response.status();
		}
	} );

	return {
		urls,
		store,
		describe() {
			const keys: string[] = [];
			const alias = ( key: string | null ) => {
				if ( null === key ) {
					return '';
				}

				if ( ! keys.includes( key ) ) {
					keys.push( key );
				}

				return ` key#${ keys.indexOf( key ) + 1 }`;
			};

			return [
				`Store API requests (${ store.length }):`,
				...store.map(
					( entry, index ) =>
						`  ${ index + 1 }. ${ entry.method } ${ entry.route } -> ${
							entry.status || 'no answer'
						}${ entry.storeHeader ? '' : ' (no X-SEOCart-Store)' }${ alias(
							entry.idempotencyKey
						) }${ entry.body ? ` ${ entry.body }` : '' }`
				),
			].join( '\n' );
		},
	};
}

/** The host's two writes: the placement and the resume. */
const HOST_WRITES = [
	`POST ${ STORE_ROUTE }/checkout`,
	`POST ${ STORE_ROUTE }/checkout/resume`,
];

/** The fixture's own read: the cart, which the page reads to create the host and on onStale(). */
const FIXTURE_READS = [ `GET ${ STORE_ROUTE }/cart` ];

/**
 * Fails when the page sent the Store API anything but the host's two writes, each with the Store
 * API's header and the placement with its idempotency key, and the fixture's own read of the cart.
 *
 * @param ledger What the page sent.
 */
export function expectOnlyHostWrites( ledger: RequestLedger ): void {
	const description = ledger.describe();

	for ( const entry of ledger.store ) {
		const request = `${ entry.method } ${ entry.route }`;

		expect(
			[ ...HOST_WRITES, ...FIXTURE_READS ],
			`${ request } is neither one of the host's writes nor the fixture's read.\n${ description }`
		).toContain( request );

		if ( HOST_WRITES.includes( request ) ) {
			expect(
				entry.storeHeader,
				`${ request } carried no X-SEOCart-Store header.\n${ description }`
			).toBe( true );
		}

		if ( HOST_WRITES[ 0 ] === request ) {
			expect(
				entry.idempotencyKey,
				`The placement carried no Idempotency-Key.\n${ description }`
			).toBeTruthy();
		}
	}
}

/**
 * Tells whether a run of digits is a card number by its check digit (Luhn), so a long number of
 * another kind, such as a timestamp, is not taken for one.
 *
 * @param digits The digits.
 */
function passesLuhn( digits: string ): boolean {
	let sum = 0;

	for ( let index = 0; index < digits.length; index++ ) {
		let digit = Number( digits[ digits.length - 1 - index ] );

		if ( 1 === index % 2 ) {
			digit *= 2;
			digit -= digit > 9 ? 9 : 0;
		}

		sum += digit;
	}

	return 0 === sum % 10;
}

/**
 * Fails when a URL the page requested, a further URL given, or the body of a Store API request
 * carries one of the secrets given, a provider's client handle (`_secret_`) or a card number.
 *
 * @param ledger    What the page sent.
 * @param secrets   The secrets of the test, such as the order key the host handed over.
 * @param extraUrls Optional. Addresses the page did not request, such as an island's return address.
 */
export function expectNoSecretInUrls(
	ledger: RequestLedger,
	secrets: string[],
	extraUrls: string[] = []
): void {
	const carriers = [
		...ledger.urls.map( ( url ) => [ 'URL', url ] ),
		...extraUrls.map( ( url ) => [ 'address', url ] ),
		...ledger.store.map( ( entry ) => [
			`${ entry.route } body`,
			entry.body,
		] ),
	];

	for ( const [ kind, text ] of carriers ) {
		for ( const secret of secrets.filter( ( value ) => '' !== value ) ) {
			expect(
				text.includes( secret ),
				`A ${ kind } carries a secret: ${ text.replace( secret, '<secret>' ) }`
			).toBe( false );
		}

		expect(
			text,
			`A ${ kind } carries a provider's handle.`
		).not.toContain( '_secret_' );

		const card = ( text.match( /\d{13,19}/g ) ?? [] ).find( passesLuhn );

		expect( card, `A ${ kind } carries a card number.` ).toBeUndefined();
	}
}

/**
 * Runs a change and expects the island of a gateway to be mounted again as many times as given:
 * once for a change of the payment it was mounted for, never for a change that leaves it alone.
 *
 * The host gives every mount a fresh `.seocart-island[data-gateway]` element, so the mounts are
 * counted as those elements are added, whatever the island.
 *
 * @param page      The fixture page, its host created.
 * @param gatewayId The gateway whose island is counted.
 * @param change    What changes the cart and hands it to the host.
 * @param times     Optional. How many mounts the change must cause. Default 1.
 */
export async function expectRemountOn(
	page: Page,
	gatewayId: string,
	change: () => Promise< void >,
	times = 1
): Promise< void > {
	type Counted = Window & {
		__seocartMounts?: { count: number; observer: MutationObserver };
	};

	await page.evaluate( ( id ) => {
		const counted = window as Counted;
		const counter = {
			count: 0,
			observer: new MutationObserver( ( records ) => {
				for ( const record of records ) {
					for ( const node of record.addedNodes ) {
						if (
							node instanceof HTMLElement &&
							node.matches(
								`.seocart-island[data-gateway="${ id }"]`
							)
						) {
							counter.count++;
						}
					}
				}
			} ),
		};

		counted.__seocartMounts?.observer.disconnect();
		counter.observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
		counted.__seocartMounts = counter;
	}, gatewayId );

	await change();

	const counted = () =>
		page.evaluate(
			() => ( window as Counted ).__seocartMounts?.count ?? 0
		);

	if ( times > 0 ) {
		await expect.poll( counted ).toBeGreaterThanOrEqual( times );
	}

	// One more turn of the page's event loop, for a mount that would come late.
	await page.evaluate(
		() => new Promise( ( resolve ) => setTimeout( resolve, 100 ) )
	);

	expect(
		await counted(),
		`The "${ gatewayId }" island's mounts after the change.`
	).toBe( times );
}
