/**
 * Internal dependencies
 */
import { translatedPost, translationLocale } from '../translation';

describe( 'translatedPost', () => {
	it( 'reads the original from an "add translation" link', () => {
		expect(
			translatedPost(
				'?post_type=seocart_product&from_post=12&new_lang=de&_wpnonce=abc'
			)
		).toBe( 12 );
		expect( translatedPost( 'from_post=7' ) ).toBe( 7 );
	} );

	it( 'names no post for an editor opened any other way', () => {
		expect( translatedPost( '' ) ).toBeUndefined();
		expect(
			translatedPost( '?post_type=seocart_product' )
		).toBeUndefined();
		expect( translatedPost( '?post=12&action=edit' ) ).toBeUndefined();
	} );

	it( 'refuses anything but a whole number above zero', () => {
		expect( translatedPost( '?from_post=0' ) ).toBeUndefined();
		expect( translatedPost( '?from_post=-3' ) ).toBeUndefined();
		expect( translatedPost( '?from_post=12abc' ) ).toBeUndefined();
		expect( translatedPost( '?from_post=1.5' ) ).toBeUndefined();
		expect( translatedPost( '?from_post=' ) ).toBeUndefined();
		expect(
			translatedPost( '?from_post=99999999999999999999' )
		).toBeUndefined();
	} );
} );

describe( 'translationLocale', () => {
	const languages = { en: 'en_US', 'en-gb': 'en_GB', de: 'de_DE' };

	it( 'reads the locale of the language the link asks for', () => {
		expect(
			translationLocale(
				'?post_type=seocart_product&from_post=12&new_lang=de',
				languages
			)
		).toBe( 'de_DE' );
		expect( translationLocale( '?new_lang=en-gb', languages ) ).toBe(
			'en_GB'
		);
	} );

	it( 'names no locale for a language the site does not publish in, or none', () => {
		expect(
			translationLocale( '?new_lang=fr', languages )
		).toBeUndefined();
		expect(
			translationLocale( '?from_post=12', languages )
		).toBeUndefined();
		expect(
			translationLocale( '?new_lang=toString', languages )
		).toBeUndefined();
		expect( translationLocale( '?new_lang=de', [] ) ).toBeUndefined();
	} );
} );
