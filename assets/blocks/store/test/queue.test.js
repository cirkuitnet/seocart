/**
 * The store's queue of cart writes over a scripted Store API: each case of a write sent from a
 * cached page, and the requests it took.
 *
 * Planted violations, each shown red and removed:
 * - resend a write that got no answer (in settle(), send the add again on `answer.network`): the
 *   lost answer's case finds the cart holding twice the quantity;
 * - never resend after a first stale answer (in add(), settle the first answer whatever it is): the
 *   returning shopper's add fails;
 * - resend after a second stale answer too (in add(), loop while the answer is stale): the second
 *   stale answer's case does not end;
 * - send two writes at once (queued() runs each task at once): the double press finds two writes in
 *   flight;
 * - adopt every answer whatever its version (take() adopts always): the older answer's case shows
 *   the cart going back;
 * - skip the nonce step (client.js's send() returns the first answer): the subscriber's add ends in
 *   `store_api.nonce_missing`'s refusal.
 */

/**
 * Internal dependencies
 */
import { createClient } from '../client';
import { createQueue } from '../queue';
import { createFakeStore, CONFIG } from './fixtures/fake-store';

/**
 * Returns a queue over a store, with the cart it holds.
 *
 * @param {Object}      store The scripted store.
 * @param {Object|null} cart  The cart held at the start, or null.
 * @return {{queue: Object, held: function(): Object}} The queue, and what it holds.
 */
function queueOver( store, cart = null ) {
	let held = cart;

	return {
		queue: createQueue( {
			client: createClient( {
				...CONFIG,
				fetch: ( ...request ) => store.fetch( ...request ),
			} ),
			held: () => held,
			adopt: ( taken ) => {
				held = taken;
			},
		} ),
		held: () => held,
	};
}

/**
 * Returns the requests a store was sent, as `METHOD route @version`.
 *
 * @param {Object} store The scripted store.
 * @return {string[]} The requests.
 */
function requests( store ) {
	return store.sent.map( ( { url, method = 'GET', body } ) => {
		const route = url.replace( CONFIG.restRoot, '' );

		return body
			? `${ method } ${ route } @${ JSON.parse( body ).cart_version }`
			: `${ method } ${ route }`;
	} );
}

const ADD = [ { variant_id: 7, quantity: 2 } ];

test( "a guest's first add starts the cart at version 0, with the store's header and the browser's cookies", async () => {
	const store = createFakeStore();
	const { queue, held } = queueOver( store );

	const outcome = await queue.add( ADD );

	expect( outcome.ok ).toBe( true );
	expect( held().version ).toBe( 1 );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
	] );
	expect( store.sent[ 0 ].credentials ).toBe( 'same-origin' );
	expect( store.sent[ 0 ].headers[ 'X-SEOCart-Store' ] ).toBe( '1' );
	expect( store.sent[ 0 ].headers[ 'X-WP-Nonce' ] ).toBeUndefined();
} );

test( "a returning shopper's first add, sent at 0, is refused stale and sent once more at the current version", async () => {
	const store = createFakeStore( {
		version: 3,
		lines: [ { line_identity: 'line-7', variant_id: 7, quantity: 1 } ],
	} );
	const { queue, held } = queueOver( store );

	const outcome = await queue.add( ADD );

	expect( outcome.ok ).toBe( true );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		'POST seocart/store/v1/cart/lines @3',
	] );
	expect( held().lines[ 0 ].quantity ).toBe( 3 );
} );

test( 'a second stale answer is not sent again: the cart is read and the shopper told it changed', async () => {
	const store = createFakeStore( { version: 3 } );
	const { queue } = queueOver( store );

	store.staleAlways = true;

	const outcome = await queue.add( ADD );

	expect( outcome ).toEqual( {
		ok: false,
		notice: { key: 'cart_changed', values: {} },
	} );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		'POST seocart/store/v1/cart/lines @3',
		'GET seocart/store/v1/cart',
	] );
} );

test( 'a write whose answer is lost is never sent again: the cart read shows it once', async () => {
	const store = createFakeStore();
	const { queue, held } = queueOver( store );

	store.lose = 1;

	const outcome = await queue.add( ADD );

	expect( outcome ).toEqual( {
		ok: false,
		notice: { key: 'unconfirmed', values: {} },
	} );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		'GET seocart/store/v1/cart',
	] );
	expect( store.lines ).toEqual( [
		{ line_identity: 'line-7', variant_id: 7, quantity: 2 },
	] );
	expect( held().version ).toBe( 1 );

	// The shopper's next add is a new action, sent at the version just read.
	await queue.add( ADD );

	expect( requests( store )[ 2 ] ).toBe(
		'POST seocart/store/v1/cart/lines @1'
	);
} );

