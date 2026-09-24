<?php
/**
 * Sku: the stock-keeping unit a merchant gives a variant
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A SKU, in the one form the store keeps it.
 *
 * Owns one fact: what a valid SKU is. Surrounding whitespace is trimmed and every run of inner
 * whitespace becomes one space; the result has 1 to 64 characters of valid UTF-8 and no control
 * or invisible formatting character. The case is kept as typed: uniqueness is the database's,
 * whose collation makes `abc` and `ABC` the same SKU, so nothing here lower-cases.
 *
 * @since 0.1.0
 */
final class Sku {

	/**
	 * The most characters a SKU may have: the width of `variants.sku`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 64;

	/**
	 * How many characters of a refused value its error shows.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SHOWN_LENGTH = 80;

	/**
	 * The SKU, normalized.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Creates a SKU from a normalized, checked value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Returns the SKU a string names, normalized.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::SkuInvalid when the normalized value is empty, longer
	 *                        than MAX_LENGTH characters, not valid UTF-8, or holds a control or
	 *                        formatting character.
	 *
	 * @param string $sku The SKU as given.
	 * @return self The SKU.
	 */
	public static function of( string $sku ): self {
		$normalized = self::normalize( $sku );

		if ( null === $normalized || '' === $normalized || self::length( $normalized ) > self::MAX_LENGTH || 1 === preg_match( '/[\p{Cc}\p{Cf}]/u', $normalized ) ) {
			CodedException::raise( CatalogError::SkuInvalid, array( 'sku' => self::shown( $normalized ) ) );
		}

		return new self( $normalized );
	}

	/**
	 * Returns the SKU as the store keeps it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The normalized value.
	 */
	public function toString(): string {
		return $this->value;
	}

	/**
	 * Tells whether two SKUs are written the same, case included.
	 *
	 * @since 0.1.0
	 *
	 * @param Sku $other The SKU to compare with.
	 * @return bool True when both hold the same string.
	 */
	public function equals( Sku $other ): bool {
		return $this->value === $other->value;
	}

	/**
	 * Trims a value and collapses its inner whitespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sku The value as given.
	 * @return string|null The normalized value, or null when it is not valid UTF-8.
	 */
	private static function normalize( string $sku ): ?string {
		$collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $sku );

		return null === $collapsed ? null : trim( $collapsed, ' ' );
	}

	/**
	 * Counts the characters of a valid UTF-8 string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value.
	 * @return int The number of characters.
	 */
	private static function length( string $value ): int {
		return (int) preg_match_all( '/./su', $value );
	}

	/**
	 * Returns what a refused value's error shows: its first characters, without control or formatting characters.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $normalized The normalized value, or null when it was not valid UTF-8.
	 * @return string The value to show; empty for one that was not valid UTF-8.
	 */
	private static function shown( ?string $normalized ): string {
		if ( null === $normalized ) {
			return '';
		}

		$visible = (string) preg_replace( '/[\p{Cc}\p{Cf}]/u', '', $normalized );

		preg_match( '/^.{0,' . self::SHOWN_LENGTH . '}/su', $visible, $start );

		return $start[0] ?? '';
	}
}
