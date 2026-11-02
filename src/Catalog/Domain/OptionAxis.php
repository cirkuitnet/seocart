<?php
/**
 * OptionAxis: one option axis of a product, such as Size, with its values
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
 * An option axis: its key, its label, its place among the product's axes, and its values in order.
 *
 * Owns one fact: what a valid axis is. The key is fixed once the axis exists and is part of the
 * combination hash of every variant of the product; the label is the name a shopper reads. The
 * values are kept in their order, by position and then by key, and no two have one key. An axis
 * is changed only through its product, so it is a value.
 *
 * @since 0.2.0
 */
final class OptionAxis {

	/**
	 * Creates a checked axis.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $key      The key.
	 * @param string        $label    The label.
	 * @param int           $position Where it sorts among the product's axes.
	 * @param OptionValue[] $values   The values, in order.
	 *
	 * @phpstan-param list<OptionValue> $values
	 */
	private function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly int $position,
		public readonly array $values
	) {
	}

	/**
	 * Returns an axis, checked, with its values in order.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid when the key, the label or the position is not one an
	 *                        axis may have, or two values have one key.
	 *
	 * @param string        $key      The key: 1 to 32 lower-case letters, digits, "-" or "_".
	 * @param string        $label    The label: 1 to 100 characters, no control character; surrounding white space is trimmed.
	 * @param int           $position Where it sorts among the product's axes, 0 or more.
	 * @param OptionValue[] $values   The values, in any order.
	 * @return self The axis.
	 */
	public static function of( string $key, string $label, int $position, array $values ): self {
		$label = OptionValue::label( 'axis', $key, $label );

		if ( 1 !== preg_match( VariantSelection::KEY_PATTERN, $key ) ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'axis key',
					'value' => OptionValue::shown( $key ),
				)
			);
		}

		if ( $position < 0 ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'position',
					'value' => (string) $position,
				)
			);
		}

		$keyed = array();

		foreach ( $values as $value ) {
			if ( isset( $keyed[ $value->key ] ) ) {
				CodedException::raise(
					CatalogError::OptionInvalid,
					array(
						'field' => 'value key',
						'value' => $key . '=' . $value->key,
					)
				);
			}

			$keyed[ $value->key ] = $value;
		}

		$ordered = array_values( $keyed );

		usort( $ordered, static fn( OptionValue $a, OptionValue $b ): int => array( $a->position, $a->key ) <=> array( $b->position, $b->key ) );

		return new self( $key, $label, $position, $ordered );
	}

	/**
	 * Returns one of the axis's values.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The value's key.
	 * @return OptionValue|null The value, or null when the axis has none with that key.
	 */
	public function value( string $key ): ?OptionValue {
		foreach ( $this->values as $value ) {
			if ( $value->key === $key ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Tells whether two axes are the same in every field, their values included.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionAxis $other The axis to compare with.
	 * @return bool True when they are.
	 */
	public function equals( OptionAxis $other ): bool {
		if ( $this->key !== $other->key || $this->label !== $other->label || $this->position !== $other->position || count( $this->values ) !== count( $other->values ) ) {
			return false;
		}

		foreach ( $this->values as $index => $value ) {
			if ( ! $value->equals( $other->values[ $index ] ) ) {
				return false;
			}
		}

		return true;
	}
}
