<?php
/**
 * VariantSets: the option axes and variant sets the tests save, as the wire carries them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Domain\VariantSelection;

/**
 * Builds the `options` and `variants` of a save of coffee: a size axis and a grind axis, every combination a variant with its SKU, its weight and prices in dollars and euros.
 *
 * Owns one fact: the coffee the tests sell. `six()` is the done-when's product: two sizes by three
 * grinds. Every value has a label, so a variant's label reads "250 g / Whole bean".
 *
 * @since 0.2.0
 */
final class VariantSets {

	/**
	 * The label of each value key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const LABELS = array(
		'250g'     => '250 g',
		'1kg'      => '1 kg',
		'2kg'      => '2 kg',
		'whole'    => 'Whole bean',
		'filter'   => 'Filter',
		'espresso' => 'Espresso',
		'dark'     => 'Dark',
		'light'    => 'Light',
	);

	/**
	 * The two sizes of the done-when's product.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const SIZES = array( '250g', '1kg' );

	/**
	 * The three grinds of the done-when's product.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const GRINDS = array( 'whole', 'filter', 'espresso' );

	/**
	 * Returns the options of a size axis and a grind axis, and of a roast axis when given its values.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sizes   The size values' keys.
	 * @param string[] $grinds  The grind values' keys.
	 * @param string[] $roasts  Optional. The roast values' keys; none for no roast axis. Default none.
	 * @return list<array<string, mixed>> The options.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $grinds
	 * @phpstan-param list<string> $roasts
	 */
	public static function options( array $sizes = self::SIZES, array $grinds = self::GRINDS, array $roasts = array() ): array {
		$axes = array(
			self::axis( 'size', 'Size', 0, $sizes ),
			self::axis( 'grind', 'Grind', 1, $grinds ),
		);

		if ( array() !== $roasts ) {
			$axes[] = self::axis( 'roast', 'Roast', 2, $roasts );
		}

		return $axes;
	}

	/**
	 * Returns a variant per combination of the sizes and grinds (and roasts), sizes outermost, each with a SKU, a weight and prices.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $prefix The SKUs' prefix.
	 * @param string[] $sizes  The size values' keys.
	 * @param string[] $grinds The grind values' keys.
	 * @param string[] $roasts Optional. The roast values' keys. Default none.
	 * @param bool     $euros  Optional. Whether each variant has a price in euros as well. Default true.
	 * @return list<array<string, mixed>> The variants.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $grinds
	 * @phpstan-param list<string> $roasts
	 */
	public static function variants( string $prefix, array $sizes = self::SIZES, array $grinds = self::GRINDS, array $roasts = array(), bool $euros = true ): array {
		$variants = array();

		foreach ( self::combinations( $sizes, $grinds, $roasts ) as $index => $pairs ) {
			$prices = array(
				array(
					'currency'    => 'USD',
					'price_minor' => 1200 + 100 * $index,
				),
			);

			if ( $euros ) {
				$prices[] = array(
					'currency'     => 'EUR',
					'price_minor'  => 1100 + 100 * $index,
					'amount_basis' => 'gross',
				);
			}

			$variants[] = array(
				'combination'  => self::combination( $pairs ),
				'sku'          => $prefix . '-' . implode( '-', array_values( $pairs ) ),
				'weight_grams' => 250 + $index,
				'prices'       => $prices,
			);
		}

		return $variants;
	}

	/**
	 * Returns the options of two axes whose keys and value keys are digits alone.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>> The options.
	 */
	public static function digitOptions(): array {
		return array(
			self::axis( '2024', 'Year', 0, array( '500', '1000' ) ),
			self::axis( '9', 'Batch', 1, array( '7' ) ),
		);
	}

	/**
	 * Returns a variant per combination of digitOptions(), each with a SKU and a price.
	 *
	 * @since 0.2.0
	 *
	 * @param string $prefix The SKUs' prefix.
	 * @return list<array<string, mixed>> The variants.
	 */
	public static function digitVariants( string $prefix ): array {
		return array_map(
			static fn( string $value ): array => array(
				'combination' => array(
					array(
						'axis'  => '2024',
						'value' => $value,
					),
					array(
						'axis'  => '9',
						'value' => '7',
					),
				),
				'sku'         => $prefix . '-' . $value,
				'prices'      => array(
					array(
						'currency'    => 'USD',
						'price_minor' => 100,
					),
				),
			),
			array( '500', '1000' )
		);
	}

