<?php
/**
 * BodyDigest: the form a message body's hash takes in a log line
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * A body's SHA-256 written so the log's card-number filter can never take part of it for a card number.
 *
 * Owns one fact: how a hash of a body is written in a log line. The digest is in hexadecimal, in groups
 * of GROUP_LENGTH joined by colons, such as `9f86d081:884c7d65:…:b0f00a08`. It identifies a body, so
 * someone can match it with the sender's own record, and it holds nothing of the body. A digest the
 * store made earlier, such as a stand-in kept in place of a value, is written the same way by grouped().
 *
 * Not one run of 64 digits. Measured over 100 000 hashes of short bodies, about 1 in 23 holds 13 to
 * 19 decimal digits in a row and about 1 in 232 passes the Luhn check as well, so the filter would
 * remove part of such a hash from the log. A colon ends a run of digits for the filter, and a group
 * of eight is shorter than any card number (CardNumbers::MIN_DIGITS), so none of these digests is
 * card-shaped: that is a property of the form, not a probability. The same hash kept as one run of
 * hexadecimal in a database column, which is never printed, stays plain.
 *
 * Pure: no I/O and no WordPress function.
 *
 * @since 0.2.0
 */
final class BodyDigest {

	/**
	 * How many hexadecimal digits stand between two colons: fewer than CardNumbers::MIN_DIGITS.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const GROUP_LENGTH = 8;

	/**
	 * Returns a body's hash as it is written in a log line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body, byte for byte.
	 * @return string The SHA-256 in lower-case hexadecimal, in groups of GROUP_LENGTH joined by colons.
	 */
	public static function of( string $body ): string {
		return self::grouped( hash( 'sha256', $body ) );
	}

	/**
	 * Writes a SHA-256 already made, in lower-case hexadecimal, as it is written in a log line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digest The 64 hexadecimal digits.
	 * @return string The digest in groups of GROUP_LENGTH joined by colons.
	 */
	public static function grouped( string $digest ): string {
		return implode( ':', str_split( $digest, self::GROUP_LENGTH ) );
	}
}
