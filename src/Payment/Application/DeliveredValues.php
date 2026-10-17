<?php
/**
 * DeliveredValues: what of a provider's answers and deliveries the store keeps and prints
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Payment\Domain\Webhook\WebhookReceipts;
use SEOCart\Platform\Logging\BodyDigest;
use SEOCart\Platform\Logging\CardNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Canonicalises every value a provider supplies, its answers to the store's calls and its signed deliveries alike, so the same provider value is kept the same way whichever way it arrives; and prints what is kept without a card number.
 *
 * Owns one fact: the shapes the store keeps a provider's values in. The lengths are the columns'
 * own: a receipt's, as WebhookReceipts declares them, and a result's references, which
 * GatewayResult already holds to the ledger's.
 *
 * - **An identifier** (a provider object, the provider's reference to an intent, an event's id)
 *   is kept exactly as the provider gave it when it is letters, digits, `_`, `.`, `:` and `-`, a
 *   letter or digit first: its characters and its length are checked, never what it looks like.
 *   Digits alone are an identifier, as a provider such as Authorize.Net numbers its transactions,
 *   whether or not they would pass for a card number.
 * - **One that is not** is kept as a stand-in, `~sha256:` and the hex digest of what was sent:
 *   stable, so every answer and delivery naming it meets the same row and the same receipt.
 * - **A synthetic identity** begins with SYNTHETIC, `~`, which an identifier never does: the
 *   stand-in, and the ledger identity `~event:{mode}:{event id}` of a delivered result that names
 *   no provider object. A provider's value is never one, so it can never meet one.
 * - **A type** (the event's type, why an event is ignored) is letters, underscores and dots, a
 *   letter first, at most WebhookReceipts::EVENT_TYPE_LENGTH bytes; anything else is OTHER.
 * - **A uuid the provider echoes** (the store's intent, the refund) is a uuid, or none.
 *
 * What is kept is printed through printable(): in a log line or doctor's output a run that reads
 * as a card number is replaced by the scrubber's marker, and anything not of a kept shape is
 * withheld. The kept value itself is never altered for being printed.
 *
 * Every answer of a gateway passes through canonical() once, as Gateways hands each gateway out
 * wrapped in CanonicalGateway; every delivery through reading() once, in the webhook receiver.
 *
 * @since 0.2.0
 */
final class DeliveredValues {

	/**
	 * What a type that is not of a provider's event name's shape is kept and printed as.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OTHER = 'other';

	/**
	 * The mark every synthetic identity begins with, which no identifier contains.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SYNTHETIC = '~';

	/**
	 * The prefix of the stand-in of a value that is not an identifier.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STAND_IN = self::SYNTHETIC . 'sha256:';

	/**
	 * The prefix of the ledger identity of a delivered result that names no provider object.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EVENT_PREFIX = self::SYNTHETIC . 'event:';

	/**
	 * What printable() prints for a value of no kept shape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WITHHELD = '(withheld)';

	/**
	 * A type's shape: letters and underscores in dotted words, a letter first, no digit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TYPE = '/^[A-Za-z][A-Za-z_]*(?:\.[A-Za-z_]+)*\z/';

	/**
	 * An identifier's characters, a letter or digit first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9_.:-]*\z/';

	/**
	 * A kept value's characters, as printable() checks them: an identifier, or a synthetic identity.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEPT = '/^~?[A-Za-z0-9][A-Za-z0-9_.:~-]*\z/';

	/**
	 * A uuid, any version.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

	/**
	 * The shape of a stand-in the store makes: the mark, `sha256:` and a lower-case hex digest.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MADE_STAND_IN = '/^' . self::STAND_IN . '[0-9a-f]{64}\z/';

	/**
	 * Returns a gateway's answer with its references kept as canonical forms: what every path the store asks a gateway by applies.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The answer, as the gateway built it.
	 * @return GatewayResult The answer the store applies.
	 */
	public static function canonical( GatewayResult $result ): GatewayResult {
		return new GatewayResult(
			$result->provider,
			$result->operation,
			$result->outcome,
			$result->intentUuid,
			$result->amount,
			self::reference( $result->providerObjectId ),
			self::reference( $result->providerIntentId ),
			$result->errorCode,
			$result->settlement,
			$result->nextAction
		);
	}

	/**
	 * Returns a verified reading with every value the provider supplied canonical, and a result that names no provider object given its event's identity.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When a result reading carries no result.
	 *
	 * @param WebhookReading $reading What the gateway read: ignored, or a result.
	 * @param Mode           $mode    The mode of the delivery's address.
	 * @return WebhookReading The reading the store keeps.
	 */
	public static function reading( WebhookReading $reading, Mode $mode ): WebhookReading {
		$eventId   = self::eventId( (string) $reading->eventId );
		$eventType = self::type( (string) $reading->eventType );

		if ( WebhookReadingKind::Ignored === $reading->kind ) {
			$reason = WebhookReading::DISPUTE === $reading->reason ? WebhookReading::DISPUTE : self::type( (string) $reading->reason );

			return WebhookReading::ignored( $eventId, $eventType, $reason, $reading->occurredAt, self::uuid( $reading->intentUuid ), self::reference( $reading->providerIntentId ) );
		}

		$result = self::canonical( $reading->result ?? throw new \UnexpectedValueException( 'A result reading carries its result.' ) );
		$result = new GatewayResult( $result->provider, $result->operation, $result->outcome, self::uuid( $result->intentUuid ) ?? '', $result->amount, $result->providerObjectId ?? self::identity( $mode, $eventId ), $result->providerIntentId, $result->errorCode, $result->settlement, $result->nextAction );

		return WebhookReading::result( $eventId, $eventType, $reading->occurredAt, $result, self::uuid( $reading->refundUuid ) );
	}

