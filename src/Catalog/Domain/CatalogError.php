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
 * Owns one fact: how a refused catalog write, or a refused page of the product listing, is
 * reported to a client. A SKU another variant
 * holds is a conflict (409), so a client can tell it from a SKU that is malformed. A duplicate
 * key in storage never reaches a client as a database error: the repository translates the SKU
 * key into `catalog.sku_taken`, with the combination of the variant that asked for it in the
 * error's details, so an editor can mark the row. A variant set that cannot be saved as given is
 * refused before anything is written, with what makes it so: the combination, the counts and their
 * limits, the currency. Codes the catalog only reports, never raises, are not rows here.
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
	 * The SKU is empty, too long, holds a control or formatting character, or starts with "~", which marks a variant whose SKU another variant took; or a variant that is new or enabled was given none.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A leading "~", and a variant without a SKU.
	 */
	case SkuInvalid = 'catalog.sku_invalid';

	/**
	 * A price was given in a currency other than the store's base currency.
	 *
	 * @since 0.1.0
	 */
	case CurrencyNotBase = 'catalog.currency_not_base';

	/**
	 * A variant's price was given in a currency the store does not offer.
	 *
	 * @since 0.2.0
	 */
	case CurrencyNotOffered = 'catalog.currency_not_offered';

	/**
	 * A variant was given two prices in one currency.
	 *
	 * @since 0.2.0
	 */
	case PriceDuplicate = 'catalog.price_duplicate';

	/**
	 * A product was given more option axes, or more option values in all, than a product may have.
	 *
	 * @since 0.2.0
	 */
	case TooManyOptions = 'catalog.too_many_options';

	/**
	 * An option axis or value was given a key, a label, a position, an image or a swatch it may not have, or two axes, or two values of an axis, one key.
	 *
	 * @since 0.2.0
	 */
	case OptionInvalid = 'catalog.option_invalid';

	/**
	 * A variant's combination does not name every option axis of the product once, with one of its values.
	 *
	 * @since 0.2.0
	 */
	case CombinationInvalid = 'catalog.combination_invalid';

	/**
	 * Two variants of one save name the same combination.
	 *
	 * @since 0.2.0
	 */
	case CombinationDuplicate = 'catalog.combination_duplicate';

	/**
	 * A save gave a product more variants than one save may write.
	 *
	 * @since 0.2.0
	 */
	case TooManyVariants = 'catalog.too_many_variants';

	/**
	 * Two variants of one save were given one SKU; SKUs are compared without regard to case.
	 *
	 * @since 0.2.0
	 */
	case SkuDuplicate = 'catalog.sku_duplicate';

	/**
	 * A save gave the single SKU, price and weight of a product without options together with a list of variants, or for a product with options.
	 *
	 * @since 0.2.0
	 */
	case VariantsAmbiguous = 'catalog.variants_ambiguous';

	/**
	 * WordPress refused to write the product's post, or its database refused the update (`db_update_error`),
	 * as MariaDB does when another request changed the post since the save first read it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The message says what `db_update_error` means.
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
	 * Another write bound the post, or changed the product's generation marker, while this save was writing.
	 *
	 * @since 0.1.0
	 */
	case WriteConflict = 'catalog.write_conflict';

	/**
	 * The post already presents another product, so it cannot present this one too.
	 *
	 * @since 0.1.0
	 */
	case PostBoundElsewhere = 'catalog.post_bound_elsewhere';

	/**
	 * The product already has a post in that locale; a product has one post per locale.
	 *
	 * @since 0.1.0
	 */
	case LocaleTaken = 'catalog.locale_taken';

	/**
	 * The post is its product's source post, which is not unlinked until another of the product's posts is made the source.
	 *
	 * @since 0.1.0
	 */
	case SourceBindingKept = 'catalog.source_binding_kept';

	/**
	 * The product's source post changed while another was being made the source, or the post named does not present the product.
	 *
	 * @since 0.1.0
	 */
	case PromotionConflict = 'catalog.promotion_conflict';

	/**
	 * The locale is not one the site publishes in.
	 *
	 * @since 0.1.0
	 */
	case LocaleUnsupported = 'catalog.locale_unsupported';

	/**
	 * A save gave a post that presents a product another locale than the one it presents it in; a post's locale is given when it is first saved, and changed in the multilingual plugin.
	 *
	 * @since 0.1.0
	 */
	case LocaleFixed = 'catalog.locale_fixed';

	/**
	 * A listing was filtered by a category, brand or tag slug that no term of its taxonomy has.
	 *
	 * @since 0.2.0
	 */
	case TermNotFound = 'catalog.term_not_found';

	/**
	 * A listing's search held more words than a search may, or was not valid UTF-8 text.
	 *
	 * @since 0.2.0
	 */
	case SearchInvalid = 'catalog.search_invalid';

	/**
	 * A listing was asked for a currency the store does not sell in, or for a code that is no currency.
	 *
	 * @since 0.2.0
	 */
	case CurrencyUnsupported = 'catalog.currency_unsupported';

	/**
	 * A listing's price range had its lowest price above its highest.
	 *
	 * @since 0.2.0
	 */
	case PriceRangeInvalid = 'catalog.price_range_invalid';

	/**
	 * A listing was asked for an order it offers only with a search, without one.
	 *
	 * @since 0.2.0
	 */
	case OrderUnavailable = 'catalog.order_unavailable';

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
				array( 'sku' ),
				details: array( 'combination' )
			),
			new ErrorDefinition(
				self::SkuInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The SKU as it was given. */
					__( 'The SKU "%1$s" is not valid: a SKU has 1 to 64 characters, no control characters and no leading "~", and a new or enabled variant must have one.', 'seocart' ),
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
				self::CurrencyNotOffered,
				400,
				static fn(): string =>
					/* translators: %1$s: The currency the price was given in, such as EUR. */
					__( 'The store does not offer %1$s, so a variant cannot have a price in it.', 'seocart' ),
				array( 'currency' )
			),
			new ErrorDefinition(
				self::PriceDuplicate,
				400,
				static fn(): string =>
					/* translators: 1: A variant's combination, such as size=250g, grind=whole. 2: A currency, such as EUR. */
					__( 'The variant %1$s was given two prices in %2$s; a variant has one price per currency.', 'seocart' ),
				array( 'combination', 'currency' )
			),
			new ErrorDefinition(
				self::TooManyOptions,
				400,
				static fn(): string =>
					/* translators: 1: The number of option axes given. 2: The number of option values given, in all. 3: The most axes a product may have. 4: The most values a product may have, in all. */
					__( 'A product may have at most %3$s option axes and %4$s option values in all; %1$s axes and %2$s values were given.', 'seocart' ),
				array( 'axes', 'values', 'most_axes', 'most_values' )
			),
			new ErrorDefinition(
				self::OptionInvalid,
				400,
				static fn(): string =>
					/* translators: 1: What was refused, such as axis key, value label or swatch. 2: The value given, or the key of what it names. */
					__( 'The option\'s %1$s "%2$s" is not valid: a key has 1 to 32 lower-case letters, digits, "-" or "_" and is used once; a label has 1 to 100 characters; a swatch is written #rrggbb in lower case; a position is not negative; an image is a file of the media library.', 'seocart' ),
				array( 'field', 'value' )
			),
			new ErrorDefinition(
				self::CombinationInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: A variant's combination as given, such as size=250g, grind=whole. */
					__( 'The combination "%1$s" is not one of the product\'s: a variant names every option axis of the product once, with one of that axis\'s values.', 'seocart' ),
				array( 'combination' )
			),
			new ErrorDefinition(
				self::CombinationDuplicate,
				400,
				static fn(): string =>
					/* translators: %1$s: A variant's combination, such as size=250g, grind=whole. */
					__( 'Two variants were given the combination "%1$s"; a product has one variant per combination.', 'seocart' ),
				array( 'combination' )
			),
			new ErrorDefinition(
				self::TooManyVariants,
				400,
				static fn(): string =>
					/* translators: 1: The number of variants given. 2: The most variants one save may write. */
					__( 'A save may write at most %2$s variants of a product; %1$s were given.', 'seocart' ),
				array( 'count', 'limit' )
			),
			new ErrorDefinition(
				self::SkuDuplicate,
				400,
				static fn(): string =>
					/* translators: 1: The SKU. 2: The combinations of the variants that were given it. */
					__( 'The SKU "%1$s" was given to more than one variant (%2$s); a SKU names one variant.', 'seocart' ),
				array( 'sku', 'combinations' )
			),
			new ErrorDefinition(
				self::VariantsAmbiguous,
				400,
				static fn(): string => __( 'Give a product\'s variants either as the single SKU, price and weight of a product without options, or as the list of variants, not both; a product with options takes the list.', 'seocart' )
			),
			new ErrorDefinition(
				self::PostRejected,
				400,
				static fn(): string =>
					/* translators: %1$s: The error code WordPress gave, such as empty_content. */
					__( 'WordPress did not save the product\'s post (%1$s), so nothing was saved. db_update_error: the database refused the update, as it does when another request changed the post meanwhile: save it again.', 'seocart' ),
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
			new ErrorDefinition(
				self::WriteConflict,
				409,
				static fn(): string =>
					/* translators: %1$s: A post ID. */
					__( 'The product of post %1$s was changed by another save while this one was being written; nothing was saved. Try again.', 'seocart' ),
				array( 'post_id' )
			),
			new ErrorDefinition(
				self::PostBoundElsewhere,
				409,
				static fn(): string =>
					/* translators: 1: A post ID. 2: A product ID. */
					__( 'Post %1$s already presents product %2$s.', 'seocart' ),
				array( 'post_id', 'product_id' )
			),
			new ErrorDefinition(
				self::LocaleTaken,
				409,
				static fn(): string =>
					/* translators: 1: A product ID. 2: A locale, such as de_DE. */
					__( 'Product %1$s already has a post in %2$s.', 'seocart' ),
				array( 'product_id', 'locale' )
			),
			new ErrorDefinition(
				self::SourceBindingKept,
				409,
				static fn(): string =>
					/* translators: %1$s: A post ID. */
					__( 'Post %1$s is its product\'s source post; make another of its posts the source first.', 'seocart' ),
				array( 'post_id' )
			),
			new ErrorDefinition(
				self::PromotionConflict,
				409,
				static fn(): string =>
					/* translators: 1: A product ID. 2: A post ID. */
					__( 'Post %2$s could not become the source post of product %1$s: the source changed meanwhile, or the post does not present the product.', 'seocart' ),
				array( 'product_id', 'post_id' )
			),
			new ErrorDefinition(
				self::LocaleUnsupported,
				400,
				static fn(): string =>
					/* translators: %1$s: A locale, such as de_DE. */
					__( 'The site does not publish in %1$s.', 'seocart' ),
				array( 'locale' )
			),
			new ErrorDefinition(
				self::LocaleFixed,
				409,
				static fn(): string =>
					/* translators: 1: A post ID. 2: A locale, such as de_DE. */
					__( 'Post %1$s presents its product in another locale than %2$s; change its language in the multilingual plugin.', 'seocart' ),
				array( 'post_id', 'locale' )
			),
			new ErrorDefinition(
				self::TermNotFound,
				404,
				static fn(): string =>
					/* translators: 1: The filter, category, brand or tag. 2: The slug as it was given. */
					__( 'No %1$s has the slug "%2$s".', 'seocart' ),
				array( 'filter', 'slug' )
			),
			new ErrorDefinition(
				self::SearchInvalid,
				400,
				static fn(): string =>
					/* translators: %1$s: The most words a search may hold. */
					__( 'A search may hold at most %1$s words, in valid UTF-8 text.', 'seocart' ),
				array( 'most' )
			),
			new ErrorDefinition(
				self::CurrencyUnsupported,
				400,
				static fn(): string =>
					/* translators: %1$s: The currency code as it was given, such as EUR. */
					__( 'The store does not sell in %1$s.', 'seocart' ),
				array( 'currency' )
			),
			new ErrorDefinition(
				self::PriceRangeInvalid,
				400,
				static fn(): string =>
					/* translators: 1: The lowest price asked for, in minor units. 2: The highest price asked for, in minor units. */
					__( 'The lowest price, %1$s, is above the highest, %2$s.', 'seocart' ),
				array( 'min_price_minor', 'max_price_minor' )
			),
			new ErrorDefinition(
				self::OrderUnavailable,
				400,
				static fn(): string =>
					/* translators: %1$s: The order asked for, such as relevance. */
					__( 'Products are ordered by %1$s only for a search: give the words to search for.', 'seocart' ),
				array( 'orderby' )
			),
		);
	}
}
