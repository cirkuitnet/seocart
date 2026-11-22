<?php
/**
 * Tests the stand-in gateway against the payment gateway conformance suite
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Payment\Infrastructure\Gateway\StubGateway;

/**
 * Runs every rule of the suite against the stand-in gateway the plugin registers on a site that is not in production.
 *
 * @since 0.2.0
 */
final class StubGatewayConformanceTest extends StandInConformanceCase {

	/**
	 * Returns the stand-in's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return StubGateway::ID;
	}
}
