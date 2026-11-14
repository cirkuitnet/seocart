/**
 * Internal dependencies
 */
import { createHost, mintKey } from '../host';
import { STORAGE_KEY } from '../keeper';
import { installRegistry } from '../registry';

const UUID = '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e';

const PAGE = 'https://shop.example/checkout/?lang=en';

const CART = {
	version: 3,
	amountMinor: 1999,
	currency: 'GBP',
	billingCountry: 'GB',
};

const BILLING = { country: 'GB', postcode: 'SW1Y 4JH' };

/**
 * The answer of a placement: the order, and the outcome given.
 *
 * @param {string} outcome The outcome.
 * @param {Object} fields  Optional. Fields to set or replace.
 * @return {Object} The answer.
 */
function placed( outcome, fields = {} ) {
	return {
		order_uuid: '0192a4b3-0000-7000-8000-000000000001',
		order_number: '000042',
		order_key: 'k'.repeat( 32 ),
		cart_version: 4,
		outcome,
		status: 'processing',
		payment_status: 'authorized',
		next_action: null,
		intent_uuid: null,
		...fields,
	};
}

/**
 * The answer of a placement whose payment waits for the shopper.
 *
 * @param {Object} next Optional. The step. Default a redirect.
 * @return {Object} The answer.
 */
function acting(
	next = {
		type: 'redirect',
		url: `https://shop.example/?seocart_payment=${ UUID }`,
		client_token: `stub_cs_${ UUID }`,
	}
) {
	return placed( 'requires_action', {
		status: 'pending_payment',
		payment_status: 'unpaid',
		next_action: next,
		intent_uuid: UUID,
	} );
}

/**
 * The answer of a resume.
 *
 * @param {string} outcome The outcome.
 * @return {Object} The answer.
 */
function resumed( outcome ) {
	return {
		intent_uuid: UUID,
		outcome,
		status: 'processing',
		payment_status: 'authorized',
	};
}

/**
 * Returns a configuration with the methods given.
 *
 * @param {Object[]} methods The methods' differences from a plain test method.
 * @param {Object}   fields  Optional. Top-level fields to set.
 * @return {Object} The configuration.
 */
function config( methods, fields = {} ) {
	return {
		contract: '0.1.0',
		restRoot: 'https://shop.example/wp-json/',
		returnQueryArg: 'seocart_payment',
		locale: 'en_GB',
		methods: methods.map( ( method ) => ( {
			label: `Pay with ${ method.id }`,
			mode: 'test',
			island: false,
			settings: {},
			currencies: [ 'GBP' ],
			instructions: null,
			paysOnDelivery: false,
			...method,
		} ) ),
		...fields,
	};
}

/**
 * Returns an island that records every call made to it, and what each was given.
 *
 * @param {string} id        Its id.
 * @param {Object} behaviour Optional. `token` (a list gives one per confirmation), `action` (what
 *                           action() resolves to, or false for an island that takes no step),
 *                           `canPay`, `confirm` (a function replacing confirm()), `mountFails`.
 * @return {{island: Object, calls: Array}} The island and its calls.
 */
function fakeIsland( id, behaviour = {} ) {
	const calls = [];
	const tokens = [].concat( behaviour.token ?? 'stub:approve' );
	const island = {
		id,
		contractVersion: '0.1.0',
		supports: { action: false !== behaviour.action },
		canMakePayment: ( ctx ) => behaviour.canPay?.( ctx ) ?? true,
		mount( element, ctx ) {
			calls.push( [ 'mount', ctx, element ] );

			if ( behaviour.mountFails ) {
				throw new Error( 'the provider’s script did not load' );
			}

			return {
				confirm:
					behaviour.confirm ??
					( async ( given ) => {
						calls.push( [ 'confirm', given ] );
						return {
							payment_data: {
								payment_token:
									tokens.length > 1
										? tokens.shift()
										: tokens[ 0 ],
							},
						};
					} ),
				action: async ( next, resume ) => {
					calls.push( [ 'action', next, resume ] );
					return behaviour.action ?? 'completed';
				},
				showError: ( error ) => calls.push( [ 'showError', error ] ),
				unmount: () => calls.push( [ 'unmount' ] ),
			};
		},
	};

	return { island, calls };
}

/**
 * Returns a `fetch` that answers from a script, and the requests it was sent.
 *
 * @param {Object[]} answers Each `{ status, body, headers }`, or `{ network: true }`.
 * @return {{fetch: Function, requests: Object[]}} The fetch and its requests.
 */
function scriptedFetch( answers ) {
	const requests = [];
	const fetch = async ( url, init ) => {
		requests.push( {
			url,
			init,
			route: url.split( 'v1' )[ 1 ],
			key: init.headers[ 'Idempotency-Key' ],
			body: JSON.parse( init.body ),
		} );

		const next = answers.shift();

		if ( ! next ) {
			throw new Error( `no answer scripted for ${ url }` );
		}

		if ( next.network ) {
			throw new TypeError( 'Failed to fetch' );
		}

		return {
			status: next.status,
			json: async () => next.body,
			headers: { get: ( name ) => next.headers?.[ name ] ?? null },
		};
	};

	return { fetch, requests };
}

