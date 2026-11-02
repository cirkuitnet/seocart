<?php
/**
 * OptionMatrix: a product's option axes and their values, read only
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
 * The option axes of a product, in order, each with its values: what combinations its variants may stand for.
 *
 * Owns one fact: which selections are combinations of the product. A selection is one when it
 * names every axis once, with one of that axis's values, and nothing else; a product without
 * options has the empty matrix, whose one combination is the empty selection. The axes are kept by
 * position and then by key, and no two have one key. The matrix also writes a variant's label: the
 * labels of its values, in the order of their axes, separated by " / ". It holds no rule of how
 * many axes or values a product may have: the product does.
 *
 * @since 0.2.0
 */
final class OptionMatrix {

	/**
	 * What separates the value labels in a variant's label.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LABEL_SEPARATOR = ' / ';

	/**
	 * Creates a matrix of checked, ordered axes.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionAxis[] $axes The axes, in order.
	 *
	 * @phpstan-param list<OptionAxis> $axes
	 */
	private function __construct(
		public readonly array $axes
	) {
	}

	/**
	 * Returns the matrix of some axes.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid when two axes have one key.
	 *
	 * @param OptionAxis[] $axes The axes, in any order.
	 * @return self The matrix.
	 */
	public static function of( array $axes ): self {
		$keyed = array();

		foreach ( $axes as $axis ) {
			if ( isset( $keyed[ $axis->key ] ) ) {
				CodedException::raise(
					CatalogError::OptionInvalid,
					array(
						'field' => 'axis key',
						'value' => $axis->key,
					)
				);
			}

			$keyed[ $axis->key ] = $axis;
		}

		$ordered = array_values( $keyed );

		usort( $ordered, static fn( OptionAxis $a, OptionAxis $b ): int => array( $a->position, $a->key ) <=> array( $b->position, $b->key ) );

		return new self( $ordered );
	}

	/**
	 * Returns the matrix of a product without options.
	 *
	 * @since 0.2.0
	 *
	 * @return self The empty matrix.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Tells whether the product has no option.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for the empty matrix.
	 */
	public function isEmpty(): bool {
		return array() === $this->axes;
	}

	/**
	 * Returns the axis keys, sorted: the set a generation is built over.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The keys, in byte order.
	 */
	public function axisKeys(): array {
		$keys = array_map( static fn( OptionAxis $axis ): string => $axis->key, $this->axes );

		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Counts the values of every axis together.
	 *
	 * @since 0.2.0
	 *
	 * @return int The number of values.
	 */
	public function valueCount(): int {
		return array_sum( array_map( static fn( OptionAxis $axis ): int => count( $axis->values ), $this->axes ) );
	}

	/**
	 * Returns one of the axes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The axis's key.
	 * @return OptionAxis|null The axis, or null when the matrix has none with that key.
	 */
	public function axis( string $key ): ?OptionAxis {
		foreach ( $this->axes as $axis ) {
			if ( $axis->key === $key ) {
				return $axis;
			}
		}

		return null;
	}

	/**
	 * Tells whether a selection is one of the matrix's combinations: every axis named once, with one of its values, and nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param VariantSelection $selection The selection.
	 * @return bool True when it is.
	 */
	public function admits( VariantSelection $selection ): bool {
		if ( count( $selection->pairs() ) !== count( $this->axes ) ) {
			return false;
		}

		foreach ( $this->axes as $axis ) {
			$value = $selection->valueOn( $axis->key );

			if ( null === $value || null === $axis->value( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns a variant's label: the labels of the values its selection names, in the order of their axes.
	 *
	 * @since 0.2.0
	 *
	 * @param VariantSelection $selection A selection the matrix admits.
	 * @return string|null The label, such as "250 g / Whole bean"; null for the empty selection.
	 */
	public function labelOf( VariantSelection $selection ): ?string {
		$labels = array();

		foreach ( $this->axes as $axis ) {
			$value = $axis->value( (string) $selection->valueOn( $axis->key ) );

			if ( null !== $value ) {
				$labels[] = $value->label;
			}
		}

		return array() === $labels ? null : implode( self::LABEL_SEPARATOR, $labels );
	}

	/**
	 * Tells whether two matrices have the same axis keys, whatever their labels, positions and values: whether the combinations of one could be the other's.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionMatrix $other The matrix to compare with.
	 * @return bool True when the axis keys are the same set.
	 */
	public function hasAxesOf( OptionMatrix $other ): bool {
		return $this->axisKeys() === $other->axisKeys();
	}

	/**
	 * Tells whether two matrices are the same in every field.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionMatrix $other The matrix to compare with.
	 * @return bool True when they are.
	 */
	public function equals( OptionMatrix $other ): bool {
		if ( count( $this->axes ) !== count( $other->axes ) ) {
			return false;
		}

		foreach ( $this->axes as $index => $axis ) {
			if ( ! $axis->equals( $other->axes[ $index ] ) ) {
				return false;
			}
		}

		return true;
	}
}
