<?php
/**
 * VariantSelection: the option value a variant stands for on each axis of its product
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A combination of option values: one value key per axis key.
 *
 * Owns one fact: what identifies a variant among its product's. The combination hash is SHA-256
 * of the pairs written `axis_key=value_key`, sorted by axis key and joined by a line feed, so it
 * depends on the keys alone: a label can change without moving any variant, and the order the
 * values were given in does not matter. The empty selection is the one combination of a product
 * without options, and its hash is SHA-256 of the empty string. A key is 1 to 32 lower-case
 * letters, digits, "-" or "_", the shape OptionAxis and OptionValue keep, so a pair can never
 * hide a separator.
 *
 * @since 0.2.0
 */
final class VariantSelection {

	/**
	 * The shape of an axis key and of a value key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KEY_PATTERN = '/^[a-z0-9_-]{1,32}\z/';

	/**
	 * The value key of each axis key, sorted by axis key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $pairs;

	/**
	 * Holds checked, sorted pairs.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $pairs The value key of each axis key, sorted by axis key.
	 */
	private function __construct( array $pairs ) {
		$this->pairs = $pairs;
	}

	/**
	 * Returns the selection of the given value keys.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::CombinationInvalid when a key is not 1 to 32 lower-case letters, digits, "-" or "_".
	 *
	 * @param array<string, string> $pairs The value key of each axis key, in any order.
	 * @return self The selection.
	 */
	public static function of( array $pairs ): self {
		$checked = array();

		foreach ( $pairs as $axis => $value ) {
			$axis = (string) $axis;

			if ( 1 !== preg_match( self::KEY_PATTERN, $axis ) || 1 !== preg_match( self::KEY_PATTERN, $value ) ) {
				CodedException::raise( CatalogError::CombinationInvalid, array( 'combination' => self::describe( $pairs ) ) );
			}

			$checked[ $axis ] = $value;
		}

		ksort( $checked, SORT_STRING );

		return new self( $checked );
	}

	/**
	 * Returns the selection a list of pairs names, as a save gives them: each pair an axis key and a value key.
	 *
	 * A list can name an axis twice, which a selection cannot hold: the last value would win without
	 * anyone asking. That is refused here, whichever values it names, for every list a save gives.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::CombinationInvalid when an axis is named twice, or when a key is not 1 to 32 lower-case letters, digits, "-" or "_".
	 *
	 * @param array<int, array{0: string, 1: string}> $named The pairs, in any order: the axis key, then the value key.
	 * @return self The selection.
	 */
	public static function ofList( array $named ): self {
		$pairs = array();

		foreach ( $named as $pair ) {
			if ( isset( $pairs[ $pair[0] ] ) ) {
				CodedException::raise( CatalogError::CombinationInvalid, array( 'combination' => self::shown( array_map( static fn( array $each ): string => $each[0] . '=' . $each[1], $named ) ) ) );
			}

			$pairs[ $pair[0] ] = $pair[1];
		}

		return self::of( $pairs );
	}

	/**
	 * Returns the selection of a product without options: no value at all.
	 *
	 * @since 0.2.0
	 *
	 * @return self The empty selection.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Returns the combination hash: SHA-256 of the sorted `axis_key=value_key` pairs, joined by a line feed.
	 *
	 * @since 0.2.0
	 *
	 * @return string 64 lower-case hexadecimal digits; that of the empty string for the empty selection.
	 */
	public function hash(): string {
		return hash( 'sha256', implode( "\n", $this->lines() ) );
	}

	/**
	 * Returns the value key of each axis key.
	 *
	 * PHP keeps an array key of digits alone as an integer, so a caller that hands an axis key on as
	 * text casts it first.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The pairs, sorted by axis key.
	 */
	public function pairs(): array {
		return $this->pairs;
	}

	/**
	 * Returns the value key the selection names on an axis.
	 *
	 * @since 0.2.0
	 *
	 * @param string $axisKey The axis key.
	 * @return string|null The value key, or null when the selection names no value on the axis.
	 */
	public function valueOn( string $axisKey ): ?string {
		return $this->pairs[ $axisKey ] ?? null;
	}

	/**
	 * Tells whether the selection names no value: the one combination of a product without options.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for the empty selection.
	 */
	public function isEmpty(): bool {
		return array() === $this->pairs;
	}

	/**
	 * Returns the selection as a person reads it in a message: the pairs, sorted by axis key, separated by commas.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `grind=whole, size=250g`; empty for the empty selection.
	 */
	public function toString(): string {
		return implode( ', ', $this->lines() );
	}

	/**
	 * Returns the pairs as `axis_key=value_key`, sorted by axis key.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The pairs.
	 */
	private function lines(): array {
		$lines = array();

		foreach ( $this->pairs as $axis => $value ) {
			$lines[] = $axis . '=' . $value;
		}

		return $lines;
	}

	/**
	 * Describes pairs that were refused, for the error.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed, mixed> $pairs The pairs as given.
	 * @return string The pairs as shown() shows them.
	 */
	private static function describe( array $pairs ): string {
		$lines = array();

		foreach ( $pairs as $axis => $value ) {
			$lines[] = (string) $axis . '=' . ( is_scalar( $value ) ? (string) $value : '' );
		}

		return self::shown( $lines );
	}

	/**
	 * Returns refused pairs as the error shows them.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The pairs, each `axis=value`.
	 * @return string The pairs, separated by commas: at most 200 characters, any that is not printable ASCII shown as "?".
	 */
	private static function shown( array $lines ): string {
		return substr( (string) preg_replace( '/[^\x20-\x7e]/', '?', implode( ', ', $lines ) ), 0, 200 );
	}
}
