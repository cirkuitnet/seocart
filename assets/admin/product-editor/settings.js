/**
 * What the plugin prints before the editor's script, and the declarations in it.
 */

/**
 * What the script is given.
 *
 * @type {Object}
 */
export const settings = window.seocartProductEditor;

/**
 * The language the editor's page is in, which amounts and currency names are written for.
 *
 * @type {string|undefined}
 */
export const LOCALE = document.documentElement.lang || undefined;

/**
 * Finds a declaration by its dotted path among the two lists, such as `variants.prices.price_minor`.
 *
 * @param {string} path The path.
 * @return {Object} The field: its label, its kind and its bounds.
 */
export function declaration( path ) {
	return path
		.split( '.' )
		.reduce(
			( fields, name ) =>
				( fields.fields ?? fields ).find(
					( field ) => field.name === name
				),
			settings.lists
		);
}

/**
 * Finds a currency the store offers by its code.
 *
 * @param {string} code The code.
 * @return {{code: string, exponent: number}} The currency.
 */
export function currencyOf( code ) {
	return settings.currencies.find( ( currency ) => currency.code === code );
}
