<?php
/**
 * Tests rule 10's comparison of what the store's webhook route decided with what the gateway read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\ReceiptRules;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Each reading of a delivery and what the route did to its event's receipt: rejected with no receipt changed; a new receipt of this delivery with the decision the reading calls for; a redelivery that changes nothing. Anything else fails rule 10.
 *
 * @since 0.2.0
 */
final class ReceiptRulesTest extends TestCase {

	/**
	 * The delivery's raw body.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BODY = '{"id":"evt_1","type":"intent.authorized"}';

	/**
	 * Returns each reading with what the route answered and did to the receipt, and whether that matches.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, int, string|null, array{id: int, result: string, result_code: string, payload_hash: string}|null, array{id: int, result: string, result_code: string, payload_hash: string}|null, string|null}> The reading's kind, the status, the refusal's code, the receipt before and after, and what the failure says after the status, the refusal's code included; null when they match.
	 */
	public function decisions(): array {
		$rejected = PaymentError::WebhookRejected->value;
		$applied  = self::receipt( ReceiptResult::Applied->value, 'authorize_approved' );
		$other    = self::receipt( ReceiptResult::Ignored->value, ReceiptDecision::UNKNOWN_INTENT, 2, '{"id":"evt_1","type":"account.updated"}' );

		return array(
			'rejected, refused with no receipt'       => array( 'rejected', 401, $rejected, null, null, null ),
			'rejected, its event already decided'     => array( 'rejected', 401, $rejected, $applied, $applied, null ),
			'rejected, yet kept'                      => array( 'rejected', 401, $rejected, null, $applied, ' payment.webhook_rejected and changed its event\'s receipt' ),
			'rejected, refused for another reason'    => array( 'rejected', 503, PaymentError::WebhookNotSettled->value, null, null, ' payment.webhook_not_settled, not refused as unverified' ),
			'ignored, kept for its type'              => array( 'ignored', 200, null, null, self::receipt( ReceiptResult::Ignored->value, 'account.updated' ), null ),
			'ignored, kept as no intent of the store' => array( 'ignored', 200, null, null, self::receipt( ReceiptResult::Ignored->value, ReceiptDecision::UNKNOWN_INTENT ), ' and its receipt says ignored (unknown_intent)' ),
			'ignored, applied'                        => array( 'ignored', 200, null, null, $applied, ' and its receipt says applied (authorize_approved)' ),
			'ignored, refused'                        => array( 'ignored', 401, $rejected, null, null, ' payment.webhook_rejected and kept no receipt' ),
			'a result, applied'                       => array( 'result', 200, null, null, $applied, null ),
			'a result, a duplicate'                   => array( 'result', 200, null, null, self::receipt( ReceiptResult::Duplicate->value, 'authorize_approved' ), null ),
			'a result, stale'                         => array( 'result', 200, null, null, self::receipt( ReceiptResult::Stale->value, 'authorize_approved' ), null ),
			'a result, unapplied'                     => array( 'result', 200, null, null, self::receipt( ReceiptResult::Unapplied->value, 'authorize_approved' ), null ),
			'a result of an intent not the store\'s'  => array( 'result', 200, null, null, self::receipt( ReceiptResult::Ignored->value, ReceiptDecision::UNKNOWN_INTENT ), null ),
			'a result of the other mode'              => array( 'result', 200, null, null, self::receipt( ReceiptResult::Ignored->value, ReceiptDecision::MODE_MISMATCH ), null ),
			'a result, ignored for its type'          => array( 'result', 200, null, null, self::receipt( ReceiptResult::Ignored->value, 'intent.authorized' ), ' and its receipt says ignored (intent.authorized)' ),
			'a result, not settled'                   => array( 'result', 503, PaymentError::WebhookNotSettled->value, null, null, ' payment.webhook_not_settled and kept no receipt' ),
			'a result, refused as unverified'         => array( 'result', 401, $rejected, null, null, ' payment.webhook_rejected and kept no receipt' ),
			'a result delivered again, unchanged'     => array( 'result', 200, null, $applied, $applied, null ),
			'a result delivered again, changed'       => array( 'result', 200, null, $applied, self::receipt( ReceiptResult::Duplicate->value, 'authorize_approved' ), ', and, delivered again, it changed the receipt its first delivery left' ),
			'a result whose event id an earlier used' => array( 'result', 200, null, $other, $other, ', and its event\'s receipt is another delivery\'s: an earlier delivery of another body used the same event id' ),
		);
	}

	/**
	 * Tests that a decision matching the reading passes, and any other fails rule 10, naming the delivery, the reading and what the route did.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider decisions
	 * @group conformance
	 *
	 * @param string                                                                         $kind    The reading's kind.
	 * @param int                                                                            $status  The route's status.
	 * @param string|null                                                                    $refused The code of the route's refusal.
	 * @param array{id: int, result: string, result_code: string, payload_hash: string}|null $before The receipt before the delivery.
	 * @param array{id: int, result: string, result_code: string, payload_hash: string}|null $after  The receipt after it.
	 * @param string|null                                                                    $says    What the failure says after the status, the refusal's code included; null when the decision matches.
	 */
	public function test_the_route_decides_as_the_gateway_reads( string $kind, int $status, ?string $refused, ?array $before, ?array $after, ?string $says ): void {
		$failure = ReceiptRules::failure( 'authorized', self::reading( $kind ), $status, $refused, $before, $after, self::BODY );

		if ( null === $says ) {
			$this->assertNull( $failure );

			return;
		}

		$this->assertSame( Rule::WebhooksVerifiedFirst->message() . 'the delivery authorized, which the gateway reads as ' . $kind . ', was decided otherwise by the store\'s webhook route: it answered ' . $status . $says . '.', $failure );
	}

	/**
	 * Builds a reading of a kind.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kind `rejected`, `ignored` or `result`.
	 * @return WebhookReading The reading.
	 */
	private static function reading( string $kind ): WebhookReading {
		$at = new \DateTimeImmutable( '2026-06-01T12:00:00+00:00' );

		return match ( $kind ) {
			'rejected' => WebhookReading::rejected( WebhookReading::BAD_SIGNATURE ),
			'ignored'  => WebhookReading::ignored( 'evt_2', 'account.updated', 'not_a_payment_event', $at ),
			default    => WebhookReading::result( 'evt_1', 'intent.authorized', $at, new GatewayResult( 'reference', Operation::Authorize, Outcome::Approved, '01942f00-0000-7000-8000-00000000a001', Money::of( 2000, Currency::of( 'USD' ) ), 'rch_1', 'ri_1' ) ),
		};
	}

	/**
	 * Builds a receipt as the route's table keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $result The result.
	 * @param string $code   The result's code.
	 * @param int    $id     Optional. The receipt's id. Default 1.
	 * @param string $body   Optional. The body of the delivery that left it. Default this test's delivery's.
	 * @return array{id: int, result: string, result_code: string, payload_hash: string} The receipt.
	 */
	private static function receipt( string $result, string $code, int $id = 1, string $body = self::BODY ): array {
		return array(
			'id'           => $id,
			'result'       => $result,
			'result_code'  => $code,
			'payload_hash' => hash( 'sha256', $body ),
		);
	}
}
