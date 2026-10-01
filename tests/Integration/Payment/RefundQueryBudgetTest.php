<?php
/**
 * Tests what a refund costs in statements: the same for three lines as for one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\QueryLog;

/**
 * A refund of some units of each of L lines and the shipping sends a fixed number of statements, whatever L.
 *
 * Every read is batched, one per kind of row; every write is one statement for every line, or
 * every component, at once. The count, of the first refund of an order, which moves its payment
 * status:
 *
 * - 8 reads before the gateway is asked: the order with its current totals version, the lines
 *   asked for, the shipping added up, the tax components; the captured intent, and what earlier
 *   refunds returned of the lines, of the components and of the shipping;
 * - 4 statements of the transaction itself, and 2 for each of its two savepoints, the refund's and
 *   the money path's;
 * - 7 of the money path: the intent's lock, the order's lock, the ledger row, the intent's
 *   refund, the order's payment amounts, the order event of its new payment status and its
 *   PaymentStatusChanged;
 * - 5 of the document: the refund, its lines, its components, the lines' refunded quantities and
 *   RefundRecorded.
 *
 * So 28, for one line as for three; a later refund that leaves the payment status as it is costs 2
 * fewer.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class RefundQueryBudgetTest extends RefundTestCase {

	/**
	 * The statements of an order's first refund, of any number of lines and the shipping.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const STATEMENTS = 28;

	/**
	 * Tests that a refund of one line and the shipping, and one of three lines and the shipping, each send the budget's statements.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_costs_the_same_for_three_lines_as_for_one(): void {
		list( $one )           = $this->refundsOf( 1 );
		list( $three, $later ) = $this->refundsOf( 3 );

		$this->assertQueryCount( self::STATEMENTS, $one, 'a refund of one line and the shipping' );
		$this->assertQueryCount( self::STATEMENTS, $three, 'a refund of three lines and the shipping' );
		$this->assertQueryCount( self::STATEMENTS - 2, $later, 'a later refund of three lines, the payment status unchanged' );
	}

	/**
	 * Places a paid order of some lines, and logs the statements of a first refund of one unit of each line and the shipping, then of a second, whose shipping has nothing left.
	 *
	 * @since 0.1.0
	 *
	 * @param int $lines How many lines.
	 * @return array{0: QueryLog, 1: QueryLog} Each refund's statements, the capability check's reads of the user aside.
	 */
	private function refundsOf( int $lines ): array {
		$inputs = array();

		for ( $index = 1; $index <= $lines; ++$index ) {
			$inputs[] = RefundOrders::line( 'line-' . $index, '12.34', 3, 'standard', variantId: 500 + $index );
		}

		list( $order ) = $this->placePaid( RefundOrders::priced( $inputs ) );
		$units         = array_fill_keys( $this->lineUuids( $order->id ), 1 );
		$statements    = '/seocart_|^(START TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT)/';

		// The user's capabilities are read once and cached; the refund's own statements are counted.
		$this->agent();

		return array(
			$this->captureQueries( fn() => $this->refund( $order->uuid, $units, true ) )->matching( $statements ),
			$this->captureQueries( fn() => $this->refund( $order->uuid, $units, true ) )->matching( $statements ),
		);
	}
}
