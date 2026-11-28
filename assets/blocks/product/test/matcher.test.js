/**
 * The matcher over a product whose matrix misses a combination.
 */

/**
 * Internal dependencies
 */
import {
	available,
	buttonLabel,
	findVariant,
	purchasable,
	valueLabel,
} from '../matcher';
import { coffee } from './fixtures/product';

test( 'a selection finds its variant, whatever the order of its axes', () => {
	const { variants } = coffee();

	expect( findVariant( variants, { size: '250g', roast: 'dark' } ).id ).toBe(
		12
	);
	expect( findVariant( variants, { roast: 'light', size: '1kg' } ).id ).toBe(
		13
	);
} );

test( 'a combination the product does not sell finds nothing', () => {
	const { variants } = coffee();

	expect(
		findVariant( variants, { size: '1kg', roast: 'dark' } )
	).toBeNull();
	expect( findVariant( variants, { size: '250g' } ) ).toBeNull();
} );

test( 'a product without options has its one variant for the empty selection', () => {
	const only = { id: 5, values: [], price: null, inStock: true };

	expect( findVariant( [ only ], [] ) ).toBe( only );
	expect( findVariant( [ only ], {} ) ).toBe( only );
} );

test( 'a variant can be added when it has a price and is in stock', () => {
	const { variants } = coffee();

	expect( variants.map( purchasable ) ).toEqual( [ true, false, false ] );
	expect( purchasable( null ) ).toBe( false );
} );

test( 'a value is unavailable when, with the other axes as selected, it makes nothing to buy', () => {
	const product = coffee();

	expect( available( product, 'size', '250g' ) ).toBe( true );
	expect( available( product, 'size', '1kg' ) ).toBe( false );
	expect( available( product, 'roast', 'light' ) ).toBe( true );
	expect( available( product, 'roast', 'dark' ) ).toBe( false );

	product.selection = { size: '1kg', roast: 'dark' };

	expect( available( product, 'size', '250g' ) ).toBe( false );
	expect( available( product, 'roast', 'light' ) ).toBe( false );
} );

test( 'the button reads add, sold out or unavailable', () => {
	const { variants } = coffee();

	expect( variants.map( buttonLabel ) ).toEqual( [
		'add',
		'sold_out',
		'unavailable',
	] );
	expect( buttonLabel( null ) ).toBe( 'unavailable' );
} );

test( "an axis's legend reads the label of its value selected", () => {
	const product = coffee();

	expect( valueLabel( product, 'roast' ) ).toBe( 'Light' );

	product.selection.roast = 'dark';

	expect( valueLabel( product, 'roast' ) ).toBe( 'Dark' );
	expect( valueLabel( product, 'grind' ) ).toBe( '' );
} );
