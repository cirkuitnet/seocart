/**
 * Internal dependencies
 */
import {
	currencyName,
	currencySymbol,
	formatAmount,
	formatMoney,
	parseAmount,
	parseWholeNumber,
} from '../amount';

describe( 'parseAmount', () => {
	it( 'reads an amount into minor units, padding the decimal places', () => {
		expect( parseAmount( '19.99', 2 ) ).toBe( 1999 );
		expect( parseAmount( '19.9', 2 ) ).toBe( 1990 );
		expect( parseAmount( '19', 2 ) ).toBe( 1900 );
		expect( parseAmount( '0.05', 2 ) ).toBe( 5 );
		expect( parseAmount( ' 19,99 ', 2 ) ).toBe( 1999 );
		expect( parseAmount( '1.234', 3 ) ).toBe( 1234 );
		expect( parseAmount( '1999', 0 ) ).toBe( 1999 );
	} );

	it( 'reads no text as no amount', () => {
		expect( parseAmount( '', 2 ) ).toBeNull();
		expect( parseAmount( '   ', 2 ) ).toBeNull();
	} );

	it( 'refuses text that is not an amount of the currency', () => {
		expect( parseAmount( '19.999', 2 ) ).toBeUndefined();
		expect( parseAmount( '19.5', 0 ) ).toBeUndefined();
		expect( parseAmount( '-1', 2 ) ).toBeUndefined();
		expect( parseAmount( '1e3', 2 ) ).toBeUndefined();
		expect( parseAmount( 'abc', 2 ) ).toBeUndefined();
		expect( parseAmount( '99999999999999999', 2 ) ).toBeUndefined();
	} );
} );

describe( 'formatAmount', () => {
	it( 'writes minor units with the decimal places of the currency', () => {
		expect( formatAmount( 1999, 2 ) ).toBe( '19.99' );
		expect( formatAmount( 5, 2 ) ).toBe( '0.05' );
		expect( formatAmount( 0, 2 ) ).toBe( '0.00' );
		expect( formatAmount( 1234, 3 ) ).toBe( '1.234' );
		expect( formatAmount( 1999, 0 ) ).toBe( '1999' );
	} );

	it( 'writes no amount as no text', () => {
		expect( formatAmount( null, 2 ) ).toBe( '' );
		expect( formatAmount( undefined, 2 ) ).toBe( '' );
	} );

	it( 'is read back as the same amount', () => {
		for ( const minor of [ 0, 1, 99, 100, 1999, 123456789 ] ) {
			expect( parseAmount( formatAmount( minor, 2 ), 2 ) ).toBe( minor );
		}
	} );
} );

describe( 'parseWholeNumber', () => {
	it( 'reads digits, and no text as nothing', () => {
		expect( parseWholeNumber( '250' ) ).toBe( 250 );
		expect( parseWholeNumber( '' ) ).toBeNull();
	} );

	it( 'refuses anything else', () => {
		expect( parseWholeNumber( '2.5' ) ).toBeUndefined();
		expect( parseWholeNumber( '-3' ) ).toBeUndefined();
		expect( parseWholeNumber( 'ten' ) ).toBeUndefined();
	} );
} );

describe( 'formatMoney', () => {
	it( 'writes an amount with its currency and its own decimal places', () => {
		expect(
			formatMoney( 1800, { code: 'USD', exponent: 2 }, 'en-US' )
		).toBe( '$18.00' );
		expect(
			formatMoney( 1200, { code: 'JPY', exponent: 0 }, 'en-US' )
		).toBe( '¥1,200' );
		expect(
			formatMoney( 5490, { code: 'EUR', exponent: 2 }, 'de-DE' )
		).toBe( '54,90\u00a0€' );
	} );

	it( 'is exact at the largest amount a price may be', () => {
		expect(
			formatMoney(
				Number.MAX_SAFE_INTEGER,
				{ code: 'USD', exponent: 2 },
				'en-US'
			)
		).toBe( '$90,071,992,547,409.91' );
	} );

	it( 'writes nothing for no amount', () => {
		expect(
			formatMoney( null, { code: 'USD', exponent: 2 }, 'en-US' )
		).toBe( '' );
	} );
} );

describe( 'currencySymbol', () => {
	it( 'writes a currency’s symbol', () => {
		expect( currencySymbol( 'USD', 'en-US' ) ).toBe( '$' );
		expect( currencySymbol( 'EUR', 'de-DE' ) ).toBe( '€' );
	} );
} );

describe( 'currencyName', () => {
	it( 'names a currency', () => {
		expect( currencyName( 'USD', 'en-US' ) ).toBe( 'US Dollar' );
	} );
} );
