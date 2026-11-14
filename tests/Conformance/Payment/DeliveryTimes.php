<?php
/**
 * DeliveryTimes: what in a webhook delivery's headers reads as a time
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Finds a time in a webhook delivery's headers, which a test that declares its deliveries carry no signed time must not have.
 *
 * Owns one fact: what in a header's value is a time. A provider that signs the time of a delivery
 * puts it in a header, so a gateway can refuse a delivery too old or too far ahead; a test that
 * declares its provider signs none (deliveriesCarryNoSignedTime()) is refused when a header holds
 * one of these:
 *
 * - a `t=<digits>` part, as a signature header such as `t=1780315200,v1=…` carries one;
 * - a Unix time, in seconds or milliseconds, within TEN_YEARS of the instant the delivery was
 *   signed: a run of 9 to 13 digits standing as a word of its own, so digits inside a digest or
 *   an id are not one;
 * - an RFC 3339 date, `2026-06-01` with a time after it or none;
 * - an HTTP date, in any of the three forms HTTP takes (`Mon, 01 Jun 2026 12:00:00 GMT`,
 *   `Monday, 01-Jun-26 12:00:00 GMT`, `Mon Jun  1 12:00:00 2026`).
 *
 * @since 0.2.0
 */
final class DeliveryTimes {

	/**
	 * How far from the instant a delivery was signed a Unix time is read as its time, in seconds: ten years of 365.25 days.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TEN_YEARS = 315576000;

	/**
	 * The times a header may hold that are written as words, by what each is.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const WRITTEN = array(
		'a `t=<digits>` part' => '/(?<![A-Za-z0-9_])t=\d/i',
		'an RFC 3339 date'    => '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/',
		'an HTTP date'        => '/(?<![A-Za-z])(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+(?:\d{1,2}[\s\-]+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[\s\-]+\d{2,4}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2})\s+\d{1,2}:\d{2}/i',
	);

	/**
	 * Cannot be called: the class is used through its static function only.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {
	}

	/**
	 * Returns the first header of a delivery that holds a time, and what it holds.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers  The delivery's headers.
	 * @param \DateTimeImmutable    $signedAt When it was signed.
	 * @return array{string, string}|null The header's name and what it holds, such as "a Unix time"; null when none holds a time.
	 */
	public static function heldIn( array $headers, \DateTimeImmutable $signedAt ): ?array {
		foreach ( $headers as $name => $value ) {
			$held = self::timeIn( (string) $value, $signedAt->getTimestamp() );

			if ( null !== $held ) {
				return array( strtolower( (string) $name ), $held );
			}
		}

		return null;
	}

	/**
	 * Says which time a header's value holds.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value    The value.
	 * @param int    $signedAt When the delivery was signed, as a Unix time.
	 * @return string|null What it holds; null when it holds no time.
	 */
	private static function timeIn( string $value, int $signedAt ): ?string {
		foreach ( self::WRITTEN as $what => $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $what;
			}
		}

		preg_match_all( '/(?<![A-Za-z0-9])\d{9,13}(?![A-Za-z0-9])/', $value, $runs );

		foreach ( $runs[0] as $run ) {
			foreach ( array( (int) $run, intdiv( (int) $run, 1000 ) ) as $seconds ) {
				if ( abs( $seconds - $signedAt ) <= self::TEN_YEARS ) {
					return 'a Unix time within ten years of when it was signed';
				}
			}
		}

		return null;
	}
}
