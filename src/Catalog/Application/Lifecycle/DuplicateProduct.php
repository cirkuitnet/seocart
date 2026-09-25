<?php
/**
 * DuplicateProduct: copies a product into a new draft product with its own variant and SKU
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a copy of a product: a new draft post, a new product with a new default variant, a new SKU and a new stock item.
 *
 * Owns one fact: what a copy of a product is. It is saved through SaveProduct, as a first save of
 * a new post, so it is bound, stored and settled like any other product, and its stock item is
 * created at zero. The post takes the original's title, content, excerpt and featured image, as a
 * draft, with no slug of its own until it is published; the variant takes its price, its
 * compare-at price and its weight. The copy is `complete` when the original has a price, and
 * cannot be sold until it is published. The copy's post is in the language of the original's
 * source post, while the site still publishes in it, and in no translation group: a copy is a
 * product of its own, never a translation of the original.
 *
 * The SKU is the original's with `-copy`, then `-copy-2` up to `-copy-9`, each tried in turn while
 * another variant holds it; the original's part is shortened when the suffix would take the SKU
 * past its length. When all nine are taken, the copy fails with `catalog.sku_taken`. A try that
 * fails rolls back whole, its post included. A product without a variant is copied without one.
 *
 * Like the editor's save, it fires `wp_after_insert_post` for the new post once the copy is
 * durable.
 *
 * @since 0.1.0
 */
final class DuplicateProduct {

	/**
	 * How many SKUs a copy tries: `-copy` and `-copy-2` to `-copy-9`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const COPIES = 9;

	/**
	 * The status of a copy's post.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STATUS = 'draft';

	/**
	 * Loads the original.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Saves the copy.
	 *
	 * @since 0.1.0
	 *
	 * @var SaveProduct
	 */
	private SaveProduct $save;

	/**
	 * Reads the original's post and fires the copy's after-insert hook.
	 *
	 * @since 0.1.0
	 *
	 * @var PostGateway
	 */
	private PostGateway $posts;

	/**
	 * Tells whether the site still publishes in the original's locale.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products Loads the original.
	 * @param SaveProduct       $save     Saves the copy.
	 * @param PostGateway       $posts    Reads the original's post and fires the copy's after-insert hook.
	 * @param PostLocales       $locales  Tells whether the site still publishes in the original's locale.
	 */
	public function __construct( ProductRepository $products, SaveProduct $save, PostGateway $posts, PostLocales $locales ) {
		$this->products = $products;
		$this->save     = $save;
		$this->posts    = $posts;
		$this->locales  = $locales;
	}

	/**
	 * Copies a product into a new draft product.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `catalog.product_not_found` when there is no such product or its post is gone;
	 *                        `catalog.sku_taken` when every SKU a copy tries is taken; what SaveProduct::save()
	 *                        throws otherwise.
	 * @phpstan-throws \Throwable
	 *
	 * @param int   $productId The original.
	 * @param Actor $actor     Who copies it: the copy's post is theirs.
	 * @return SaveResult The copy.
	 */
	public function duplicate( int $productId, Actor $actor ): SaveResult {
		$original = $this->products->find( $productId );
		$postId   = $original?->sourcePostId();
		$fields   = null === $postId ? null : $this->posts->contentOf( $postId );

		if ( null === $original || null === $fields ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		$fields['post_status'] = self::STATUS;

		if ( $actor->userId() > 0 ) {
			$fields['post_author'] = $actor->userId();
		}

		$variant = $original->defaultVariant();
		$locale  = $original->bindingOf( $postId )?->locale();
		$locale  = null !== $locale && $this->locales->publishesIn( $locale ) ? $locale : null;

		if ( null === $variant ) {
			return $this->saved( new ProductSave( null, $fields, null, $actor, null, $locale ) );
		}

		$sku = '';

		foreach ( self::skus( $variant->sku() ) as $sku ) {
			try {
				return $this->saved( new ProductSave( null, $fields, self::commerce( $variant, $sku ), $actor, null, $locale ) );
			} catch ( CodedException $refused ) {
				if ( CatalogError::SkuTaken !== $refused->errorCode() ) {
					throw $refused;
				}
			}
		}

		CodedException::raise( CatalogError::SkuTaken, array( 'sku' => $sku ) );
	}

	/**
	 * Saves the copy, and fires the after-insert hook for its new post.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductSave $command The copy's save.
	 * @return SaveResult The copy.
	 */
	private function saved( ProductSave $command ): SaveResult {
		$result = $this->save->save( $command );

		$this->posts->fireAfterInsert( $result->postId, false, null );

		return $result;
	}

	/**
	 * Returns the commerce fields a copy's variant takes from the original's, with its own SKU.
	 *
	 * @since 0.1.0
	 *
	 * @param Variant $variant The original's default variant.
	 * @param string  $sku     The copy's SKU.
	 * @return CommerceInput The fields.
	 */
	private static function commerce( Variant $variant, string $sku ): CommerceInput {
		$price  = $variant->basePrice();
		$values = array(
			CommerceFields::SKU          => $sku,
			CommerceFields::WEIGHT_GRAMS => $variant->weightGrams(),
		);

		if ( null !== $price ) {
			$values[ CommerceFields::PRICE_MINOR ]      = $price->priceMinor();
			$values[ CommerceFields::CURRENCY ]         = $price->currency()->code();
			$values[ CommerceFields::COMPARE_AT_MINOR ] = $price->compareAtMinor();
		}

		return CommerceInput::fromArray( $values );
	}

	/**
	 * Returns the SKUs a copy tries, in order: the original's with `-copy`, then `-copy-2` to `-copy-9`.
	 *
	 * @since 0.1.0
	 *
	 * @param Sku $original The original's SKU.
	 * @return list<string> The SKUs, each within the length a SKU may have.
	 */
	private static function skus( Sku $original ): array {
		$skus = array();

		for ( $copy = 1; $copy <= self::COPIES; ++$copy ) {
			$suffix = 1 === $copy ? '-copy' : '-copy-' . $copy;
			$skus[] = mb_substr( $original->toString(), 0, Sku::MAX_LENGTH - strlen( $suffix ), 'UTF-8' ) . $suffix;
		}

		return $skus;
	}
}
