<?php
/**
 * OfflinePaymentEvidence: what a person states of a payment received outside any provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The day a payment received offline arrived, with the reference it came with and a note, as the person who records it states them.
 *
 * Owns one fact: what a statement of a payment received offline holds. The day is a calendar day,
 * written YYYY-MM-DD; a reference and a note are optional, never empty, and no longer than the
 * table that keeps them holds. Whether the day is between the day the order was placed and the
 * store's today, and whether a reference or a note holds a card number, the payment service
 * decides before the payment is recorded. The names and lengths are the capture operation's
 * inputs (PaymentOperations::capturePayment()), which a unit test holds equal to these.
 *
 * @since 0.2.0
 */
final readonly class OfflinePaymentEvidence {

	/**
	 * The name of the day the money arrived, as the capture operation takes it and the table keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIVED_ON = 'received_on';

	/**
	 * The name of the reference the money came with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFERENCE = 'reference';

	/**
	 * The name of the note.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOTE = 'note';

	/**
	 * How the day a payment arrived is written.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DAY_FORMAT = 'Y-m-d';

	/**
	 * The length of a day written YYYY-MM-DD.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DAY_LENGTH = 10;

	/**
	 * The longest reference, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const REFERENCE_MAX_LENGTH = 191;

	/**
	 * The longest note, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const NOTE_MAX_LENGTH = 500;

	/**
	 * Records the statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the day is not a calendar day written YYYY-MM-DD, or a reference or a note is
	 *                                   empty or longer than the table keeps.
	 *
	 * @param string      $receivedOn The day the money arrived, YYYY-MM-DD.
	 * @param string|null $reference  Optional. The reference it came with, such as a cheque's number. Default null, none.
	 * @param string|null $note       Optional. What the person writes about it. Default null, nothing.
	 */
	public function __construct(
		public string $receivedOn,
		public ?string $reference = null,
		public ?string $note = null
	) {
		$invalid = self::invalidField( $receivedOn, $reference, $note );

		if ( null !== $invalid ) {
			throw new \InvalidArgumentException( sprintf( 'A payment received offline is recorded with the day it arrived, a calendar day written YYYY-MM-DD, and no reference or one of 1 to %1$d characters, and no note or one of 1 to %2$d characters; %3$s is not.', self::REFERENCE_MAX_LENGTH, self::NOTE_MAX_LENGTH, $invalid ) );
		}
	}

	/**
	 * Says which part of a statement cannot be recorded as it is: a day that is not a calendar day written YYYY-MM-DD, or an empty or too long reference or note.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $receivedOn The day the money arrived.
	 * @param string|null $reference  The reference, or null.
	 * @param string|null $note       The note, or null.
	 * @return string|null The field's name, RECEIVED_ON, REFERENCE or NOTE; null when each can be recorded.
	 */
	public static function invalidField( string $receivedOn, ?string $reference, ?string $note ): ?string {
		if ( ! self::isDay( $receivedOn ) ) {
			return self::RECEIVED_ON;
		}

		if ( null !== $reference && ( '' === $reference || mb_strlen( $reference ) > self::REFERENCE_MAX_LENGTH ) ) {
			return self::REFERENCE;
		}

		if ( null !== $note && ( '' === $note || mb_strlen( $note ) > self::NOTE_MAX_LENGTH ) ) {
			return self::NOTE;
		}

		return null;
	}

	/**
	 * Tells whether a text is a calendar day written YYYY-MM-DD: a day that exists, such as 2024-02-29, and not 2025-02-29 or 2025-2-1.
	 *
	 * @since 0.2.0
	 *
	 * @param string $day The text.
	 * @return bool True when it is.
	 */
	public static function isDay( string $day ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::DAY_FORMAT, $day, new \DateTimeZone( 'UTC' ) );

		return false !== $parsed && $parsed->format( self::DAY_FORMAT ) === $day;
	}

	/**
	 * Tells whether the money arrived after today, today being the calendar day of an instant in the store's time zone.
	 *
	 * @since 0.2.0
	 *
	 * @param \DateTimeImmutable $now      The instant whose day, in the store's time zone, the day the money arrived is compared with.
	 * @param \DateTimeZone      $timeZone The store's time zone, in which the day is read.
	 * @return bool True when the day the money arrived is later than the store's today.
	 */
	public function isAfter( \DateTimeImmutable $now, \DateTimeZone $timeZone ): bool {
		return strcmp( $this->receivedOn, $now->setTimezone( $timeZone )->format( self::DAY_FORMAT ) ) > 0;
	}

	/**
	 * Tells whether the money arrived before the day the order was placed, that day being the calendar day of an instant in the store's time zone.
	 *
	 * @since 0.2.0
	 *
	 * @param \DateTimeImmutable $placed   The instant the payment was created, whose day, in the store's time zone, is the first the money can have arrived on.
	 * @param \DateTimeZone      $timeZone The store's time zone, in which the day is read.
	 * @return bool True when the day the money arrived is earlier than the day the order was placed.
	 */
	public function isBefore( \DateTimeImmutable $placed, \DateTimeZone $timeZone ): bool {
		return strcmp( $this->receivedOn, $placed->setTimezone( $timeZone )->format( self::DAY_FORMAT ) ) < 0;
	}
}
