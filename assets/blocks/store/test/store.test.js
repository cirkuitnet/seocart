/**
 * The store `seocart/store` as the page wires it: its state through an add and a read, over a
 * scripted Store API, with a stand-in for WordPress's runtime.
 */

/**
 * Internal dependencies
 */
import runtime from '../../test/fixtures/interactivity';
import { createFakeStore, CONFIG } from './fixtures/fake-store';

jest.mock(
	'@wordpress/interactivity',
	() => require( '../../test/fixtures/interactivity' ),
	{ virtual: true }
);

/**
 * The store module, loaded once a test has set the page's configuration.
 *
 * @type {Object}
 */
let seocartStore;

/**
 * The scripted Store API the module's requests reach.
 *
 * @type {Object}
 */
let api;

beforeAll( () => {
	api = createFakeStore();
	window.fetch = ( ...request ) => api.fetch( ...request );
	runtime.config[ 'seocart/store' ] = CONFIG;
	seocartStore = require( '../index' );
} );

afterAll( () => {
	delete window.fetch;
} );

test( 'loading the module sends nothing, and knows no cart', () => {
	expect( api.sent ).toEqual( [] );
	expect( seocartStore.state ).toMatchObject( {
		cart: null,
		cartStatus: 'unknown',
		pending: false,
		lastAdded: null,
		notice: null,
	} );
} );

test( 'an accepted add holds the cart, what was added, and nothing pending', async () => {
	const adding = seocartStore.actions.addLines( [
		{ variant_id: 7, quantity: 2 },
	] );

	expect( seocartStore.state.pending ).toBe( true );

	const outcome = await adding;

	expect( outcome.ok ).toBe( true );
	expect( seocartStore.state ).toMatchObject( {
		cartStatus: 'ready',
		pending: false,
		lastAdded: { variantId: 7, quantity: 2 },
		notice: null,
	} );
	expect( seocartStore.state.cart.version ).toBe( 1 );
} );

test( 'a refused add holds its notice; the next add clears it', async () => {
	api.refuse = { status: 429, body: { code: 'store_api.rate_limited' } };

	await seocartStore.actions.addLines( [ { variant_id: 7, quantity: 1 } ] );

	expect( seocartStore.state.notice ).toEqual( {
		key: 'rate_limited',
		values: {},
	} );
	expect( seocartStore.state.lastAdded ).toEqual( {
		variantId: 7,
		quantity: 2,
	} );

	api.refuse = null;

	await seocartStore.actions.addLines( [ { variant_id: 8, quantity: 1 } ] );

	expect( seocartStore.state.notice ).toBeNull();
	expect( seocartStore.state.cart.version ).toBe( 2 );
} );

test( 'a read says how it went', async () => {
	const reading = seocartStore.actions.readCart();

	expect( seocartStore.state.cartStatus ).toBe( 'reading' );
	expect( ( await reading ).ok ).toBe( true );
	expect( seocartStore.state.cartStatus ).toBe( 'ready' );

	window.fetch = () => Promise.reject( new TypeError( 'network' ) );

	expect( ( await seocartStore.actions.readCart() ).ok ).toBe( false );
	expect( seocartStore.state.cartStatus ).toBe( 'failed' );
} );
