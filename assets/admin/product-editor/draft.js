/**
 * What a text field of the area commits of the text a merchant types, keystroke by keystroke and when they leave it.
 *
 * Text is committed as it is typed, as soon as it can be read. A field emptied while the merchant
 * types commits the value the running save carries while one runs, and otherwise the value it had
 * when they came into it or the value the last successful save since then stored; and its emptiness
 * only when they leave it.
 * Emptying a price to type another is how a price is retyped: its emptiness committed at once would
 * take the price away, and with it what the merchant set beside it, such as its compare-at price and
 * whether it includes tax, so the price typed next would start again from the price as read. And a
 * field that committed nothing would leave the product with what its text read before its last
 * character went, such as 2.00 of an erased 20.00, if the field went away before it was left.
 */

/**
 * Says what a keystroke commits.
 *
 * @param {string}   text    The field's text after the keystroke.
 * @param {Function} parse   Reads text: the value, or undefined when it is not one.
 * @param {*}        focused The value an emptied field keeps: the one the running save carries while one runs, else
 *                           the field's value when the merchant came into it or the one the last successful save since
 *                           then stored.
 * @return {*} The value the text reads as; for an emptied field, `focused`, or its emptiness when it had none; undefined
 *             commits nothing, for text that cannot be read.
 */
export function committedOnTyping( text, parse, focused ) {
	if ( text.trim() !== '' ) {
		return parse( text );
	}

	return focused ?? parse( text );
}

/**
 * Says what leaving a field does with the text it shows instead of its value.
 *
 * @param {string|null} draft The text the field shows instead of its value, or null for none.
 * @param {Function}    parse Reads text: the value, or undefined when it is not one.
 * @return {{draft: string|null, commit: *}} The text the field keeps showing, null once it can show its value again; and the
 *                                           value to commit, which is the emptied field's, the other text that can be read
 *                                           having been committed as it was typed; undefined commits nothing.
 */
export function committedOnLeaving( draft, parse ) {
	const value = draft === null ? undefined : parse( draft );

	if ( value === undefined ) {
		return { draft, commit: undefined };
	}

	return { draft: null, commit: draft.trim() === '' ? value : undefined };
}
