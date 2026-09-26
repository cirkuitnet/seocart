<?php
/**
 * AmountBasis: whether an authored amount includes tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Whether an amount a merchant or a provider authored is before tax or includes it.
 *
 * Owns one fact: the two bases every authored amount carries, spelled as `variant_prices`
 * stores them. The calculation never guesses a basis and never reads a store-wide entry mode:
 * a price, a rate or a fee says which it is, and tax is added to a net amount or taken out of a
 * gross one accordingly.
 *
 * @since 0.1.0
 */
enum AmountBasis: string {

	/**
	 * The amount is before tax.
	 *
	 * @since 0.1.0
	 */
	case Net = 'net';

	/**
	 * The amount includes tax.
	 *
	 * @since 0.1.0
	 */
	case Gross = 'gross';
}
