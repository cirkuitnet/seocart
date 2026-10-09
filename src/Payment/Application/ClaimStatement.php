<?php
/**
 * ClaimStatement: what a person states about a refund the gateway cannot account for
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Platform\Logging\CardNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * A person's statement about a claimed refund: that the provider made it, naming the provider's refund and the amount it gave back, or that it did not; and why they say so.
 *
 * Owns one fact: what a statement that can settle a claim says. It decides nothing by itself:
 * the gateway is asked once more first, and the statement decides only when the gateway cannot
 * say. The provider's refund it names becomes a ledger row's provider object, the key the ledger
 * finds the provider's refund by: it is named as a provider names its objects, in printable ASCII
 * with no space, which the ledger's column keeps as it is, and it is refused when it holds what
 * reads as a card number, as the note is.
 *
 * @since 0.2.0
 */
final readonly class ClaimStatement {

	/**
	 * The statement that the provider made the refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUNDED = 'refunded';

	/**
	 * The statement that the provider never made the refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_REFUNDED = 'not_refunded';

	/**
	 * What a statement is that says no why, or says the refund was made without naming the provider's refund, in printable ASCII with no space, and the amount.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * What a statement is that says the refund was not made and names a provider's refund or an amount all the same.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRADICTORY = 'contradictory';

	/**
	 * What a statement is whose note is longer than a claim keeps: PaymentOperations::NOTE_MAX_LENGTH characters.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOTE_TOO_LONG = 'note_too_long';

	/**
	 * What a statement is whose provider's refund holds what reads as a card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARD_NUMBER = 'card_number';

	/**
	 * What a statement is whose provider's refund the ledger holds already: another refund's, recorded under the key this one would be.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALREADY_RECORDED = 'already_recorded';

	/**
	 * Records the statement.
	 *
	 * @since 0.2.0
	 *
	 * @param bool        $refunded         Whether the person states the provider made the refund.
	 * @param string      $note             Why they say so.
	 * @param string|null $providerRefundId Optional. The provider's refund they name, with a statement that it was made. Default null.
	 * @param int|null    $amountMinor      Optional. What it gave back, in minor units of the order's currency, with a statement that it was made. Default null.
	 */
	public function __construct(
		public bool $refunded,
		public string $note,
		public ?string $providerRefundId = null,
		public ?int $amountMinor = null
	) {
	}

	/**
	 * Returns the statement's word, as the claim records it.
	 *
	 * @since 0.2.0
	 *
	 * @return string REFUNDED or NOT_REFUNDED.
	 */
	public function word(): string {
		return $this->refunded ? self::REFUNDED : self::NOT_REFUNDED;
	}

	/**
	 * Tells what keeps the statement from settling a claim, if anything.
	 *
	 * A statement says why, in at most PaymentOperations::NOTE_MAX_LENGTH characters, whoever
	 * makes it: the operation's schema holds its callers to that, and this holds any other. One
	 * that the refund was made names the provider's refund, in printable ASCII with no space, and
	 * an amount of more than nothing; one that it was not names neither. The provider's refund
	 * holds no card number. The note's card number is the refund service's to refuse.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null NOTE_TOO_LONG, INCOMPLETE, CONTRADICTORY, CARD_NUMBER, or null for a statement that can settle a claim.
	 */
	public function problem(): ?string {
		if ( mb_strlen( $this->note ) > PaymentOperations::NOTE_MAX_LENGTH ) {
			return self::NOTE_TOO_LONG;
		}

		$named  = null !== $this->providerRefundId && '' !== $this->providerRefundId;
		$amount = null !== $this->amountMinor;

		if ( '' === trim( $this->note ) || ( $this->refunded && ( ! self::namesProviderObject( $this->providerRefundId ) || ! $amount || $this->amountMinor < 1 ) ) ) {
			return self::INCOMPLETE;
		}

		if ( ! $this->refunded && ( $named || $amount ) ) {
			return self::CONTRADICTORY;
		}

		return $named && CardNumbers::contains( (string) $this->providerRefundId ) ? self::CARD_NUMBER : null;
	}

	/**
	 * Tells whether a provider's refund is named as a provider names its objects: one or more printable ASCII characters, with no space.
	 *
	 * A blank one would reach the ledger as no provider object, the ledger's NULLIF taking it for
	 * none, and a character outside ASCII would be replaced by the ledger's ASCII column: either
	 * way the ledger could never find the provider's refund by its key.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $providerRefundId The provider's refund, as the person named it; null for none.
	 * @return bool True for printable ASCII with no space.
	 */
	private static function namesProviderObject( ?string $providerRefundId ): bool {
		return null !== $providerRefundId && 1 === preg_match( '/\A[\x21-\x7E]+\z/', $providerRefundId );
	}
}
