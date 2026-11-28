/**
 * The store `seocart/product` as the page wires it, over the real `seocart/store` and a scripted
 * Store API, with a stand-in for WordPress's runtime: what a shopper's picks, quantities and adds
 * change, and what reaches the data layer.
 *
 * Planted violations, each shown red and removed: `view_item` pushed for every product when the
 * module starts (the prerendering case); `unavailable_lines` left unread after an add (the flagged
 * add's case shows the confirmation alone).
 */

/**
 * Internal dependencies
 */
import runtime from '../../test/fixtures/interactivity';
import { STRINGS } from '../../test/fixtures/strings';
import { createFakeStore, CONFIG } from '../../store/test/fixtures/fake-store';
import { coffee } from './fixtures/product';

jest.mock(
	'@wordpress/interactivity',
	() => require( '../../test/fixtures/interactivity' ),
	{ virtual: true }
);
jest.mock( '@seocart/store', () => require( '../../store/index' ), {
	virtual: true,
} );

const POST = 41;

/**
 * Whether the document is being prerendered, as the test sets it.
 *
 * @type {boolean}
 */
let prerendering = true;

/**
 * The store `seocart/product`.
 *
 * @type {Object}
 */
let product;

/**
 * The scripted Store API.
 *
 * @type {Object}
 */
let api;

/**
 * Sets the context the next getter or action sees: the post's, and an axis and a value when given.
 *
 * @param {Object} more The axis and the value, when any.
 */
function within( more = {} ) {
	runtime.context = { postId: POST, ...more };
}

/**
 * Returns the events pushed onto the data layer.
 *
 * @return {Array<Object>} The events.
 */
function pushed() {
	return window.dataLayer ?? [];
}

beforeAll( () => {
	Object.defineProperty( document, 'prerendering', {
		configurable: true,
		get: () => prerendering,
	} );

	api = createFakeStore();
	window.fetch = ( ...request ) => api.fetch( ...request );
	runtime.config[ 'seocart/store' ] = {
		...CONFIG,
		currency: 'EUR',
		strings: STRINGS,
	};
	runtime.state[ 'seocart/product' ] = { products: { [ POST ]: coffee() } };

	// The modules start here, as on a page the browser prerenders.
	require( '../index' );
	product = runtime.stores[ 'seocart/product' ];
} );

afterAll( () => {
	delete window.fetch;
	delete window.dataLayer;
	delete document.prerendering;
} );

test( 'a prerendered page pushes nothing until it is shown, then one view_item', () => {
	within();
	product.callbacks.viewItem();

	expect( pushed() ).toEqual( [] );

	prerendering = false;
	document.dispatchEvent( new Event( 'prerenderingchange' ) );

	expect( pushed() ).toEqual( [
		{
			event: 'view_item',
			ecommerce: {
				currency: 'EUR',
				items: [
					{
						product_uuid: '0193b7a2-7c1e-7d2a-9f00-5e1a2b3c4d5e',
						variant_id: 11,
						name: 'House blend',
						variant_label: '250 g / Light',
						quantity: 1,
						unit_price_minor: 1250,
					},
				],
			},
		},
	] );

	product.callbacks.viewItem();

	expect( pushed() ).toHaveLength( 1 );
	expect( api.sent ).toEqual( [] );
} );

test( "the first paint's getters read the variant selected by the server", () => {
	within();

	expect( product.state.priceText ).toBe( '12,50 €' );
	expect( product.state.compareAtText ).toBe( '14,00 €' );
	expect( product.state.buttonLabel ).toBe( '«add»' );
	expect( product.state.cannotAdd ).toBe( false );
	expect( product.state.quantity ).toBe( 1 );
	expect( product.state.maxQuantity ).toBeNull();

	within( { axis: 'roast' } );

	expect( product.state.axisValueLabel ).toBe( 'Light' );
} );

test( 'a value that makes nothing to buy with the others is marked, and stays selectable', () => {
	within( { axis: 'roast', value: 'dark' } );

	expect( product.state.valueUnavailable ).toBe( true );
	expect( product.state.valueUnavailableText ).toBe( '«value_unavailable»' );

	within( { axis: 'roast', value: 'light' } );

	expect( product.state.valueUnavailable ).toBe( false );
	expect( product.state.valueUnavailableText ).toBe( '' );

	within( { axis: 'roast', value: 'dark' } );
	product.actions.select();
	within();

	expect( product.state.priceText ).toBe( '12,50 €' );
	expect( product.state.compareAtText ).toBe( '' );
	expect( product.state.buttonLabel ).toBe( '«sold_out»' );
	expect( product.state.cannotAdd ).toBe( true );
} );

