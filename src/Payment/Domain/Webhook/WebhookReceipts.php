<?php
/**
 * WebhookReceipts: the port to the webhook receipts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Webhook;

use SEOCart\Contracts\Payment\Mode;

defined( 'ABSPATH' ) || exit;

/**
 * The receipts of the events webhook deliveries reported: claimed and settled in the transaction that applies the event's result.
 *
 * Owns one fact: when a receipt is written. An event decided before is answered by one read of
 * its receipt. Otherwise its receipt is claimed as the first write of the transaction that applies
 * its result, and settled in that same transaction, so the receipt and the money commit together:
 * a second delivery of the event waits on the receipt's unique key until the first's transaction
 * ends, then finds the receipt decided; a crash, or a fault, leaves neither, and the provider's
 * next delivery processes the event once.
 *
 * @since 0.2.0
 */
interface WebhookReceipts {

	/**
	 * The longest event id a receipt keeps, in bytes: `webhook_receipts.event_id` is declared from it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EVENT_ID_LENGTH = 191;

	/**
	 * The longest event type a receipt keeps, in bytes: `webhook_receipts.event_type` is declared from it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EVENT_TYPE_LENGTH = 64;

	/**
	 * Finds the receipt of an event by its unique key: one plain read, decided or not, at any depth.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway the delivery was addressed to.
	 * @param Mode   $mode      The mode of its address.
	 * @param string $eventId   The event's id, as kept.
	 * @return Receipt|null The receipt, or null when no delivery of the event left one.
	 */
	public function find( string $gatewayId, Mode $mode, string $eventId ): ?Receipt;

	/**
	 * Claims the receipt of an event as the first write of the caller's transaction: inserts it undecided, or, when an earlier delivery's receipt is there, waits for that delivery's transaction and reads it under its lock.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 *
	 * @param string                  $gatewayId     The gateway the delivery was addressed to.
	 * @param Mode                    $mode          The mode of its address.
	 * @param string                  $eventId       The event's id, as kept: an identifier of 1 to 191 bytes.
	 * @param string                  $eventType     The event's type, as kept: 1 to 64 bytes.
	 * @param \DateTimeImmutable|null $occurredAt    When the provider says it happened; information only.
	 * @param string                  $payloadHash   The SHA-256 of the delivery's body, in hex.
	 * @param string                  $correlationId The request's correlation id.
	 * @return Receipt The receipt: undecided when this delivery claimed it, or as an earlier delivery left it.
	 */
	public function claim( string $gatewayId, Mode $mode, string $eventId, string $eventType, ?\DateTimeImmutable $occurredAt, string $payloadHash, string $correlationId ): Receipt;

	/**
	 * Settles an undecided receipt with what was decided, in the transaction that claimed it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 *
	 * @param int             $receiptId The receipt.
	 * @param ReceiptDecision $decision  What was decided.
	 * @return bool True when it was settled here; false when another delivery of the event settled it first.
	 */
	public function settle( int $receiptId, ReceiptDecision $decision ): bool;
}
