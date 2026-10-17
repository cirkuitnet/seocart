<?php
/**
 * ReceiptRules: rule 10's comparison of what the store's webhook route decided with what the gateway read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;

/**
 * Tells whether the store's receiver decided about a delivery as the gateway's reading of it says, by what the delivery did to its event's receipt.
 *
 * Owns one fact: which decision of the receiver matches which reading. The receipt of the event is
 * read before the delivery and after it:
 *
 * - a delivery read as rejected is refused with `payment.webhook_rejected` and changes no receipt;
 * - one read as ignored or as a result is answered 200 and leaves a new receipt, the first of its
 *   event, or, delivered again, finds the receipt its first delivery left and changes nothing: the
 *   duplicate's transition. Either way the receipt is this delivery's, by the hash of its body, so
 *   an earlier delivery of another body that used the same event id cannot stand in for it;
 * - the receipt of one read as ignored says `ignored` for its own type; of one read as a result, it
 *   settled it (applied, duplicate, stale or unapplied), or ignored it because the intent it names
 *   is not the store's (a replayed delivery is about an intent of the recording).
 *
 * @since 0.2.0
 */
final class ReceiptRules {

	/**
	 * The words of a receipt that ignored a result for its intent, not for its type.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const NOT_THE_STORES = array( ReceiptDecision::UNKNOWN_INTENT, ReceiptDecision::MODE_MISMATCH );

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {
	}

	/**
	 * Returns rule 10's failure when the route decided about a delivery otherwise than the gateway read it.
	 *
	 * @since 0.2.0
	 *
	 * @param string                    $name    The delivery, with the case, for the message.
	 * @param WebhookReading            $reading The gateway's reading of it.
	 * @param int                       $status  The route's status.
	 * @param string|null               $refused The code of the route's refusal; null when it accepted the delivery.
	 * @param array<string, mixed>|null $before  The receipt of the delivery's event before the delivery: its id, result, result_code and payload_hash; null for none.
	 * @param array<string, mixed>|null $after   The receipt of the event after the delivery; null for none.
	 * @param string                    $body    The delivery's raw body.
	 * @return string|null The failure, beginning with the rule's message; null when the decision matches the reading.
	 *
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string}|null $before
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string}|null $after
	 */
	public static function failure( string $name, WebhookReading $reading, int $status, ?string $refused, ?array $before, ?array $after, string $body ): ?string {
		$how = self::mismatch( $reading, $status, $refused, $before, $after, $body );

		if ( null === $how ) {
			return null;
		}

		return Rule::WebhooksVerifiedFirst->message() . sprintf( 'the delivery %1$s, which the gateway reads as %2$s, was decided otherwise by the store\'s webhook route: %3$s.', $name, $reading->kind->value, $how );
	}

	/**
	 * Says how a decision differs from a reading.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookReading            $reading The reading.
	 * @param int                       $status  The route's status.
	 * @param string|null               $refused The code of the route's refusal, or null.
	 * @param array<string, mixed>|null $before  The receipt before, or null.
	 * @param array<string, mixed>|null $after   The receipt after, or null.
	 * @param string                    $body    The delivery's raw body.
	 * @return string|null How, as a clause; null when they match.
	 *
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string}|null $before
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string}|null $after
	 */
	private static function mismatch( WebhookReading $reading, int $status, ?string $refused, ?array $before, ?array $after, string $body ): ?string {
		$answered = sprintf( 'it answered %1$d%2$s', $status, null === $refused ? '' : ' ' . $refused );

		if ( WebhookReadingKind::Rejected === $reading->kind ) {
			if ( PaymentError::WebhookRejected->value !== $refused ) {
				return $answered . ', not refused as unverified';
			}

			return $before === $after ? null : $answered . ' and changed its event\'s receipt';
		}

		if ( 200 !== $status || null === $after ) {
			return $answered . ' and ' . ( null === $after ? 'kept no receipt' : self::says( $after ) );
		}

		if ( hash( 'sha256', $body ) !== $after['payload_hash'] ) {
			return $answered . ', and its event\'s receipt is another delivery\'s: an earlier delivery of another body used the same event id';
		}

		if ( null !== $before && $before !== $after ) {
			return $answered . ', and, delivered again, it changed the receipt its first delivery left';
		}

		return self::decides( $reading, $after ) ? null : $answered . ' and ' . self::says( $after );
	}

	/**
	 * Tells whether a receipt's decision is the one a reading calls for.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookReading       $reading The reading: ignored or a result.
	 * @param array<string, mixed> $receipt The receipt.
	 * @return bool True when it is.
	 *
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string} $receipt
	 */
	private static function decides( WebhookReading $reading, array $receipt ): bool {
		$ignored    = ReceiptResult::Ignored->value === $receipt['result'];
		$forIntent  = in_array( $receipt['result_code'], self::NOT_THE_STORES, true );
		$settledOne = in_array( $receipt['result'], array( ReceiptResult::Applied->value, ReceiptResult::Duplicate->value, ReceiptResult::Stale->value, ReceiptResult::Unapplied->value ), true );

		return WebhookReadingKind::Ignored === $reading->kind ? $ignored && ! $forIntent : $settledOne || ( $ignored && $forIntent );
	}

	/**
	 * Says what a receipt decided.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 * @return string For example "its receipt says ignored (intent.authorized)".
	 *
	 * @phpstan-param array{id: int, result: string, result_code: string, payload_hash: string} $receipt
	 */
	private static function says( array $receipt ): string {
		return sprintf( 'its receipt says %1$s (%2$s)', $receipt['result'], $receipt['result_code'] );
	}
}