/**
 * Lets every pending promise settle.
 *
 * @return {Promise<void>} Once they have.
 */
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/**
 * Keeps a placement in the tab's storage, as an earlier load of the page left it.
 *
 * @param {Object} fields Optional. Members to set or replace.
 */
function keep( fields = {} ) {
	window.sessionStorage.setItem(
		STORAGE_KEY,
		JSON.stringify( {
			key: 'kept-key',
			cartVersion: 3,
			amountMinor: 1999,
			currency: 'GBP',
			gatewayId: 'stub',
			intentUuid: null,
			...fields,
		} )
	);
}

/**
 * Returns what the tab's storage holds of the placement, parsed, or null.
 *
 * @return {Object|null} The stored placement.
 */
const stored = () =>
	JSON.parse( window.sessionStorage.getItem( STORAGE_KEY ) ?? 'null' );

/**
 * Creates a host on a page of its own.
 *
 * @param {Object} setup Optional. `methods`, `islands`, `answers`, `cart`, `href`, `onStale`, `nonce`.
 * @return {Object} The host, its page, its requests and what its callbacks heard.
 */
function setUp( setup = {} ) {
	let minted = 0;
	const { fetch, requests } = scriptedFetch( setup.answers ?? [] );
	const scope = {
		document,
		CustomEvent,
		console: { warn: jest.fn() },
		crypto: { randomUUID: () => `key-${ ++minted }` },
		location: { href: setup.href ?? PAGE, assign: jest.fn() },
		history: { state: null, replaceState: jest.fn() },
		sessionStorage: window.sessionStorage,
		fetch,
	};
	const installed = installRegistry( scope );

	for ( const island of setup.islands ?? [] ) {
		scope.seocart.payments.register( island );
	}

	const heard = {
		changes: [],
		placed: [],
		selected: [],
		stale: [],
		waits: [],
	};
	const root = document.createElement( 'div' );

	document.body.append( root );

	const host = createHost(
		root,
		{
			config: config(
				setup.methods ?? [ { id: 'stub' } ],
				undefined === setup.nonce ? {} : { nonce: setup.nonce }
			),
			cart: setup.cart ?? CART,
			onSelect: ( id ) => heard.selected.push( id ),
			onStale: async ( detail ) => {
				heard.stale.push( detail );
				return setup.onStale ? setup.onStale( detail ) : null;
			},
			onPlaced: ( result ) => heard.placed.push( result ),
			onChange: ( state ) => heard.changes.push( state ),
		},
		{
			window: scope,
			registry: scope.seocart.payments,
			onRegister: installed.onRegister,
			wait: async ( ms ) => {
				heard.waits.push( ms );
			},
		}
	);

	return {
		host,
		scope,
		root,
		requests,
		heard,
		last: () => heard.changes.at( -1 ),
	};
}

const names = ( calls ) => calls.map( ( [ name ] ) => name );

beforeEach( () => {
	window.sessionStorage.clear();
	document.body.replaceChildren();
} );

