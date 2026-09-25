<?php
/**
 * SellabilityReason: whether a variant may be sold, and if not, the first reason why not
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The verdict on one variant: `sellable`, or the first rule it fails.
 *
 * Owns one fact: the names of the verdicts, which are also their wire values. Only
 * Sellability::verdict() decides which one applies; every reader shows or acts on the verdict
 * it returns, and none derives one of its own.
 *
 * @since 0.1.0
 */
enum SellabilityReason: string {

	/**
	 * The variant may be sold.
	 *
	 * @since 0.1.0
	 */
	case Sellable = 'sellable';

	/**
	 * The product is missing a commerce row a sale needs.
	 *
	 * @since 0.1.0
	 */
	case Incomplete = 'incomplete';

	/**
	 * The product is being written, or a write of it did not finish.
	 *
	 * @since 0.1.0
	 */
	case Updating = 'updating';

	/**
	 * The product has no source post that presents it.
	 *
	 * @since 0.1.0
	 */
	case NoBinding = 'no_binding';

	/**
	 * The product has no post in the language the verdict was asked for, so it is not shown there.
	 *
	 * @since 0.1.0
	 */
	case NotTranslated = 'not_translated';

	/**
	 * The variant has no price in the store's base currency.
	 *
	 * @since 0.1.0
	 */
	case NoBasePrice = 'no_base_price';

	/**
	 * The variant belongs to a generation the product does not publish.
	 *
	 * @since 0.1.0
	 */
	case NotActiveGeneration = 'not_active_generation';

	/**
	 * The variant is disabled.
	 *
	 * @since 0.1.0
	 */
	case VariantDisabled = 'variant_disabled';

	/**
	 * The product's post is not published: a draft, pending, scheduled or in the trash.
	 *
	 * @since 0.1.0
	 */
	case NotPublished = 'not_published';

	/**
	 * The product's post is private, and the reader may not read private products.
	 *
	 * @since 0.1.0
	 */
	case PrivateProduct = 'private';

	/**
	 * No variant has that id.
	 *
	 * @since 0.1.0
	 */
	case UnknownVariant = 'unknown_variant';
}
