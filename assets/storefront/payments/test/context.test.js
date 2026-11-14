/**
 * Internal dependencies
 */
import { contextFor, needsRemount } from '../context';

const METHOD = Object.freeze( {
	id: 'stripe',
	mode: 'test',
	settings: Object.freeze( { publishable_key: 'pk_test_a' } ),
	currencies: Object.freeze( [ 'GBP', 'EUR' ] ),
} );

const CART = {
	version: 4,
	amountMinor: 1999,
	currency: 'GBP',
	billingCountry: 'GB',
};

const CONFIG = { locale: 'en_GB' };

describe( 'contextFor', () => {
	it( 'gives the island the ten members, frozen', () => {
		const context = contextFor( METHOD, CART, CONFIG );

		expect( context ).toEqual( {
			gatewayId: 'stripe',
			mode: 'test',
			channel: 'storefront',
			amountMinor: 1999,
			currency: 'GBP',
			billingCountry: 'GB',
			cartVersion: 4,
			settings: { publishable_key: 'pk_test_a' },
			currencies: [ 'GBP', 'EUR' ],
			locale: 'en_GB',
		} );
		expect( Object.isFrozen( context ) ).toBe( true );
	} );

	it( 'adds the billing address when the checkout gives one, frozen and copied', () => {
		const billing = { country: 'GB', postcode: 'SW1Y 4JH' };
		const context = contextFor( METHOD, { ...CART, billing }, CONFIG );

		expect( context.billing ).toEqual( billing );
		expect( Object.isFrozen( context.billing ) ).toBe( true );
		expect( Object.isFrozen( billing ) ).toBe( false );
	} );

	it( 'says null for a billing country the checkout does not know yet', () => {
		const { billingCountry, ...unknown } = CART;

		expect( billingCountry ).toBe( 'GB' );
		expect(
			contextFor( METHOD, unknown, CONFIG ).billingCountry
		).toBeNull();
	} );
} );

describe( 'needsRemount', () => {
	const before = contextFor( METHOD, CART, CONFIG );

	it.each( [
		[ 'the amount', { ...CART, amountMinor: 2999 }, METHOD ],
		[ 'the currency', { ...CART, currency: 'EUR' }, METHOD ],
		[ 'the gateway', CART, { ...METHOD, id: 'paypal' } ],
		[ 'the mode', CART, { ...METHOD, mode: 'live' } ],
		[
			'a setting',
			CART,
			{ ...METHOD, settings: { publishable_key: 'pk_test_b' } },
		],
		[
			'a setting added',
			CART,
			{
				...METHOD,
				settings: {
					publishable_key: 'pk_test_a',
					account_country: 'GB',
				},
			},
		],
	] )( 'mounts again when %s changes', ( change, cart, method ) => {
		expect(
			needsRemount( before, contextFor( method, cart, CONFIG ) )
		).toBe( true );
	} );

	it( 'does not mount again when only the version or the billing country moves', () => {
		expect(
			needsRemount(
				before,
				contextFor(
					METHOD,
					{ ...CART, version: 9, billingCountry: 'FR' },
					CONFIG
				)
			)
		).toBe( false );
	} );
} );