describe( 'the methods', () => {
	it( 'shows each method as a radio, the first that can be used selected and its island mounted', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { root, heard } = setUp( {
			methods: [
				{ id: 'stub' },
				{
					id: 'bank',
					instructions: 'Pay by transfer.',
					paysOnDelivery: false,
				},
			],
			islands: [ island ],
		} );

		await flush();

		const group = root.querySelector(
			'.seocart-choices[role="radiogroup"]'
		);
		const choices = group.querySelectorAll( '.seocart-choice' );

		expect( choices ).toHaveLength( 2 );
		expect( choices[ 0 ].classList.contains( 'is-selected' ) ).toBe( true );
		expect( choices[ 0 ].querySelector( 'input' ).checked ).toBe( true );
		expect(
			choices[ 0 ].querySelector( '.seocart-choice__label' ).textContent
		).toBe( 'Pay with stub' );
		expect(
			choices[ 0 ].querySelector(
				'.seocart-choice__panel > .seocart-island[data-gateway="stub"]'
			)
		).toBe( calls[ 0 ][ 2 ] );
		expect(
			choices[ 1 ].querySelector( '.seocart-choice__panel' ).hidden
		).toBe( true );
		expect( choices[ 1 ].querySelector( 'p' ).textContent ).toBe(
			'Pay by transfer.'
		);
		expect( names( calls ) ).toEqual( [ 'mount' ] );
		expect( heard.selected ).toEqual( [ 'stub' ] );
	} );

	it( 'shows a method that needs an island, and has none, disabled with a warning, and enables it when one registers', async () => {
		const { root, scope, heard } = setUp( {
			methods: [ { id: 'card', island: true } ],
		} );
		const choice = root.querySelector( '[data-method="card"]' );

		expect( choice.dataset.unavailable ).toBe( 'island_missing' );
		expect( choice.querySelector( 'input' ).disabled ).toBe( true );
		expect( scope.console.warn ).toHaveBeenCalledWith(
			'seocart.payments: method "card" needs an island and none is registered: it is shown unavailable.'
		);
		expect( heard.selected ).toEqual( [] );

		const { island, calls } = fakeIsland( 'card' );

		scope.seocart.payments.register( island );
		await flush();

		expect( choice.dataset.unavailable ).toBeUndefined();
		expect( choice.querySelector( 'input' ).disabled ).toBe( false );
		expect( heard.selected ).toEqual( [ 'card' ] );
		expect( names( calls ) ).toEqual( [ 'mount' ] );
	} );

	it( 'shows a method disabled when its island cannot pay, or cannot be mounted', async () => {
		const declining = fakeIsland( 'stub', { canPay: () => false } );
		const broken = fakeIsland( 'card', { mountFails: true } );
		const { root, scope } = setUp( {
			methods: [ { id: 'stub' }, { id: 'card', island: true } ],
			islands: [ declining.island, broken.island ],
		} );

		await flush();

		expect(
			root.querySelector( '[data-method="stub"]' ).dataset.unavailable
		).toBe( 'cannot_make_payment' );
		expect(
			root.querySelector( '[data-method="card"]' ).dataset.unavailable
		).toBe( 'island_failed' );
		expect( scope.console.warn ).toHaveBeenCalledWith(
			'seocart.payments: island "card" could not be mounted: the provider’s script did not load'
		);
	} );

	it( 'mounts the island of the method the shopper picks, after unmounting the other', async () => {
		const first = fakeIsland( 'stub' );
		const second = fakeIsland( 'other' );
		const { root, heard } = setUp( {
			methods: [ { id: 'stub' }, { id: 'other' } ],
			islands: [ first.island, second.island ],
		} );

		await flush();

		const input = root.querySelector( '[data-method="other"] input' );

		input.checked = true;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flush();

		expect( names( first.calls ) ).toEqual( [ 'mount', 'unmount' ] );
		expect( names( second.calls ) ).toEqual( [ 'mount' ] );
		expect( heard.selected ).toEqual( [ 'stub', 'other' ] );
	} );

	it( 'mounts again when the payment changes, never for a version alone, and ignores an older cart', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, root } = setUp( { islands: [ island ] } );

		await flush();
		host.update( { ...CART, version: 4 } );
		host.update( { ...CART, version: 2, amountMinor: 5 } );
		await flush();

		expect( names( calls ) ).toEqual( [ 'mount' ] );

		host.update( { ...CART, version: 5, amountMinor: 2999 } );
		await flush();
		host.update( {
			...CART,
			version: 6,
			amountMinor: 2999,
			currency: 'EUR',
		} );
		await flush();

		expect( names( calls ) ).toEqual( [
			'mount',
			'unmount',
			'mount',
			'unmount',
			'mount',
		] );
		expect( calls[ 4 ][ 1 ] ).toMatchObject( {
			amountMinor: 2999,
			currency: 'EUR',
			cartVersion: 6,
		} );
		expect( root.querySelectorAll( '.seocart-island' ) ).toHaveLength( 1 );
		expect(
			root.querySelector( '.seocart-island' ).dataset.mount
		).not.toBe( '1' );
	} );

	it( 'gives the island the billing address when it confirms, not when it is mounted: a new address alone mounts nothing again', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host } = setUp( {
			islands: [ island ],
			answers: [ { status: 200, body: placed( 'approved' ) } ],
		} );

		await flush();
		host.update( { ...CART, billing: BILLING } );
		await flush();

		expect( names( calls ) ).toEqual( [ 'mount' ] );
		expect( calls[ 0 ][ 1 ] ).not.toHaveProperty( 'billing' );

		await host.place();

		const [ , given ] = calls.find( ( [ name ] ) => 'confirm' === name );

		expect( given ).toEqual( { billing: BILLING } );
		expect( Object.isFrozen( given.billing ) ).toBe( true );
	} );

	it( 'removes itself and unmounts the island when destroyed', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, root } = setUp( { islands: [ island ] } );

		await flush();
		host.destroy();

		expect( names( calls ) ).toEqual( [ 'mount', 'unmount' ] );
		expect( root.querySelector( '.seocart-choices' ) ).toBeNull();
	} );
} );

