<?php
/**
 * CalculationRequest: what a cart or an order replay asks the calculator for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\Address;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * A request for totals: the lines, the currency, where the order goes, the codes entered and the shipping chosen.
 *
 * Owns one fact: what a caller of the calculation provides. It carries no money: prices, rates
 * and tax are the calculation's to find, so no caller can hand in a price of its own.
 *
 * @since 0.1.0
 */
final readonly class CalculationRequest {

	/**
	 * Holds the request.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency     $currency          The cart's currency.
	 * @param array        $lines             The lines, in cart order: the order every tie is broken in.
	 * @param Address|null $destination       Where the order ships, or null before an address is known.
	 * @param array        $promotionCodes    The codes the customer entered.
	 * @param string|null  $shippingMethodKey The shipping method chosen, or null for the cheapest.
	 *
	 * @phpstan-param list<LineRequest> $lines
	 * @phpstan-param list<string>      $promotionCodes
	 */
	public function __construct(
		public Currency $currency,
		public array $lines,
		public ?Address $destination = null,
		public array $promotionCodes = array(),
		public ?string $shippingMethodKey = null
	) {
	}
}
