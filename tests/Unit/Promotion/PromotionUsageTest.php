<?php
/**
 * Tests the order of the usage ledger's statements, and that it runs only inside a transaction
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Application\PromotionUsage;
use SEOCart\Promotion\Application\UsageClaim;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\FakePromotionRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Promotion\Promotions;

/**
 * The ledger claims in ascending promotion id whatever order it is given, stops at the first promotion it may not use, gives uses back before it releases them, and refuses to run outside a transaction.
 *
 * Planted violation, shown red and removed: in PromotionUsage::claim(), drop the sort. The claims
 * are then sent in the order given, and the lock-order test fails.
 *
 * @since 0.1.0
 */
final class PromotionUsageTest extends TestCase {

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeTransactionManager
	 */
	private FakeTransactionManager $transactions;

	/**
	 * The store's promotions.
	 *
	 * @since 0.1.0
	 *
	 * @var FakePromotionRepository
	 */
	private FakePromotionRepository $promotions;

	/**
	 * The ledger under test.
	 *
	 * @since 0.1.0
	 *
	 * @var PromotionUsage
	 */
	private PromotionUsage $usage;

	/**
	 * Adds three promotions: 3 and 5 without a limit, 9 with one use left.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transactions = new FakeTransactionManager();
		$this->promotions   = new FakePromotionRepository( $this->transactions );
		$this->usage        = new PromotionUsage( $this->promotions, $this->transactions );

		$this->promotions->add( Promotions::of( 3, 'THREE' ) );
		$this->promotions->add( Promotions::of( 5, 'FIVE' ) );
		$this->promotions->add( Promotions::of( 9, 'NINE' ), 1 );
	}

	/**
	 * Tests that claims are sent in ascending promotion id, a count then a row each, whatever order they are given in.
	 *
	 * @since 0.1.0
	 */
	public function test_claims_are_sent_in_ascending_promotion_id(): void {
		$this->transactions->transaction( fn() => $this->usage->claim( array( self::claim( 9 ), self::claim( 3 ), self::claim( 5 ) ) ) );

		$this->assertSame( array( 'claim:3', 'insertUsage:3', 'claim:5', 'insertUsage:5', 'claim:9', 'insertUsage:9' ), $this->promotions->calls );
	}

	/**
	 * Tests that a promotion used up stops the claim with the constant-shaped refusal, and records no row for it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_used_up_promotion_stops_the_claim(): void {
		$this->transactions->transaction( fn() => $this->usage->claim( array( self::claim( 9, 1 ) ) ) );

		try {
			$this->transactions->transaction( fn() => $this->usage->claim( array( self::claim( 9, 2 ), self::claim( 3, 2 ) ) ) );
			$this->fail( 'A promotion was used past its limit.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( PromotionError::LimitReached, array() ), array( $refused->errorCode(), $refused->context() ) );
		}

		$this->assertSame( array( 'claim:9', 'insertUsage:9', 'claim:3', 'insertUsage:3', 'claim:9' ), $this->promotions->calls, 'The second order claimed 3 first, then was refused 9; its transaction rolls both back.' );
	}

	/**
	 * Tests that a release gives each reserved use back, then marks the rows; and that a count already at zero is corrupt.
	 *
	 * @since 0.1.0
	 */
	public function test_a_release_gives_uses_back_then_marks_them_and_refuses_a_count_at_zero(): void {
		$this->transactions->transaction( fn() => $this->usage->claim( array( self::claim( 5 ), self::claim( 3 ) ) ) );
		$this->promotions->calls = array();

		$this->transactions->transaction( fn() => $this->usage->release( 100 ) );

		$this->assertSame( array( 'reservedPromotionIds:100', 'giveBack:3', 'giveBack:5', 'releaseUsage:100' ), $this->promotions->calls );
		$this->assertSame( array( 0, 0 ), array( $this->promotions->used( 3 ), $this->promotions->used( 5 ) ) );
		$this->assertSame(
			array(
				'3:100' => 'released',
				'5:100' => 'released',
			),
			$this->promotions->usage
		);

		$this->promotions->usage['3:101'] = 'reserved';

		try {
			$this->transactions->transaction( fn() => $this->usage->release( 101 ) );
			$this->fail( 'A use was given back from a count of zero.' );
		} catch ( CodedException $corrupt ) {
			$this->assertSame( array( PromotionError::UsageCorrupt, array( 'promotion_id' => 3 ) ), array( $corrupt->errorCode(), $corrupt->context() ) );
		}
	}

	/**
	 * Tests that each method refuses to run outside a transaction, before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_nothing_runs_outside_a_transaction(): void {
		foreach ( array(
			'claim'   => fn() => $this->usage->claim( array( self::claim( 3 ) ) ),
			'commit'  => fn() => $this->usage->commit( 100 ),
			'release' => fn() => $this->usage->release( 100 ),
		) as $method => $call ) {
			try {
				$call();
				$this->fail( "PromotionUsage::{$method}() ran outside a transaction." );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( "PromotionUsage::{$method}()", $refused->getMessage() );
			}
		}

		$this->assertSame( array(), $this->promotions->calls );
	}

	/**
	 * Returns a use of a promotion by an order, with a discount of 1.00.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @param int $orderId     Optional. The order. Default 100.
	 * @return UsageClaim The use.
	 */
	private static function claim( int $promotionId, int $orderId = 100 ): UsageClaim {
		return new UsageClaim( $promotionId, $orderId, Money::of( -100, Currency::of( 'USD' ) ), Money::of( -100, Currency::of( 'USD' ) ) );
	}
}
