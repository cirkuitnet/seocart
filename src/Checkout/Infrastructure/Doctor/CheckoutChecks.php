<?php
/**
 * CheckoutChecks: doctor's check of the checkout's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure\Doctor;

use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a binary log that refuses the checkout's writes, placements still waiting for their payment after a day, and idempotency keys stranded in `claimed`, which it deletes on repair.
 *
 * Owns one fact: what doctor checks of the checkout. Placing an order and every stock change run
 * at READ COMMITTED, and a server whose binary log is on in `STATEMENT` format refuses every write
 * at that level: activation refuses such a server, and this check reports one whose format was
 * changed since. Nothing here can repair that; the format is the host's setting.
 *
 * Doctor calls a claimed key stranded when it is claimed long after it could be. A key is claimed and completed in the
 * one transaction that places its order, so a committed key that is still claimed after
 * STRANDED_SECONDS was left by a lost connection: the server committed the claim on its own and
 * the rest of the placement rolled back. A retry with that key is answered "placement in
 * progress" for as long as the key lives. Nothing references such a key, so --repair deletes it,
 * after checking again in its statement that it is still claimed and still that old; the client's
 * next retry then places its order. Age is judged by the database clock.
 *
 * A placement whose payment intent still waits for the gateway's result after PENDING_SECONDS is
 * reported by its order's uuid. The reconciliation job settles a placement as soon as the gateway
 * answers, a "no record of it" included, so one still waiting is one the gateway keeps deciding:
 * a person looks it up there. Nothing here settles it, and repair leaves it alone.
 *
 * It prints key ids, scopes and ages, and order uuids, never a hash or an answer.
 *
 * @since 0.1.0
 */
final class CheckoutChecks implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'checkout';

	/**
	 * How long a key may stay claimed before it is reported: far longer than any placement takes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STRANDED_SECONDS = 3600;

	/**
	 * How long a placement may wait for its payment's result before it is reported: a day.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PENDING_SECONDS = 86400;

	/**
	 * The most keys, and the most placements, the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The key statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlIdempotencyKeys
	 */
	private MysqlIdempotencyKeys $keys;

	/**
	 * The connection, whose server's binary log is checked.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The payment intents, whose waiting ones are the placements waiting.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentRepository
	 */
	private PaymentRepository $payments;

	/**
	 * The orders, which name a waiting placement by its uuid.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The keys the last run() found, for repair() to act on.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlIdempotencyKeys $keys     The key statements.
	 * @param Database             $db       The connection, whose server's binary log is checked.
	 * @param PaymentRepository    $payments The payment intents.
	 * @param OrderRepository      $orders   The orders.
	 */
	public function __construct( MysqlIdempotencyKeys $keys, Database $db, PaymentRepository $payments, OrderRepository $orders ) {
		$this->keys     = $keys;
		$this->db       = $db;
		$this->payments = $payments;
		$this->orders   = $orders;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `checkout`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Checks the binary log, and lists the placements waiting too long and the keys stranded in `claimed`.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when the binary log takes the checkout's writes, no placement has
	 *                     waited too long and no key is stranded.
	 */
	public function run(): CheckResult {
		$refused  = $this->db->refusesReadCommitted();
		$waiting  = $this->waitingPlacements();
		$stranded = $this->keys->stranded( self::STRANDED_SECONDS, self::LIMIT );

		$this->found = array_column( $stranded, 'id' );

		if ( ! $refused && array() === $waiting && array() === $stranded ) {
			return CheckResult::pass( self::NAME, sprintf( 'The binary log takes the checkout\'s writes, no placement has waited for its payment for more than %1$d hours, and no idempotency key has been claimed for more than %2$d minutes without its order.', intdiv( self::PENDING_SECONDS, 3600 ), intdiv( self::STRANDED_SECONDS, 60 ) ) );
		}

		$findings = array();

		if ( $refused ) {
			$findings[] = 'Critical: the binary log records statements (binlog_format = STATEMENT), and in that format the server refuses every write of an order placement and of a stock change, which run at READ COMMITTED (error 1665). Ask the host to set binlog_format to ROW or MIXED.';
		}

		foreach ( $waiting as $orderUuid ) {
			$findings[] = sprintf(
				'Warning: order %1$s has waited for its payment for more than %2$d hours: the gateway is still deciding. Look the payment up with the gateway; the order is settled once the gateway answers.',
				$orderUuid,
				intdiv( self::PENDING_SECONDS, 3600 )
			);
		}

		foreach ( $stranded as $key ) {
			$findings[] = sprintf(
				'Warning: idempotency key %1$d (%2$s) has been claimed for %3$d seconds without an order: a lost connection committed the claim alone. A retry with this key is told an order is being placed until --repair deletes it.',
				$key['id'],
				$key['scope'],
				$key['age_seconds']
			);
		}

		$summary = $refused ? 'The binary log refuses the checkout\'s writes.' : '';

		if ( array() !== $waiting ) {
			$summary = trim( $summary . ' ' . sprintf( '%d %s waiting for the payment.', count( $waiting ), 1 === count( $waiting ) ? 'placement' : 'placements' ) );
		}

		if ( array() !== $stranded ) {
			$summary = trim( $summary . ' ' . sprintf( '%d stranded idempotency %s.', count( $stranded ), 1 === count( $stranded ) ? 'key' : 'keys' ) );
		}

		return CheckResult::fail( self::NAME, $summary, $findings );
	}

	/**
	 * Lists the placements whose payment has waited for the gateway's result for more than PENDING_SECONDS, by their orders' uuids.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The orders' uuids; at most LIMIT.
	 */
	private function waitingPlacements(): array {
		$uuids = array();

		foreach ( $this->payments->stale( IntentStatus::awaitingResult(), self::PENDING_SECONDS, '', self::LIMIT ) as $intent ) {
			$order = $this->orders->statusOf( $intent->orderId );

			if ( null !== $order ) {
				$uuids[] = $order['uuid'];
			}
		}

		return $uuids;
	}

	/**
	 * Deletes the keys run() found, only while each is still claimed and still that old.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed.
	 */
	public function repair(): RepairResult {
		if ( array() === $this->found ) {
			return new RepairResult( self::NAME );
		}

		$deleted = $this->keys->deleteStranded( $this->found, self::STRANDED_SECONDS );

		if ( 0 === $deleted ) {
			return new RepairResult( self::NAME );
		}

		return new RepairResult(
			self::NAME,
			array( sprintf( 'deleted %1$d of the stranded idempotency keys %2$s; a key completed or claimed again since was kept.', $deleted, implode( ', ', $this->found ) ) )
		);
	}
}