test( 'a lost answer whose read fails too tells the shopper something went wrong', async () => {
	const store = createFakeStore();
	const { queue } = queueOver( store );
	const fetch = store.fetch;

	store.fetch = async ( url, init = {} ) => {
		if ( 'GET' === init.method ) {
			throw new TypeError( 'network' );
		}

		store.lose = 1;

		return fetch( url, init );
	};

	expect( await queue.add( ADD ) ).toEqual( {
		ok: false,
		notice: { key: 'failed', values: {} },
	} );
} );

test( 'two adds pressed at once are sent one after the other, each at the version before it', async () => {
	const store = createFakeStore();
	const { queue, held } = queueOver( store );

	const outcomes = await Promise.all( [
		queue.add( ADD ),
		queue.add( ADD ),
	] );

	expect( outcomes.map( ( outcome ) => outcome.ok ) ).toEqual( [
		true,
		true,
	] );
	expect( store.mostInFlight ).toBe( 1 );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		'POST seocart/store/v1/cart/lines @1',
	] );
	expect( held().lines[ 0 ].quantity ).toBe( 4 );
} );

test( 'an answer older than the cart held is not taken', async () => {
	const store = createFakeStore( { version: 4 } );
	const newer = {
		version: 6,
		lines: [ { line_identity: 'line-9', variant_id: 9, quantity: 1 } ],
	};
	const { queue, held } = queueOver( store, newer );

	const outcome = await queue.read();

	expect( outcome.ok ).toBe( true );
	expect( outcome.cart.version ).toBe( 4 );
	expect( held() ).toBe( newer );
} );

test( "a logged-in shopper's first add is refused for its nonce, which is fetched once and the add sent once more with it", async () => {
	const store = createFakeStore( { loggedIn: true } );
	const { queue, held } = queueOver( store );

	const outcome = await queue.add( ADD );

	expect( outcome.ok ).toBe( true );
	expect( held().version ).toBe( 1 );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		`GET ${ CONFIG.nonceUrl }`,
		'POST seocart/store/v1/cart/lines @0',
	] );
	expect( store.sent[ 2 ].headers[ 'X-WP-Nonce' ] ).toBe( '0123456789' );

	// The nonce is held for the page: the next add sends it at once.
	await queue.add( ADD );

	expect( requests( store ) ).toHaveLength( 4 );
	expect( store.sent[ 3 ].headers[ 'X-WP-Nonce' ] ).toBe( '0123456789' );
} );

test( 'a nonce that cannot be fetched tells the shopper the session has expired, and is not asked twice', async () => {
	const store = createFakeStore( { loggedIn: true } );
	const { queue } = queueOver( store );

	store.nonceAnswer = null;

	expect( await queue.add( ADD ) ).toEqual( {
		ok: false,
		notice: { key: 'session_expired', values: {} },
	} );
	expect( requests( store ) ).toEqual( [
		'POST seocart/store/v1/cart/lines @0',
		`GET ${ CONFIG.nonceUrl }`,
	] );
} );

test( "the other refusals are said in the shopper's words, or in the store's", async () => {
	const cases = [
		[
			429,
			{ code: 'store_api.rate_limited' },
			{ key: 'rate_limited', values: {} },
		],
		[ 409, { code: 'cart.not_open' }, { key: 'placing', values: {} } ],
		[
			429,
			{ code: 'cart.creation_limited', message: 'Too many carts.' },
			{ message: 'Too many carts.' },
		],
		[
			409,
			{ code: 'cart.too_many_lines', message: 'At most 50 lines.' },
			{ message: 'At most 50 lines.' },
		],
		[
			422,
			{ code: 'pricing.quote_unavailable' },
			{ key: 'failed', values: {} },
		],
	];

	for ( const [ status, body, notice ] of cases ) {
		const store = createFakeStore();
		const { queue } = queueOver( store );

		store.refuse = { status, body };

		expect( await queue.add( ADD ) ).toEqual( { ok: false, notice } );
		expect( store.sent ).toHaveLength( 1 );
	}
} );
