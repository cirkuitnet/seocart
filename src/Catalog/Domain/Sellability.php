<?php
/**
 * Sellability: the one rule that says whether a variant may be sold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Judges a variant from its facts.
 *
 * Owns one fact: when a variant may be sold. It may when its product is complete and bound to
 * its source post, the variant has a price in the base currency, belongs to the generation the
 * product publishes and is enabled, and the source post is published, or private and the reader
 * may read private products. Otherwise the verdict is the first rule it fails, checked in this
 * order: unknown variant, incomplete, updating, not translated, no binding, no base price, not
 * the active generation, disabled, not published, private. So a product being written is
 * reported as `updating` whatever its post's status.
 *
 * Asked for a locale, the rule judges the product's post in that locale, and a product with no
 * post there is `not_translated`: it is not shown in that locale. Asked for none, it judges the
 * source post.
 *
 * This is the only place the rule is written. Readers reach it through Query\Sellability, and a
 * structural test keeps any other class from reading the marker to decide a sale.
 *
 * @since 0.1.0
 */
final class Sellability {

	/**
	 * The post status of a product anyone may buy.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PUBLISHED = 'publish';

	/**
	 * The statuses of a post a product can be sold through, to someone: published, and private to those who may read private products.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const SELLING_STATUSES = array( self::PUBLISHED, self::PRIVATE_STATUS );

	/**
	 * The post status of a product only readers of private products may buy.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PRIVATE_STATUS = 'private';

	/**
	 * Returns the verdict on one variant.
	 *
	 * @since 0.1.0
	 *
	 * @param SellabilityFacts|null $facts          The variant's facts, or null when no variant has the id asked about.
	 * @param bool                  $canReadPrivate Whether the reader may read private products.
	 * @return SellabilityReason Sellable, or the first rule the variant fails.
	 */
	public static function verdict( ?SellabilityFacts $facts, bool $canReadPrivate ): SellabilityReason {
		if ( null === $facts ) {
			return SellabilityReason::UnknownVariant;
		}

		if ( GenerationState::Incomplete === $facts->generation ) {
			return SellabilityReason::Incomplete;
		}

		if ( GenerationState::Updating === $facts->generation ) {
			return SellabilityReason::Updating;
		}

		if ( ! $facts->translated ) {
			return SellabilityReason::NotTranslated;
		}

		if ( null === $facts->sourcePostId || null === $facts->boundPostId || null === $facts->postStatus ) {
			return SellabilityReason::NoBinding;
		}

		if ( ! $facts->hasBasePrice ) {
			return SellabilityReason::NoBasePrice;
		}

		if ( $facts->variantGeneration !== $facts->activeGeneration ) {
			return SellabilityReason::NotActiveGeneration;
		}

		if ( ! $facts->variantEnabled ) {
			return SellabilityReason::VariantDisabled;
		}

		if ( self::PRIVATE_STATUS === $facts->postStatus ) {
			return $canReadPrivate ? SellabilityReason::Sellable : SellabilityReason::PrivateProduct;
		}

		return self::PUBLISHED === $facts->postStatus ? SellabilityReason::Sellable : SellabilityReason::NotPublished;
	}

	/**
	 * Returns the verdict on a post that has no variant to judge: it is not bound to a product, or its product has no variant yet.
	 *
	 * The same order holds: a product without a variant is incomplete, or `updating` while a save
	 * writes it; a post no product is bound to has no binding.
	 *
	 * @since 0.1.0
	 *
	 * @param GenerationState|null $generation The marker of the post's product, or null when no product is bound to the post.
	 * @return SellabilityReason Never Sellable.
	 */
	public static function withoutVariant( ?GenerationState $generation ): SellabilityReason {
		if ( null === $generation ) {
			return SellabilityReason::NoBinding;
		}

		return GenerationState::Updating === $generation ? SellabilityReason::Updating : SellabilityReason::Incomplete;
	}
}
