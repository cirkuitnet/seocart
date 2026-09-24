<?php
/**
 * Sellability: the one place a reader asks whether variants may be sold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Query;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\Sellability as SellabilityRule;
use SEOCart\Catalog\Domain\SellabilityReason;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for a set of variants, whether each may be sold and if not why.
 *
 * Owns one fact: how a reader gets a verdict. It reads every variant's facts with the
 * repository's one fetch and judges each with the domain's one rule, so a REST response, a cart
 * and a checkout all see the same verdict for the same variant. A variant no row has is
 * `unknown_variant`.
 *
 * @since 0.1.0
 */
final class Sellability {

	/**
	 * Reads the facts.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Creates the query. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products Reads the facts.
	 */
	public function __construct( ProductRepository $products ) {
		$this->products = $products;
	}

	/**
	 * Returns the verdict on each variant.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds     The variants' ids.
	 * @param bool  $canReadPrivate Whether the reader may read private products: the capability
	 *                              `read_private_seocart_products`, checked by the caller.
	 * @return array<int, SellabilityReason> Each variant's verdict, keyed by its id, in the order asked; one query, none for no id.
	 */
	public function of( array $variantIds, bool $canReadPrivate ): array {
		$ids = array_values( array_unique( array_map( 'intval', $variantIds ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$facts = array();

		foreach ( $this->products->sellabilityFacts( ...$ids ) as $fact ) {
			$facts[ $fact->variantId ] = $fact;
		}

		$verdicts = array();

		foreach ( $ids as $id ) {
			$verdicts[ $id ] = SellabilityRule::verdict( $facts[ $id ] ?? null, $canReadPrivate );
		}

		return $verdicts;
	}
}
