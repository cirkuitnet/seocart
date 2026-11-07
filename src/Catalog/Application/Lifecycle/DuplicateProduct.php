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
use SEOCart\Catalog\Application\ProductWrite\VariantFields;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a copy of a product: a new draft post, a new product with new variants, new SKUs and new stock items.
 *
 * Owns one fact: what a copy of a product is. It is saved through SaveProduct, as a first save of
 * a new post, so it is bound, stored and settled like any other product, and its stock item is
 * created at zero. The post takes the original's title, content, excerpt and featured image, as a
 * draft, with no slug of its own until it is published. A product is copied with its option axes
 * and values, when it has any, and every variant of the generation it publishes that its options
 * still admit and that has a SKU, each with its state, weight and prices, each price on its basis,
 * in one save: the one variant of a product without options is such a variant, under no options.
 * A variant without a SKU, whose SKU another of the original's variants took, is left out rather
 * than given a SKU of the copy's making: it is disabled, it keeps the original's carts, orders and
 * stock, which a copy has none of, and it has no SKU of the merchant's to derive one from. The
 * copy is `complete` when the original is whole, and cannot be sold until it is published. The
 * copy's post is in the language of the original's source post, while the site still publishes in
 * it, and in no translation group: a copy is a product of its own, never a translation of the
 * original.
 *
 * Each SKU is the original's with `-copy`, then `-copy-2` up to `-copy-9`, every variant taking the
 * same suffix in a try, each try one save, tried in turn while another variant holds one of them;
 * the original's part is shortened when the suffix would take a SKU past its length. When all
 * nine are taken, the copy fails with `catalog.sku_taken`. A try that fails rolls back whole, its
 * post included. A product without a variant is copied without one.
 *
 * Like the editor's save, it gives the new post the original's categories, brands and tags once
 * the copy is durable, outside its transaction, as the product endpoint writes terms; then it
 * fires `wp_after_insert_post` for the new post.
 *
 * @since 0.1.0
 * @since 0.2.0 The copy keeps the original's categories, brands and tags.
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

		if ( null === $original || null === $postId || null === $fields ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		$fields['post_status'] = self::STATUS;

		if ( $actor->userId() > 0 ) {
			$fields['post_author'] = $actor->userId();
		}

		$locale = $original->bindingOf( $postId )?->locale();
		$locale = null !== $locale && $this->locales->publishesIn( $locale ) ? $locale : null;

		// Only the post is left to copy; axes are kept even when the generation they publish has no variant.
		if ( array() === $original->variants() && $original->options()->isEmpty() ) {
			return $this->saved( new ProductSave( null, $fields, null, $actor, null, $locale ), $postId );
		}

		$sku     = '';
		$named   = self::copiedVariants( $original )[0] ?? null;
		$options = $this->copiedOptions( $original );

		for ( $copy = 1; $copy <= self::COPIES; ++$copy ) {
			$sku = null === $named ? '' : self::copied( $named->sku(), $copy );

			try {
				return $this->saved( new ProductSave( null, $fields, self::options( $original, $options, $copy ), $actor, null, $locale ), $postId );
			} catch ( CodedException $refused ) {
				if ( CatalogError::SkuTaken !== $refused->errorCode() ) {
					throw $refused;
				}
			}
		}

		CodedException::raise( CatalogError::SkuTaken, array( 'sku' => $sku ) );
	}

	/**
	 * Saves the copy, gives its new post the original's terms, and fires the after-insert hook for it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Copies the original's terms.
	 *
	 * @param ProductSave $command        The copy's save.
	 * @param int         $originalPostId The original's source post, whose terms the copy takes.
	 * @return SaveResult The copy.
	 */
	private function saved( ProductSave $command, int $originalPostId ): SaveResult {
		$result = $this->save->save( $command );

		$this->posts->copyTerms( $originalPostId, $result->postId );
		$this->posts->fireAfterInsert( $result->postId, false, null );

		return $result;
	}

	/**
	 * Returns the variants of a product a copy of it carries: those its options still admit that have a SKU.
	 *
	 * A variant the options no longer admit, one whose value was removed, and a variant without a
	 * SKU, are left out of the copy.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $original The original.
	 * @return list<Variant> The variants, in the original's order.
	 */
	private static function copiedVariants( Product $original ): array {
		return array_values( array_filter( $original->variants(), static fn( Variant $variant ): bool => $variant->hasSku() && $original->options()->admits( $variant->selection() ) ) );
	}

	/**
	 * Returns the options and the variant set of a copy of a product, every SKU with one suffix: no options and one variant for a product without options.
	 *
	 * @since 0.2.0
	 *
	 * @param Product                    $original The original.
	 * @param list<array<string, mixed>> $options  The copy's options, as copiedOptions() gives them.
	 * @param int                        $copy     Which try: 1 for `-copy`, 2 to 9 for `-copy-2` to `-copy-9`.
	 * @return CommerceInput The fields.
	 */
	private static function options( Product $original, array $options, int $copy ): CommerceInput {
		$variants = array();

		foreach ( self::copiedVariants( $original ) as $variant ) {
			$combination = array();

			foreach ( $variant->selection()->pairs() as $axis => $value ) {
				$combination[] = array(
					VariantFields::AXIS  => (string) $axis,
					VariantFields::VALUE => $value,
				);
			}

			$variants[] = array(
				VariantFields::COMBINATION   => $combination,
				CommerceFields::SKU          => self::copied( $variant->sku(), $copy ),
				VariantFields::ENABLED       => $variant->isEnabled(),
				VariantFields::POSITION      => $variant->position(),
				CommerceFields::WEIGHT_GRAMS => $variant->weightGrams(),
				VariantFields::PRICES        => array_map(
					static fn( VariantPrice $price ): array => array(
						CommerceFields::CURRENCY         => $price->currency()->code(),
						CommerceFields::PRICE_MINOR      => $price->priceMinor(),
						CommerceFields::COMPARE_AT_MINOR => $price->compareAtMinor(),
						VariantFields::AMOUNT_BASIS      => $price->amountBasis(),
					),
					$variant->prices()
				),
			);
		}

		return CommerceInput::fromArray(
			array(
				VariantFields::OPTIONS  => $options,
				VariantFields::VARIANTS => $variants,
			)
		);
	}

	/**
	 * Returns the option axes of a copy of a product, as a save gives them.
	 *
	 * A value keeps its image while the attachment exists. One deleted since is left out: the copy's
	 * save would refuse it as an image the value is given anew.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $original The original.
	 * @return list<array<string, mixed>> Each axis with its values.
	 */
	private function copiedOptions( Product $original ): array {
		return array_map(
			fn( OptionAxis $axis ): array => array(
				VariantFields::KEY      => $axis->key,
				VariantFields::LABEL    => $axis->label,
				VariantFields::POSITION => $axis->position,
				VariantFields::VALUES   => array_map(
					fn( OptionValue $value ): array => array(
						VariantFields::KEY      => $value->key,
						VariantFields::LABEL    => $value->label,
						VariantFields::POSITION => $value->position,
						VariantFields::IMAGE_ID => null !== $value->imageAttachmentId && SaveProduct::IMAGE_POST_TYPE === $this->posts->postTypeOf( $value->imageAttachmentId ) ? $value->imageAttachmentId : null,
						VariantFields::SWATCH   => $value->swatchHex,
					),
					$axis->values
				),
			),
			$original->options()->axes
		);
	}

	/**
	 * Returns the SKU a copy's variant tries: the original's with `-copy`, or `-copy-2` to `-copy-9`.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 One try's SKU, of any variant.
	 *
	 * @param Sku $original The original's SKU.
	 * @param int $copy     Which try: 1 for `-copy`, 2 to 9 for `-copy-2` to `-copy-9`.
	 * @return string The SKU, within the length a SKU may have.
	 */
	private static function copied( Sku $original, int $copy ): string {
		$suffix = 1 === $copy ? '-copy' : '-copy-' . $copy;

		return mb_substr( $original->toString(), 0, Sku::MAX_LENGTH - strlen( $suffix ), 'UTF-8' ) . $suffix;
	}
}
