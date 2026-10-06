<?php
/**
 * Tests how the gateways' kill switches are named, read and written
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Application\GatewaySwitches;

/**
 * A gateway's switch is `gateway.{id}`, for a gateway id only and within the length a kill switch may have; disabling turns it on, enabling removes it, and asking reads it.
 *
 * @since 0.2.0
 */
final class GatewaySwitchesTest extends TestCase {

	/**
	 * Tests the switch's name: the prefix and the id, the longest id within a kill switch's 64 characters, and anything that is not a gateway id refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateways_switch_is_named_by_its_id(): void {
		$this->assertSame( 'gateway.stripe', GatewaySwitches::switchOf( 'stripe' ) );
		$this->assertLessThanOrEqual( 64, strlen( GatewaySwitches::switchOf( str_repeat( 'a', 32 ) ) ) );

		foreach ( array( 'Stripe', 'pay-pal', '1stripe', str_repeat( 'a', 33 ), '' ) as $id ) {
			try {
				GatewaySwitches::switchOf( $id );
				$this->fail( sprintf( 'The id "%s" was given a switch.', $id ) );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'snake_case', $refused->getMessage() );
			}
		}
	}

	/**
	 * Tests that disabling and enabling turn the gateway's own switch on and off, and that asking reads it.
	 *
	 * @since 0.2.0
	 */
	public function test_disable_and_enable_turn_the_gateways_switch(): void {
		/**
		 * The kill switches the record holds, as the boot record keeps them.
		 *
		 * @var array<string, true> $record
		 */
		$record   = array();
		$switches = new GatewaySwitches(
			static function ( string $name ) use ( &$record ): bool {
				return isset( $record[ $name ] );
			},
			static function ( string $name, bool $off ) use ( &$record ): bool {
				if ( $off ) {
					$record[ $name ] = true;
				} else {
					unset( $record[ $name ] );
				}

				return isset( $record[ $name ] ) === $off;
			},
			static fn(): bool => true
		);

		$this->assertTrue( $switches->isEnabled( 'stripe' ) );
		$this->assertTrue( $switches->disable( 'stripe' ) );
		$this->assertSame( array( 'gateway.stripe' => true ), $record );
		$this->assertFalse( $switches->isEnabled( 'stripe' ) );
		$this->assertTrue( $switches->isEnabled( 'paypal' ), 'Another gateway is not switched off with it.' );
		$this->assertTrue( $switches->enable( 'stripe' ) );
		$this->assertSame( array(), $record );
		$this->assertTrue( $switches->isEnabled( 'stripe' ) );
		$this->assertTrue( $switches->safeMode() );
	}
}
