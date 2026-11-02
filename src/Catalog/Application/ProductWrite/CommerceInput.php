<?php
/**
 * CommerceInput: the commerce fields a product save gives
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
 * Owns one fact: which commerce values a save changes. A save gives either the flat fields of
 * the single variant of a product without options, CommerceFields', or the product's option axes
 * and its whole set of variants, VariantFields'. Each value is checked against its declaration:
 * its type, whether it may be null, its bounds, and inside a list each item's keys. A value that
 * fails is a caller's mistake, since the endpoint's own schema, compiled from the same
 * declarations, refuses it first; so it is an \InvalidArgumentException, not a client error.
 * Whether a SKU, a key or a combination is valid, and whether a currency may be priced in, are the
 * domain's to say, when the save applies them.
 *
 * @since 0.1.0
 * @since 0.2.0 The option axes and the variant set.
 *
 * @phpstan-type OptionValueInput array{key: string, label: string, position?: int, image_id?: int|null, swatch?: string|null}
 * @phpstan-type OptionAxisInput array{key: string, label: string, position?: int, values?: list<OptionValueInput>}
 * @phpstan-type PriceInput array{currency?: string, price_minor?: int|null, compare_at_minor?: int|null, amount_basis?: string}
 * @phpstan-type StockCellInput array{location_uuid: string, on_hand: int, expected_on_hand?: int|null}
 * @phpstan-type VariantEntryInput array{combination?: list<array{axis: string, value: string}>, sku?: string|null, enabled?: bool, position?: int, weight_grams?: int|null, prices?: list<PriceInput>, stock?: list<StockCellInput>}
 */
final class CommerceInput {

	/**
	 * The given values, keyed by wire name, in wire order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Holds checked values.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $values The given values, keyed by wire name.
	 */
	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Returns the input a save's commerce values make.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The option axes and the variant set.
	 *
	 * @throws \InvalidArgumentException When a key is not a commerce field, or a value does not fit its declaration.
	 *
	 * @param array<string, mixed> $values The values, keyed by wire name: CommerceFields' and VariantFields' names.
	 * @return self The input.
	 */
	public static function fromArray( array $values ): self {
		$fields = array_merge( CommerceFields::all(), VariantFields::all() );
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
	 * @param string $field One of CommerceFields' or VariantFields' names.
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
	 * Tells whether the save gives any flat field of the single variant of a product without options.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it gives one of CommerceFields'.
	 */
	public function givesFlatFields(): bool {
		return array() !== array_intersect_key( $this->values, CommerceFields::all() );
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
	 * Returns the given option axes.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>>|null The axes as given, each with its values, or null when they are not given.
	 *
	 * @phpstan-return list<OptionAxisInput>|null
	 */
	public function options(): ?array {
		return $this->values[ VariantFields::OPTIONS ] ?? null;
	}

	/**
	 * Returns the given variant set.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>>|null The variants as given, or null when they are not given.
	 *
	 * @phpstan-return list<VariantEntryInput>|null
	 */
	public function variants(): ?array {
		return $this->values[ VariantFields::VARIANTS ] ?? null;
	}

	/**
	 * Checks a value against its field's declaration, the items of a list against their fields.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Booleans, identifiers and lists of objects.
	 *
	 * @throws \InvalidArgumentException When the value does not fit.
	 *
	 * @param FieldSpec $field The field.
	 * @param mixed     $value The value.
	 * @return mixed The value.
	 */
	private static function checked( FieldSpec $field, mixed $value ): mixed {
		if ( null === $value ) {
			if ( ! $field->isNullable() ) {
				throw new \InvalidArgumentException( sprintf( 'The commerce field %s may not be null.', $field->name() ) );
			}

			return null;
		}

		return match ( $field->type() ) {
			FieldType::Integer    => self::checkedInteger( $field, $value ),
			FieldType::Boolean    => is_bool( $value ) ? $value : throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes true or false.', $field->name() ) ),
			FieldType::ObjectList => self::checkedList( $field, $value ),
			default               => self::checkedText( $field, $value ),
		};
	}

	/**
	 * Checks an integer against its bounds.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the value is no integer, or out of bounds.
	 *
	 * @param FieldSpec $field The field.
	 * @param mixed     $value The value.
	 * @return int The value.
	 */
	private static function checkedInteger( FieldSpec $field, mixed $value ): int {
		$tooSmall = is_int( $value ) && null !== $field->minimum() && $value < $field->minimum();
		$tooLarge = is_int( $value ) && null !== $field->maximum() && $value > $field->maximum();

		if ( ! is_int( $value ) || $tooSmall || $tooLarge ) {
			throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes an integer within its bounds.', $field->name() ) );
		}

		return $value;
	}

	/**
	 * Checks a text against its length and its allowed values.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the value is no text, too long, or not one of the values allowed.
	 *
	 * @param FieldSpec $field The field.
	 * @param mixed     $value The value.
	 * @return string The value.
	 */
	private static function checkedText( FieldSpec $field, mixed $value ): string {
		$tooLong    = is_string( $value ) && null !== $field->maxLength() && (int) preg_match_all( '/./su', $value ) > $field->maxLength();
		$notAllowed = is_string( $value ) && array() !== $field->allowedValues() && ! in_array( $value, $field->allowedValues(), true );

		if ( ! is_string( $value ) || $tooLong || $notAllowed ) {
			throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes text of at most its declared length, one of its values when it lists them.', $field->name() ) );
		}

		return $value;
	}

	/**
	 * Checks a list of objects: each item an object of its declared fields, its required ones given.
	 *
	 * How many items a list may have is the domain's to judge, whose refusal names the count and
	 * the limit; the endpoint's schema refuses a longer list first.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the value is no list, or an item does not fit.
	 *
	 * @param FieldSpec $field The list.
	 * @param mixed     $value The value.
	 * @return list<array<string, mixed>> The items.
	 */
	private static function checkedList( FieldSpec $field, mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'The commerce field %s takes a list of objects.', $field->name() ) );
		}

		$members = array();

		foreach ( $field->fields() as $member ) {
			$members[ $member->name() ] = $member;
		}

		$items = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array() !== array_diff_key( $item, $members ) ) {
				throw new \InvalidArgumentException( sprintf( 'Each item of the commerce field %s is an object of its declared fields.', $field->name() ) );
			}

			$checked = array();

			foreach ( $members as $name => $member ) {
				if ( array_key_exists( $name, $item ) ) {
					$checked[ $name ] = self::checked( $member, $item[ $name ] );
				} elseif ( $member->isRequired() ) {
					throw new \InvalidArgumentException( sprintf( 'Each item of the commerce field %1$s gives %2$s.', $field->name(), $name ) );
				}
			}

			$items[] = $checked;
		}

		return $items;
	}

	/**
	 * Reads a text value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The stored value, checked to be text or null.
	 * @return string|null The text, or null.
	 */
	private static function text( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Reads an integer value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The stored value, checked to be an integer or null.
	 * @return int|null The integer, or null.
	 */
	private static function integer( mixed $value ): ?int {
		return is_int( $value ) ? $value : null;
	}
}
