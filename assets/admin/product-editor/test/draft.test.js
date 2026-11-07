/**
 * Internal dependencies
 */
import { parseAmount, parseWholeNumber } from '../amount';
import { committedOnLeaving, committedOnTyping } from '../draft';
import {
	entryOfRead,
	GROSS,
	NET,
	priceIn,
	seocartEdit,
	withPrice,
	withPriceFields,
	withWeight,
} from '../model';

const LIMIT = 250;
const USD = 'USD';

/**
 * Reads what a merchant types in a price field in US dollars.
 *
 * @param {string} text The text.
 * @return {number|null|undefined} The amount in cents, null for none, undefined when it cannot be read.
 */
const parse = ( text ) => parseAmount( text, 2 );

/**
 * Lists the texts a field shows as a merchant empties it one Backspace at a time, then types text into it a key at a time.
 *
 * @param {string} shown The text the field showed.
 * @param {string} text  The text typed.
 * @return {Array<string>} The field's text after each keystroke.
 */
function keystrokes( shown, text ) {
	return [
		...Array.from( shown, ( character, erased ) =>
			shown.slice( 0, shown.length - erased - 1 )
		),
		...Array.from( text, ( character, typed ) =>
			text.slice( 0, typed + 1 )
		),
	];
}

/**
 * Says what a field commits as the merchant types, before they leave it.
 *
 * @param {*}             focused The field's value when the merchant came into it.
 * @param {Array<string>} texts   The field's text after each keystroke.
 * @param {Function}      [read]  Reads text; a price in US dollars by default.
 * @return {Array} The values the field committed, in order.
 */
function committedWhileTyping( focused, texts, read = parse ) {
	return texts
		.map( ( text ) => committedOnTyping( text, read, focused ) )
		.filter( ( value ) => value !== undefined );
}

/**
 * Empties a price field one Backspace at a time, types text into it a key at a time, and leaves it, as a merchant does.
 *
 * @param {string} shown The text the field showed when the merchant came into it.
 * @param {string} text  The text typed.
 * @return {Array} The values the field committed, in order.
 */
function retype( shown, text ) {
	const texts = keystrokes( shown, text );
	const commits = committedWhileTyping( parse( shown ), texts );
	const { commit } = committedOnLeaving( texts[ texts.length - 1 ], parse );

	return commit === undefined ? commits : [ ...commits, commit ];
}

/**
 * A product without options as the `edit` context reads it: one variant at USD 20.00, compare-at 30.00, tax included, 250 g.
 *
 * @return {{saved: Object, read: Object}} The `seocart` object and its variant.
 */
function storedProduct() {
	const read = {
		variant_id: 100,
		combination: [],
		sku: 'SKU',
		enabled: true,
		position: 0,
		label: null,
		weight_grams: 250,
		prices: [
			{
				currency: USD,
				price_minor: 2000,
				compare_at_minor: 3000,
				amount_basis: GROSS,
			},
		],
		stock: [],
		sellability: 'sellable',
	};

	return { saved: { options: [], variants: [ read ] }, read };
}

/**
 * Edits the stored price's compare-at or basis, then gives the amount the values its field commits, and says what the save sends.
 *
 * @param {Object} fields  The fields edited beside the amount.
 * @param {Array}  commits The values the amount's field commits.
 * @return {Object|undefined} The price in US dollars the save sends; none when it sends no variant.
 */
function editThenCommit( fields, commits ) {
	const { saved, read } = storedProduct();
	let entry = withPriceFields( entryOfRead( read ), read, USD, fields );

	for ( const minor of commits ) {
		entry = withPrice( entry, read, USD, minor, USD );
	}

	const edit = seocartEdit( saved, undefined, [], [ entry ], LIMIT );

	return edit.variants && priceIn( edit.variants[ 0 ], USD );
}

/**
 * Edits the stored price's compare-at or basis, then retypes the amount in its field, and says what the save sends.
 *
 * @param {Object} fields The fields edited beside the amount.
 * @param {string} typed  The amount retyped.
 * @return {Object|undefined} The price in US dollars the save sends; none when it sends no variant.
 */
function editThenRetype( fields, typed ) {
	return editThenCommit( fields, retype( '20.00', typed ) );
}