	/**
	 * Returns a variant set with no prices given.
	 *
	 * @since 0.2.0
	 *
	 * @param array $variants The variants.
	 * @return list<array<string, mixed>> The variants, without prices.
	 *
	 * @phpstan-param list<array<string, mixed>> $variants
	 */
	public static function withoutPrices( array $variants ): array {
		return array_map(
			static function ( array $variant ): array {
				unset( $variant['prices'] );

				return $variant;
			},
			$variants
		);
	}

	/**
	 * Returns a variant set that names each variant and gives it its SKU, and gives nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param array $variants The variants.
	 * @return list<array<string, mixed>> The variants, each with its combination and its SKU alone.
	 *
	 * @phpstan-param list<array<string, mixed>> $variants
	 */
	public static function bare( array $variants ): array {
		return array_map(
			static fn( array $variant ): array => array(
				'combination' => $variant['combination'],
				'sku'         => $variant['sku'],
			),
			$variants
		);
	}

	/**
	 * Returns a variant set with a stock cell at each location given for every variant.
	 *
	 * @since 0.2.0
	 *
	 * @param array    $variants  The variants.
	 * @param string[] $locations The locations' UUIDs.
	 * @param int      $onHand    The first cell's count; each next one is one more.
	 * @return list<array<string, mixed>> The variants, each with its cells, read at zero.
	 *
	 * @phpstan-param list<array<string, mixed>> $variants
	 * @phpstan-param list<string> $locations
	 */
	public static function withStock( array $variants, array $locations, int $onHand = 10 ): array {
		foreach ( $variants as $index => $variant ) {
			$variants[ $index ]['stock'] = array();

			foreach ( $locations as $at => $location ) {
				$variants[ $index ]['stock'][] = array(
					'location_uuid'    => $location,
					'on_hand'          => $onHand + 2 * $index + $at,
					'expected_on_hand' => 0,
				);
			}
		}

		return $variants;
	}

	/**
	 * Returns the combination hash of a combination of keys.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $pairs The value key of each axis key.
	 * @return string The hash.
	 */
	public static function hash( array $pairs ): string {
		return VariantSelection::of( $pairs )->hash();
	}

	/**
	 * Returns every combination of the values, sizes outermost.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sizes  The size values.
	 * @param string[] $grinds The grind values.
	 * @param string[] $roasts The roast values; none for no roast axis.
	 * @return list<array<string, string>> The combinations, each the value key of each axis key.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $grinds
	 * @phpstan-param list<string> $roasts
	 */
	public static function combinations( array $sizes = self::SIZES, array $grinds = self::GRINDS, array $roasts = array() ): array {
		$combinations = array();

		foreach ( $sizes as $size ) {
			foreach ( $grinds as $grind ) {
				foreach ( array() === $roasts ? array( null ) : $roasts as $roast ) {
					$pairs = array(
						'size'  => $size,
						'grind' => $grind,
					);

					if ( null !== $roast ) {
						$pairs['roast'] = $roast;
					}

					$combinations[] = $pairs;
				}
			}
		}

		return $combinations;
	}

	/**
	 * Returns a combination as the wire carries it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $pairs The value key of each axis key.
	 * @return list<array{axis: string, value: string}> The pairs.
	 */
	public static function combination( array $pairs ): array {
		$combination = array();

		foreach ( $pairs as $axis => $value ) {
			$combination[] = array(
				'axis'  => $axis,
				'value' => $value,
			);
		}

		return $combination;
	}

	/**
	 * Returns an axis with its values, each labelled.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $key      The axis key.
	 * @param string   $label    The axis label.
	 * @param int      $position Its place.
	 * @param string[] $values   The values' keys.
	 * @return array<string, mixed> The axis.
	 *
	 * @phpstan-param list<string> $values
	 */
	private static function axis( string $key, string $label, int $position, array $values ): array {
		return array(
			'key'      => $key,
			'label'    => $label,
			'position' => $position,
			'values'   => array_map(
				static fn( string $value, int $at ): array => array(
					'key'      => $value,
					'label'    => self::LABELS[ $value ] ?? $value,
					'position' => $at,
				),
				$values,
				array_keys( $values )
			),
		);
	}
}
