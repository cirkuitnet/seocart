<?php
/**
 * ContractVersion: whether code that implements a contract supports an extension written against a version of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The rule every public contract's version is compared by.
 *
 * This class owns one fact: when SEOCart, implementing a contract at one version, supports an
 * extension written against another. A version is `major.minor` or `major.minor.patch`; the
 * patch never matters. Before 1.0 the major and minor versions must be equal, since a minor
 * version may break a contract; from 1.0 the major versions must be equal and the extension's
 * minor no later than SEOCart's. Anything that is no version is never supported. The payment
 * gateway registry and the checkout field registry both answer `supportsContract()` here, each
 * with its own contract's version.
 *
 * @since 0.2.0
 */
final class ContractVersion {

	/**
	 * What a version is: its major and minor numbers, and an optional patch.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^(\d+)\.(\d+)(?:\.\d+)?\z/';

	/**
	 * Cannot be called: the class is one static rule.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Tells whether code implementing a contract at one version supports an extension written against another.
	 *
	 * @since 0.2.0
	 *
	 * @param string $implemented    The version the code implements, such as a contract's CONTRACT_VERSION.
	 * @param string $writtenAgainst The version the extension was written against.
	 * @return bool True when the versions agree: the same major and minor before 1.0; from 1.0, the
	 *              same major and a minor no later than the implemented one.
	 */
	public static function supports( string $implemented, string $writtenAgainst ): bool {
		$ours   = self::majorMinor( $implemented );
		$theirs = self::majorMinor( $writtenAgainst );

		if ( null === $theirs || null === $ours || $theirs[0] !== $ours[0] ) {
			return false;
		}

		return 0 === $ours[0] ? $theirs[1] === $ours[1] : $theirs[1] <= $ours[1];
	}

	/**
	 * Reads a version's major and minor numbers.
	 *
	 * @since 0.2.0
	 *
	 * @param string $version `major.minor` or `major.minor.patch`.
	 * @return array{0: int, 1: int}|null The two numbers; null when the text is no version.
	 */
	private static function majorMinor( string $version ): ?array {
		if ( 1 !== preg_match( self::PATTERN, $version, $parts ) ) {
			return null;
		}

		return array( (int) $parts[1], (int) $parts[2] );
	}
}
