<?php
/**
 * PaymentLedgerCheck: every payment amount agrees with the ledger rows it is derived from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Doctor;

use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * Holds each intent's and each order's payment amounts to the ledger, and reports; it never repairs.
 *
 * Owns one fact: when doctor calls payments inconsistent. An intent's authorized, captured and
 * refunded amounts, and their base twins, must equal the sums of its applied, approved ledger
 * rows by operation; an order's must equal the sums over its intents; no intent may have
 * refunded more than it captured; and an order must point at its current totals snapshot. Any
 * difference is critical, named with the intent's or the order's uuid and both figures, and left
 * for a person: the ledger is the truth and the amounts are its projection, so doctor never
 * rewrites one. A ledger row that did not match its order, and an order flagged for it, are
 * warnings: money a person must reconcile.
 *
 * The intents and the orders are compared a page at a time, by id, each page one bounded read
 * (with one more for the orders' intent sums), until every one was compared or a line has found
 * as many as it lists. The other lines are reads of what they report, the first LIMIT found. It
 * prints uuids and amounts in minor units, never anything about a person.
 *
 * @since 0.1.0
 */
final class PaymentLedgerCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'payments';

	/**
	 * The most intents or orders each line of the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * How many intents or orders one read compares, by default.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * Each order amount column, by the name of the sum of the intents' column it projects.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ORDER_COLUMNS = array(
		'authorized'      => 'authorized',
		'captured'        => 'paid',
		'refunded'        => 'refunded',
		'base_authorized' => 'base_authorized',
		'base_captured'   => 'base_paid',
		'base_refunded'   => 'base_refunded',
	);

	/**
	 * The payment statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlPaymentRepository
	 */
	private MysqlPaymentRepository $payments;

	/**
	 * The order statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * How many intents or orders one read compares.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $page;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the page size is not positive.
	 *
	 * @param MysqlPaymentRepository $payments The payment statements.
	 * @param OrderRepository        $orders   The order statements.
	 * @param int                    $page     Optional. How many intents or orders one read compares. Default PAGE.
	 */
	public function __construct( MysqlPaymentRepository $payments, OrderRepository $orders, int $page = self::PAGE ) {
		if ( $page < 1 ) {
			throw new \InvalidArgumentException( 'The check reads at least one row at a time.' );
		}

		$this->payments = $payments;
		$this->orders   = $orders;
		$this->page     = $page;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `payments`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Compares every intent's and every order's payment amounts with the ledger.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every amount agrees and nothing waits for a person.
	 */
	public function run(): CheckResult {
		list( $intentDrift, $overRefunded ) = $this->intentLines();

		$findings = array_merge(
			$intentDrift,
			$this->orderDrift(),
			$overRefunded,
			$this->totalsDrift(),
			$this->unappliedResults(),
			$this->unreconciledOrders()
		);

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Every intent\'s and every order\'s payment amounts agree with the ledger, and no payment waits for a person to reconcile it.' );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d payment %s found.', count( $findings ), 1 === count( $findings ) ? 'problem' : 'problems' ), $findings );
	}

	/**
	 * Compares every intent with its ledger rows, a page at a time: the intents whose amounts are not their sums, and those that refunded more than they captured.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: list<string>, 1: list<string>} The critical lines of each kind, at most LIMIT of each.
	 */
	private function intentLines(): array {
		$drift        = array();
		$overRefunded = array();

		$this->walk(
			fn( int $after, int $page ): array => $this->payments->intentLedger( $after, $page ),
			static function ( array $intents ) use ( &$drift, &$overRefunded ): bool {
				foreach ( $intents as $intent ) {
					$uuid    = CheckResult::identifier( $intent['uuid'] );
					$amounts = $intent['amounts'];

					if ( $amounts !== $intent['sums'] ) {
						$drift[] = sprintf( 'Critical: payment %1$s records %2$s.', $uuid, self::differences( $amounts, $intent['sums'], 'its applied approvals add up to' ) );
					}

					if ( $amounts['refunded'] > $amounts['captured'] ) {
						$overRefunded[] = sprintf( 'Critical: payment %1$s refunded %2$d of the %3$d it captured.', $uuid, $amounts['refunded'], $amounts['captured'] );
					} elseif ( $amounts['base_refunded'] > $amounts['base_captured'] ) {
						$overRefunded[] = sprintf( 'Critical: payment %1$s refunded %2$d of the %3$d it captured, in the base currency.', $uuid, $amounts['base_refunded'], $amounts['base_captured'] );
					}
				}

				return count( $drift ) >= self::LIMIT && count( $overRefunded ) >= self::LIMIT;
			}
		);

		return array( array_slice( $drift, 0, self::LIMIT ), array_slice( $overRefunded, 0, self::LIMIT ) );
	}

	/**
	 * Lists the orders whose payment amounts are not the sums of their intents', a page of orders at a time.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One critical line per order, at most LIMIT.
	 */
	private function orderDrift(): array {
		$findings = array();

		$this->walk(
			fn( int $after, int $page ): array => $this->orders->paymentAmounts( $after, $page ),
			function ( array $orders ) use ( &$findings ): bool {
				$sums = array() === $orders ? array() : $this->payments->orderSums( array_column( $orders, 'id' ) );

				foreach ( $orders as $order ) {
					$amounts = array();
					$totals  = array();

					foreach ( self::ORDER_COLUMNS as $sum => $column ) {
						$amounts[ $column ] = $order[ $column ];
						$totals[ $column ]  = $sums[ $order['id'] ][ $sum ] ?? 0;
					}

					if ( $amounts !== $totals ) {
						$findings[] = sprintf( 'Critical: order %1$s records %2$s.', CheckResult::identifier( $order['uuid'] ), self::differences( $amounts, $totals, 'its payments add up to' ) );
					}
				}

				return count( $findings ) >= self::LIMIT;
			}
		);

		return array_slice( $findings, 0, self::LIMIT );
	}

	/**
	 * Lists the orders that do not point at their current totals snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One critical line per order.
	 */
	private function totalsDrift(): array {
		return array_map(
			static fn( array $order ): string => sprintf(
				'Critical: order %1$s points at totals snapshot %2$s, but its current snapshot is %3$s.',
				CheckResult::identifier( $order['uuid'] ),
				null === $order['points_at'] ? 'none' : (string) $order['points_at'],
				null === $order['current'] ? 'none' : (string) $order['current']
			),
			$this->orders->currentTotalsDrift( self::LIMIT )
		);
	}

	/**
	 * Lists the ledger rows the projection refused: results that did not match their orders.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One warning line per row.
	 */
	private function unappliedResults(): array {
		return array_map(
			static fn( array $row ): string => sprintf( 'Warning: the %1$s result %2$s of payment %3$s did not match its order and moved no money; a person must reconcile it.', CheckResult::identifier( $row['operation'] ), CheckResult::identifier( $row['uuid'] ), CheckResult::identifier( $row['intent_uuid'] ) ),
			$this->payments->unappliedResults( self::LIMIT )
		);
	}

	/**
	 * Lists the orders flagged as holding money a person must reconcile.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One warning line per order.
	 */
	private function unreconciledOrders(): array {
		return array_map(
			static fn( array $order ): string => sprintf( 'Warning: order %1$s holds money a person must reconcile.', CheckResult::identifier( $order['uuid'] ) ),
			$this->orders->unreconciled( self::LIMIT )
		);
	}

	/**
	 * Reads a table a page at a time, by id, until a page comes back short or the inspection has found enough.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $read    Reads the page after an id (int), of at most a number of rows (int); each row has its `id`.
	 * @param callable $inspect Inspects one page, and tells whether it has found as many as its lines list.
	 *
	 * @phpstan-param callable(int, int): list<array<string, mixed>> $read
	 * @phpstan-param callable(list<array<string, mixed>>): bool $inspect
	 */
	private function walk( callable $read, callable $inspect ): void {
		$after = 0;

		do {
			$rows   = $read( $after, $this->page );
			$count  = count( $rows );
			$enough = $inspect( $rows );
			$after  = 0 === $count ? $after : (int) $rows[ $count - 1 ]['id'];
		} while ( $this->page === $count && ! $enough );
	}

	/**
	 * Describes the columns whose recorded amount differs from the sum it projects.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $recorded The amounts recorded, by column.
	 * @param array<string, int> $sums     The sums they project, by the same columns.
	 * @param string             $source   What the sums are, for the sentence.
	 * @return string For example `paid_minor 3080 while its payments add up to 3079`.
	 */
	private static function differences( array $recorded, array $sums, string $source ): string {
		$parts = array();

		foreach ( $recorded as $column => $amount ) {
			if ( $amount !== $sums[ $column ] ) {
				$parts[] = sprintf( '%1$s_minor %2$d while %3$s %4$d', $column, $amount, $source, $sums[ $column ] );
			}
		}

		return implode( '; ', $parts );
	}
}
