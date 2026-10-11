<?php
/**
 * CanonicalGateways: tells a test which registered gateway the registry serves
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use PHPUnit\Framework\Assert;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\CanonicalGateway;

/**
 * Reads the gateway a CanonicalGateway wraps, for a test that asserts which registration the registry serves.
 *
 * Owns one fact: that only a test reaches behind the wrapper. CanonicalGateway gives no way to its
 * gateway, so nothing can hand a gateway's raw answer to the money path; a test reads the private
 * property by reflection.
 *
 * @since 0.2.0
 */
final class CanonicalGateways {

	/**
	 * Cannot be called: the reading is a static function.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the registered gateway the registry serves wrapped.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $served What the registry served.
	 * @return PaymentGateway The gateway it wraps.
	 */
	public static function behind( PaymentGateway $served ): PaymentGateway {
		Assert::assertInstanceOf( CanonicalGateway::class, $served, 'The registry serves every gateway wrapped.' );

		$inner = ( new \ReflectionProperty( CanonicalGateway::class, 'inner' ) )->getValue( $served );

		Assert::assertInstanceOf( PaymentGateway::class, $inner );

		return $inner;
	}
}