	/**
	 * Returns an event's type as the store keeps it: itself when it has the shape of a provider's event name, OTHER otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The type the provider sent.
	 * @return string The type kept.
	 */
	public static function type( string $type ): string {
		return strlen( $type ) <= WebhookReceipts::EVENT_TYPE_LENGTH && 1 === preg_match( self::TYPE, $type ) ? $type : self::OTHER;
	}

	/**
	 * Returns an event's id as the store keeps it: itself when it is an identifier a receipt holds, or else its stand-in.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The id the provider sent.
	 * @return string The id kept.
	 */
	public static function eventId( string $eventId ): string {
		return ( strlen( $eventId ) <= WebhookReceipts::EVENT_ID_LENGTH ? self::identifier( $eventId ) : null ) ?? self::standIn( $eventId );
	}

	/**
	 * Returns a provider's reference as the store keeps it: itself when it is an identifier, or else its stand-in; null for none.
	 *
	 * GatewayResult already holds a reference to the ledger's length, which the stand-in is within.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The reference the provider gave, or null.
	 * @return string|null The reference kept, or null.
	 */
	public static function reference( ?string $value ): ?string {
		return null === $value ? null : ( self::identifier( $value ) ?? self::standIn( $value ) );
	}

	/**
	 * Returns a provider's value when it has an identifier's characters, never the synthetic mark; null otherwise.
	 *
	 * What the value looks like beyond its characters, such as a run of digits that would pass for a
	 * card number, does not matter: printable() keeps that out of what is printed.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The value the provider sent, or null.
	 * @return string|null The identifier, or null.
	 */
	public static function identifier( ?string $value ): ?string {
		if ( null === $value || str_starts_with( $value, self::SYNTHETIC ) ) {
			return null;
		}

		return 1 === preg_match( self::IDENTIFIER, $value ) ? $value : null;
	}

	/**
	 * Returns a kept value when it is a stand-in the store made; null for any other value, a provider's that begins with the mark among them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value as kept.
	 * @return string|null The stand-in, or null.
	 */
	public static function madeStandIn( string $value ): ?string {
		return 1 === preg_match( self::MADE_STAND_IN, $value ) ? $value : null;
	}

	/**
	 * Returns a kept reference when it is one the store made in place of a provider's: a stand-in, or an event's identity, the prefix, a mode and an event id as kept, no longer than a receipt's event id; null for any other value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The reference as kept.
	 * @return string|null The reference, or null.
	 */
	public static function madeReference( string $value ): ?string {
		if ( ! str_starts_with( $value, self::EVENT_PREFIX ) || strlen( $value ) > WebhookReceipts::EVENT_ID_LENGTH ) {
			return self::madeStandIn( $value );
		}

		[ $mode, $eventId ] = array_pad( explode( ':', substr( $value, strlen( self::EVENT_PREFIX ) ), 2 ), 2, '' );

		return null !== Mode::tryFrom( $mode ) && null !== ( self::identifier( $eventId ) ?? self::madeStandIn( $eventId ) ) ? $value : null;
	}

	/**
	 * Returns a uuid the provider echoed, lower-cased, or null for anything that is not one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value The value, or null.
	 * @return string|null The uuid, or null.
	 */
	public static function uuid( ?string $value ): ?string {
		return null !== $value && 1 === preg_match( self::UUID, $value ) ? strtolower( $value ) : null;
	}

	/**
	 * Returns the ledger identity of a delivered result that names no provider object: derived from its event, stable for every delivery of it, no longer than a receipt's event id, and synthetic.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode   $mode    The mode of the delivery's address.
	 * @param string $eventId The event's id, as kept.
	 * @return string The identity.
	 */
	public static function identity( Mode $mode, string $eventId ): string {
		$identity = self::EVENT_PREFIX . $mode->value . ':' . $eventId;

		return strlen( $identity ) <= WebhookReceipts::EVENT_ID_LENGTH ? $identity : self::EVENT_PREFIX . $mode->value . ':' . self::standIn( $eventId );
	}

	/**
	 * Returns a kept value as a log line or doctor prints it: a run that reads as a card number replaced by the scrubber's marker, and a value of no kept shape withheld.
	 *
	 * A stand-in the store made is printed with its digest in groups joined by colons (BodyDigest), since
	 * about 1 digest in 232 written as one run holds digits the scrubber would remove. The value kept
	 * stays plain, and a provider's value, which is never a stand-in, is scrubbed as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kept The value as the store keeps it.
	 * @return string What is printed.
	 */
	public static function printable( string $kept ): string {
		if ( null !== self::madeStandIn( $kept ) ) {
			return self::STAND_IN . BodyDigest::grouped( substr( $kept, strlen( self::STAND_IN ) ) );
		}

		return 1 === preg_match( self::KEPT, $kept ) ? CardNumbers::scrub( $kept ) : self::WITHHELD;
	}

	/**
	 * Returns the stand-in of a value that is not an identifier: the synthetic mark, `sha256:` and its hex digest.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string The stand-in, 72 characters.
	 */
	private static function standIn( string $value ): string {
		return self::STAND_IN . hash( 'sha256', $value );
	}
}
