<?php
/**
 * CatalogTables: the declarations of the catalog's tables
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
 * Declares `products`, `product_posts`, `variants`, `variant_prices`, the option tables `option_axes`, `option_values` and `variant_option_values`, and the two tables derived from them, `product_price_projections` and `product_search_index`.
 *
 * Owns one fact: the shape of the catalog's storage. The migration that creates the tables and
 * the data registry that lists them call these factories, so each table is declared once. The
 * keys are the invariants: one binding per product per locale, one product per source post, a
 * SKU used once in the store (in the table's collation, so without regard to case), one price
 * row per variant and currency, one axis per product and key, one value per axis and key, and one
 * selected value per variant and value. `products.updated_at` keeps microseconds, so a statement
 * that rewrites the generation marker always changes the row and reports it. `variant_prices`
 * holds money and is classified `financial`, as are the price projections; the others hold nothing
 * personal. The rows of all nine live as long as the product they belong to and are removed by the
 * service that deletes it. The two derived tables are rebuilt from the others by the catalog's
 * commands, never read as the truth of a price or a word.
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
	 * The unprefixed name of the price projections table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRICE_PROJECTIONS = 'product_price_projections';

	/**
	 * The unprefixed name of the search index table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SEARCH_INDEX = 'product_search_index';

	/**
	 * The unprefixed name of the option axes table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTION_AXES = 'option_axes';

	/**
	 * The unprefixed name of the option values table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTION_VALUES = 'option_values';

	/**
	 * The unprefixed name of the table of the option values each variant stands for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANT_OPTION_VALUES = 'variant_option_values';

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
	 * Returns the nine declarations, parents before children, the derived tables last.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The price projections, the search index and the three option tables.
	 *
	 * @return list<TableDefinition> `products`, `product_posts`, `variants`, `variant_prices`, `option_axes`, `option_values`,
	 *                               `variant_option_values`, `product_price_projections`, `product_search_index`.
	 */
	public static function all(): array {
		return array(
			self::products(),
			self::productPosts(),
			self::variants(),
			self::variantPrices(),
			self::optionAxes(),
			self::optionValues(),
			self::variantOptionValues(),
			self::priceProjections(),
			self::searchIndex(),
		);
	}

	/**
	 * Returns the unprefixed names of the nine tables, which a statement names as tokens such as `{variants}`.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names, in the order all() declares them.
	 */
	public static function names(): array {
		return array( self::PRODUCTS, self::PRODUCT_POSTS, self::VARIANTS, self::VARIANT_PRICES, self::OPTION_AXES, self::OPTION_VALUES, self::VARIANT_OPTION_VALUES, self::PRICE_PROJECTIONS, self::SEARCH_INDEX );
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
				new ColumnSpec( 'stock_summary', 'varchar(16)', $public, 'A summary of its variants\' stock for listings: in_stock, out_of_stock or unknown, written by the stock summary projector and `wp seocart catalog summarize-stock`.', defaultValue: 'unknown', collation: 'ascii_bin' ),
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
				'products -> variants'      => 'Deleting a product removes its variants, the option values they select and its option axes and values first, in the same transaction. A product with no enabled variant is incomplete.',
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
				self::variantLabel(),
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
	 * Declares `variants.label`: the variant's option values, by their labels in axis order.
	 *
	 * The variants declaration ends with it, and the migration that adds it to a store installed
	 * before it adds this same column.
	 *
	 * @since 0.2.0
	 *
	 * @return ColumnSpec The column.
	 */
	public static function variantLabel(): ColumnSpec {
		return new ColumnSpec( 'label', 'varchar(255)', Classification::Public, 'The labels of the option values the variant stands for, in the order of their axes, such as "250 g / Whole bean"; NULL for a product without options.', nullable: true );
	}

	/**
	 * Declares `option_axes`: the option axes of a product, such as Size or Grind.
	 *
	 * An axis's key is fixed when the axis is created and is part of every combination hash of the
	 * product's variants; its label is the name a shopper reads and may change.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function optionAxes(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::OPTION_AXES,
			self::MODULE,
			'Holds the option axes of each product, such as Size or Grind: the axis\'s fixed key, which its variants\' combinations are built from, its label and its place among the product\'s axes.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'product_id', 'bigint unsigned', $public, 'The product the axis belongs to.' ),
				new ColumnSpec( 'axis_key', 'varchar(32)', $public, 'The axis\'s key: lower-case letters, digits, "-" and "_"; fixed once the axis exists, and part of the combination hash of every variant of the product.', collation: 'ascii_bin' ),
				new ColumnSpec( 'label', 'varchar(100)', $public, 'The axis\'s name as a shopper reads it, such as Size, in the language of the product\'s source post.' ),
				new ColumnSpec( 'position', 'int unsigned', $public, 'Where the axis sorts among its product\'s.', defaultValue: '0' ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the axis was created, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime', $public, 'When the row last changed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'product_axis', array( 'product_id', 'axis_key' ), 'A product has one axis per key; also finds a product\'s axes.' ),
			),
			array(),
			self::RETENTION,
			array(
				'option_axes -> products' => 'Deleting a product deletes its axes in the same transaction. An axis whose product no longer exists is reported by doctor, never repaired.',
			)
		);
	}

	/**
	 * Declares `option_values`: the values of an option axis, such as 250 g and 1 kg.
	 *
	 * A value's key is fixed when the value is created and is part of the combination hash of every
	 * variant that selects it; its label, position, image and swatch may change.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function optionValues(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::OPTION_VALUES,
			self::MODULE,
			'Holds the values of each option axis, such as 250 g and 1 kg: the value\'s fixed key, its label, its place among the axis\'s values, and the image and colour swatch a shopper picks it by.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'axis_id', 'bigint unsigned', $public, 'The axis the value belongs to.' ),
				new ColumnSpec( 'value_key', 'varchar(32)', $public, 'The value\'s key: lower-case letters, digits, "-" and "_"; fixed once the value exists, and part of the combination hash of every variant that selects it.', collation: 'ascii_bin' ),
				new ColumnSpec( 'label', 'varchar(100)', $public, 'The value\'s name as a shopper reads it, such as 250 g, in the language of the product\'s source post.' ),
				new ColumnSpec( 'position', 'int unsigned', $public, 'Where the value sorts among its axis\'s.', defaultValue: '0' ),
				new ColumnSpec( 'image_attachment_id', 'bigint unsigned', $public, 'The Media Library attachment that shows the value; NULL for none. Nothing clears it when the attachment is deleted: doctor reports a value whose attachment is gone.', nullable: true ),
				new ColumnSpec( 'swatch_hex', 'char(7)', $public, 'The colour that shows the value, as #rrggbb in lower case; NULL for none.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the value was created, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime', $public, 'When the row last changed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'axis_value', array( 'axis_id', 'value_key' ), 'An axis has one value per key; also finds an axis\'s values.' ),
			),
			array(),
			self::RETENTION,
			array(
				'option_values -> option_axes' => 'Deleting an axis deletes its values in the same transaction. A value whose axis no longer exists is reported by doctor, never repaired.',
				'option_values -> attachments' => 'A value whose image attachment no longer exists shows no image and is reported by doctor; nothing clears the column.',
			)
		);
	}

	/**
	 * Declares `variant_option_values`: the option value a variant selects on each axis of its product.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function variantOptionValues(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::VARIANT_OPTION_VALUES,
			self::MODULE,
			'Holds, for each variant of a product with options, the option value it selects on each of the product\'s axes.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'variant_id', 'bigint unsigned', $public, 'The variant.' ),
				new ColumnSpec( 'option_value_id', 'bigint unsigned', $public, 'The value it selects.' ),
				new ColumnSpec( 'axis_id', 'bigint unsigned', $public, 'The axis of the value, so the value a variant selects on an axis is found by key.' ),
			),
			array( 'variant_id', 'option_value_id' ),
			array(),
			array(
				IndexSpec::key( 'option_value', array( 'option_value_id' ), 'Finds the variants that select a value, when the value is deleted and for doctor.' ),
				IndexSpec::key( 'variant_axis', array( 'variant_id', 'axis_id' ), 'Finds the value a variant selects on an axis.' ),
			),
			self::RETENTION,
			array(
				'variant_option_values -> variants'      => 'Deleting a product deletes its variants\' rows here in the same transaction. A row whose variant no longer exists is reported by doctor, never repaired.',
				'variant_option_values -> option_values' => 'Deleting a value deletes the rows that select it in the same transaction. A row whose value no longer exists is reported by doctor, never repaired.',
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

	/**
	 * Declares `product_price_projections`: the price each product sorts by in each currency the store sells in.
	 *
	 * Derived: PriceProjector writes it from the product's prices and the current exchange rates, and
	 * `wp seocart catalog project-prices` rebuilds it. A product has a row in a currency only when it
	 * can be sold there, so a price order lists only what it can price. The covering key on the
	 * currency, the price and the product is the whole of what a price order reads of the table.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function priceProjections(): TableDefinition {
		$financial = Classification::Financial;

		return new TableDefinition(
			self::PRICE_PROJECTIONS,
			self::MODULE,
			'Holds, for each product and each currency the store sells in, the price its listed variant sorts by in a price order; derived from its prices and the exchange rates, and rebuilt by `wp seocart catalog project-prices`.',
			MutationPattern::Derived,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', $financial, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'product_id', 'bigint unsigned', $financial, 'The product.' ),
				new ColumnSpec( 'currency', 'char(3)', $financial, 'ISO 4217 code of the price\'s currency, in upper case.', collation: 'ascii_bin' ),
				new ColumnSpec( 'sort_price_minor', 'bigint', $financial, 'The listed variant\'s price in the currency, in minor units: the price authored in it, or the base price converted at the current rate.' ),
				new ColumnSpec( 'amount_basis', 'varchar(5)', $financial, 'net or gross, as the price it comes from was authored.', collation: 'ascii_bin' ),
				new ColumnSpec( 'has_explicit_price', 'tinyint(1)', $financial, '1 when the price was authored in the currency; 0 when it is the base price converted.' ),
				new ColumnSpec( 'rate_version', 'bigint unsigned', $financial, 'The exchange-rate version a converted price was converted at; NULL for an authored price.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime', $financial, 'When the row was first written, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $financial, 'When the price was last computed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'product_currency', array( 'product_id', 'currency' ), 'A product has one sort price per currency; also finds a product\'s rows.' ),
			),
			array(
				IndexSpec::key( 'currency_price', array( 'currency', 'sort_price_minor', 'product_id' ), 'Walks a currency\'s products in price order, either way, holding everything a price order reads of the table.' ),
				IndexSpec::key( 'stale_converted', array( 'has_explicit_price', 'rate_version' ), 'Finds the converted prices behind the current exchange-rate version.' ),
			),
			self::RETENTION,
			array(
				'products -> product_price_projections' => 'Deleting a product deletes its rows in the same transaction. A row whose product no longer exists is deleted by doctor --repair.',
			)
		);
	}

	/**
	 * Declares `product_search_index`: one document of searchable words per product and locale.
	 *
	 * Derived: SearchDocumentProjector writes it from the product's posts and variants, and
	 * `wp seocart catalog index-products` rebuilds it. The document is in the site's collation, so its
	 * words match whatever their case; its fulltext key is what a search ordered by relevance reads.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function searchIndex(): TableDefinition {
		$public = Classification::Public;

		return new TableDefinition(
			self::SEARCH_INDEX,
			self::MODULE,
			'Holds, for each product and each locale it has a post in, the words a search ordered by relevance matches: the post\'s title and excerpt and the SKUs of its enabled variants; derived from them, and rebuilt by `wp seocart catalog index-products`.',
			MutationPattern::Derived,
			array(
				new ColumnSpec( 'product_id', 'bigint unsigned', $public, 'The product.' ),
				new ColumnSpec( 'locale', 'varchar(' . ProductPostBinding::LOCALE_MAX_LENGTH . ')', $public, 'The WordPress locale of the post the words come from, such as en_US.', collation: 'ascii_bin' ),
				new ColumnSpec( 'search_document', 'text', $public, 'The words: the title, the excerpt and the SKUs, without markup, in the site\'s collation.' ),
				new ColumnSpec( 'created_at', 'datetime', $public, 'When the row was first written, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', $public, 'When the words were last written, UTC.' ),
			),
			array( 'product_id', 'locale' ),
			array(),
			array(
				IndexSpec::fulltext( 'search_document', array( 'search_document' ), 'Finds, and ranks, the documents that hold every word of a search ordered by relevance.' ),
			),
			self::RETENTION,
			array(
				'products -> product_search_index' => 'Deleting a product deletes its documents in the same transaction. A document whose product no longer exists is deleted by doctor --repair.',
			)
		);
	}
}
