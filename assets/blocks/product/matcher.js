/**
 * A product's variants matched to the values a shopper picks.
 *
 * A product's state lists the variants a visitor may buy, each with its value on every axis
 * (`values`); a combination the product does not sell has no variant. The matcher finds the
 * variant of a selection, and says whether a value, taken with the other axes' values selected,
 * makes a variant that can be bought: one with a price that is in stock.
 */

/**
 * Returns the variant whose value on every axis is the selection's.
 *
 * @param {Array<Object>}          variants  The product's variants.
 * @param {Object<string, string>} selection The value picked on each axis.
 * @return {Object|null} The variant, or null when the product has none of that combination.
 */
export function findVariant( variants, selection ) {
	const axes = Object.keys( selection );

	return (
		variants.find(
			( variant ) =>
				Object.keys( variant.values ).length === axes.length &&
				axes.every(
					( axis ) => variant.values[ axis ] === selection[ axis ]
				)
		) ?? null
	);
}

/**
 * Tells whether a variant can be added to the cart: it has a price and is in stock.
 *
 * @param {Object|null} variant The variant, or null for none.
 * @return {boolean} True when it can be added.
 */
export function purchasable( variant ) {
	return null !== variant && null !== variant.price && variant.inStock;
}

/**
 * Tells whether a value, with the other axes' values as selected, makes a variant that can be bought.
 *
 * @param {Object} product The product's state.
 * @param {string} axis    The axis.
 * @param {string} value   The value.
 * @return {boolean} True when it does.
 */
export function available( product, axis, value ) {
	return purchasable(
		findVariant( product.variants, {
			...product.selection,
			[ axis ]: value,
		} )
	);
}

/**
 * Returns the key of what the add button reads for a variant: add, sold out, or unavailable.
 *
 * @param {Object|null} variant The variant, or null for a combination the product does not sell.
 * @return {string} The key of the words in the store's strings.
 */
export function buttonLabel( variant ) {
	if ( null === variant || null === variant.price ) {
		return 'unavailable';
	}

	return variant.inStock ? 'add' : 'sold_out';
}

/**
 * Returns the label of the value selected on an axis.
 *
 * @param {Object} product The product's state.
 * @param {string} axis    The axis.
 * @return {string} The label, or an empty string when the axis has none selected.
 */
export function valueLabel( product, axis ) {
	const selected = product.selection[ axis ];
	const found = product.axes
		.find( ( candidate ) => candidate.key === axis )
		?.values.find( ( value ) => value.key === selected );

	return found?.label ?? '';
}
