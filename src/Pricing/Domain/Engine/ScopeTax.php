<?php
/**
 * ScopeTax: an amount taxed once, and the share each rate took of its tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * The tax step's result for one line or one adjustment.
 *
 * Owns one fact: that an amount's components are its own tax shared out. Their taxes add up to
 * the amount's tax exactly.
 *
 * @since 0.1.0
 */
final readonly class ScopeTax {

	/**
	 * Holds the taxed amount and its components.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $amount     The amount's net, tax and gross.
	 * @param array      $components Each rate's share of the tax; none for an untaxed amount.
	 *
	 * @phpstan-param list<ComponentShare> $components
	 */
	public function __construct(
		public TaxedMoney $amount,
		public array $components
	) {
	}
}
