<?php
/**
 * Tests that a placement is exactly two transactions at READ COMMITTED, with the gateway called between them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * Every placement, approved or declined, is two units of work, each asked for at READ COMMITTED, and the gateway is called between them, at depth 0, with no request leaving inside a transaction.
 *
 * Planted violations:
 * - in PlaceOrder::placeInside(), call `$this->payments->authorize( $intent->uuid, array() )`
 *   before returning: the payment service refuses a gateway call inside a transaction;
 * - in PlaceOrder::paid(), open one more transaction after the settlement, as a reopen of the cart
 *   on its own would: the log then holds three START TRANSACTION.
 *
 * @since 0.1.0
 */
final class PlacementTransactionsTest extends PlacementTestCase {

	/**
	 * Tests that an approved and a declined placement are each two transactions, with the gateway's one call between them, outside any.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_is_two_transactions_with_the_gateway_between_them(): void {
		$requests = 0;

		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$requests ) {
				++$requests;

				return $preempt;
			}
		);

		foreach ( array( StubGateway::APPROVE, StubGateway::DECLINE ) as $token ) {
			$this->readyCart( array( $this->sellable() => 1 ) );

			$input = $this->placeInput( 'attempt-' . $token, $token );
			$log   = $this->captureQueries(
				function () use ( $input ): void {
					try {
						$this->placement->place( $input, self::guest() );
					} catch ( \SEOCart\Support\Error\CodedException $declined ) {
						$this->assertSame( 'checkout.payment_declined', $declined->errorCode()->value );
					}
				}
			);

			$control = $log->matching( '/^(SET TRANSACTION|START TRANSACTION|COMMIT|ROLLBACK)\b/' )->sqls();
			$intent  = '/^SELECT .* FROM `' . preg_quote( $this->table( PaymentTables::INTENTS ), '/' ) . '` WHERE uuid = /';
			$order   = array();

			foreach ( $log->sqls() as $sql ) {
				if ( 1 === preg_match( '/^(START TRANSACTION|COMMIT)$/', $sql ) ) {
					$order[] = $sql;
				} elseif ( 1 === preg_match( $intent, $sql ) && ! str_contains( $sql, 'FOR UPDATE' ) ) {
					$order[] = 'gateway';
				}
			}

			$this->assertSame(
				array( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'START TRANSACTION', 'COMMIT', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'START TRANSACTION', 'COMMIT' ),
				$control,
				$token . ': two transactions, each asked for at READ COMMITTED.'
			);
			$this->assertSame( array( 'START TRANSACTION', 'COMMIT', 'gateway', 'START TRANSACTION', 'COMMIT' ), $order, $token . ': the gateway is asked between the two.' );
		}

		$this->assertSame( array( 'authorize:0', 'authorize:0' ), array_map( static fn( array $call ): string => $call['method'] . ':' . $call['depth'], $this->gateway->calls ), 'Each call to the gateway was made at depth 0.' );
		$this->assertSame( 0, $requests, 'No outbound request was made.' );
	}

	/**
	 * Tests that a placement with nothing to pay is two transactions too, each at READ COMMITTED, with no intent read and no gateway call between them.
	 *
	 * Planted violation: in PlaceOrder::paid(), settle an order with nothing due in a transaction of
	 * its own before the settlement (`$this->tx->transaction( fn() => null )`, as a separate step
	 * would): the log then holds three START TRANSACTION.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_with_nothing_due_is_two_transactions_with_no_gateway_call(): void {
		$this->plantPromotion(
			'FULL',
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 100000000,
			)
		);
		$this->plantPromotion(
			'SHIP',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->readyCart( array( $this->sellable() => 1 ), array( 'FULL', 'SHIP' ) );

		$input  = $this->placeInput();
		$answer = array();
		$log    = $this->captureQueries(
			function () use ( $input, &$answer ): void {
				$answer = $this->placement->place( $input, self::guest() );
			}
		);

		$this->assertSame( array( 0, 'approved' ), array( $input['grand_total_minor'], $answer['outcome'] ?? null ) );
		$this->assertSame(
			array( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'START TRANSACTION', 'COMMIT', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'START TRANSACTION', 'COMMIT' ),
			$log->matching( '/^(SET TRANSACTION|START TRANSACTION|COMMIT|ROLLBACK)\b/' )->sqls(),
			'Two transactions, each asked for at READ COMMITTED.'
		);
		$this->assertSame( 0, $log->matching( '/`' . preg_quote( $this->table( PaymentTables::INTENTS ), '/' ) . '`/' )->count(), 'No statement names an intent.' );
		$this->assertSame( array(), $this->gateway->calls, 'The gateway was never called.' );
	}
}
