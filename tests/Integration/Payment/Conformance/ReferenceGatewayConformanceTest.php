<?php
/**
 * Tests the reference gateway against the payment gateway conformance suite, on its committed tapes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Tests\Conformance\Payment\GatewayConformanceCase;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;

/**
 * Runs every rule of the suite against the reference gateway, replaying its tapes as a gateway plugin's test replays its own.
 *
 * With SEOCART_TAPE=record the tapes are recorded again, against the fake provider.
 *
 * @since 0.2.0
 */
final class ReferenceGatewayConformanceTest extends GatewayConformanceCase {

	use ReferenceGatewaySetup;
}
