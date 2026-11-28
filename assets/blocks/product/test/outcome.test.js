/**
 * What the form says after an add, from the store's answer; every word from the store's strings.
 */

/**
 * Internal dependencies
 */
import { afterAdd } from '../outcome';
import { coffee } from './fixtures/product';
import { STRINGS } from '../../test/fixtures/strings';

jest.mock( '@seocart/store', () => require( '../../store/notice' ), {
	virtual: true,
} );

/**
 * Returns an accepted add's outcome: a cart that holds a line of a variant.
 *
 * @param {Object} flags    The answer's flags: `unpriced_lines` and `unavailable_lines`.
 * @param {number} quantity How many units the cart holds of variant 11.
 * @return {Object} The outcome.
 */
function accepted( flags = {}, quantity = 2 ) {
	return {
		ok: true,
		cart: {
			version: 4,
			lines: [
				{ line_identity: 'a', variant_id: 11, quantity },
				{ line_identity: 'b', variant_id: 12, quantity: 9 },
			],
			unpriced_lines: [],
			unavailable_lines: [],
			...flags,
		},
	};
}

const product = coffee();
const [ variant ] = product.variants;

test( "an add accepted unflagged is confirmed by the product's name and the variant's label", () => {
	expect( afterAdd( accepted(), product, variant, STRINGS ) ).toEqual( {
		status: '«added_variant» House blend 250 g / Light',
		alert: '',
		max: undefined,
		soldOut: false,
	} );
	expect(
		afterAdd( accepted(), product, { ...variant, label: '' }, STRINGS )
			.status
	).toBe( '«added» House blend' );
} );

test( 'an add flagged short of stock says how many are for sale and how many the cart holds, and caps the quantity', () => {
	const flagged = accepted(
		{
			unavailable_lines: [
				{
					line_identity: 'a',
					variant_id: 11,
					reason: 'insufficient_stock',
					for_sale: 3,
				},
			],
		},
		5
	);

	expect( afterAdd( flagged, product, variant, STRINGS ) ).toEqual( {
		status: '«insufficient» 3 5',
		alert: '',
		max: 3,
		soldOut: false,
	} );
} );

test( 'an add flagged out of stock says so and makes the variant sold out', () => {
	const flagged = accepted( {
		unavailable_lines: [
			{
				line_identity: 'a',
				variant_id: 11,
				reason: 'out_of_stock',
				for_sale: 0,
			},
		],
	} );

	expect( afterAdd( flagged, product, variant, STRINGS ) ).toEqual( {
		status: '«out_of_stock»',
		alert: '',
		max: undefined,
		soldOut: true,
	} );
} );

test( 'an add the cart could not price says it cannot be bought in the currency', () => {
	const flagged = accepted( {
		unpriced_lines: [ { line_identity: 'a', variant_id: 11 } ],
	} );

	expect( afterAdd( flagged, product, variant, STRINGS ).status ).toBe(
		'«unpriced»'
	);
} );

test( "another variant's flag says nothing of this one", () => {
	const flagged = accepted( {
		unavailable_lines: [
			{
				line_identity: 'b',
				variant_id: 12,
				reason: 'out_of_stock',
				for_sale: 0,
			},
		],
	} );

	expect( afterAdd( flagged, product, variant, STRINGS ).status ).toBe(
		'«added_variant» House blend 250 g / Light'
	);
} );

test( 'a refused or lost add is said in the alert region alone', () => {
	expect(
		afterAdd(
			{ ok: false, notice: { key: 'unconfirmed', values: {} } },
			product,
			variant,
			STRINGS
		)
	).toEqual( {
		status: '',
		alert: '«unconfirmed»',
		max: undefined,
		soldOut: false,
	} );
	expect(
		afterAdd(
			{ ok: false, notice: { message: 'At most 50 lines.' } },
			product,
			variant,
			STRINGS
		).alert
	).toBe( 'At most 50 lines.' );
} );
