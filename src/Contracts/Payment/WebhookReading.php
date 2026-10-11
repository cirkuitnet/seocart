<?php
/**
 * WebhookReading: what a gateway made of a webhook delivery
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * A gateway's reading of one delivery: rejected, ignored, or a payment's result.
 *
 * Owns one fact: what a delivery says, once verified. A rejected reading carries only why; the
 * plugin keeps nothing else of the delivery. An ignored or a result reading carries the
 * provider's event id, by which the plugin applies a delivery once however often it arrives, and
 * its type. A result carries the payment's GatewayResult and, for a refund the plugin asked for,
 * the refund's uuid as the provider echoed it back. An ignored reading may name the payment it is
 * about, as a dispute does, so the plugin can say which order it concerns. The time the provider
 * says the event happened is information only: the window a delivery must arrive in is checked by
 * the gateway, on the delivery's signed timestamp, before it reads anything.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class WebhookReading {

	/**
	 * Why a delivery is rejected: its signature does not verify.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BAD_SIGNATURE = 'bad_signature';

	/**
	 * Why a delivery is rejected: its signed timestamp is outside the window.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STALE = 'stale';

	/**
	 * Why a delivery is rejected: it verifies, but cannot be read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MALFORMED = 'malformed';

	/**
	 * Why a genuine delivery is ignored: it reports a dispute, which the plugin records and reports but never acts on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DISPUTE = 'dispute';

	/**
	 * Records the reading. Use rejected(), ignored() or result().
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the fields do not fit the kind: a rejected reading without a reason or with
	 *                                   an event, another without an event id or type, a result without its result,
	 *                                   a refund uuid on anything but a refund's result, or the payment it is about
	 *                                   named on anything but an ignored reading.
	 *
	 * @param WebhookReadingKind      $kind       What the delivery is.
	 * @param string|null             $eventId    The provider's id of the event; null only for a rejected delivery.
	 * @param string|null             $eventType  The provider's type of the event, such as `payment_intent.succeeded`.
	 * @param \DateTimeImmutable|null $occurredAt When the provider says the event happened; information only.
	 * @param GatewayResult|null      $result     The payment's result, for a result reading.
	 * @param string|null             $reason     Why a delivery was rejected or ignored.
	 * @param string|null             $refundUuid The uuid of the refund the plugin asked for, as the provider echoed it; null otherwise.
	 * @param string|null             $intentUuid Optional. For an ignored reading, the plugin's intent it is about, when the
	 *                                            provider echoed it. Default null.
	 * @param string|null             $providerIntentId Optional. For an ignored reading, the provider's own reference to
	 *                                                  the intent it is about. Default null.
	 */
	public function __construct(
		public WebhookReadingKind $kind,
		public ?string $eventId,
		public ?string $eventType,
		public ?\DateTimeImmutable $occurredAt,
		public ?GatewayResult $result,
		public ?string $reason,
		public ?string $refundUuid,
		public ?string $intentUuid = null,
		public ?string $providerIntentId = null
	) {
		$rejected = WebhookReadingKind::Rejected === $kind;

		if ( ( null === $eventId ) !== $rejected || ( $rejected && ( null === $reason || null !== $eventType || null !== $result ) ) ) {
			throw new \InvalidArgumentException( 'A rejected reading carries its reason and nothing of the delivery; any other carries the event\'s id.' );
		}

		if ( ! $rejected && ( '' === (string) $eventId || '' === (string) $eventType ) ) {
			throw new \InvalidArgumentException( 'A reading of a genuine delivery carries the event\'s id and type.' );
		}

		if ( ( WebhookReadingKind::Result === $kind ) !== ( null !== $result ) ) {
			throw new \InvalidArgumentException( 'A result reading, and only one, carries the payment\'s result.' );
		}

		if ( null !== $refundUuid && Operation::Refund !== $result?->operation ) {
			throw new \InvalidArgumentException( 'Only a refund\'s result carries a refund uuid.' );
		}

		if ( ( null !== $intentUuid || null !== $providerIntentId ) && WebhookReadingKind::Ignored !== $kind ) {
			throw new \InvalidArgumentException( 'Only an ignored reading names its payment apart: a result names it in its result, and a rejected one names nothing.' );
		}
	}

	/**
	 * Reads a delivery that failed verification.
	 *
	 * @since 0.2.0
	 *
	 * @param string $reason BAD_SIGNATURE, STALE or MALFORMED.
	 * @return self The reading.
	 */
	public static function rejected( string $reason ): self {
		return new self( WebhookReadingKind::Rejected, null, null, null, null, $reason, null );
	}

	/**
	 * Reads a genuine delivery the plugin does not act on.
	 *
	 * @since 0.2.0
	 *
	 * @param string                  $eventId    The provider's id of the event.
	 * @param string                  $eventType  The provider's type of the event.
	 * @param string                  $reason           Why it is not acted on, such as DISPUTE.
	 * @param \DateTimeImmutable|null $occurredAt       Optional. When the provider says it happened. Default null.
	 * @param string|null             $intentUuid       Optional. The plugin's intent it is about, when the provider echoed
	 *                                                  it. Default null.
	 * @param string|null             $providerIntentId Optional. The provider's own reference to the intent it is about.
	 *                                                  Default null.
	 * @return self The reading.
	 */
	public static function ignored( string $eventId, string $eventType, string $reason, ?\DateTimeImmutable $occurredAt = null, ?string $intentUuid = null, ?string $providerIntentId = null ): self {
		return new self( WebhookReadingKind::Ignored, $eventId, $eventType, $occurredAt, null, $reason, null, $intentUuid, $providerIntentId );
	}

	/**
	 * Reads a genuine delivery that reports a payment's result.
	 *
	 * @since 0.2.0
	 *
	 * @param string                  $eventId    The provider's id of the event.
	 * @param string                  $eventType  The provider's type of the event.
	 * @param \DateTimeImmutable|null $occurredAt When the provider says it happened.
	 * @param GatewayResult           $result     The result.
	 * @param string|null             $refundUuid Optional. For a refund, the uuid the plugin asked for it with, as the provider
	 *                                            echoed it; null for a refund the plugin did not ask for. Default null.
	 * @return self The reading.
	 */
	public static function result( string $eventId, string $eventType, ?\DateTimeImmutable $occurredAt, GatewayResult $result, ?string $refundUuid = null ): self {
		return new self( WebhookReadingKind::Result, $eventId, $eventType, $occurredAt, $result, null, $refundUuid );
	}
}
