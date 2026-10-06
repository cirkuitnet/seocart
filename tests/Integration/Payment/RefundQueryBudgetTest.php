<?php
/**
 * Tests what a refund through the refund operation costs in statements: the same for three lines as for one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\QueryLog;

/**
 * A refund through the refund operation's service, of some units of each of L lines and the shipping, sends a fixed number of statements, whatever L.
 *
 * Every read is batched, one per kind of row; every write is one statement for every line, or
 * every component, at once. The count, of an order's first refund by a user whom no refund cap
 * holds, which moves the order's payment status:
 *
 * - 1 read of the claim the idempotency key names, which names none;
 * - 8 reads before the gateway is asked: the order with its current totals version, the lines
 *   asked for, the shipping added up, the tax components; the captured intent, and what earlier
 *   refunds returned of the lines, of the components and of the shipping;
 * - 7 of the claim, before the gateway is asked: the 4 statements of its own short transaction,
 *   the intent's lock, which reads what the refund's uuid is named by and the intent's open claim,
 *   the claim, and what it asked of each line, in one statement;
 * - 4 statements of the recording transaction itself, and 2 for each of its two savepoints, the
 *   refund's and the money path's;
 * - 7 of the money path: the intent's lock, the order's lock, the ledger row, the intent's
 *   refund, the order's payment amounts, the order event of its new payment status and its
 *   PaymentStatusChanged;
 * - 7 of the document: the refund, its lines, its components, the lines' refunded quantities, the
 *   claim ended `recorded`, the order's event of the refund, and RefundRecorded.
 *
 * So 38, for one line as for three; a later refund that leaves the payment status as it is costs 2
 * fewer. An order agent, held to both caps, costs 2 more: the agent's lock row and the sum of what
 * the agent asked in the last 24 hours; on a cold object cache the caps' settings are read as well,
 * in one statement. A retry with the same key and request, once the refund was recorded, costs 2:
 * the key's claim and the document; the same key with another request costs the key's claim alone.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class RefundQueryBudgetTest extends RefundTestCase {

	/**
	 * The statements of an order's first refund, of any number of lines and the shipping, by a user whom no cap holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const FIRST = 38;

	/**
	 * What a refund that leaves the payment status as it is saves: the status change's event and PaymentStatusChanged.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const STATUS_UNCHANGED = 2;

	/**
	 * What the caps of an order agent add: the agent's lock row, and the sum of what the agent asked.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CAPPED = 2;

	/**
	 * What a cold object cache adds to a capped refund: the read of the caps' settings.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const COLD_SETTINGS = 1;

	/**
	 * Tests that a refund of one line and the shipping, and one of three lines and the shipping, each send the budget's statements, and a later one two fewer.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_costs_the_same_for_three_lines_as_for_one(): void {
		$manager = $this->userWithRole( 'seocart_manager' );

		list( $one )           = $this->refundsOf( 1, $manager );
		list( $three, $later ) = $this->refundsOf( 3, $manager );

		$this->assertQueryCount( self::FIRST, $one, 'a refund of one line and the shipping' );
		$this->assertQueryCount( self::FIRST, $three, 'a refund of three lines and the shipping' );
		$this->assertQueryCount( self::FIRST - self::STATUS_UNCHANGED, $later, 'a later refund of three lines, the payment status unchanged' );
	}

	/**
	 * Tests that an order agent's refund costs two more, and on a cold object cache the read of the caps' settings as well.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capped_refund_costs_two_more_and_one_on_a_cold_cache(): void {
		$this->capOrderAgents( '250.00', '1000.00' );

		// The first read of the caps' settings after they were written finds them in no cache.
		list( $first ) = $this->refundsOf( 1, $this->agent() );
		list( $warm )  = $this->refundsOf( 1, $this->agent() );
		list( $cold )  = $this->refundsOf( 1, $this->agent(), true );

		$this->assertQueryCount( self::FIRST + self::CAPPED + self::COLD_SETTINGS, $first, 'an order agent\'s first refund, the caps\' settings read' );
		$this->assertQueryCount( self::FIRST + self::CAPPED, $warm, 'an order agent\'s first refund of another order, the settings cached' );
		$this->assertQueryCount( self::FIRST + self::CAPPED + self::COLD_SETTINGS, $cold, 'the same on a cold object cache' );
	}

	/**
	 * Tests that a retry with the same key and request, once the refund was recorded, costs the key's claim and the document; and that the key with another request costs the key's claim alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_retry_by_key_costs_two_and_a_reused_key_one(): void {
		$manager       = $this->userWithRole( 'seocart_manager' );
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$input         = $this->input( $order->uuid, array( $tee => 1 ), false, 'attempt-1' );
		$first         = $this->refunds->refundOrder( $input, $manager );
		$again         = null;

		$replay = $this->captureQueries(
			function () use ( $input, $manager, &$again ): void {
				$again = $this->refunds->refundOrder( $input, $manager );
			}
		)->matching( self::STATEMENTS );

		$this->assertSame( $first, $again, 'The same refund.' );
		$this->assertQueryCount( 2, $replay, 'a retry by key after the refund was recorded' );

		$reused = $this->captureQueries(
			function () use ( $order, $tee, $manager ): void {
				try {
					$this->refunds->refundOrder( $this->input( $order->uuid, array( $tee => 2 ), false, 'attempt-1' ), $manager );
				} catch ( CodedException $refused ) {
					unset( $refused );
				}
			}
		)->matching( self::STATEMENTS );

		$this->assertQueryCount( 1, $reused, 'the key sent again with another request' );
	}

	/**
	 * Places a paid order of some lines, and logs the statements of a first refund through the operation's service of one unit of each line and the shipping, then of a second, whose shipping has nothing left.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Through the operation's service, by a given user, on a cache flushed first when asked.
	 *
	 * @param int   $lines How many lines.
	 * @param Actor $actor Who refunds.
	 * @param bool  $cold  Optional. Whether the object cache is flushed before the first refund. Default false.
	 * @return array{0: QueryLog, 1: QueryLog} Each refund's statements, the capability check's reads of the user aside.
	 */
	private function refundsOf( int $lines, Actor $actor, bool $cold = false ): array {
		$inputs = array();

		for ( $index = 1; $index <= $lines; ++$index ) {
			$inputs[] = RefundOrders::line( 'line-' . $index, '12.34', 3, 'standard', variantId: 500 + $index );
		}

		list( $order ) = $this->placePaid( RefundOrders::priced( $inputs ) );
		$units         = array_fill_keys( $this->lineUuids( $order->id ), 1 );
		$first         = $this->input( $order->uuid, $units, true, 'first-' . $order->uuid );
		$second        = $this->input( $order->uuid, $units, true, 'second-' . $order->uuid );

		if ( $cold ) {
			wp_cache_flush();
		}

		return array(
			$this->captureQueries( fn() => $this->refunds->refundOrder( $first, $actor ) )->matching( self::STATEMENTS ),
			$this->captureQueries( fn() => $this->refunds->refundOrder( $second, $actor ) )->matching( self::STATEMENTS ),
		);
	}

	/**
	 * Builds the refund operation's prepared input.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param bool               $shipping  Whether to give back what is left of the shipping.
	 * @param string             $key       The idempotency key.
	 * @return array<string, mixed> The input.
	 */
	private function input( string $orderUuid, array $units, bool $shipping, string $key ): array {
		$lines = array();

		foreach ( $units as $lineUuid => $quantity ) {
			$lines[] = array(
				'line_uuid' => (string) $lineUuid,
				'quantity'  => $quantity,
			);
		}

		return array(
			'order_uuid'      => $orderUuid,
			'lines'           => $lines,
			'shipping'        => $shipping,
			'reason_code'     => self::REASON,
			'idempotency_key' => $key,
		);
	}
}
