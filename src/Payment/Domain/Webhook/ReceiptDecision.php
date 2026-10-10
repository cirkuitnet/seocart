<?php
/**
 * ReceiptDecision: what a webhook receipt is settled with
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Webhook;

use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Domain\Refund\ProviderRefundOutcome;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * What was decided about a provider's event, as its receipt records it: the decision, the word that says more, the intent and the ledger row.
 *
 * Owns one fact: how a decision is written down, so the receipt says what happened without a
 * second read. The word is the receipt's `result_code`: for an applied result, what the money
 * path did; for a stale one, the state it found the payment in; for money kept for a person, why;
 * for an ignored event, its type or why it is not the store's.
 *
 * @since 0.2.0
 */
final readonly class ReceiptDecision {

	/**
	 * Why a result is ignored: no intent of the store's has the reference it names, or the intent is another gateway's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNKNOWN_INTENT = 'unknown_intent';

	/**
	 * Why a result is ignored: it reached the address of one mode about an intent of the other, as a second site sharing a provider account sees.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MODE_MISMATCH = 'mode_mismatch';

	/**
	 * The longest word `webhook_receipts.result_code` holds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const CODE_MAX_LENGTH = 64;

	/**
	 * Records the decision.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the word is empty or longer than the receipt holds.
	 *
	 * @param ReceiptResult $result        What was decided.
	 * @param string|null   $code          Optional. The word that says more; null for none. Default null.
	 * @param string|null   $intentUuid    Optional. The intent the event was about, once known. Default null.
	 * @param int|null      $transactionId Optional. The ledger row the delivery wrote, or met for a duplicate. Default null.
	 */
	public function __construct(
		public ReceiptResult $result,
		public ?string $code = null,
		public ?string $intentUuid = null,
		public ?int $transactionId = null
	) {
		if ( null !== $code && ( '' === $code || strlen( $code ) > self::CODE_MAX_LENGTH ) ) {
			throw new \InvalidArgumentException( sprintf( 'A receipt\'s word is 1 to %d bytes, or none.', self::CODE_MAX_LENGTH ) );
		}
	}

	/**
	 * Writes down what the money path did with an event's result.
	 *
	 * An applied, a declined, a waiting and a pending result are `applied`, with the kind as the
	 * word; a duplicate is `duplicate`; a stale result is `stale`, with the state the intent was in;
	 * money kept for a person is `unapplied`, with why. The intent and the ledger row the money path
	 * wrote, or met, go with it.
	 *
	 * @since 0.2.0
	 *
	 * @param Application $applied What the money path did.
	 * @return self The decision.
	 */
	public static function ofApplication( Application $applied ): self {
		$result = match ( $applied->kind ) {
			ApplicationKind::Duplicate => ReceiptResult::Duplicate,
			ApplicationKind::Stale     => ReceiptResult::Stale,
			ApplicationKind::Mismatch  => ReceiptResult::Unapplied,
			default                    => ReceiptResult::Applied,
		};
		$code = match ( $result ) {
			ReceiptResult::Applied   => $applied->kind->value,
			ReceiptResult::Stale     => $applied->intentFrom->value,
			ReceiptResult::Unapplied => $applied->reason,
			default                  => null,
		};

		return new self( $result, $code, $applied->intentUuid, $applied->transactionId );
	}

	/**
	 * Writes down what a refund's result came to, recorded through its claim or kept for a person.
	 *
	 * A refund recorded or declined through its open claim is `applied`, with the word the money
	 * path uses for it; one the ledger had already is `duplicate`; money kept for a person, by the
	 * claim's answer, with no claim, or for a refund the provider took back, is `unapplied`, with
	 * why; a result that moved no money with no
	 * open claim is `ignored`, with why.
	 *
	 * @since 0.2.0
	 *
	 * @param ProviderRefundOutcome $outcome    What the refund service did.
	 * @param string                $intentUuid The intent the refund is of.
	 * @return self The decision.
	 */
	public static function ofRefund( ProviderRefundOutcome $outcome, string $intentUuid ): self {
		return match ( $outcome->kind ) {
			ProviderRefundKind::Recorded     => new self( ReceiptResult::Applied, ApplicationKind::Applied->value, $intentUuid, $outcome->transactionId ),
			ProviderRefundKind::Declined     => new self( ReceiptResult::Applied, ApplicationKind::Declined->value, $intentUuid, $outcome->transactionId ),
			ProviderRefundKind::Duplicate    => new self( ReceiptResult::Duplicate, null, $intentUuid, $outcome->transactionId ),
			ProviderRefundKind::Unreconciled,
			ProviderRefundKind::Unexpected,
			ProviderRefundKind::Reversed     => new self( ReceiptResult::Unapplied, $outcome->reason, $intentUuid, $outcome->transactionId ),
			ProviderRefundKind::Ignored      => new self( ReceiptResult::Ignored, $outcome->reason, $intentUuid ),
		};
	}

	/**
	 * Decides that an event is not acted on.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $code       The event's type, or why it is not the store's, such as UNKNOWN_INTENT.
	 * @param string|null $intentUuid Optional. The store's intent it was about, when it names one. Default null.
	 * @return self The decision.
	 */
	public static function ignored( string $code, ?string $intentUuid = null ): self {
		return new self( ReceiptResult::Ignored, $code, $intentUuid );
	}
}
