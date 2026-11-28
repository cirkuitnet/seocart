<?php
/**
 * ProductPageView: what a page that shows a product reads of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Query;

use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A product as a page shows it to every visitor: its option axes, the variants it sells with their prices in the page's currency, whether each is in stock, and the exchange-rate version the prices were read under.
 *
 * Owns one fact: which variant a page shows first, defaultVariant(). Everything else is read
 * once by ProductPage. It holds no count of units and nothing of a visitor: a variant is in
 * stock or not, and its price is the public one in the page's currency. A variant the
 * sellability rule refuses is left out, so the page shows its combination as unavailable.
 *
 * @since 0.2.0
 *
 * @phpstan-type PageVariant array{id: int, values: array<string, string>, label: string|null, price: Money|null, compareAt: Money|null, inStock: bool}
 */
final class ProductPageView {

	/**
	 * Creates the view.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $postId       The post the page shows.
	 * @param string       $uuid         The product's uuid.
	 * @param OptionAxis[] $axes         The option axes in the product's order, each with its values in order; none for a product without options.
	 * @param array[]      $variants     The enabled variants of the generation the product publishes, in their order: each its id, the value
	 *                                   key it has on each axis by axis key, its label, its price and compare-at price in the page's currency
	 *                                   (null when it has none there), and whether any unit of it is for sale. None when the product's
	 *                                   generation is not complete.
	 * @param Currency     $currency     The page's currency.
	 * @param int|null     $priceVersion The exchange-rate version current when the prices were read, or null when there is none.
	 *
	 * @phpstan-param list<OptionAxis>  $axes
	 * @phpstan-param list<PageVariant> $variants
	 */
	public function __construct(
		public readonly int $postId,
		public readonly string $uuid,
		public readonly array $axes,
		public readonly array $variants,
		public readonly Currency $currency,
		public readonly ?int $priceVersion
	) {
	}

	/**
	 * Returns the variant the page shows first: the first in stock that has a price, else the first.
	 *
	 * @since 0.2.0
	 *
	 * @return array|null The variant, or null when the product sells none.
	 *
	 * @phpstan-return PageVariant|null
	 */
	public function defaultVariant(): ?array {
		foreach ( $this->variants as $variant ) {
			if ( $variant['inStock'] && null !== $variant['price'] ) {
				return $variant;
			}
		}

		return $this->variants[0] ?? null;
	}
}
