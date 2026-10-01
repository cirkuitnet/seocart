<?php
/**
 * ExchangeRates: saves a new version of the store's exchange rates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the store's exchange rates change.
 *
 * Owns one fact: that rates are never edited, only superseded. Each save is a complete set of
 * rates, stored as a new version beside every earlier one, and made current only once it is
 * stored. So a cart prices at the current version from its next calculation on, while an order
 * keeps the rate it was placed at, frozen with it, and every earlier version stays readable.
 *
 * @since 0.1.0
 */
interface ExchangeRates {

	/**
	 * Saves a complete set of rates as the next version, and makes it the current one.
	 *
	 * A currency with no rate in the set has none in the new version, so prices are no longer
	 * offered in it until a later set gives it one again. Saves run one at a time, each in a
	 * transaction of its own: two at once publish two versions in turn. A save that waits too long
	 * for another to finish is refused with a coded exception, and saves nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           When a transaction is open.
	 * @throws \InvalidArgumentException When the set is empty, a rate is not from the base currency,
	 *                                   or two rates are for the same currency.
	 *
	 * @param ManualRate[] $rates The rates, one per currency.
	 * @param Actor        $actor Who saves them.
	 * @return int The new version.
	 *
	 * @phpstan-param list<ManualRate> $rates
	 */
	public function saveVersion( array $rates, Actor $actor ): int;
}
