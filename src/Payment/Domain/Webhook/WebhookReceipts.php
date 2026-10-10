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
 * The receipts of the events webhook deliveries reported: recorded before the event's result is applied, and settled after.
 *
 * Owns one fact: the receipt's two statements, each in a transaction of its own, never inside
 * another. A receipt is recorded before the money's transaction begins, so a crash in between
 * leaves it undecided and the provider's next delivery processes the event again; it is settled
 * after that transaction committed, only while it is still undecided, so of two deliveries of an
 * event racing each other the first to finish writes the decision and the second changes nothing.
 *
 * @since 0.2.0
 */
interface WebhookReceipts {

	/**
	 * Records the receipt of an event, or finds the one an earlier delivery of it recorded; outside any transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, before any statement.
	 *
	 * @param string                  $gatewayId     The gateway the delivery was addressed to.
	 * @param Mode                    $mode          The mode of its address.
	 * @param string                  $eventId       The provider's id of the event: printable ASCII, 1 to 191 bytes.
	 * @param string                  $eventType     The provider's type of the event: printable ASCII, 1 to 64 bytes.
	 * @param \DateTimeImmutable|null $occurredAt    When the provider says it happened; information only.
	 * @param string                  $payloadHash   The SHA-256 of the delivery's body, in hex.
	 * @param string                  $correlationId The request's correlation id.
	 * @return Receipt The receipt: undecided when this delivery recorded it, or as an earlier delivery left it.
	 */
	public function record( string $gatewayId, Mode $mode, string $eventId, string $eventType, ?\DateTimeImmutable $occurredAt, string $payloadHash, string $correlationId ): Receipt;

	/**
	 * Settles an undecided receipt with what was decided, outside any transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, before any statement.
	 *
	 * @param int             $receiptId The receipt.
	 * @param ReceiptDecision $decision  What was decided.
	 * @return bool True when it was settled here; false when another delivery of the event settled it first.
	 */
	public function settle( int $receiptId, ReceiptDecision $decision ): bool;
}
