<?php
/**
 * Tests that the canonical gateway offers nothing beyond the gateway contract
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\CanonicalGateway;

/**
 * The wrapper the registry serves every gateway in is the gateway contract and nothing more: no way to the gateway behind it, so no caller, an extension included, can hand a gateway's raw answer to the money path.
 *
 * Planted violation: give CanonicalGateway a public `wrapped(): PaymentGateway` returning its
 * gateway: the surface then holds a method the contract does not.
 *
 * @since 0.2.0
 */
final class CanonicalGatewayTest extends TestCase {

	/**
	 * Tests that the wrapper's public methods are the contract's, and its constructor.
	 *
	 * @since 0.2.0
	 */
	public function test_the_wrapper_offers_the_contract_alone(): void {
		$public = static fn( string $type ): array => array_map( static fn( \ReflectionMethod $method ): string => $method->getName(), ( new \ReflectionClass( $type ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );
		$offers = $public( CanonicalGateway::class );

		sort( $offers );

		$contract = array_merge( $public( PaymentGateway::class ), array( '__construct' ) );

		sort( $contract );

		$this->assertSame( $contract, $offers );
	}
}