describe( 'place()', () => {
	it( 'sends the placement with a fresh key and the Store API header, and hands over the order once', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, requests, heard, last } = setUp( {
			islands: [ island ],
			answers: [ { status: 200, body: placed( 'approved' ) } ],
		} );

		await flush();

		expect( await host.place() ).toBe( true );
		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].url ).toBe(
			'https://shop.example/wp-json/seocart/store/v1/checkout'
		);
		expect( requests[ 0 ].init ).toMatchObject( {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-SEOCart-Store': '1', 'Idempotency-Key': 'key-1' },
		} );
		expect( requests[ 0 ].init.headers ).not.toHaveProperty( 'X-WP-Nonce' );
		expect( requests[ 0 ].body ).toEqual( {
			cart_version: 3,
			grand_total_minor: 1999,
			currency: 'GBP',
			payment_data: { payment_token: 'stub:approve' },
		} );
		expect( calls[ 1 ] ).toEqual( [ 'confirm', { billing: null } ] );
		expect( heard.placed ).toEqual( [
			{
				outcome: 'approved',
				orderUuid: placed( 'approved' ).order_uuid,
				orderNumber: '000042',
				orderKey: 'k'.repeat( 32 ),
				status: 'processing',
				paymentStatus: 'authorized',
				intentUuid: null,
			},
		] );
		expect( last() ).toEqual( {
			selected: 'stub',
			phase: 'settled',
			error: null,
		} );
		expect( stored() ).toBeNull();
	} );

	it( 'sends a logged-in shopper’s nonce with both writes', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests } = setUp( {
			islands: [ island ],
			nonce: 'a1b2c3d4e5',
			answers: [
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'approved' ) },
			],
		} );

		await flush();
		await host.place();

		expect(
			requests.map( ( request ) => request.init.headers[ 'X-WP-Nonce' ] )
		).toEqual( [ 'a1b2c3d4e5', 'a1b2c3d4e5' ] );
	} );

	it( 'confirms nothing and sends no data for a method without an island', async () => {
		const { host, requests } = setUp( {
			methods: [ { id: 'bank', instructions: 'Pay by transfer.' } ],
			answers: [ { status: 200, body: placed( 'processing' ) } ],
		} );

		await host.place();

		expect( requests[ 0 ].body.payment_data ).toEqual( {} );
	} );

	it( 'refuses a second placement while one is under way', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests } = setUp( {
			islands: [ island ],
			answers: [ { status: 200, body: placed( 'approved' ) } ],
		} );

		await flush();

		const first = host.place();

		expect( await host.place() ).toBe( false );
		await first;
		expect( requests ).toHaveLength( 1 );
	} );

	it.each( [
		[
			'at the placement',
			[
				{
					status: 402,
					body: {
						code: 'checkout.payment_declined',
						message: 'The payment was declined.',
					},
				},
			],
		],
		[
			'replayed',
			[
				{
					status: 200,
					body: placed( 'declined', {
						message: 'The payment was declined.',
					} ),
				},
			],
		],
		[
			'at the resume',
			[
				{ status: 200, body: acting() },
				{
					status: 402,
					body: {
						code: 'checkout.payment_declined',
						message: 'The payment was declined.',
					},
				},
			],
		],
	] )(
		'shows a decline %s in the island, reads the cart again once, hands nothing over and ends the key',
		async ( name, answers ) => {
			const { island, calls } = fakeIsland( 'stub' );
			const { host, requests, heard, last } = setUp( {
				islands: [ island ],
				answers: [
					...answers,
					{ status: 200, body: placed( 'approved' ) },
				],
				onStale: () => ( { ...CART, version: 5 } ),
			} );

			await flush();
			await host.place();

			expect(
				calls.find( ( [ call ] ) => 'showError' === call )
			).toEqual( [
				'showError',
				{ code: 'declined', message: 'The payment was declined.' },
			] );
			expect( heard.stale.map( ( detail ) => detail.reason ) ).toEqual( [
				'declined',
			] );
			expect( heard.placed ).toEqual( [] );
			expect( last().error.code ).toBe( 'declined' );
			expect( stored() ).toBeNull();

			await host.place();

			const placements = requests.filter(
				( request ) => '/checkout' === request.route
			);

			expect( placements.at( -1 ).key ).not.toBe( placements[ 0 ].key );
			expect( placements.at( -1 ).body.cart_version ).toBe( 5 );
			expect( heard.placed ).toHaveLength( 1 );
		}
	);

	it.each( [
		[ 'at the placement', [ { status: 200, body: placed( 'voided' ) } ] ],
		[
			'at the resume',
			[
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'voided' ) },
			],
		],
	] )(
		'tells the island nothing of a void %s, reads the cart again once, hands nothing over and ends the key',
		async ( name, answers ) => {
			const { island, calls } = fakeIsland( 'stub' );
			const { host, heard, last } = setUp( {
				islands: [ island ],
				answers,
			} );

			await flush();
			await host.place();

			expect( names( calls ) ).not.toContain( 'showError' );
			expect( heard.stale.map( ( detail ) => detail.reason ) ).toEqual( [
				'voided',
			] );
			expect( heard.placed ).toEqual( [] );
			expect( last().error.code ).toBe( 'voided' );
			expect( stored() ).toBeNull();
		}
	);

	it( 'tells the island nothing when the cart changed, takes the new cart and mints a fresh key', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, requests, heard, last } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 409, body: { code: 'cart.version_stale' } },
				{ status: 200, body: placed( 'approved' ) },
			],
			onStale: () => ( { ...CART, version: 4 } ),
		} );

		await flush();
		await host.place();

		expect( names( calls ) ).toEqual( [ 'mount', 'confirm' ] );
		expect( last().error ).toEqual( {
			code: 'stale',
			detail: 'cart.version_stale',
		} );
		expect( heard.stale ).toEqual( [
			{ reason: 'stale', code: 'cart.version_stale' },
		] );

		await host.place();

		expect(
			requests.map( ( request ) => [
				request.key,
				request.body.cart_version,
			] )
		).toEqual( [
			[ 'key-1', 3 ],
			[ 'key-2', 4 ],
		] );
	} );

	it.each( [
		[ 'a network failure', { network: true } ],
		[ 'a server error', { status: 500, body: null } ],
		[
			'an unavailable gateway',
			{ status: 502, body: { code: 'checkout.gateway_unavailable' } },
		],
	] )(
		'confirms again after %s and sends the same key with what the island confirms now',
		async ( name, failure ) => {
			const { island, calls } = fakeIsland( 'stub', {
				token: [ 'stub:first', 'stub:approve' ],
			} );
			const { host, requests, heard } = setUp( {
				islands: [ island ],
				answers: [
					failure,
					{ status: 200, body: placed( 'approved' ) },
				],
			} );

			await flush();
			await host.place();

			expect( stored() ).toEqual( {
				key: 'key-1',
				cartVersion: 3,
				amountMinor: 1999,
				currency: 'GBP',
				gatewayId: 'stub',
				intentUuid: null,
			} );

			await host.retry();

			expect( requests.map( ( request ) => request.key ) ).toEqual( [
				'key-1',
				'key-1',
			] );
			expect(
				requests.map(
					( request ) => request.body.payment_data.payment_token
				)
			).toEqual( [ 'stub:first', 'stub:approve' ] );
			expect( names( calls ) ).toEqual( [
				'mount',
				'confirm',
				'confirm',
			] );
			expect( heard.placed ).toHaveLength( 1 );
		}
	);

	it.each( [
		[ 'total', { ...CART, version: 4, amountMinor: 2999 }, 'stub' ],
		[ 'currency', { ...CART, version: 4, currency: 'EUR' }, 'stub' ],
		[ 'payment method', CART, 'other' ],
	] )(
		'mints a fresh key when the %s changed since the kept placement',
		async ( change, cart, method ) => {
			const { island } = fakeIsland( 'stub' );
			const other = fakeIsland( 'other' );
			const { host, requests } = setUp( {
				methods: [ { id: 'stub' }, { id: 'other' } ],
				islands: [ island, other.island ],
				answers: [
					{ network: true },
					{ status: 200, body: placed( 'approved' ) },
				],
			} );

			await flush();
			await host.place();
			host.update( cart );
			host.select( method );
			await flush();
			await host.place();

			expect( requests.map( ( request ) => request.key ) ).toEqual( [
				'key-1',
				'key-2',
			] );
		}
	);

	it( 'waits while another request with the key places the order, and sends it again', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests, heard } = setUp( {
			islands: [ island ],
			answers: [
				{
					status: 409,
					body: { code: 'checkout.placement_in_progress' },
					headers: { 'Retry-After': '1' },
				},
				{ status: 200, body: placed( 'approved' ) },
			],
		} );

		await flush();
		await host.place();

		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			'key-1',
			'key-1',
		] );
		expect( heard.placed ).toHaveLength( 1 );
		expect( heard.waits ).toEqual( [ 1000 ] );
	} );

	it( 'asks once more after a moment when the placement is pending, and hands the order over once settled', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests, heard } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 200, body: placed( 'pending' ) },
				{ status: 200, body: placed( 'approved' ) },
			],
		} );

		await flush();
		await host.place();

		expect( heard.waits ).toEqual( [ 2000 ] );
		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			'key-1',
			'key-1',
		] );
		expect( heard.placed ).toEqual( [
			expect.objectContaining( { outcome: 'approved' } ),
		] );
	} );

	it( 'rests with the key kept when the placement is still pending, and hands nothing over', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests, heard, last } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 200, body: placed( 'pending' ) },
				{ status: 200, body: placed( 'pending' ) },
			],
		} );

		await flush();
		await host.place();

		expect( requests ).toHaveLength( 2 );
		expect( heard.placed ).toEqual( [] );
		expect( last() ).toMatchObject( {
			phase: 'idle',
			error: { code: 'pending' },
		} );
		expect( stored().key ).toBe( 'key-1' );
	} );

	it.each( [
		[ 'store_api.nonce_missing', 403 ],
		[ 'rest_cookie_invalid_nonce', 403 ],
	] )(
		'asks for a fresh session on %s, tells the island and the checkout nothing, and keeps the key',
		async ( code, status ) => {
			const { island, calls } = fakeIsland( 'stub' );
			const { host, requests, heard, last } = setUp( {
				islands: [ island ],
				nonce: 'old',
				answers: [
					{ status, body: { code } },
					{ status: 200, body: placed( 'approved' ) },
				],
			} );

			await flush();
			await host.place();

			expect( last().error ).toEqual( {
				code: 'session_expired',
				detail: code,
			} );
			expect( names( calls ) ).not.toContain( 'showError' );
			expect( heard.stale ).toEqual( [] );
			expect( stored().key ).toBe( 'key-1' );

			await host.retry();

			expect( requests.map( ( request ) => request.key ) ).toEqual( [
				'key-1',
				'key-1',
			] );
			expect( heard.placed ).toHaveLength( 1 );
		}
	);

	it( 'shows the island’s own refusal and sends nothing', async () => {
		const refusing = fakeIsland( 'stub', {
			confirm: async () => {
				throw Object.assign(
					new Error( 'The card number is incomplete.' ),
					{ code: 'incomplete_number' }
				);
			},
		} );
		const { host, requests, last } = setUp( {
			islands: [ refusing.island ],
		} );

		await flush();
		await host.place();

		const refusal = {
			code: 'island_refused',
			detail: 'incomplete_number',
			message: 'The card number is incomplete.',
		};

		expect( requests ).toEqual( [] );
		expect( refusing.calls.at( -1 ) ).toEqual( [ 'showError', refusal ] );
		expect( last().error ).toEqual( refusal );
	} );

	it( 'discards a confirmation made for a payment that changed while it was made', async () => {
		let finish;
		const { island, calls } = fakeIsland( 'stub', {
			confirm: () =>
				new Promise( ( resolve ) => {
					finish = () =>
						resolve( {
							payment_data: { payment_token: 'stub:approve' },
						} );
				} ),
		} );
		const { host, requests, last } = setUp( { islands: [ island ] } );

		await flush();

		const placing = host.place();

		await flush();
		host.update( { ...CART, version: 4, amountMinor: 2999 } );
		await flush();
		finish();
		await placing;

		expect( requests ).toEqual( [] );
		expect( names( calls ) ).toEqual( [ 'mount', 'unmount', 'mount' ] );
		expect( last().error ).toEqual( { code: 'stale' } );
	} );

	it( 'holds a new cart while the order is placed, and takes it once idle', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		let answer;
		const { host, scope } = setUp( { islands: [ island ] } );

		scope.fetch = () =>
			new Promise( ( resolve ) => {
				answer = () =>
					resolve( {
						status: 409,
						json: async () => ( {
							code: 'checkout.totals_changed',
						} ),
						headers: { get: () => null },
					} );
			} );

		await flush();

		const placing = host.place();

		await flush();
		host.update( { ...CART, version: 4, amountMinor: 2999 } );
		await flush();

		expect( names( calls ) ).toEqual( [ 'mount', 'confirm' ] );

		answer();
		await placing;
		await flush();

		expect( names( calls ) ).toEqual( [
			'mount',
			'confirm',
			'unmount',
			'mount',
		] );
		expect( calls[ 3 ][ 1 ].amountMinor ).toBe( 2999 );
	} );
} );

