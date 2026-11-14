/**
 * Internal dependencies
 */
import {
	returnedIntent,
	returnUrlFor,
	routeAction,
	withoutReturn,
} from '../action';

const UUID = '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e';

const REDIRECT = {
	type: 'redirect',
	url: 'https://bank.example/3ds',
	client_token: 'h',
};

const SDK = { type: 'sdk', url: null, client_token: 'pi_1_secret_2' };

const TAKER = { supports: { action: true } };

const HANDLE = { action: async () => 'completed' };

describe( 'routeAction', () => {
	it( 'offers the island the step first, whatever its kind, when it takes steps', () => {
		expect( routeAction( TAKER, HANDLE, REDIRECT ) ).toBe( 'island' );
		expect( routeAction( TAKER, HANDLE, SDK ) ).toBe( 'island' );
	} );

	it( 'takes a redirect itself when the island does not take steps', () => {
		expect( routeAction( {}, HANDLE, REDIRECT ) ).toBe( 'redirect' );
		expect( routeAction( TAKER, {}, REDIRECT ) ).toBe( 'redirect' );
		expect( routeAction( undefined, null, REDIRECT ) ).toBe( 'redirect' );
	} );

	it( 'cannot take a script’s step without an island that takes it', () => {
		expect( routeAction( {}, HANDLE, SDK ) ).toBe( 'unsupported' );
		expect( routeAction( undefined, null, SDK ) ).toBe( 'unsupported' );
		expect(
			routeAction( undefined, null, { type: 'redirect', url: null } )
		).toBe( 'unsupported' );
	} );
} );

describe( 'the return address', () => {
	it( 'is this page with the payment’s uuid as its only argument', () => {
		expect(
			returnUrlFor(
				'https://shop.example/checkout/?step=pay&order_key=k#payment',
				'seocart_payment',
				UUID
			)
		).toBe( `https://shop.example/checkout/?seocart_payment=${ UUID }` );
	} );

	it( 'is read back from the page’s address', () => {
		expect(
			returnedIntent(
				`https://shop.example/checkout/?seocart_payment=${ UUID.toUpperCase() }`,
				'seocart_payment'
			)
		).toBe( UUID );
	} );

	it.each( [
		[ 'no argument', 'https://shop.example/checkout/' ],
		[ 'no uuid', 'https://shop.example/checkout/?seocart_payment=1%27' ],
		[
			'another argument',
			`https://shop.example/checkout/?other=${ UUID }`,
		],
	] )( 'is nothing to resume with %s', ( name, href ) => {
		expect( returnedIntent( href, 'seocart_payment' ) ).toBeNull();
	} );

	it( 'is removed from the page’s address once handled, the rest kept', () => {
		expect(
			withoutReturn(
				`https://shop.example/checkout/?lang=fr&seocart_payment=${ UUID }`,
				'seocart_payment'
			)
		).toBe( 'https://shop.example/checkout/?lang=fr' );
	} );
} );
