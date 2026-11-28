/**
 * The storefront's words in the browser: a sentence of the store's configuration, its placeholders filled.
 */

/**
 * Internal dependencies
 */
import { notice, noticeText, text } from '../notice';
import { STRINGS } from '../../test/fixtures/strings';

test( "a sentence is the configuration's, each named placeholder filled", () => {
	expect(
		text( STRINGS, 'insufficient', { for_sale: 3, quantity: 5 } )
	).toBe( '«insufficient» 3 5' );
	expect(
		text( STRINGS, 'added_variant', {
			name: 'Tea {label}',
			label: 'Loose',
		} )
	).toBe( '«added_variant» Tea {label} Loose' );
} );

test( 'a key the configuration does not hold reads as nothing', () => {
	expect( text( STRINGS, 'no_such_key' ) ).toBe( '' );
} );

test( "a notice reads as its sentence, or as the sentence the store's answer carried", () => {
	expect( noticeText( STRINGS, notice( 'cart_changed' ) ) ).toBe(
		'«cart_changed»'
	);
	expect( noticeText( STRINGS, { message: 'At most 50 lines.' } ) ).toBe(
		'At most 50 lines.'
	);
} );