describe( 'a payment the shopper must act for', () => {
	it( 'offers the island the step with the return address and the billing address, then resumes and hands over the order', async () => {
		const { island, calls } = fakeIsland( 'stub', {
			token: 'stub:requires_action',
		} );
		const { host, requests, heard } = setUp( {
			islands: [ island ],
			cart: { ...CART, billing: BILLING },
			answers: [
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'approved' ) },
			],
		} );

		await flush();
		await host.place();

		expect( calls[ 2 ] ).toEqual( [
			'action',
			acting().next_action,
			{
				intentUuid: UUID,
				returnUrl: `https://shop.example/checkout/?seocart_payment=${ UUID }`,
				billing: BILLING,
			},
		] );
		expect( requests[ 1 ].url ).toBe(
			'https://shop.example/wp-json/seocart/store/v1/checkout/resume'
		);
		expect( requests[ 1 ].body ).toEqual( { intent_uuid: UUID } );
		expect( requests[ 1 ].key ).toBeUndefined();
		expect( heard.placed ).toEqual( [
			expect.objectContaining( {
				outcome: 'approved',
				orderKey: 'k'.repeat( 32 ),
				status: 'processing',
				paymentStatus: 'authorized',
				intentUuid: UUID,
			} ),
		] );
		expect( stored() ).toBeNull();
	} );

	it( 'sends the shopper to the provider’s page when the island takes no step, keeping the key and the payment, and no secret', async () => {
		const { island, calls } = fakeIsland( 'stub', {
			action: false,
			token: 'stub:requires_action',
		} );
		const { host, scope } = setUp( {
			islands: [ island ],
			answers: [ { status: 200, body: acting() } ],
		} );

		await flush();
		await host.place();

		const text = window.sessionStorage.getItem( STORAGE_KEY );

		expect( scope.location.assign ).toHaveBeenCalledWith(
			`https://shop.example/?seocart_payment=${ UUID }`
		);
		expect( names( calls ) ).toEqual( [ 'mount', 'confirm' ] );
		expect( Object.keys( JSON.parse( text ) ).sort() ).toEqual( [
			'amountMinor',
			'cartVersion',
			'currency',
			'gatewayId',
			'intentUuid',
			'key',
		] );
		expect( stored() ).toMatchObject( { key: 'key-1', intentUuid: UUID } );
		expect( text ).not.toContain( 'stub:requires_action' );
		expect( text ).not.toContain( 'stub_cs_' );
		expect( text ).not.toContain( 'k'.repeat( 32 ) );
	} );

	it( 'cannot take a script’s step without an island that takes it, and keeps the key', async () => {
		const { island } = fakeIsland( 'stub', { action: false } );
		const { host, scope, last } = setUp( {
			islands: [ island ],
			answers: [
				{
					status: 200,
					body: acting( {
						type: 'sdk',
						url: null,
						client_token: 'h',
					} ),
				},
			],
		} );

		await flush();
		await host.place();

		expect( scope.location.assign ).not.toHaveBeenCalled();
		expect( last().error ).toEqual( { code: 'action_unsupported' } );
		expect( stored() ).not.toBeNull();
	} );

	it( 'does nothing more when the island says the shopper is leaving the page', async () => {
		const { island } = fakeIsland( 'stub', { action: 'left' } );
		const { host, requests, last } = setUp( {
			islands: [ island ],
			answers: [ { status: 200, body: acting() } ],
		} );

		await flush();
		await host.place();

		expect( requests ).toHaveLength( 1 );
		expect( last().phase ).toBe( 'acting' );
	} );

	it( 'keeps the key when the shopper has not finished, so placing again confirms and takes the same step', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, requests, heard, last } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'requires_action' ) },
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'duplicate' ) },
			],
		} );

		await flush();
		await host.place();

		expect( last().error ).toEqual( { code: 'action_incomplete' } );

		await host.retry();

		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			'key-1',
			undefined,
			'key-1',
			undefined,
		] );
		expect( names( calls ) ).toEqual( [
			'mount',
			'confirm',
			'action',
			'confirm',
			'action',
		] );
		expect( heard.placed ).toEqual( [
			expect.objectContaining( { outcome: 'duplicate' } ),
		] );
	} );

	it( 'resumes again when the resume is what failed', async () => {
		const { island } = fakeIsland( 'stub' );
		const { host, requests, heard } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 200, body: acting() },
				{ network: true },
				{ status: 200, body: resumed( 'approved' ) },
			],
		} );

		await flush();
		await host.place();
		await host.retry();

		expect( requests.map( ( request ) => request.route ) ).toEqual( [
			'/checkout',
			'/checkout/resume',
			'/checkout/resume',
		] );
		expect( heard.placed ).toHaveLength( 1 );
	} );

	it( 'reports an answer that asks the shopper to act but names no payment, keeping the key', async () => {
		const { island, calls } = fakeIsland( 'stub' );
		const { host, last } = setUp( {
			islands: [ island ],
			answers: [
				{ status: 200, body: { ...acting(), intent_uuid: null } },
			],
		} );

		await flush();
		await host.place();

		expect( names( calls ) ).toEqual( [ 'mount', 'confirm' ] );
		expect( last().error ).toEqual( { code: 'action_incomplete' } );
	} );
} );

