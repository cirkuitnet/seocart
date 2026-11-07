<?php
/**
 * Tests the cash on delivery offline payment method against the payment gateway conformance suite
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Payment\Domain\OfflineMethod;

/**
 * Runs every rule of the suite against the cash on delivery offline payment method.
 *
 * @since 0.2.0
 */
final class CashOnDeliveryConformanceTest extends OfflineMethodConformanceCase {

	/**
	 * Returns the method's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return OfflineMethod::CashOnDelivery->value;
	}
}
