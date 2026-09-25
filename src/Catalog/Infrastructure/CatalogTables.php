<?php
/**
 * CatalogTables: the declarations of the catalog's four tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `products`, `product_posts`, `variants` and `variant_prices`.
 *
 * Owns one fact: the shape of the catalog's storage. The migration that creates the tables and
 * the data registry that lists them call these factories, so each table is declared once. The
 * keys are the invariants: one binding per product per locale, one product per source post, a
 * SKU used once in the store (in the table's collation, so without regard to case) and one price
 * row per variant and currency. `products.updated_at` keeps microseconds, so a statement that
 * rewrites the generation marker always changes the row and reports it. `variant_prices` holds
 * money and is classified `financial`; the other three hold nothing personal. The rows of all
 * four live as long as the product they belong to and are removed by the service that deletes it.
 *
 * @since 0.1.0
 */
final class CatalogTables {

	/**
	 * The unprefixed name of the products table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PRODUCTS = 'products';

	/**
	 * The unprefixed name of the bindings table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PRODUCT_POSTS = 'product_posts';

	/**
	 * The unprefixed name of the variants table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VARIANTS = 'variants';

	/**
	 * The unprefixed name of the prices table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VARIANT_PRICES = 'variant_prices';

	/**
	 * The owning module, as every declaration names it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Catalog';

	/**
	 * The retention policy of every catalog row: it lives as long as its product.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RETENTION = 'entity_lifetime';

	/**
	 * Returns the four declarations, parents before children.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> `products`, `product_posts`, `variants`, `variant_prices`.
	 */
	public static function all(): array {
		return array( self::products(), self::productPosts(), self::variants(), self::variantPrices() );
	}

