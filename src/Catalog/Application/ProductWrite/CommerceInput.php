<?php
/**
 * CommerceInput: the commerce values one product save gives
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An InvalidArgumentException names a field for the developer; it is never rendered.

/**
 * The commerce fields a save gives, and only those: a field it leaves out keeps its stored value.
 *
 * Owns one fact: which commerce values a save changes. Each value is checked against its
 * CommerceFields declaration: its type, whether it may be null, and its bounds. A value that
 * fails is a caller's mistake, since the endpoint's own schema, compiled from the same
 * declarations, refuses it first; so it is an \InvalidArgumentException, not a client error.
 * Whether a SKU is valid and whether the currency is the store's base one are the domain's to
 * say, when the save applies them.
 *
 * @since 0.1.0
 */
final class CommerceInput {

	/**
	 * The given values, keyed by wire name, in wire order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string|null>
	 */
	private array $values;

	/**
	 * Holds checked values.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|null> $values The given values, keyed by wire name.
	 */
	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Returns the input a save's commerce values make.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a key is not a commerce field, or a value does not fit its declaration.
	 *
	 * @param array<string, mixed> $values The values, keyed by wire name: CommerceFields' names.
	 * @return self The input.
	 */
	public static function fromArray( array $values ): self {
		$fields = CommerceFields::all();
		$given  = array();

		foreach ( array_diff( array_keys( $values ), array_keys( $fields ) ) as $unknown ) {
			throw new \InvalidArgumentException( sprintf( '"%s" is not a commerce field of a product.', (string) $unknown ) );
		}

		foreach ( $fields as $name => $field ) {
			if ( array_key_exists( $name, $values ) ) {
				$given[ $name ] = self::checked( $field, $values[ $name ] );
			}
		}

		return new self( $given );
	}

	/**
	 * Tells whether the save gives a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field One of CommerceFields' names.
	 * @return bool True when the save gives it, null included.
	 */
	public function has( string $field ): bool {
		return array_key_exists( $field, $this->values );
	}

	/**
	 * Returns the names of the fields the save gives.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in wire order.
	 */
	public function fields(): array {
		return array_keys( $this->values );
	}

	/**
	 * Returns the given SKU.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The SKU as given, or null when it is not given.
	 */
	public function sku(): ?string {
		return self::text( $this->values[ CommerceFields::SKU ] ?? null );
	}

	/**
	 * Returns the given price.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The price in minor units, or null when it is given as null or not given.
	 */
	public function priceMinor(): ?int {
		return self::integer( $this->values[ CommerceFields::PRICE_MINOR ] ?? null );
	}

	/**
	 * Returns the given currency.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The ISO 4217 code as given, or null when it is not given.
	 */
	public function currency(): ?string {
		return self::text( $this->values[ CommerceFields::CURRENCY ] ?? null );
	}

	/**
	 * Returns the given compare-at price.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The price in minor units, or null when it is given as null or not given.
	 */
	public function compareAtMinor(): ?int {
		return self::integer( $this->values[ CommerceFields::COMPARE_AT_MINOR ] ?? null );
	}

	/**
	 * Returns the given weight.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The weight in grams, or null when it is given as null or not given.
	 */
	public function weightGrams(): ?int {
		return self::integer( $this->values[ CommerceFields::WEIGHT_GRAMS ] ?? null );
	}

	/**
	 * Checks a value against its field's declaration.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the value does not fit.
	 *
	 * @param FieldSpec $field The field.
	 * @param mixed     $value The value.
	 * @return int|string|null The value.
	 */
	private static function checked( FieldSpec $field, mixed $value ): int|string|null {
		if ( null === $value ) {
			if ( ! $field->isNullable() ) {
				throw new \InvalidArgumentException( sprintf( 'The commerce field %s may not be null.', $field->name() ) );
			}

			return null;
		}

		if ( FieldType::Integer === $field->type() ) {
			$tooSmall = is_int( $value ) && null !== $field->minimum() && $value < $field->minimum();
			$tooLarge = is_int( $value ) && null !== $field->maximum() && $value > $field->maximum();

			if ( ! is_int( $value ) || $tooSmall || $tooLarge ) {
				throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes an integer within its bounds.', $field->name() ) );
			}

			return $value;
		}

		if ( ! is_string( $value ) || ( null !== $field->maxLength() && (int) preg_match_all( '/./su', $value ) > $field->maxLength() ) ) {
			throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes text of at most its declared length.', $field->name() ) );
		}

		return $value;
	}

	/**
	 * Reads a text value.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null $value The stored value.
	 * @return string|null The text, or null.
	 */
	private static function text( int|string|null $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * Reads an integer value.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null $value The stored value.
	 * @return int|null The integer, or null.
	 */
	private static function integer( int|string|null $value ): ?int {
		return null === $value ? null : (int) $value;
	}
}
