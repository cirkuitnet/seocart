/**
 * Internal dependencies
 */
import { STORAGE_KEY, createKeeper, keepsKey, samePlacement } from '../keeper';

const INPUTS = {
	cartVersion: 3,
	amountMinor: 1999,
	currency: 'GBP',
	gatewayId: 'stub',
	paymentData: { payment_token: 'stub:approve' },
};

/**
 * Returns a key minter that counts.
 *
 * @return {function(): string} The minter.
 */
function counter() {
	let minted = 0;

	return () => `key-${ ++minted }`;
}

describe( 'keepsKey', () => {
	it.each( [
		'network',
		'server',
		'unavailable',
		'in_progress',
		'pending',
		'session',
		'acting',
	] )( 'keeps the key after %s', ( kind ) => {
		expect( keepsKey( kind ) ).toBe( true );
	} );

	it.each( [ 'settled', 'declined', 'voided', 'stale', 'refused' ] )(
		'ends the key after %s',
		( kind ) => {
			expect( keepsKey( kind ) ).toBe( false );
		}
	);
} );

describe( 'samePlacement', () => {
	const kept = { key: 'k', ...INPUTS };

	it( 'is the same placement for the same total, currency and gateway, whatever the version', () => {
		expect(
			samePlacement( kept, {
				amountMinor: 1999,
				currency: 'GBP',
				gatewayId: 'stub',
			} )
		).toBe( true );
	} );

	it.each( [
		[ 'total', { amountMinor: 2999, currency: 'GBP', gatewayId: 'stub' } ],
		[
			'currency',
			{ amountMinor: 1999, currency: 'EUR', gatewayId: 'stub' },
		],
		[
			'gateway',
			{ amountMinor: 1999, currency: 'GBP', gatewayId: 'other' },
		],
	] )( 'is another placement when the %s changed', ( change, inputs ) => {
		expect( samePlacement( kept, inputs ) ).toBe( false );
	} );

	it( 'is no placement when nothing is kept', () => {
		expect( samePlacement( null, INPUTS ) ).toBe( false );
	} );
} );

describe( 'createKeeper', () => {
	beforeEach( () => window.sessionStorage.clear() );

	it( 'stores the six members of a placement and never its payment data, and keeps all of it for the page', () => {
		const keeper = createKeeper( window, counter() );
		const placement = keeper.begin( INPUTS );
		const text = window.sessionStorage.getItem( STORAGE_KEY );

		expect( placement ).toEqual( {
			key: 'key-1',
			...INPUTS,
			intentUuid: null,
		} );
		expect( JSON.parse( text ) ).toEqual( {
			key: 'key-1',
			cartVersion: 3,
			amountMinor: 1999,
			currency: 'GBP',
			gatewayId: 'stub',
			intentUuid: null,
		} );
		expect( text ).not.toContain( 'stub:approve' );
		expect( text ).not.toMatch( /payment|order_key|orderKey/ );
		expect( keeper.read() ).toEqual( placement );
	} );

	it( 'reads a placement another load of the page stored, without payment data', () => {
		createKeeper( window, counter() ).write( {
			key: 'key-1',
			...INPUTS,
			intentUuid: 'u',
		} );

		expect( createKeeper( window, counter() ).read() ).toEqual( {
			key: 'key-1',
			cartVersion: 3,
			amountMinor: 1999,
			currency: 'GBP',
			gatewayId: 'stub',
			intentUuid: 'u',
			paymentData: null,
		} );
	} );

	it( 'ends the key when cleared', () => {
		const keeper = createKeeper( window, counter() );

		keeper.begin( INPUTS );
		keeper.clear();

		expect( window.sessionStorage.getItem( STORAGE_KEY ) ).toBeNull();
		expect( keeper.read() ).toBeNull();
	} );

	it( 'mints a fresh key for every placement begun', () => {
		const keeper = createKeeper( window, counter() );

		expect( keeper.begin( INPUTS ).key ).toBe( 'key-1' );
		expect( keeper.begin( INPUTS ).key ).toBe( 'key-2' );
	} );

	it( 'ignores what it cannot read back', () => {
		window.sessionStorage.setItem( STORAGE_KEY, '{"key":' );

		expect( createKeeper( window, counter() ).read() ).toBeNull();

		window.sessionStorage.setItem( STORAGE_KEY, '{"key":"k"}' );

		expect( createKeeper( window, counter() ).read() ).toBeNull();
	} );

	it.each( [
		[ 'is missing', {} ],
		[
			'refuses access',
			Object.defineProperty( {}, 'sessionStorage', {
				get() {
					throw new Error( 'SecurityError' );
				},
			} ),
		],
		[
			'refuses writes',
			{
				sessionStorage: {
					getItem: () => null,
					setItem() {
						throw new Error( 'QuotaExceededError' );
					},
					removeItem() {},
				},
			},
		],
	] )(
		'keeps the placement for the page when storage %s',
		( name, scope ) => {
			const keeper = createKeeper( scope, counter() );
			const placement = keeper.begin( INPUTS );

			expect( keeper.read() ).toEqual( placement );

			keeper.clear();

			expect( keeper.read() ).toBeNull();
		}
	);
} );
