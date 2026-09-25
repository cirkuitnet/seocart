/**
 * The post a new post translates, and the language it is written in, as the "add translation"
 * link that opened the editor names them.
 */

/**
 * Reads the post a new post translates from the query string of the editor's address.
 *
 * Polylang's "add translation" link names the original in the `from_post` parameter. Anything
 * but a whole number above zero names no post.
 *
 * @param {string} search The query string, such as `?post_type=seocart_product&from_post=12&new_lang=de`.
 * @return {number|undefined} The original's ID, or undefined when the query string names none.
 */
export function translatedPost( search ) {
	const value = new URLSearchParams( search ).get( 'from_post' );

	if ( value === null || ! /^[1-9]\d*$/.test( value ) ) {
		return undefined;
	}

	const id = Number( value );

	return Number.isSafeInteger( id ) ? id : undefined;
}

/**
 * Reads the locale of the language a new translation is written in from the query string of the editor's address.
 *
 * Polylang's "add translation" link names the language by its code in the `new_lang`
 * parameter. Only a code the site publishes in names a locale.
 *
 * @param {string}                 search    The query string.
 * @param {Object<string, string>} languages The locale of each language the site publishes in, by its code.
 * @return {string|undefined} The locale, such as de_DE, or undefined when the query string names no language the site publishes in.
 */
export function translationLocale( search, languages ) {
	const code = new URLSearchParams( search ).get( 'new_lang' );

	return code !== null &&
		languages !== null &&
		typeof languages === 'object' &&
		Object.prototype.hasOwnProperty.call( languages, code )
		? languages[ code ]
		: undefined;
}
