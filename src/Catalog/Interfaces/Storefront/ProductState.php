<?php
/**
 * ProductState: a product as the page's script and markup read it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

use SEOCart\Catalog\Application\Query\ProductPageView;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Platform\Localization\MoneyFormat;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a product's state, the one shape the components print from and the store `seocart/product` holds, and answers what the page shows for it.
 *
 * Owns one fact: the product's state on a page, `{ uuid, name, axes, variants, selection, quantity }`.
 * Each variant is `{ id, values, label, price, compareAt, inStock }`, its prices `{ minor, text }`
 * as the server wrote them, or null when it has none in the page's currency; nothing in it is a
 * count of units, and nothing depends on the visitor. The selection is the variant the page shows
 * first. The questions the markup asks of it, which variant is selected and what the button
 * reads, are answered here for the server's first paint and by the store's getters after a change.
 *
 * @since 0.2.0
 *
 * @phpstan-type Price array{minor: int, text: string}
 * @phpstan-type StateVariant array{id: int, values: array<string, string>, label: string|null, price: Price|null, compareAt: Price|null, inStock: bool}
 * @phpstan-type StateValue array{key: string, label: string, swatch: string|null, image: int|null}
 * @phpstan-type StateAxis array{key: string, label: string, values: list<StateValue>}
 * @phpstan-type State array{uuid: string, name: string, axes: list<StateAxis>, variants: list<StateVariant>, selection: array<string, string>, quantity: int}
 */
final class ProductState {

	/**
	 * Writes a product's state.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductPageView $view   The product, as the page reads it.
	 * @param string          $name   The product's name, as the page shows it.
	 * @param string          $locale The locale its prices are written in, such as `en_US`.
	 * @return array The state.
	 *
	 * @phpstan-return State
	 */
	public static function of( ProductPageView $view, string $name, string $locale ): array {
		$variants = array();

		foreach ( $view->variants as $variant ) {
			$variants[] = array(
				'id'        => $variant['id'],
				'values'    => $variant['values'],
				'label'     => $variant['label'],
				'price'     => self::price( $variant['price'], $locale ),
				'compareAt' => self::price( $variant['compareAt'], $locale ),
				'inStock'   => $variant['inStock'],
			);
		}

		return array(
			'uuid'      => $view->uuid,
			'name'      => $name,
			'axes'      => array_map( array( self::class, 'axis' ), $view->axes ),
			'variants'  => $variants,
			'selection' => $view->defaultVariant()['values'] ?? array(),
			'quantity'  => 1,
		);
	}

	/**
	 * Returns the variant the state selects: the one whose value on every axis is the selection's.
	 *
	 * @since 0.2.0
	 *
	 * @param array $product The product's state.
	 * @return array|null The variant, or null when no variant has that combination.
	 *
	 * @phpstan-param State $product
	 * @phpstan-return StateVariant|null
	 */
	public static function selected( array $product ): ?array {
		$selection = $product['selection'];

		ksort( $selection );

		foreach ( $product['variants'] as $variant ) {
			$values = $variant['values'];

			ksort( $values );

			if ( $values === $selection ) {
				return $variant;
			}
		}

		return null;
	}

	/**
	 * Tells whether a variant can be added to the cart: it has a price and is in stock.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $variant The variant, or null for none.
	 * @return bool True when it can be added.
	 *
	 * @phpstan-param StateVariant|null $variant
	 */
	public static function canAdd( ?array $variant ): bool {
		return null !== $variant && null !== $variant['price'] && $variant['inStock'];
	}

	/**
	 * Returns the name of what the add button reads for a variant: add, sold out, or unavailable.
	 *
	 * @since 0.2.0
	 *
	 * @param array|null $variant The variant, or null for a combination no variant has.
	 * @return string The name of the words, a key of the storefront's strings.
	 *
	 * @phpstan-param StateVariant|null $variant
	 */
	public static function buttonLabel( ?array $variant ): string {
		if ( null === $variant || null === $variant['price'] ) {
			return 'unavailable';
		}

		return $variant['inStock'] ? 'add' : 'sold_out';
	}

	/**
	 * Returns the label of the value an axis has selected.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $product The product's state.
	 * @param string $axisKey The axis.
	 * @return string The value's label, or an empty string when the axis has none selected.
	 *
	 * @phpstan-param State $product
	 */
	public static function valueLabel( array $product, string $axisKey ): string {
		$selected = $product['selection'][ $axisKey ] ?? null;

		foreach ( $product['axes'] as $axis ) {
			foreach ( $axis['key'] === $axisKey ? $axis['values'] : array() as $value ) {
				if ( $value['key'] === $selected ) {
					return $value['label'];
				}
			}
		}

		return '';
	}

	/**
	 * Writes an axis as the state holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionAxis $axis The axis.
	 * @return array The axis.
	 *
	 * @phpstan-return StateAxis
	 */
	private static function axis( OptionAxis $axis ): array {
		return array(
			'key'    => (string) $axis->key,
			'label'  => $axis->label,
			'values' => array_map(
				static fn( OptionValue $value ): array => array(
					'key'    => (string) $value->key,
					'label'  => $value->label,
					'swatch' => $value->swatchHex,
					'image'  => $value->imageAttachmentId,
				),
				$axis->values
			),
		);
	}

	/**
	 * Writes a price as the state holds it: its minor units and the text the server formatted.
	 *
	 * @since 0.2.0
	 *
	 * @param Money|null $money  The price, or null.
	 * @param string     $locale The locale it is written in.
	 * @return array|null The price, or null for none.
	 *
	 * @phpstan-return Price|null
	 */
	private static function price( ?Money $money, string $locale ): ?array {
		return null === $money ? null : array(
			'minor' => $money->minorUnits(),
			'text'  => MoneyFormat::text( $money, $locale ),
		);
	}
}