	/**
	 * Declares `products`: one row per product, its commerce identity and its generation marker.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function products(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::PRODUCTS,
			self::MODULE,
			'Holds each product\'s commerce identity, independent of the posts that present it, with its generation marker and the generation of variants it publishes.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', $public, 'The public identifier, a lowercase UUID.', collation: 'ascii_bin' ),
				new ColumnSpec( 'source_post_id', 'bigint unsigned', $public, 'The post of the source binding, which controls whether the product exists; NULL when it has none.', nullable: true ),
				new ColumnSpec( 'kind', 'varchar(20)', $public, 'What kind of product it is; every product is standard for now.', defaultValue: 'standard', collation: 'ascii_bin' ),
				new ColumnSpec( 'purchasability_mode', 'varchar(16)', $public, 'How a shopper acquires it; every product is bought for now.', defaultValue: 'buy', collation: 'ascii_bin' ),
				new ColumnSpec( 'generation_state', 'varchar(12)', $public, 'The generation marker: incomplete, updating or complete. Only a complete product may be sold.', defaultValue: 'incomplete', collation: 'ascii_bin' ),
				new ColumnSpec( 'active_variant_generation', 'int unsigned', $public, 'The generation of variants the product publishes; 0 before it publishes any.', defaultValue: '0' ),
				new ColumnSpec( 'visibility', 'varchar(12)', $public, 'Where the product is listed; not yet written.', defaultValue: 'hidden', collation: 'ascii_bin' ),
				new ColumnSpec( 'stock_summary', 'varchar(16)', $public, 'A summary of its variants\' stock for listings; not yet written.', defaultValue: 'unknown', collation: 'ascii_bin' ),
				new ColumnSpec( 'variant_count', 'int unsigned', $public, 'How many variants the published generation has.', defaultValue: '0' ),
				new ColumnSpec( 'enabled_variant_count', 'int unsigned', $public, 'How many of them are enabled.', defaultValue: '0' ),
				new ColumnSpec( 'default_amount_basis', 'varchar(5)', $public, 'Whether its prices are authored net or gross by default.', defaultValue: 'net', collation: 'ascii_bin' ),
				new ColumnSpec( 'tax_class_id', 'bigint unsigned', $public, 'The tax class its prices are taxed by; not yet written.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the product was created, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the row last changed, UTC, from the database clock. Microseconds, so that every rewrite of the marker changes the row.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'A public identifier names one product.' ),
				IndexSpec::unique( 'source_post_id', array( 'source_post_id' ), 'A post is the source binding of at most one product, so two writers binding one post cannot both succeed.' ),
			),
			array(
				IndexSpec::key( 'generation_state', array( 'generation_state' ), 'Finds the products that are incomplete, or were left updating.' ),
			),
			self::RETENTION,
			array(
				'products -> variants'      => 'Deleting a product removes its variants first, in the same transaction. A product with no variant is incomplete.',
				'products -> product_posts' => 'Deleting the source post deletes the product; deleting any other binding removes that binding only. A product with no binding at all is reported by doctor, never repaired: whether one may be deleted waits for order history. A product whose source binding is missing or invalid is reported for a person to decide, never repaired. A non-source binding whose post is gone is deleted by doctor --repair. A post written by a path that never bound one is bound, inline by the lifecycle or by doctor --repair when it was not, to a new, incomplete product.',
			)
		);
	}

	/**
	 * Declares `product_posts`: one row per post that presents a product, with the locale of its content.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function productPosts(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::PRODUCT_POSTS,
			self::MODULE,
			'Binds each post that presents a product to that product, one post per product and locale.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'post_id', 'bigint unsigned', $public, 'The post; a post presents at most one product.' ),
				new ColumnSpec( 'product_id', 'bigint unsigned', $public, 'The product it presents.' ),
				new ColumnSpec( 'locale', 'varchar(' . ProductPostBinding::LOCALE_MAX_LENGTH . ')', $public, 'The WordPress locale of the post\'s content, such as en_US; the site locale on a store in one language.', collation: 'ascii_bin' ),
				new ColumnSpec( 'visibility_override', 'varchar(12)', $public, 'Where this post lists the product, when it differs from the product; not yet written.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'linked_at', 'datetime', $public, 'When the post was bound to the product, UTC.' ),
				new ColumnSpec( 'linked_by_adapter', 'varchar(32)', $public, 'The multilingual adapter that bound it; NULL when the store bound it itself.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the row was written, UTC.' ),
			),
			array( 'post_id' ),
			array(
				IndexSpec::unique( 'product_locale', array( 'product_id', 'locale' ), 'A product has at most one post per locale; also finds every binding of a product.' ),
			),
			array(),
			self::RETENTION,
			array(
				'posts -> product_posts' => 'A binding whose post no longer exists is removed by doctor --repair, except the source binding, which is reported for a person to decide.',
			)
		);
	}

	/**
	 * Declares `variants`: one row per sellable combination of a product.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function variants(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::VARIANTS,
			self::MODULE,
			'Holds each variant of a product: its SKU, the option combination it stands for and the generation it belongs to.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key; cart lines, order lines and stock items name a variant by it.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', $public, 'The public identifier, a lowercase UUID.', collation: 'ascii_bin' ),
				new ColumnSpec( 'product_id', 'bigint unsigned', $public, 'The product the variant belongs to.' ),
				new ColumnSpec( 'sku', 'varchar(64)', $public, 'The SKU, trimmed, as the merchant typed it; unique without regard to case.' ),
				new ColumnSpec( 'combination_hash', 'char(64)', $public, 'SHA-256 of the option values the variant stands for; that of the empty combination for a product without options.', collation: 'ascii_bin' ),
				new ColumnSpec( 'generation', 'int unsigned', $public, 'The generation the variant was written in; readers see only the generation the product publishes.' ),
				new ColumnSpec( 'is_enabled', 'tinyint(1)', $public, '1 when the variant may be sold.', defaultValue: '1' ),
				new ColumnSpec( 'weight_grams', 'int', $public, 'The weight in grams; NULL when none is given.', nullable: true ),
				new ColumnSpec( 'position', 'int unsigned', $public, 'Where the variant sorts among its product\'s.', defaultValue: '0' ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the variant was created, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime', $public, 'When the row last changed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'A public identifier names one variant.' ),
				IndexSpec::unique( 'sku', array( 'sku' ), 'A SKU names one variant in the whole store, without regard to case.' ),
				IndexSpec::unique( 'product_combination', array( 'product_id', 'combination_hash' ), 'A product has one variant per option combination, which keeps its id across being disabled and enabled.' ),
			),
			array(
				IndexSpec::key( 'product_generation', array( 'product_id', 'generation', 'is_enabled', 'position' ), 'Reads the enabled variants of the generation a product publishes, in order.' ),
			),
			self::RETENTION,
			array(
				'variants -> variant_prices' => 'Deleting a variant removes its prices. A variant without a price in the base currency leaves its product incomplete.',
				'variants -> products'       => 'A variant whose product no longer exists is reported by doctor, never repaired: the variant id may already be on a ledger row.',
				'variants -> stock items'    => 'Deleting a variant removes its stock item; its stock ledger rows are kept. A variant without a stock item is given one at zero by doctor --repair. A stock item whose variant no longer exists is reported by doctor, never repaired: deleting it would write a tombstone to the stock ledger.',
			)
		);
	}

	/**
	 * Declares `variant_prices`: one authored price per variant and currency.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function variantPrices(): TableDefinition {
		$financial = Classification::Financial;

		return new TableDefinition(
			self::VARIANT_PRICES,
			self::MODULE,
			'Holds the prices a merchant authors for each variant, one row per currency; a price in another currency than the base one is never derived into this table.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $financial, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', $financial, 'The variant the price belongs to.' ),
				new ColumnSpec( 'currency', 'char(3)', $financial, 'ISO 4217 code of the price\'s currency, in upper case.', collation: 'ascii_bin' ),
				new ColumnSpec( 'amount_basis', 'varchar(5)', $financial, 'net when the amounts exclude tax, gross when they include it.', collation: 'ascii_bin' ),
				new ColumnSpec( 'price_minor', 'bigint', $financial, 'The price, in minor units of the currency.' ),
				new ColumnSpec( 'compare_at_minor', 'bigint', $financial, 'The price it is compared with, in minor units; NULL when there is none.', nullable: true ),
				new ColumnSpec( 'tax_class_id', 'bigint unsigned', $financial, 'The tax class the price is taxed by; not yet written.', nullable: true ),
				new ColumnSpec( 'updated_by', 'bigint unsigned', $financial, 'The user who last set the price; NULL when not recorded.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime', $financial, 'When the price was first set, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime', $financial, 'When the price last changed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'variant_currency', array( 'variant_id', 'currency' ), 'A variant has one authored price per currency; also finds a variant\'s prices.' ),
			),
			array(),
			self::RETENTION,
			array(
				'variant_prices -> variants' => 'A price whose variant no longer exists is reported, never repaired.',
			)
		);
	}
}
