<?php
/**
 * Tests that a card number in a webhook delivery, in its body or a header, reaches no log line and no receipt, and that the webhook secret is never logged
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A provider's delivery may carry what the plugin must never keep: a card number in a metadata field of its body, or in a header. Neither reaches a log line, so the logger's card-number filter has nothing to remove, nor the receipt, which keeps only the body's hash. The same body with a bad signature is logged with its hash and length alone. The stand-in's webhook secret is in no log line either.
 *
 * Planted violation: in ReceiveWebhook::logDecided(), add the raw body to the line's context
 * (`'body' => $envelope->rawBody`): the card number then reaches the logger, which removes it and
 * warns `logging.card_number_removed`.
 *
 * @since 0.2.0
 */
final class WebhookCardDataTest extends WebhookTestCase {

	/**
	 * The published test card numbers the deliveries carry: one in the body, one in a header.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const PLANTED = array( '4242424242424242', '4111 1111 1111 1111', '4111111111111111' );

	/**
	 * Tests that a verified delivery carrying a card number in its body and a header is settled, and the number reaches neither the log nor the receipt.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_in_a_verified_delivery_is_kept_nowhere(): void {
		$placed = $this->placed( StubGateway::THROW );
		$signed = $this->withCardNumber( StubWebhooks::of( $this->approvalOf( $placed ) ) );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $signed ) );
		$this->assertKeptNowhere();
		$this->assertSame( 1, $this->receiptCount() );
		$this->assertSame( hash( 'sha256', $signed->body() ), $this->receiptOf( $signed->eventId() )['payload_hash'] ?? null, 'The receipt keeps the body as its hash.' );
	}

	/**
	 * Tests that the same delivery with a bad signature is logged with the address, the reason and the body's hash and length only.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_in_a_rejected_delivery_is_kept_nowhere(): void {
		$placed = $this->placed( StubGateway::THROW );
		$forged = $this->withCardNumber( StubWebhooks::of( $this->approvalOf( $placed ) ) )->withBadSignature();

		$this->assertSame( 'payment.webhook_rejected', $this->refusalOf( $forged ) );
		$this->assertKeptNowhere();
		$this->assertSame( array( array( 'gateway_id', 'mode', 'reason', 'body_sha256', 'body_length' ) ), array_map( 'array_keys', $this->logged( 'payment.webhook_rejected' ) ) );
	}

	/**
	 * Returns a delivery whose body carries a card number in a metadata field and which is sent with one in a header, signed as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery The delivery.
	 * @return StubWebhooks The delivery, carrying the numbers.
	 */
	private function withCardNumber( StubWebhooks $delivery ): StubWebhooks {
		$event = (array) json_decode( $delivery->body(), true );

		$event['data']['metadata'] = array( 'customer_note' => self::PLANTED[0] );

		return $delivery->withBody( (string) wp_json_encode( $event ) )->withHeader( 'X-Card', self::PLANTED[1] );
	}

	/**
	 * Asserts that no planted number, and not the webhook secret, reached a log line or a receipt, and that the logger never had a card number to remove.
	 *
	 * @since 0.2.0
	 */
	private function assertKeptNowhere(): void {
		$logged   = (string) wp_json_encode( $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i', $this->table( LogsTable::NAME ) ) );
		$receipts = (string) wp_json_encode( $this->db->fetchAll( 'SELECT * FROM %i', $this->table( WebhookReceiptTables::RECEIPTS ) ) );

		foreach ( array_merge( self::PLANTED, array( StubGateway::WEBHOOK_SECRET ) ) as $kept ) {
			$this->assertStringNotContainsString( $kept, $logged, 'The log holds what it must never keep.' );
			$this->assertStringNotContainsString( $kept, $receipts, 'A receipt holds what it must never keep.' );
		}

		$this->assertSame( array(), $this->logged( 'logging.card_number_removed' ), 'The logger was handed a card number to remove.' );
	}
}
