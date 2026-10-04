<?php
/**
 * GatewayResult: what a payment provider answered, before it is applied
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to the developer; they are never HTML.

/**
 * One answer of a provider about one intent: a synchronous reply, a webhook or a status query.
 *
 * Owns one fact: what a provider said, as the payment service applies it. The same answer may
 * arrive more than once, by the redirect and by the webhook; it is applied once, keyed by the
 * provider, the provider object and the operation.
 *
 * The provider object is the object of this attempt's outcome, a charge, a capture or a refund,
 * never the long-lived provider intent: a provider that confirms one intent again after a decline
 * makes a new charge, and it is that new charge that keys the ledger, so the retry is not taken
 * for the decline. A result without an object, an error before the provider made one, is not
 * deduplicated; the intent's state still refuses to apply it twice. A request for the customer
 * to act, or an answer still pending, carries no object. A void names the provider's cancellation
 * object where the provider makes one, and otherwise the provider's intent: an intent is voided
 * once, so the key stays unique.
 *
 * @since 0.1.0
 * @since 0.2.0 Moved to the public contract.
 *
 * @api
 */
final readonly class GatewayResult {

	/**
	 * The longest provider id `payment_transactions.provider` holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PROVIDER_LENGTH = 32;

	/**
	 * The longest provider reference the ledger and the intent hold.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const REFERENCE_LENGTH = 191;

	/**
	 * The longest machine code `payment_transactions.error_code` holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ERROR_CODE_LENGTH = 64;

	/**
	 * Records the answer.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the amount is negative, an approved refund gives back nothing, or a reference is empty or longer than the ledger holds.
	 *
	 * @param string          $provider         The gateway's id.
	 * @param Operation       $operation        The operation answered.
	 * @param Outcome         $outcome          The answer.
	 * @param string          $intentUuid       The intent the answer is about, as the provider echoed it.
	 * @param Money           $amount           The amount the provider reports, in the currency it reports.
	 * @param string|null     $providerObjectId Optional. The provider's object for this outcome: a charge, a capture or a refund. Default null.
	 * @param string|null     $providerIntentId Optional. The provider's reference to the intent. Default null.
	 * @param string|null     $errorCode        Optional. The provider's machine code for a decline, for example `card_declined`. Default null.
	 * @param Settlement|null $settlement       Optional. How the provider settles the amount, when it said. Default null.
	 */
	public function __construct(
		public string $provider,
		public Operation $operation,
		public Outcome $outcome,
		public string $intentUuid,
		public Money $amount,
		public ?string $providerObjectId = null,
		public ?string $providerIntentId = null,
		public ?string $errorCode = null,
		public ?Settlement $settlement = null
	) {
		if ( $amount->isNegative() ) {
			throw new \InvalidArgumentException( 'A gateway result reports the amount it moved, which is never negative.' );
		}

		if ( Operation::Refund === $operation && Outcome::Approved === $outcome && $amount->isZero() ) {
			throw new \InvalidArgumentException( 'An approved refund gives back more than nothing; a refund of nothing is no refund.' );
		}

		self::requireFits( $provider, self::PROVIDER_LENGTH, 'provider id' );
		self::requireFits( $providerObjectId, self::REFERENCE_LENGTH, 'provider object id' );
		self::requireFits( $providerIntentId, self::REFERENCE_LENGTH, 'provider intent id' );
		self::requireFits( $errorCode, self::ERROR_CODE_LENGTH, 'error code' );
	}

	/**
	 * Refuses a reference the ledger cannot hold.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When it is empty or too long.
	 *
	 * @param string|null $value  The reference, or null for none.
	 * @param int         $length The longest it may be.
	 * @param string      $what   What it is, for the message.
	 */
	private static function requireFits( ?string $value, int $length, string $what ): void {
		if ( null !== $value && ( '' === $value || strlen( $value ) > $length ) ) {
			throw new \InvalidArgumentException( sprintf( 'A gateway result\'s %1$s is 1 to %2$d characters, or null.', $what, $length ) );
		}
	}
}
