<?php
/**
 * SellabilityFacts: everything the sellability rule reads about one variant
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What storage says about one variant, its product and the product's source post, read in one fetch.
 *
 * Owns one fact: the inputs of Sellability::verdict(), and nothing else. They are read together
 * by ProductRepository::sellabilityFacts(), so every reader judges a variant on the same facts.
 * The binding they judge is the product's source binding, or, when they were read for a locale,
 * its binding in that locale. The post status is null when that binding names no post, or a
 * post that is not a product.
 *
 * @since 0.1.0
 */
final readonly class SellabilityFacts {

	/**
	 * The variant's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantId;

	/**
	 * The product's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $productId;

	/**
	 * The product's generation marker.
	 *
	 * @since 0.1.0
	 *
	 * @var GenerationState
	 */
	public GenerationState $generation;

	/**
	 * The generation of variants the product publishes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $activeGeneration;

	/**
	 * The generation the variant belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantGeneration;

	/**
	 * Whether the variant is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $variantEnabled;

	/**
	 * The product's source post id, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $sourcePostId;

	/**
	 * The post of the binding judged, its source binding or its binding in the locale asked for, or null when there is no such binding.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $boundPostId;

	/**
	 * The status of the judged binding's post, or null when there is no such product post.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $postStatus;

	/**
	 * Whether the variant has a price in the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $hasBasePrice;

	/**
	 * Whether the product has a post in the locale the facts were read for; true when they were read for the source post.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $translated;

	/**
	 * Holds the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $variantId         The variant's id.
	 * @param int             $productId         The product's id.
	 * @param GenerationState $generation        The product's generation marker.
	 * @param int             $activeGeneration  The generation the product publishes.
	 * @param int             $variantGeneration The generation the variant belongs to.
	 * @param bool            $variantEnabled    Whether the variant is enabled.
	 * @param int|null        $sourcePostId      The product's source post id, or null.
	 * @param int|null        $boundPostId       The post of the binding judged, or null.
	 * @param string|null     $postStatus        That post's status, or null when it is not a product post.
	 * @param bool            $hasBasePrice      Whether the variant has a base-currency price.
	 * @param bool            $translated        Optional. Whether the product has a post in the locale the facts were read for;
	 *                                           true when they were read for the source post. Default true.
	 */
	public function __construct( int $variantId, int $productId, GenerationState $generation, int $activeGeneration, int $variantGeneration, bool $variantEnabled, ?int $sourcePostId, ?int $boundPostId, ?string $postStatus, bool $hasBasePrice, bool $translated = true ) {
		$this->variantId         = $variantId;
		$this->productId         = $productId;
		$this->generation        = $generation;
		$this->activeGeneration  = $activeGeneration;
		$this->variantGeneration = $variantGeneration;
		$this->variantEnabled    = $variantEnabled;
		$this->sourcePostId      = $sourcePostId;
		$this->boundPostId       = $boundPostId;
		$this->postStatus        = $postStatus;
		$this->hasBasePrice      = $hasBasePrice;
		$this->translated        = $translated;
	}
}
