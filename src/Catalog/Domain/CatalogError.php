<?php
/**
 * CatalogError: the error catalog of the catalog module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors the catalog raises.
 *
 * Owns one fact: how a refused catalog write is reported to a client. A SKU another variant
 * holds is a conflict (409), so a client can tell it from a SKU that is malformed. A duplicate
 * key in storage never reaches a client as a database error: the repository translates the SKU
 * key into `catalog.sku_taken`. Codes the catalog only reports, never raises, are not rows here.
 *
 * It lives beside the domain that raises most of its codes. Declaring it calls no WordPress
 * function: each message is a closure around a literal translation call, which runs only when
 * an adapter renders the error.
 *
 * @since 0.1.0
 */
enum CatalogError: string implements ErrorCode {

	/**
	 * Another variant already has the SKU; SKUs are compared without regard to case.
	 *
	 * @since 0.1.0
	 */
	case SkuTaken = 'catalog.sku_taken';

	/**
	 * The SKU is empty, too long, or holds a control or formatting character.
	 *
	 * @since 0.1.0
	 */
	case SkuInvalid = 'catalog.sku_invalid';

	/**
	 * A price was given in a currency other than the store's base currency.
	 *
	 * @since 0.1.0
	 */
	case CurrencyNotBase = 'catalog.currency_not_base';

	/**
	 * WordPress refused to write the product's post.
	 *
	 * @since 0.1.0
	 */
	case PostRejected = 'catalog.post_rejected';

	/**
	 * The post named is not a product post, or does not exist.
	 *
	 * @since 0.1.0
	 */
	case PostNotProduct = 'catalog.post_not_product';

	/**
	 * No product has the id, or its row was deleted while it was being written.
	 *
	 * @since 0.1.0
	 */
	case ProductNotFound = 'catalog.product_not_found';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::SkuTaken,
				409,
				static fn(): string =>
					/* translators: %1$s: The SKU another product already uses. */
					__( 'Another product already uses the SKU "%1$s".', 'seocart' ),
				array( 'sku' )
			),
			new ErrorDefinition(
				self::SkuInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The SKU as it was given. */
					__( 'The SKU "%1$s" is not valid: a SKU has 1 to 64 characters and no control characters.', 'seocart' ),
				array( 'sku' )
			),
			new ErrorDefinition(
				self::CurrencyNotBase,
				400,
				static fn(): string =>
					/* translators: 1: The currency the price was given in, such as EUR. 2: The store's base currency, such as USD. */
					__( 'A product price must be in the store\'s base currency, %2$s, not %1$s.', 'seocart' ),
				array( 'currency', 'base_currency' )
			),
			new ErrorDefinition(
				self::PostRejected,
				400,
				static fn(): string =>
					/* translators: %1$s: The error code WordPress gave, such as empty_content. */
					__( 'WordPress did not save the product\'s post (%1$s).', 'seocart' ),
				array( 'wordpress_code' )
			),
			new ErrorDefinition(
				self::PostNotProduct,
				400,
				static fn(): string =>
					/* translators: %1$s: A post ID. */
					__( 'Post %1$s is not a product.', 'seocart' ),
				array( 'post_id' )
			),
			new ErrorDefinition(
				self::ProductNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: A product ID. */
					__( 'Product %1$s does not exist.', 'seocart' ),
				array( 'product_id' )
			),
		);
	}
}
