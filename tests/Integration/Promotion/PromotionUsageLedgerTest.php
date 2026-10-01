<?php
/**
 * Tests the usage ledger against the real tables: claim, commit, release, their statements and their refusals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Promotion\PromotionTestCase;

/**
 * A claim counts a use and records it reserved; a commit keeps it; a release gives it back once; a count that would go below zero is corrupt, and nothing runs outside a transaction.
 *
 * Planted violation, shown red and removed: in MysqlPromotionRepository::GIVE_BACK, drop
 * `AND used > 0`. The release of a reserved row whose promotion counts 0 then takes the count to
 * -1 and succeeds, instead of being refused as corrupt with nothing changed.
 *
 * @since 0.1.0
 */
final class PromotionUsageLedgerTest extends PromotionTestCase {

	/**
	 * Tests that a claim then a release leaves the count at 0 and the row released, and that releasing again changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_released_use_is_given_back_once(): void {
		$promotion = $this->plantPromotion( 'BACK' );
		$b         = $this->secondConnection();

		$this->db->transaction( fn() => $this->usage->claim( array( self::claimOf( $promotion, 2001 ) ) ) );

		$this->assertSame( 1, $this->committedUsed( $b, $promotion ) );
		$this->assertSame( array( '2001:reserved' ), $this->committedUsage( $b, $promotion ) );

		$this->db->transaction( fn() => $this->usage->release( 2001 ) );

		$this->assertSame( 0, $this->committedUsed( $b, $promotion ) );
		$this->assertSame( array( '2001:released' ), $this->committedUsage( $b, $promotion ) );
		$this->assertNotNull( $b->fetchValue( sprintf( 'SELECT released_at FROM `%s` WHERE order_id = 2001', $this->db->table( PromotionTables::USAGE ) ) ), 'A released use records when.' );

		$again = $this->captureQueries( fn() => $this->db->transaction( fn() => $this->usage->release( 2001 ) ) );

		$this->assertSame( 0, $this->committedUsed( $b, $promotion ), 'A second release gives nothing back.' );
		$this->assertQueryCount( 0, $again->ofType( 'UPDATE' )->matching( '/SET used = used - 1/' ), 'Give-backs on a second release' );
	}

	/**
	 * Tests that a committed use stays counted, and a release after it gives nothing back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_committed_use_stays_counted(): void {
		$promotion = $this->plantPromotion( 'KEEP' );
		$b         = $this->secondConnection();

		$this->db->transaction( fn() => $this->usage->claim( array( self::claimOf( $promotion, 2002 ) ) ) );
		$this->db->transaction( fn() => $this->usage->commit( 2002 ) );
		$this->db->transaction( fn() => $this->usage->release( 2002 ) );

		$this->assertSame( 1, $this->committedUsed( $b, $promotion ) );
		$this->assertSame( array( '2002:committed' ), $this->committedUsage( $b, $promotion ) );
	}

	/**
	 * Tests that a release whose promotion already counts zero is refused as corrupt, names the promotion, and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_release_from_a_count_of_zero_is_corrupt_and_changes_nothing(): void {
		$promotion = $this->plantPromotion( 'ZERO' );
		$b         = $this->secondConnection();

		$this->plantUsage( $promotion, 2003, 'reserved' );

		try {
			$this->db->transaction( fn() => $this->usage->release( 2003 ) );
			$this->fail( 'A use was given back from a count of zero.' );
		} catch ( CodedException $corrupt ) {
			$this->assertSame( PromotionError::UsageCorrupt, $corrupt->errorCode() );
			$this->assertSame( array( 'promotion_id' => $promotion ), $corrupt->context() );
		}

		$this->assertSame( 0, $this->committedUsed( $b, $promotion ) );
		$this->assertSame( array( '2003:reserved' ), $this->committedUsage( $b, $promotion ), 'Nothing is repaired: the row stays for a person.' );
	}

	/**
	 * Tests that a promotion that is used up or not active cannot be claimed, and the order's other claims roll back with it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_used_up_or_inactive_promotion_refuses_the_whole_claim(): void {
		$open    = $this->plantPromotion( 'OPEN' );
		$used    = $this->plantPromotion(
			'USEDUP',
			array(
				'usage_limit' => 1,
				'used'        => 1,
			)
		);
		$paused  = $this->plantPromotion( 'PAUSED', array( 'status' => 'paused' ) );
		$b       = $this->secondConnection();
		$refused = array();

		foreach ( array( $used, $paused ) as $promotion ) {
			try {
				$this->db->transaction( fn() => $this->usage->claim( array( self::claimOf( $open, 2004 ), self::claimOf( $promotion, 2004 ) ) ) );
			} catch ( CodedException $refusal ) {
				$refused[] = array( $refusal->errorCode(), $refusal->context() );
			}
		}

		$this->assertSame( array_fill( 0, 2, array( PromotionError::LimitReached, array() ) ), $refused );
		$this->assertSame( array( 0, 1, 0 ), array( $this->committedUsed( $b, $open ), $this->committedUsed( $b, $used ), $this->committedUsed( $b, $paused ) ) );
		$this->assertSame( array(), $this->committedUsage( $b, $open ), 'The use of the open promotion rolled back with the refusal.' );
	}

	/**
	 * Tests that claiming three promotions sends two statements each, in ascending id, and nothing else.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_sends_two_statements_per_promotion_in_ascending_id(): void {
		$first  = $this->plantPromotion( 'FIRST' );
		$second = $this->plantPromotion( 'SECOND' );
		$third  = $this->plantPromotion( 'THIRD' );

		$order = array();

		add_filter(
			'query',
			static function ( string $query ) use ( &$order ): string {
				if ( 1 === preg_match( '/^(UPDATE|INSERT INTO) `[^`]+promotion[^`]*` .*?(?:WHERE id = |VALUES \( )(\d+)/', $query, $found ) ) {
					$order[] = $found[1] . ' ' . (int) $found[2];
				}

				return $query;
			}
		);

		$log = $this->captureQueries( fn() => $this->db->transaction( fn() => $this->usage->claim( array( self::claimOf( $third, 2005 ), self::claimOf( $first, 2005 ), self::claimOf( $second, 2005 ) ) ) ) );

		$this->assertQueryCount( 6, $log->ofType( 'UPDATE', 'INSERT' ), 'Statements of a claim of three promotions' );
		$this->assertSame(
			array( "UPDATE {$first}", "INSERT INTO {$first}", "UPDATE {$second}", "INSERT INTO {$second}", "UPDATE {$third}", "INSERT INTO {$third}" ),
			$order,
			'Each promotion is counted then recorded, lowest id first.'
		);
	}

	/**
	 * Tests that the ledger refuses to run outside a transaction, and sends nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_ledger_sends_nothing_outside_a_transaction(): void {
		$promotion = $this->plantPromotion( 'OUTSIDE' );

		$log = $this->captureQueries(
			function () use ( $promotion ): void {
				try {
					$this->usage->claim( array( self::claimOf( $promotion, 2006 ) ) );
					$this->fail( 'A use was claimed outside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'inside the transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'Statements of a claim outside a transaction' );
	}
}
