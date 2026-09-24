/**
 * Amounts in minor units, and the text a merchant types for them, with no floating-point number
 * anywhere: the text is split into its digits, and the digits are read as one whole number.
 */

/**
 * Reads the text a merchant typed as an amount in minor units.
 *
 * Accepts digits with at most `exponent` decimal places, the separator being a point or a comma;
 * `19.9` in a currency with two decimal places is 1990.
 *
 * @param {string} text     What the merchant typed.
 * @param {number} exponent The currency's number of decimal places.
 * @return {number|null|undefined} The amount in minor units; null for no text; undefined when the text is not an amount.
 */
export function parseAmount( text, exponent ) {
	const trimmed = String( text ).trim();

	if ( trimmed === '' ) {
		return null;
	}

	const match = /^(\d+)(?:[.,](\d*))?$/.exec( trimmed );

	if ( ! match ) {
		return undefined;
	}

	const fraction = match[ 2 ] ?? '';

	if ( fraction.length > exponent ) {
		return undefined;
	}

	return wholeNumber( match[ 1 ] + fraction.padEnd( exponent, '0' ) );
}

/**
 * Writes an amount in minor units as text, with the currency's number of decimal places.
 *
 * @param {number|null|undefined} minor    The amount in minor units, or nothing.
 * @param {number}                exponent The currency's number of decimal places.
 * @return {string} The text, such as `19.99`; empty for nothing.
 */
export function formatAmount( minor, exponent ) {
	if ( minor === null || minor === undefined ) {
		return '';
	}

	const digits = String( minor ).padStart( exponent + 1, '0' );

	return exponent === 0
		? digits
		: `${ digits.slice( 0, -exponent ) }.${ digits.slice( -exponent ) }`;
}

/**
 * Reads the text a merchant typed as a whole number, such as a weight in grams.
 *
 * @param {string} text What the merchant typed.
 * @return {number|null|undefined} The number; null for no text; undefined when the text is not one.
 */
export function parseWholeNumber( text ) {
	const trimmed = String( text ).trim();

	if ( trimmed === '' ) {
		return null;
	}

	return /^\d+$/.test( trimmed ) ? wholeNumber( trimmed ) : undefined;
}

/**
 * Reads a string of digits as a whole number, refusing one too large to be exact.
 *
 * @param {string} digits The digits.
 * @return {number|undefined} The number, or undefined when it is not a safe integer.
 */
function wholeNumber( digits ) {
	const value = Number( digits );

	return Number.isSafeInteger( value ) ? value : undefined;
}
