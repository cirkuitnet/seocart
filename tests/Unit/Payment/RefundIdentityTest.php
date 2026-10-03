<?php
/**
 * Tests a refund's uuid: named by what the refund asks for and the state it was asked in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\Refund\RefundIdentity;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The same refund asked again has the same uuid, so the gateway is asked again with the same key; any other refund has another.
 *
 * @since 0.1.0
 */
final class RefundIdentityTest extends TestCase {

	/**
	 * The order every refund here is of.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ORDER = '01928c3e-0000-7000-8000-000000000001';

	/**
	 * One of its lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TEE = '01928c3e-0000-7000-8000-0000000000a1';

	/**
	 * Another of its lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MUG = '01928c3e-0000-7000-8000-0000000000b2';

	/**
	 * Tests that the uuid is the standard version 5 uuid of the name its docblock states, so any implementation of RFC 9562 derives the same key.
	 *
	 * The expected value is Python's `uuid.uuid5()` of the same namespace and name.
	 *
	 * @since 0.1.0
	 */
	public function test_the_uuid_is_the_version_5_uuid_of_the_stated_name(): void {
		$this->assertSame(
			'e5484c93-10bd-58ac-8215-97fd38e9d86f',
			self::uuid(
				array(
					self::MUG => 2,
					self::TEE => 1,
				),
				true,
				1234
			)
		);
	}

	/**
	 * Tests that the same refund asked again has the same uuid, whatever order its lines were asked in.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_refund_has_the_same_uuid(): void {
		$this->assertSame(
			self::uuid(
				array(
					self::TEE => 1,
					self::MUG => 2,
				),
				false,
				0
			),
			self::uuid(
				array(
					self::MUG => 2,
					self::TEE => 1,
				),
				false,
				0
			)
		);
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', self::uuid( array(), true, 0 ) );
	}

	/**
	 * Tests that every item of the name tells refunds apart: the order, a line, its units, the shipping, and what the intent had refunded.
	 *
	 * @since 0.1.0
	 */
	public function test_every_item_of_the_name_tells_refunds_apart(): void {
		$uuids = array(
			'the refund'                       => self::uuid( array( self::TEE => 1 ), false, 0 ),
			'another order'                    => RefundIdentity::uuid( '01928c3e-0000-7000-8000-000000000002', array( self::TEE => 1 ), false, self::eur( 0 ) ),
			'another line'                     => self::uuid( array( self::MUG => 1 ), false, 0 ),
			'another line too'                 => self::uuid(
				array(
					self::TEE => 1,
					self::MUG => 1,
				),
				false,
				0
			),
			'more units'                       => self::uuid( array( self::TEE => 2 ), false, 0 ),
			'the shipping too'                 => self::uuid( array( self::TEE => 1 ), true, 0 ),
			'after an earlier refund'          => self::uuid( array( self::TEE => 1 ), false, 1028 ),
			'the shipping alone'               => self::uuid( array(), true, 0 ),
			'the shipping alone, after a unit' => self::uuid( array(), true, 1028 ),
		);

		$this->assertSame( array_keys( $uuids ), array_keys( array_unique( $uuids ) ), 'Each refund has its own uuid.' );
	}

	/**
	 * Tests that the count of declined refunds is a sixth item of the name only when there was one: a refund asked again after a decline is a new refund, and one asked before any keeps its uuid.
	 *
	 * The expected value is Python's `uuid.uuid5()` of the stated name with `declined 2` as its last
	 * line; the count 0 gives the uuid the first test pins.
	 *
	 * @since 0.1.0
	 */
	public function test_a_count_of_declines_names_a_new_refund_and_none_keeps_the_name(): void {
		$units = array(
			self::MUG => 2,
			self::TEE => 1,
		);

		$this->assertSame( 'e5484c93-10bd-58ac-8215-97fd38e9d86f', RefundIdentity::uuid( self::ORDER, $units, true, self::eur( 1234 ), 0 ) );
		$this->assertSame( 'faf6324f-6484-5173-962e-42218a05773d', RefundIdentity::uuid( self::ORDER, $units, true, self::eur( 1234 ), 2 ) );
		$this->assertNotSame( RefundIdentity::uuid( self::ORDER, $units, true, self::eur( 1234 ), 1 ), RefundIdentity::uuid( self::ORDER, $units, true, self::eur( 1234 ), 2 ), 'Each decline names a new refund.' );
	}

	/**
	 * Returns the uuid of a refund of the order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $units    The units of each line.
	 * @param bool               $shipping Whether the shipping is asked for.
	 * @param int                $refunded What the intent had refunded, in euro cents.
	 * @return string The uuid.
	 */
	private static function uuid( array $units, bool $shipping, int $refunded ): string {
		return RefundIdentity::uuid( self::ORDER, $units, $shipping, self::eur( $refunded ) );
	}

	/**
	 * Returns an amount in euro.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cents The amount, in cents.
	 * @return Money The amount.
	 */
	private static function eur( int $cents ): Money {
		return Money::of( $cents, Currency::of( 'EUR' ) );
	}
}
