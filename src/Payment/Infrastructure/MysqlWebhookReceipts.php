<?php
/**
 * MysqlWebhookReceipts: the webhook receipts' statements
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\Webhook\Receipt;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Domain\Webhook\WebhookReceipts;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\ModuleStatements;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The receipts of the events webhook deliveries reported, over `webhook_receipts`.
 *
 * Owns one fact: the receipts' SQL, each statement a public constant. A receipt is recorded and
 * settled each by one autocommitted statement, never inside a transaction: recorded by an insert
 * whose unique key `(provider, mode, event_id)` finds an event delivered before, settled by an
 * update that writes only an undecided receipt. A settled event delivered again therefore costs
 * the refused insert and the read that finds its receipt, two statements, whatever the storm.
 *
 * The prune's delete and doctor's two reads live here too, because they are receipt SQL; they
 * are not part of the port the receiver sees.
 *
 * @since 0.2.0
 */
final class MysqlWebhookReceipts implements WebhookReceipts {

	/**
	 * How long a receipt is kept after it was received, in seconds: thirty days, the retention policy `webhook_receipts`.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const RETENTION_SECONDS = 2592000;

	/**
	 * A receipt, undecided; a second delivery of the event meets the unique key and reads the first one's receipt instead.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECORD = "INSERT INTO {webhook_receipts} ( provider, mode, event_id, event_type, occurred_at, received_at, payload_hash, correlation_id, expires_at ) VALUES ( %s, %s, %s, %s, NULLIF( %s, '' ), UTC_TIMESTAMP(6), %s, NULLIF( %s, '' ), UTC_TIMESTAMP() + INTERVAL %d SECOND )";

	/**
	 * The receipt of an event, by the unique key: read only after the insert met it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIND = 'SELECT id, result FROM {webhook_receipts} WHERE provider = %s AND mode = %s AND event_id = %s';

	/**
	 * What was decided about an event, written only while its receipt is undecided. 0 and '' stand for no word, no intent and no ledger row.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE = "UPDATE {webhook_receipts} SET result = %s, result_code = NULLIF( %s, '' ), intent_uuid = NULLIF( %s, '' ), transaction_id = NULLIF( %d, 0 ), processed_at = UTC_TIMESTAMP(6) WHERE id = %d AND result IS NULL";

	/**
	 * A batch of the receipts past their retention, on the `expires_at` key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_EXPIRED = 'DELETE FROM {webhook_receipts} WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT %d';

	/**
	 * The receipts still undecided a while after they were received, oldest first, on the `result_received` key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNSETTLED = 'SELECT provider, mode, event_id, event_type, TIMESTAMPDIFF( SECOND, received_at, UTC_TIMESTAMP(6) ) AS age_seconds FROM {webhook_receipts} WHERE result IS NULL AND received_at <= UTC_TIMESTAMP(6) - INTERVAL %d SECOND ORDER BY received_at LIMIT %d';

	/**
	 * How many events of some decisions were received lately, by decision and word, most first, on the `result_received` key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESULT_COUNTS = 'SELECT result, result_code, COUNT(*) AS n FROM {webhook_receipts} WHERE result IN ({list}) AND received_at >= UTC_TIMESTAMP(6) - INTERVAL %d SECOND GROUP BY result, result_code ORDER BY n DESC LIMIT %d';

	/**
	 * The module the statements belong to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MODULE = 'payment';

	/**
	 * The connection, whose depth says whether a transaction is open.
	 *
	 * @since 0.2.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The statements, over the payment module's tables.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db         = $db;
		$this->statements = new ModuleStatements( $db, self::MODULE, PaymentTables::moduleNames() );
	}

	/**
	 * Turns a statement's tokens into wpdb placeholders and its values into arguments, in order, over the payment module's tables.
	 *
	 * The plugin's one token expansion (ModuleStatements::expand()); a concurrency test prepares
	 * the statement connection B sends with it, from the same constant.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When a token names no payment table, or the values do not match the placeholders.
	 *
	 * @param string   $statement One of this class's constants.
	 * @param array    $values    The values, in placeholder order.
	 * @param callable $tableName Returns a table's full name from its unprefixed name (string).
	 * @return array{0: string, 1: list<mixed>} The statement with wpdb placeholders, and its arguments.
	 *
	 * @phpstan-param list<mixed>              $values
	 * @phpstan-param callable(string): string $tableName
	 */
	public static function expand( string $statement, array $values, callable $tableName ): array {
		return ModuleStatements::expand( $statement, $values, PaymentTables::moduleNames(), $tableName, self::MODULE );
	}