describe( 'a field emptied while the merchant types', () => {
	it( 'commits the value it had when they came into it until they leave it, and then its emptiness', () => {
		expect( retype( '20.00', '' ) ).toEqual( [
			2000,
			2000,
			2000,
			200,
			2000,
			null,
		] );
	} );

	it( 'commits what is typed after it', () => {
		expect( retype( '20.00', '25' ) ).toEqual( [
			2000, 2000, 2000, 200, 2000, 200, 2500,
		] );
	} );

	it( 'never ends on what its text read before its last character went, such as 2.00 of 20.00 or 2 of 250', () => {
		expect(
			committedWhileTyping( 2000, keystrokes( '20.00', '' ) )
		).toEqual( [ 2000, 2000, 2000, 200, 2000 ] );
		expect(
			committedWhileTyping(
				250,
				keystrokes( '250', '' ),
				parseWholeNumber
			)
		).toEqual( [ 25, 2, 250 ] );
	} );

	it( 'commits its emptiness when it had no value, whatever was typed in it first', () => {
		expect(
			committedWhileTyping( undefined, [ '2', '25', '2', '' ] )
		).toEqual( [ 200, 2500, 200, null ] );
		expect(
			committedWhileTyping(
				undefined,
				[ 'A', 'AB', 'A', '' ],
				( text ) => text
			)
		).toEqual( [ 'A', 'AB', 'A', '' ] );
	} );

	it( 'keeps text that cannot be read, and commits nothing of it', () => {
		expect( committedOnTyping( 'twelve', parse, 2000 ) ).toBeUndefined();
		expect( committedOnLeaving( 'twelve', parse ) ).toEqual( {
			draft: 'twelve',
			commit: undefined,
		} );
		expect( committedOnLeaving( null, parse ) ).toEqual( {
			draft: null,
			commit: undefined,
		} );
		expect( committedOnLeaving( '25.00', parse ) ).toEqual( {
			draft: null,
			commit: undefined,
		} );
	} );
} );

describe( 'a field erased and never left, as when the code editor takes the area away', () => {
	it( 'leaves the save nothing to send of a price', () => {
		expect(
			editThenCommit(
				{},
				committedWhileTyping( 2000, keystrokes( '20.00', '' ) )
			)
		).toBeUndefined();
	} );

	it( 'leaves the save nothing to send of a weight', () => {
		const { saved, read } = storedProduct();
		let entry = entryOfRead( read );

		for ( const grams of committedWhileTyping(
			250,
			keystrokes( '250', '' ),
			parseWholeNumber
		) ) {
			entry = withWeight( entry, read, grams );
		}

		expect( seocartEdit( saved, undefined, [], [ entry ], LIMIT ) ).toEqual(
			{}
		);
	} );

	it( 'keeps the compare-at and the basis edited beside a price', () => {
		expect(
			editThenCommit(
				{ compare_at_minor: 3500, amount_basis: NET },
				committedWhileTyping( 2000, keystrokes( '20.00', '' ) )
			)
		).toEqual( {
			currency: USD,
			price_minor: 2000,
			compare_at_minor: 3500,
			amount_basis: NET,
		} );
	} );
} );

describe( 'a price retyped after its compare-at or basis was edited', () => {
	it( 'keeps the edited compare-at when the same amount is typed again', () => {
		expect( editThenRetype( { compare_at_minor: 3500 }, '20.00' ) ).toEqual(
			{
				currency: USD,
				price_minor: 2000,
				compare_at_minor: 3500,
				amount_basis: GROSS,
			}
		);
	} );

	it( 'keeps the edited basis when the same amount is typed again', () => {
		expect( editThenRetype( { amount_basis: NET }, '20.00' ) ).toEqual( {
			currency: USD,
			price_minor: 2000,
			compare_at_minor: 3000,
			amount_basis: NET,
		} );
	} );

	it( 'keeps both when a different amount is typed', () => {
		expect(
			editThenRetype(
				{ compare_at_minor: 3500, amount_basis: NET },
				'25.00'
			)
		).toEqual( {
			currency: USD,
			price_minor: 2500,
			compare_at_minor: 3500,
			amount_basis: NET,
		} );
	} );

	it( 'sends nothing when the same amount is typed again and nothing else was edited', () => {
		expect( editThenRetype( {}, '20.00' ) ).toBeUndefined();
	} );

	it( 'takes the price away when the field is left empty', () => {
		const { read } = storedProduct();
		let entry = entryOfRead( read );

		for ( const minor of retype( '20.00', '' ) ) {
			entry = withPrice( entry, read, USD, minor, USD );
		}

		expect( priceIn( entry, USD ) ).toBeUndefined();
	} );
} );
