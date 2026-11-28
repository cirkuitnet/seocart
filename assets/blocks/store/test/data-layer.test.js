/**
 * The data layer: its declaration, the page that publishes it, and the one function that pushes.
 *
 * Planted violation, shown red and removed: `email` added to `add_to_cart`'s keys (the page and
 * the declaration differ).
 */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Internal dependencies
 */
import { EVENTS, ITEM, item, pushEvent, whenShown } from '../data-layer';
import { coffee } from '../../product/test/fixtures/product';

/**
 * The published page.
 *
 * @type {string}
 */
const PAGE = fs.readFileSync(
	path.resolve( __dirname, '../../../../docs/data-layer.md' ),
	'utf8'
);

/**
 * Returns the rows of the table under a heading of the page, each a list of its cells.
 *
 * @param {string} heading The heading, without its hashes.
 * @return {Array<string[]>} The rows below the table's header.
 */
function table( heading ) {
	const section = PAGE.split( /^## /m ).find( ( part ) =>
		part.startsWith( `${ heading }\n` )
	);

	return ( section ?? '' )
		.split( '\n' )
		.filter( ( line ) => line.startsWith( '| `' ) )
		.map( ( line ) =>
			line
				.split( '|' )
				.slice( 1, -1 )
				.map( ( cell ) => cell.trim() )
		);
}

/**
 * Returns the code spans of a cell.
 *
 * @param {string} cell The cell.
 * @return {string[]} What each code span holds.
 */
function codes( cell ) {
	return [ ...cell.matchAll( /`([^`]+)`/g ) ].map( ( [ , code ] ) => code );
}

/**
 * Returns an item of the fixture's first variant.
 *
 * @return {Object} The item.
 */
function anItem() {
	const product = coffee();

	return item( product, product.variants[ 0 ], 2 );
}

afterEach( () => {
	delete window.dataLayer;
} );

test( 'the page lists the events declared, each with its keys', () => {
	expect(
		Object.fromEntries(
			table( 'Events' ).map( ( [ event, , keys ] ) => [
				codes( event )[ 0 ],
				codes( keys ),
			] )
		)
	).toEqual( EVENTS );
} );

test( 'the page lists the keys of an item declared, in order', () => {
	expect(
		table( 'An item' ).map( ( [ key ] ) => codes( key )[ 0 ] )
	).toEqual( ITEM );
} );

test( "an item carries the declared keys, its price the page's in minor units", () => {
	expect( anItem() ).toEqual( {
		product_uuid: '0193b7a2-7c1e-7d2a-9f00-5e1a2b3c4d5e',
		variant_id: 11,
		name: 'House blend',
		variant_label: '250 g / Light',
		quantity: 2,
		unit_price_minor: 1250,
	} );
	expect( Object.keys( anItem() ) ).toEqual( ITEM );

	const product = coffee();

	expect(
		item( product, product.variants[ 2 ], 1 ).unit_price_minor
	).toBeNull();
} );

test( 'an event is pushed onto the data layer, which is created when the page has none', () => {
	pushEvent( 'view_item', { currency: 'EUR', items: [ anItem() ] }, window );

	expect( window.dataLayer ).toEqual( [
		{
			event: 'view_item',
			ecommerce: { currency: 'EUR', items: [ anItem() ] },
		},
	] );

	pushEvent(
		'add_to_cart',
		{ currency: 'EUR', items: [ anItem() ] },
		window
	);

	expect( window.dataLayer.map( ( { event } ) => event ) ).toEqual( [
		'view_item',
		'add_to_cart',
	] );
} );

test( "a page's own data layer keeps what it holds", () => {
	window.dataLayer = [ { event: 'gtm.js' } ];

	pushEvent( 'view_item', { currency: 'EUR', items: [] }, window );

	expect( window.dataLayer ).toHaveLength( 2 );
	expect( window.dataLayer[ 0 ] ).toEqual( { event: 'gtm.js' } );
} );

test( 'an event, a key or an item key outside the declaration is refused, and nothing is pushed', () => {
	expect( () =>
		pushEvent( 'purchase', { currency: 'EUR', items: [] }, window )
	).toThrow( 'seocart.data_layer.event:purchase' );
	expect( () =>
		pushEvent(
			'add_to_cart',
			{ currency: 'EUR', items: [], email: 'a@example.com' },
			window
		)
	).toThrow( 'seocart.data_layer.add_to_cart:email' );
	expect( () =>
		pushEvent(
			'add_to_cart',
			{ currency: 'EUR', items: [ { ...anItem(), customer_id: 9 } ] },
			window
		)
	).toThrow( 'seocart.data_layer.item:customer_id' );
	expect( window.dataLayer ).toBeUndefined();
} );

test( 'pushing fetches nothing and sends nothing', () => {
	const fetch = jest.fn();
	const beacon = jest.fn();
	const send = jest.spyOn( window.XMLHttpRequest.prototype, 'send' );

	window.fetch = fetch;
	window.navigator.sendBeacon = beacon;

	pushEvent(
		'add_to_cart',
		{ currency: 'EUR', items: [ anItem() ] },
		window
	);

	expect( fetch ).not.toHaveBeenCalled();
	expect( beacon ).not.toHaveBeenCalled();
	expect( send ).not.toHaveBeenCalled();
	expect( document.querySelectorAll( 'script, img, iframe' ) ).toHaveLength(
		0
	);

	send.mockRestore();
	delete window.fetch;
	delete window.navigator.sendBeacon;
} );

test( 'a page is shown at once, or when a prerendered or hidden page is first seen', () => {
	const listeners = {};
	const page = {
		prerendering: true,
		visibilityState: 'hidden',
		addEventListener: ( name, listener ) =>
			( listeners[ name ] = listener ),
		removeEventListener: ( name ) => delete listeners[ name ],
	};
	const shown = jest.fn();

	whenShown( page, shown );

	expect( shown ).not.toHaveBeenCalled();

	page.prerendering = false;
	listeners.prerenderingchange();

	expect( shown ).not.toHaveBeenCalled();

	page.visibilityState = 'visible';
	listeners.visibilitychange();

	expect( shown ).toHaveBeenCalledTimes( 1 );
	expect( listeners.visibilitychange ).toBeUndefined();

	whenShown( page, shown );

	expect( shown ).toHaveBeenCalledTimes( 2 );
} );
