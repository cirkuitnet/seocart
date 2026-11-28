/**
 * What the add-to-cart form says after an add, from the store's answer.
 *
 * The store never refuses an add for its stock: it accepts a quantity past the units for sale and
 * flags the line. So after an accepted add the form reads the answer for the variant just added:
 * a line the cart could not price says it cannot be bought in the shopper's currency; a line with
 * fewer units for sale than the cart holds caps the quantity at those units for the rest of the
 * page; a line with none marks the variant sold out. Otherwise the add is confirmed by name. A
 * refused or lost add says why, in the alert region. The numbers are counts of units, never money,
 * and the form never lowers a line of the cart by itself.
 */

/**
 * Internal dependencies
 *
 * The storefront's store module: the build leaves it out and lists it in the .asset.json file, and
 * WordPress puts it in the page's import map.
 */
/* eslint-disable import/no-unresolved */
import { text, noticeText } from '@seocart/store';
/* eslint-enable import/no-unresolved */

/**
 * Returns what the form says after an add.
 *
 * @param {Object}                 outcome What the store's `addLines` came to: `{ ok, cart }` or `{ ok, notice }`.
 * @param {Object}                 product The product's state.
 * @param {Object}                 variant The variant added.
 * @param {Object<string, string>} strings The store's strings.
 * @return {{status: string, alert: string, max: (number|undefined), soldOut: boolean}} The status
 *         region's sentence, the alert region's, the quantity's new most, and whether the variant
 *         is sold out now.
 */
export function afterAdd( outcome, product, variant, strings ) {
	const said = { status: '', alert: '', max: undefined, soldOut: false };

	if ( ! outcome.ok ) {
		return { ...said, alert: noticeText( strings, outcome.notice ) };
	}

	const { cart } = outcome;
	const mine = ( line ) => line.variant_id === variant.id;

	if ( cart.unpriced_lines.some( mine ) ) {
		return { ...said, status: text( strings, 'unpriced' ) };
	}

	const flagged = cart.unavailable_lines.find( mine );

	if ( 'out_of_stock' === flagged?.reason ) {
		return {
			...said,
			status: text( strings, 'out_of_stock' ),
			soldOut: true,
		};
	}

	if ( 'insufficient_stock' === flagged?.reason ) {
		return {
			...said,
			status: text( strings, 'insufficient', {
				for_sale: flagged.for_sale,
				quantity: cart.lines
					.filter( mine )
					.reduce( ( units, line ) => units + line.quantity, 0 ),
			} ),
			max: flagged.for_sale,
		};
	}

	return {
		...said,
		status: variant.label
			? text( strings, 'added_variant', {
					name: product.name,
					label: variant.label,
				} )
			: text( strings, 'added', { name: product.name } ),
	};
}
