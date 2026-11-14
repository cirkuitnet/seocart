<?php
/**
 * RefusesADeliveryDaysLate: a gateway of undated deliveries that refuses one whose body states a time more than two hours from now
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

/**
 * Windows on the body's time as WindowsOnTheBodysDate does, two hours wide: a delivery an hour late or early is read, one retried days later is rejected.
 *
 * A provider that signs no time retries a delivery after hours and days, so under the test's
 * declaration that deliveries carry no signed time rule 10 must fail this gateway on a delivery
 * read days after it was sent.
 *
 * @since 0.2.0
 */
final class RefusesADeliveryDaysLate extends WindowsOnTheBodysDate {

	/**
	 * Returns how far from now the body's time may be: two hours.
	 *
	 * @since 0.2.0
	 *
	 * @return int The window, in seconds.
	 */
	protected function window(): int {
		return 7200;
	}
}