test( 'a combination the product does not sell shows no price and cannot be added', () => {
	within( { axis: 'size', value: '1kg' } );
	product.actions.select();
	within();

	expect( product.state.priceText ).toBe( '' );
	expect( product.state.buttonLabel ).toBe( '«unavailable»' );
	expect( product.state.cannotAdd ).toBe( true );

	within( { axis: 'roast', value: 'light' } );
	product.actions.select();
	within();

	expect( product.state.buttonLabel ).toBe( '«unavailable»' );

	within( { axis: 'size', value: '250g' } );
	product.actions.select();
	within();

	expect( product.state.buttonLabel ).toBe( '«add»' );
} );

test( 'the quantity stays within one and the most the form allows', () => {
	within();
	product.actions.decrease();

	expect( product.state.quantity ).toBe( 1 );

	product.actions.increase();
	product.actions.increase();

	expect( product.state.quantity ).toBe( 3 );

	product.actions.setQuantity( { target: { value: '0' } } );

	expect( product.state.quantity ).toBe( 1 );

	product.actions.setQuantity( { target: { value: 'two' } } );

	expect( product.state.quantity ).toBe( 1 );

	product.actions.setQuantity( { target: { value: '2' } } );

	expect( product.state.quantity ).toBe( 2 );
} );

test( 'an add reads "Adding…" and is busy, then confirms, and pushes add_to_cart with the page\'s price', async () => {
	const event = { preventDefault: jest.fn() };

	within();

	const adding = product.actions.add( event );

	expect( event.preventDefault ).toHaveBeenCalled();
	expect( product.state.adding ).toBe( true );
	expect( product.state.buttonLabel ).toBe( '«adding»' );

	await adding;

	expect( product.state.adding ).toBe( false );
	expect( product.state.buttonLabel ).toBe( '«add»' );
	expect( product.state.statusText ).toBe(
		'«added_variant» House blend 250 g / Light'
	);
	expect( product.state.alertText ).toBe( '' );
	expect( api.lines ).toEqual( [
		{ line_identity: 'line-11', variant_id: 11, quantity: 2 },
	] );
	expect( pushed()[ 1 ] ).toEqual( {
		event: 'add_to_cart',
		ecommerce: {
			currency: 'EUR',
			items: [
				{
					product_uuid: '0193b7a2-7c1e-7d2a-9f00-5e1a2b3c4d5e',
					variant_id: 11,
					name: 'House blend',
					variant_label: '250 g / Light',
					quantity: 2,
					unit_price_minor: 1250,
				},
			],
		},
	} );
} );

test( 'a second add while one is out does nothing', async () => {
	within();

	const first = product.actions.add( { preventDefault: () => {} } );
	const second = product.actions.add( { preventDefault: () => {} } );

	await Promise.all( [ first, second ] );

	expect( api.lines[ 0 ].quantity ).toBe( 4 );
	expect( pushed() ).toHaveLength( 3 );
} );

test( 'an add flagged short of stock says so in the status region and caps the quantity', async () => {
	api.unavailable = [
		{
			line_identity: 'line-11',
			variant_id: 11,
			reason: 'insufficient_stock',
			for_sale: 5,
		},
	];
	within();
	product.actions.setQuantity( { target: { value: '7' } } );

	await product.actions.add( { preventDefault: () => {} } );

	expect( product.state.statusText ).toBe( '«insufficient» 5 11' );
	expect( product.state.maxQuantity ).toBe( 5 );
	expect( product.state.quantity ).toBe( 5 );

	product.actions.increase();

	expect( product.state.quantity ).toBe( 5 );
} );

test( 'an add flagged out of stock makes the variant sold out on the page', async () => {
	api.unavailable = [
		{
			line_identity: 'line-11',
			variant_id: 11,
			reason: 'out_of_stock',
			for_sale: 0,
		},
	];
	within();

	await product.actions.add( { preventDefault: () => {} } );

	expect( product.state.statusText ).toBe( '«out_of_stock»' );
	expect( product.state.buttonLabel ).toBe( '«sold_out»' );
	expect( product.state.cannotAdd ).toBe( true );

	within( { axis: 'size', value: '250g' } );

	expect( product.state.valueUnavailable ).toBe( true );
} );

test( 'a refused add is said in the alert region, and pushes nothing', async () => {
	const before = pushed().length;
	const state = runtime.state[ 'seocart/product' ].products[ POST ];

	state.variants[ 0 ].inStock = true;
	api.unavailable = [];
	api.refuse = { status: 429, body: { code: 'store_api.rate_limited' } };
	within();

	await product.actions.add( { preventDefault: () => {} } );

	expect( product.state.alertText ).toBe( '«rate_limited»' );
	expect( product.state.statusText ).toBe( '' );
	expect( pushed() ).toHaveLength( before );
} );
