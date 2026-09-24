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
 * order: unknown variant, incomplete, updating, no binding, no base price, not the active
 * generation, disabled, not published, private. So a product being written is reported as
 * `updating` whatever its post's status.
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
}
