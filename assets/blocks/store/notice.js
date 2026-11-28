/**
 * The storefront's words in the browser: every sentence is the server's, translated, in the store's
 * configuration (`strings`), and a module names one by its key, with the values of its
 * placeholders, which are names in braces such as `{name}`.
 */

/**
 * Names a sentence to show, with its values.
 *
 * @param {string}             key    The sentence's key in the configuration's strings.
 * @param {Object<string, *>=} values Each placeholder's value, by its name.
 * @return {{key: string, values: Object<string, *>}} The notice.
 */
export function notice( key, values = {} ) {
	return { key, values };
}

/**
 * Returns a sentence of the configuration's strings, its placeholders filled in one pass, so a
 * value that holds a placeholder's name is shown as it is.
 *
 * @param {Object<string, string>} strings The configuration's strings.
 * @param {string}                 key     The sentence's key.
 * @param {Object<string, *>=}     values  Each placeholder's value, by its name.
 * @return {string} The sentence; empty for a key the strings do not have.
 */
export function text( strings, key, values = {} ) {
	return ( strings[ key ] ?? '' ).replace(
		/\{([a-z_]+)\}/g,
		( whole, name ) => ( name in values ? String( values[ name ] ) : whole )
	);
}

/**
 * Returns the sentence of a notice: the configuration's, or the one the store's answer carried.
 *
 * @param {Object<string, string>} strings The configuration's strings.
 * @param {Object}                 shown   The notice: `{ key, values }` or `{ message }`.
 * @return {string} The sentence.
 */
export function noticeText( strings, shown ) {
	return undefined === shown.message
		? text( strings, shown.key, shown.values )
		: shown.message;
}