describe( 'on loading', () => {
	const returnedTo = `https://shop.example/checkout/?lang=en&seocart_payment=${ UUID }`;

	/**
	 * Expects nothing to have acted while the page loaded: no island's step, no navigation.
	 *
	 * @param {Array}  calls The island's calls.
	 * @param {Object} scope The page.
	 */
	function expectNothingActed( calls, scope ) {
		expect( names( calls ) ).not.toContain( 'action' );
		expect( names( calls ) ).not.toContain( 'confirm' );
		expect( scope.location.assign ).not.toHaveBeenCalled();
	}

	it( 'resumes the payment the shopper was sent back for, asks the kept placement again with no payment data for the order, and clears the address', async () => {
		keep( { intentUuid: UUID } );

		const { island, calls } = fakeIsland( 'stub' );
		const { scope, requests, heard } = setUp( {
			href: returnedTo,
			islands: [ island ],
			cart: { ...CART, version: 4 },
			answers: [
				{ status: 200, body: resumed( 'approved' ) },
				{ status: 200, body: placed( 'approved' ) },
			],
		} );

		await flush();

		expect( scope.history.replaceState ).toHaveBeenCalledWith(
			null,
			'',
			'https://shop.example/checkout/?lang=en'
		);
		expect(
			requests.map( ( request ) => [ request.route, request.key ] )
		).toEqual( [
			[ '/checkout/resume', undefined ],
			[ '/checkout', 'kept-key' ],
		] );
		expect( requests[ 1 ].body ).toEqual( {
			cart_version: 3,
			grand_total_minor: 1999,
			currency: 'GBP',
			payment_data: {},
		} );
		expect( heard.placed ).toEqual( [
			expect.objectContaining( {
				outcome: 'approved',
				orderKey: 'k'.repeat( 32 ),
				intentUuid: UUID,
			} ),
		] );
		expect( stored() ).toBeNull();
		expectNothingActed( calls, scope );
	} );

	it( 'resumes the payment the kept placement names when the page is reloaded mid-step', async () => {
		keep( { intentUuid: UUID } );

		const { requests, heard } = setUp( {
			cart: { ...CART, version: 4 },
			answers: [
				{ status: 200, body: resumed( 'approved' ) },
				{ status: 200, body: placed( 'approved' ) },
			],
		} );

		await flush();

		expect( requests.map( ( request ) => request.route ) ).toEqual( [
			'/checkout/resume',
			'/checkout',
		] );
		expect( heard.placed[ 0 ].orderKey ).toBe( 'k'.repeat( 32 ) );
	} );

	it( 'reports the outcome and statuses alone when nothing was kept for the payment', async () => {
		const { requests, heard } = setUp( {
			href: returnedTo,
			answers: [ { status: 200, body: resumed( 'approved' ) } ],
		} );

		await flush();

		expect( requests ).toHaveLength( 1 );
		expect( heard.placed ).toEqual( [
			expect.objectContaining( {
				outcome: 'approved',
				orderKey: null,
				status: 'processing',
			} ),
		] );
	} );

	it( 'waits for the shopper when the resumed payment still needs them: placing again confirms and takes the step', async () => {
		keep( { intentUuid: UUID } );

		const { island, calls } = fakeIsland( 'stub' );
		const { host, scope, requests, heard, last } = setUp( {
			islands: [ island ],
			cart: { ...CART, version: 4 },
			answers: [
				{ status: 200, body: resumed( 'requires_action' ) },
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'approved' ) },
			],
		} );

		await flush();

		expect( last().error ).toEqual( { code: 'action_incomplete' } );
		expect( stored() ).toMatchObject( {
			key: 'kept-key',
			intentUuid: UUID,
		} );
		expectNothingActed( calls, scope );

		await host.place();

		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			undefined,
			'kept-key',
			undefined,
		] );
		expect( names( calls ) ).toEqual( [ 'mount', 'confirm', 'action' ] );
		expect( heard.placed ).toHaveLength( 1 );
	} );

	it( 'reads the cart again, hands nothing over and ends the key when the resumed payment was declined', async () => {
		keep( { intentUuid: UUID } );

		const { heard, last } = setUp( {
			cart: { ...CART, version: 4 },
			answers: [
				{
					status: 402,
					body: { code: 'checkout.payment_declined', message: 'No.' },
				},
			],
		} );

		await flush();

		expect( heard.stale.map( ( detail ) => detail.reason ) ).toEqual( [
			'declined',
		] );
		expect( heard.placed ).toEqual( [] );
		expect( last().error.code ).toBe( 'declined' );
		expect( stored() ).toBeNull();
	} );

	it( 'drops the kept placement and reports the refusal when the resume is refused', async () => {
		keep( { intentUuid: UUID } );

		const { last } = setUp( {
			cart: { ...CART, version: 4 },
			answers: [
				{ status: 404, body: { code: 'payment.intent_not_found' } },
			],
		} );

		await flush();

		expect( last().error ).toEqual( {
			code: 'refused',
			detail: 'payment.intent_not_found',
		} );
		expect( stored() ).toBeNull();
	} );

	it( 'drops a placement whose answer was lost without a request while the cart is at the version it was sent with', async () => {
		keep();

		const { requests, last } = setUp( {} );

		await flush();

		expect( requests ).toEqual( [] );
		expect( stored() ).toBeNull();
		expect( last() ).toMatchObject( { phase: 'idle', error: null } );
	} );

	it( 'asks a placement whose answer was lost again, with no payment data, once the cart moved on, and hands the order over', async () => {
		keep();

		const { island, calls } = fakeIsland( 'stub' );
		const { scope, requests, heard } = setUp( {
			islands: [ island ],
			cart: { ...CART, version: 4 },
			answers: [ { status: 200, body: placed( 'approved' ) } ],
		} );

		await flush();

		expect(
			requests.map( ( request ) => [
				request.route,
				request.key,
				request.body.payment_data,
			] )
		).toEqual( [ [ '/checkout', 'kept-key', {} ] ] );
		expect( heard.placed ).toEqual( [
			expect.objectContaining( { orderKey: 'k'.repeat( 32 ) } ),
		] );
		expectNothingActed( calls, scope );
	} );

	it( 'notes the payment of a lost answer that asks the shopper to act, and waits for the shopper to place again', async () => {
		keep();

		const { island, calls } = fakeIsland( 'stub' );
		const { host, scope, requests, heard, last } = setUp( {
			islands: [ island ],
			cart: { ...CART, version: 4 },
			answers: [
				{ status: 200, body: acting() },
				{ status: 200, body: acting() },
				{ status: 200, body: resumed( 'approved' ) },
			],
		} );

		await flush();

		expect( last().error ).toEqual( { code: 'action_incomplete' } );
		expect( stored() ).toMatchObject( {
			key: 'kept-key',
			intentUuid: UUID,
		} );
		expectNothingActed( calls, scope );

		await host.place();

		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			'kept-key',
			'kept-key',
			undefined,
		] );
		expect( names( calls ) ).toEqual( [ 'mount', 'confirm', 'action' ] );
		expect( heard.placed ).toHaveLength( 1 );
	} );

	it.each( [
		[ 'stale', { status: 409, body: { code: 'cart.version_stale' } } ],
		[ 'refused', { status: 409, body: { code: 'cart.not_open' } } ],
	] )(
		'drops a lost placement the store does not hold (%s) without an error',
		async ( name, answer ) => {
			keep();

			const { heard, last } = setUp( {
				cart: { ...CART, version: 4 },
				answers: [ answer ],
			} );

			await flush();

			expect( stored() ).toBeNull();
			expect( heard.stale ).toEqual( [] );
			expect( last() ).toMatchObject( { phase: 'idle', error: null } );
		}
	);

	it( 'asks again on retry() when asking on loading failed', async () => {
		keep();

		const { host, requests, heard } = setUp( {
			cart: { ...CART, version: 4 },
			answers: [
				{ network: true },
				{ status: 200, body: placed( 'approved' ) },
			],
		} );

		await flush();
		await host.retry();

		expect( requests.map( ( request ) => request.key ) ).toEqual( [
			'kept-key',
			'kept-key',
		] );
		expect( requests[ 1 ].body.payment_data ).toEqual( {} );
		expect( heard.placed ).toHaveLength( 1 );
	} );
} );

describe( 'mintKey', () => {
	it( 'makes a version 4 uuid without randomUUID(), as on a page served over HTTP', () => {
		const key = mintKey( {
			getRandomValues: ( bytes ) => bytes.fill( 255 ),
		} );

		expect( key ).toBe( 'ffffffff-ffff-4fff-bfff-ffffffffffff' );
	} );
} );