	/**
	 * Records the receipt of an event, or finds the one an earlier delivery of it recorded; outside any transaction.
	 *
	 * One insert, which expires the receipt RETENTION_SECONDS after the database clock's now; when
	 * the unique key refuses it, one read of the receipt there.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, before any statement; or when the insert met a receipt the read does not find, which only a prune between the two could cause.
	 *
	 * @param string                  $gatewayId     The gateway the delivery was addressed to.
	 * @param Mode                    $mode          The mode of its address.
	 * @param string                  $eventId       The provider's id of the event.
	 * @param string                  $eventType     The provider's type of the event.
	 * @param \DateTimeImmutable|null $occurredAt    When the provider says it happened.
	 * @param string                  $payloadHash   The SHA-256 of the delivery's body, in hex.
	 * @param string                  $correlationId The request's correlation id.
	 * @return Receipt The receipt.
	 */
	public function record( string $gatewayId, Mode $mode, string $eventId, string $eventType, ?\DateTimeImmutable $occurredAt, string $payloadHash, string $correlationId ): Receipt {
		$this->requireNoTransaction( __FUNCTION__ );

		$occurred = null === $occurredAt ? '' : $occurredAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		try {
			$this->statements->execute( self::RECORD, $gatewayId, $mode->value, $eventId, $eventType, $occurred, $payloadHash, $correlationId, self::RETENTION_SECONDS );
		} catch ( DuplicateKey $delivered ) {
			// The event was delivered before: its receipt says whether it was decided.
			return $this->find( $gatewayId, $mode, $eventId );
		}

		return new Receipt( $this->statements->lastInsertId(), null );
	}

	/**
	 * Settles an undecided receipt with what was decided, outside any transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, before any statement.
	 *
	 * @param int             $receiptId The receipt.
	 * @param ReceiptDecision $decision  What was decided.
	 * @return bool True when it was settled here; false when it was settled before.
	 */
	public function settle( int $receiptId, ReceiptDecision $decision ): bool {
		$this->requireNoTransaction( __FUNCTION__ );

		return 1 === $this->statements->execute( self::SETTLE, $decision->result->value, $decision->code ?? '', $decision->intentUuid ?? '', $decision->transactionId ?? 0, $receiptId );
	}

	/**
	 * Deletes one batch of the receipts past their retention, oldest first.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most rows to delete.
	 * @return int The rows deleted.
	 */
	public function deleteExpired( int $limit ): int {
		return $this->statements->execute( self::DELETE_EXPIRED, $limit );
	}

	/**
	 * Lists the receipts still undecided a while after they were received, oldest first, for doctor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $olderThanSeconds How long ago they were received, at least.
	 * @param int $limit            The most to list.
	 * @return list<array{provider: string, mode: string, event_id: string, event_type: string, age_seconds: int}> The receipts.
	 */
	public function unsettled( int $olderThanSeconds, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'provider'    => (string) $row['provider'],
				'mode'        => (string) $row['mode'],
				'event_id'    => (string) $row['event_id'],
				'event_type'  => (string) $row['event_type'],
				'age_seconds' => (int) $row['age_seconds'],
			),
			$this->statements->rows( self::UNSETTLED, $olderThanSeconds, $limit )
		);
	}

	/**
	 * Counts the events of some decisions received lately, by decision and word, most first, for doctor; reads nothing for no decision.
	 *
	 * @since 0.2.0
	 *
	 * @param ReceiptResult[] $results       The decisions.
	 * @param int             $withinSeconds How long ago they were received, at most.
	 * @param int             $limit         The most pairs of decision and word to count.
	 * @return list<array{result: string, result_code: string|null, n: int}> The counts.
	 *
	 * @phpstan-param list<ReceiptResult> $results
	 */
	public function resultCounts( array $results, int $withinSeconds, int $limit ): array {
		if ( array() === $results ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'result'      => (string) $row['result'],
				'result_code' => null === $row['result_code'] ? null : (string) $row['result_code'],
				'n'           => (int) $row['n'],
			),
			$this->statements->rows( self::RESULT_COUNTS, array_map( static fn( ReceiptResult $result ): string => $result->value, $results ), $withinSeconds, $limit )
		);
	}

	/**
	 * Reads the receipt of an event by the unique key.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When there is none: the insert met it a statement ago.
	 *
	 * @param string $gatewayId The gateway.
	 * @param Mode   $mode      The mode.
	 * @param string $eventId   The provider's id of the event.
	 * @return Receipt The receipt.
	 */
	private function find( string $gatewayId, Mode $mode, string $eventId ): Receipt {
		$row = $this->statements->rows( self::FIND, $gatewayId, $mode->value, $eventId )[0] ?? null;

		if ( null === $row ) {
			throw new \LogicException( sprintf( 'The receipt of event %1$s of %2$s, which the insert met, is gone: only the prune deletes one, thirty days after it was received.', $eventId, $gatewayId ) );
		}

		return new Receipt( (int) $row['id'], null === $row['result'] ? null : ReceiptResult::from( (string) $row['result'] ) );
	}

	/**
	 * Refuses a receipt's statement inside a transaction, before any statement.
	 *
	 * A receipt is recorded before the money's transaction and settled after it, each committed at
	 * once: inside a transaction the receipt would wait on another delivery's uncommitted insert, or
	 * vanish with a rollback that the provider's retry could not tell from success.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException At a depth above 0.
	 *
	 * @param string $method The method called.
	 */
	private function requireNoTransaction( string $method ): void {
		if ( 0 !== $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlWebhookReceipts::%s() runs outside any transaction: a receipt is written before the money\'s transaction and settled after it.', $method ) );
		}
	}
}
