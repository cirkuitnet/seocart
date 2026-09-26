<?php
/**
 * LineIdentity: what makes two cart lines the same line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A refused identity is a caller's programming error, reported to the developer, never HTML; this class may not call WordPress.

/**
 * The identity of a cart line: a SHA-256 digest, written as 64 lowercase hexadecimal characters.
 *
 * Owns one fact: when two lines are the same line. A cart holds one line per identity, and
 * adding a line whose identity the cart already holds adds to that line's quantity. The identity
 * is the digest of a canonical text built from what the shopper chose:
 *
 *     variant:<variant id>
 *     addons:<the add-on choices, sorted, as a JSON list>     only when there are any
 *     personalization:<the personalization hash>             only when there is one
 *
 * joined by line feeds. So lines of the same variant merge only when their add-on choices and
 * their personalization are the same too, and the choices are compared as a set, whatever order
 * they were sent in. A plain line, with neither, is `hash( 'sha256', 'variant:' . $variant_id )`,
 * which stays its identity when add-ons and personalization are built: they only add text.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class LineIdentity {

	/**
	 * The one form of an identity: 64 lowercase hexadecimal characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^[0-9a-f]{64}\z/';

	/**
	 * The digest.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Keeps a digest. Use of() or fromString().
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The digest, already checked.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Returns the identity of a line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the variant id is below 1, an add-on choice is empty,
	 *                                   or the personalization hash is not a SHA-256 digest.
	 *
	 * @param int      $variantId           The variant.
	 * @param string[] $addons              Optional. The add-on choices, in any order. Default none.
	 * @param string   $personalizationHash Optional. The digest of the personalization values, 64
	 *                                      lowercase hexadecimal characters. Default none.
	 * @return self The identity.
	 *
	 * @phpstan-param list<string> $addons
	 */
	public static function of( int $variantId, array $addons = array(), string $personalizationHash = '' ): self {
		if ( $variantId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A line\'s variant id is 1 or more; %d was given.', $variantId ) );
		}

		$text = 'variant:' . $variantId;

		if ( array() !== $addons ) {
			$text .= "\naddons:" . self::canonicalAddons( $addons );
		}

		if ( '' !== $personalizationHash ) {
			if ( 1 !== preg_match( self::PATTERN, $personalizationHash ) ) {
				throw new \InvalidArgumentException( 'A personalization hash is a SHA-256 digest: 64 lowercase hexadecimal characters.' );
			}

			$text .= "\npersonalization:" . $personalizationHash;
		}

		return new self( hash( 'sha256', $text ) );
	}

	/**
	 * Reads an identity a client sent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The text the client sent.
	 * @return self|null The identity, or null when the text is not one.
	 */
	public static function fromString( string $value ): ?self {
		return 1 === preg_match( self::PATTERN, $value ) ? new self( $value ) : null;
	}

	/**
	 * Returns the digest, as the `line_identity` column and the Store API carry it.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Tells whether two identities are the same.
	 *
	 * @since 0.1.0
	 *
	 * @param self $other The other identity.
	 * @return bool True when they are equal.
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}

	/**
	 * Writes the add-on choices in their canonical form: sorted, then encoded as a JSON list.
	 *
	 * JSON keeps choices apart whatever characters they hold, so no two different sets of choices
	 * are written the same.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a choice is empty.
	 *
	 * @param string[] $addons The choices.
	 * @return string The canonical form.
	 *
	 * @phpstan-param list<string> $addons
	 */
	private static function canonicalAddons( array $addons ): string {
		foreach ( $addons as $addon ) {
			if ( '' === $addon ) {
				throw new \InvalidArgumentException( 'An add-on choice cannot be empty.' );
			}
		}

		sort( $addons, SORT_STRING );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain code calls no WordPress function, and must throw on text that is not UTF-8 rather than hash a false.
		return (string) json_encode( $addons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	}
}
