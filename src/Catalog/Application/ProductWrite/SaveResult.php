<?php
/**
 * SaveResult: what a product save wrote, and where it left the product
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a committed product save.
 *
 * Owns one fact: what a caller learns from a save. The product, its post, its default variant
 * when it has one, and the id of every variant of the generation it publishes by combination
 * hash, so a caller that named variants by their combination can find each; whether the post was
 * created by this save, which the caller needs to fire `wp_after_insert_post`; the marker the save
 * settled on; and the product's sellability verdict after the commit, as a shopper who may not
 * read private products gets it, or null when the product has no variant to sell.
 *
 * @since 0.1.0
 */
final readonly class SaveResult {

	/**
	 * Holds the outcome.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The id of every variant, by combination hash; the verdict on the product.
	 *
	 * @param int                    $productId   The product.
	 * @param int                    $postId      Its source post.
	 * @param int|null               $variantId   Its default variant, or null when it has none, as a product with options has not.
	 * @param bool                   $postCreated Whether the save created the post.
	 * @param GenerationState        $generation  The marker the save settled on: complete or incomplete.
	 * @param SellabilityReason|null $sellability The verdict on the product, or null when it has no variant.
	 * @param array<string, int>     $variantIds  Optional. The id of every variant of the published generation, by combination hash. Default none.
	 */
	public function __construct(
		public int $productId,
		public int $postId,
		public ?int $variantId,
		public bool $postCreated,
		public GenerationState $generation,
		public ?SellabilityReason $sellability,
		public array $variantIds = array()
	) {
	}
}
