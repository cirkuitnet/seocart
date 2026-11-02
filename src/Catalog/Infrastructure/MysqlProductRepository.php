<?php
/**
 * MysqlProductRepository: products in the catalog's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionMatrix;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Domain\VariantSelection;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Stores products in `products`, `product_posts`, `variants`, `variant_prices` and the option tables, through Database.
 *
 * Owns one fact: how the Product aggregate maps onto the catalog's rows, and the one query the
 * sellability rule is fed by. It is the only class that reads or writes `generation_state`, and
 * it decides a sale on it in one place, sellabilityFacts(); the other reads load the marker as
 * state, and the only statements that change it are the creation of a product, markUpdating(),
 * relock(), leaveUpdating() and restoreMark(). Every write of a product's `updated_at` moves it
 * strictly forward, so the instant of a mark identifies that mark. A deletion takes the product's
 * row lock first, as a save's window does, reads the product with locking reads, then removes its
 * rows children first.
 *
 * A product loads with the variants of the generation it publishes, each with every price
 * authored for it, in one statement; a product with options, or without a variant, also loads
 * its axes with their values in one, and the values its variants select in one. A save writes the
 * whole set of variants as one multi-row statement whatever their number, through the
 * `product_combination` key, and publishes their generation by one conditional statement on the
 * generation it loaded, after every row of the set is written, so no reader outside the
 * transaction ever sees part of a set. A multi-row statement holds at most ROWS_PER_STATEMENT
 * rows; a longer set is sent in as many statements.
 *
 * Every statement that locks catalog rows takes them in one order, whichever path sends it:
 *
 * 1. `products` rows, in ascending order of id when there are several: a save's mark and relock,
 *    lock(), and lockWithPost() and lockByPost(), which name a post's product with a plain read,
 *    lock it, and only then read the post's binding again with a locking read;
 * 2. `product_posts` rows: the bindings a locking load reads, and every binding written, always
 *    under its product's row lock, so a binding read under that lock stays as read;
 * 3. `variants`, `variant_prices` and the option rows: the variants and options a locking load
 *    reads, lockVariants(), a save's writes, and delete();
 * 4. the inventory's rows, which its own service locks, after the catalog's.
 *
 * A binding that moved to another product between lockWithPost()'s plain read and its lock is
 * followed to that product, whose row is then locked out of that order; the unit of work's
 * deadlock retry covers that rare case, and a locking read decides every time.
 *
 * A SKU is one variant's in the whole store, in the table's collation. Before a save writes a SKU
 * a variant did not have, one locking read finds every variant that holds one of the SKUs it
 * writes: one of another product makes the save `catalog.sku_taken`, naming the combination that
 * asked for it; one of the same product, of another combination and any generation, is released
 * first by giving it a tombstone, `~` and its UUID, which no SKU a merchant gives may look like:
 * the variant then has no SKU (Variant::hasSku()) until it is given one. So two of a product's
 * variants can swap their SKUs in one save. A variant written meanwhile with one of the SKUs breaks
 * the `sku` key, or leaves its row unwritten, and is answered the same way after a second read.
 * Two SKUs of one save that only the collation finds equal, such as two that differ by an accent,
 * break the key with no holder in sight: `catalog.sku_duplicate`, as two equal SKUs are before the
 * window.
 *
 * A price is read and written per currency; the store's base currency, which is asked for when it
 * is needed, picks each variant's base price.
 *
 * @since 0.1.0
 */
final class MysqlProductRepository implements ProductRepository {

	/**
	 * The format of a DATETIME column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DATETIME = 'Y-m-d H:i:s';

	/**
	 * The status of a published post, which a promotion prefers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PUBLISHED = 'publish';

	/**
	 * The instant a statement writes into a product's `updated_at`: now, or one microsecond after the row's last instant when that is later.
	 *
	 * A marker is identified by its instant, so no two writes of one row may share one: two
	 * statements in the same microsecond, or a database clock that steps back, would otherwise
	 * let a restore take a newer mark for its own.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NEXT_INSTANT = 'GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND )';

	/**
	 * What turns a read into a locking read: it takes the rows' locks until the transaction ends, and reads the newest committed rows, whatever the transaction's snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LOCKING = ' FOR UPDATE';

	/**
	 * How often lockWithPost() follows a binding that moved to another product while it locked: a binding moves only under its product's lock, so twice is already rare.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LOCK_ATTEMPTS = 3;

	/**
	 * The unbound products scan of doctor's first check: products with no `product_posts` row, without its select list or its paging.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_PRODUCTS_SCAN = ' FROM %i p LEFT JOIN %i pp ON pp.product_id = p.id WHERE pp.product_id IS NULL';

	/**
	 * The first page of the unbound products, with no cursor comparison: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_PRODUCT_IDS_FROM_START = 'SELECT p.id' . self::UNBOUND_PRODUCTS_SCAN . ' ORDER BY p.id LIMIT %d';

	/**
	 * A page of the unbound products after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_PRODUCT_IDS = 'SELECT p.id' . self::UNBOUND_PRODUCTS_SCAN . ' AND p.id > %d ORDER BY p.id LIMIT %d';

	/**
	 * The invalid source bindings scan of doctor's second check, without its select list or its paging.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INVALID_SOURCE_SCAN = ' FROM %i p'
		. ' LEFT JOIN %i pp ON pp.product_id = p.id AND pp.post_id = p.source_post_id'
		. ' LEFT JOIN %i wpp ON wpp.ID = p.source_post_id'
		. ' WHERE ( p.source_post_id IS NULL OR pp.post_id IS NULL OR wpp.ID IS NULL OR wpp.post_type <> %s )';

	/**
	 * The first page of the products whose source binding is invalid: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INVALID_SOURCE_BINDINGS_FROM_START = 'SELECT p.id' . self::INVALID_SOURCE_SCAN . ' ORDER BY p.id LIMIT %d';

	/**
	 * A page of the products whose source binding is invalid, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INVALID_SOURCE_BINDINGS = 'SELECT p.id' . self::INVALID_SOURCE_SCAN . ' AND p.id > %d ORDER BY p.id LIMIT %d';

	/**
	 * The unbound posts scan of doctor's fourth check, without its select list or its paging.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_POSTS_SCAN = ' FROM %i wpp LEFT JOIN %i pp ON pp.post_id = wpp.ID'
		. ' WHERE wpp.post_type = %s AND wpp.post_status <> %s AND pp.post_id IS NULL';

	/**
	 * The first page of the product posts with no binding: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_POST_IDS_FROM_START = 'SELECT wpp.ID' . self::UNBOUND_POSTS_SCAN . ' ORDER BY wpp.ID LIMIT %d';

	/**
	 * A page of the product posts with no binding, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNBOUND_POST_IDS = 'SELECT wpp.ID' . self::UNBOUND_POSTS_SCAN . ' AND wpp.ID > %d ORDER BY wpp.ID LIMIT %d';

	/**
	 * The first page of the bindings, every bound post with its product and locale: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BOUND_POST_BINDINGS_FROM_START = 'SELECT post_id, product_id, locale FROM %i ORDER BY post_id LIMIT %d';

	/**
	 * A page of the bindings after a cursor on the post id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BOUND_POST_BINDINGS = 'SELECT post_id, product_id, locale FROM %i WHERE post_id > %d ORDER BY post_id LIMIT %d';

	/**
	 * The scan of doctor's fifth check up to the cursor: complete products with the enabled variants of the generation they publish, and each one's base price.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INCOMPLETE_MISMATCH_SCAN = ' FROM %i p'
		. ' LEFT JOIN %i v ON v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1'
		. ' LEFT JOIN %i vp ON vp.variant_id = v.id AND vp.currency = %s'
		. ' WHERE p.generation_state = %s';

	/**
	 * What the fifth check's scan asks of those products, and its paging: no enabled variant, or an enabled variant without a base price; one row per product.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INCOMPLETE_MISMATCH_FILTER = ' AND ( p.enabled_variant_count = 0 OR vp.id IS NULL ) GROUP BY p.id ORDER BY p.id LIMIT %d';

	/**
	 * The first page of the complete products that are not whole: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INCOMPLETE_MISMATCH_IDS_FROM_START = 'SELECT p.id' . self::INCOMPLETE_MISMATCH_SCAN . self::INCOMPLETE_MISMATCH_FILTER;

	/**
	 * A page of the complete products that are not whole, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INCOMPLETE_MISMATCH_IDS = 'SELECT p.id' . self::INCOMPLETE_MISMATCH_SCAN . ' AND p.id > %d' . self::INCOMPLETE_MISMATCH_FILTER;

	/**
	 * The variants scan of doctor's sixth check: variants whose product exists, without its select list or its paging.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANT_IDS_SCAN = ' FROM %i v JOIN %i p ON p.id = v.product_id';

	/**
	 * The first page of the variants: see keysetPage().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANT_IDS_FROM_START = 'SELECT v.id' . self::VARIANT_IDS_SCAN . ' ORDER BY v.id LIMIT %d';

	/**
	 * A page of the variants after a cursor, which the join's condition carries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANT_IDS = 'SELECT v.id' . self::VARIANT_IDS_SCAN . ' AND v.id > %d ORDER BY v.id LIMIT %d';

	/**
	 * The most rows one multi-row statement writes or names.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const ROWS_PER_STATEMENT = 100;

	/**
	 * The variants of the generation a product publishes, each with every price authored for it: a row per variant and price.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANTS_OF_GENERATION = 'SELECT' . self::VARIANTS_WITH_PRICES . ' WHERE v.product_id = %d AND v.generation = %d ORDER BY v.position, v.id, vp.currency';

	/**
	 * The variants a product holds for some combinations, each with every price authored for it: a row per variant and price.
	 *
	 * A consistent read: the product's variants and prices are written only under its lock, which
	 * the window holds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANTS_OF_COMBINATIONS = 'SELECT' . self::VARIANTS_WITH_PRICES . ' WHERE v.product_id = %d AND v.combination_hash IN ({list}) ORDER BY v.position, v.id, vp.currency';

	/**
	 * What a read of variants with every price sends after its SELECT, up to its WHERE.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANTS_WITH_PRICES = ' v.id, v.uuid, v.sku, v.combination_hash, v.generation, v.is_enabled, v.weight_grams, v.position, v.label,'
		. ' vp.currency, vp.amount_basis, vp.price_minor, vp.compare_at_minor'
		. ' FROM {variants} v LEFT JOIN {variant_prices} vp ON vp.variant_id = v.id';

	/**
	 * VARIANTS_OF_GENERATION as a locking read, which a load under the product's lock sends.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCKED_VARIANTS_OF_GENERATION = self::VARIANTS_OF_GENERATION . self::LOCKING;

	/**
	 * A product's option axes, each with its values, in order: a row per value, or one for an axis without a value.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPTIONS_OF_PRODUCT = 'SELECT a.axis_key, a.label AS axis_label, a.position AS axis_position,'
		. ' ov.value_key, ov.label AS value_label, ov.position AS value_position, ov.image_attachment_id, ov.swatch_hex'
		. ' FROM {option_axes} a LEFT JOIN {option_values} ov ON ov.axis_id = a.id'
		. ' WHERE a.product_id = %d ORDER BY a.position, a.axis_key, ov.position, ov.value_key';

	/**
	 * OPTIONS_OF_PRODUCT as a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCKED_OPTIONS_OF_PRODUCT = self::OPTIONS_OF_PRODUCT . self::LOCKING;

	/**
	 * The option values some variants select, by their keys.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SELECTIONS_OF_VARIANTS = 'SELECT j.variant_id, a.axis_key, ov.value_key'
		. ' FROM {variant_option_values} j JOIN {option_values} ov ON ov.id = j.option_value_id JOIN {option_axes} a ON a.id = j.axis_id'
		. ' WHERE j.variant_id IN ({list})';

	/**
	 * SELECTIONS_OF_VARIANTS as a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCKED_SELECTIONS_OF_VARIANTS = self::SELECTIONS_OF_VARIANTS . self::LOCKING;

	/**
	 * The variants that hold any of some SKUs, in the table's collation, with the first of the SKUs each holds: a locking read.
	 *
	 * It reads the sku index alone, whose records the write's duplicate key has just locked, and
	 * none of the rows: MariaDB refuses a locking read of a row committed after the transaction's
	 * snapshot (error 1020), which another product's holder may be. OWN_VARIANTS says which
	 * holders are the product's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SKU_HOLDERS = 'SELECT id, FIELD( sku, {list} ) AS wanted FROM {variants} WHERE sku IN ({list}) ORDER BY id LOCK IN SHARE MODE';

	/**
	 * A product's variants of every generation, each with the place of the SKU it holds among some SKUs, in the table's collation, or 0.
	 *
	 * A consistent read: the product's variants are written only under its lock, which the window
	 * holds. Another product's variants are not read here; one that holds a SKU of the set refuses
	 * the write with the table's duplicate key, and SKU_HOLDERS names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OWN_VARIANTS = 'SELECT id, combination_hash, sku, FIELD( sku, {list} ) AS wanted FROM {variants} WHERE product_id = %d ORDER BY id';


	/**
	 * Releases the SKUs of some of a product's variants by giving each the placeholder of a variant without a SKU: Variant::NO_SKU_PREFIX and its UUID.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RELEASE_SKUS = "UPDATE {variants} SET sku = CONCAT( '" . Variant::NO_SKU_PREFIX . "', uuid ), updated_at = UTC_TIMESTAMP() WHERE product_id = %d AND id IN ({list})";

	/**
	 * Whether a row an upsert of a product's variants met is that product's variant of that combination; any other row is left as it is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OWN_COMBINATION = 'product_id = VALUES( product_id ) AND combination_hash = VALUES( combination_hash )';

	/**
	 * Writes a stored variant of a product, named by its id, whose key is met first: one of the generation loaded, or of an earlier one whose combination the set gives again.
	 *
	 * A row met through another key is another variant's: every column keeps its value, so the row
	 * of the set is left unwritten, which the read of the generation afterwards finds. A SKU another
	 * variant holds is refused with the table's duplicate key. `updated_at` is assigned first, so it
	 * compares the stored values: an unchanged row keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPSERT_VARIANT = 'INSERT INTO {variants} ( id, uuid, product_id, sku, combination_hash, generation, is_enabled, weight_grams, position, label, created_at, updated_at )'
		. " VALUES ( NULLIF( %d, 0 ), %s, %d, %s, %s, %d, %d, NULLIF( %d, -1 ), %d, NULLIF( %s, '' ), UTC_TIMESTAMP(), UTC_TIMESTAMP() )"
		. ' ON DUPLICATE KEY UPDATE'
		. ' updated_at = IF( ' . self::OWN_COMBINATION . ' AND NOT ( CAST( sku AS BINARY ) <=> CONVERT( VALUES( sku ) USING binary ) AND generation <=> VALUES( generation )'
		. ' AND is_enabled <=> VALUES( is_enabled ) AND weight_grams <=> VALUES( weight_grams ) AND position <=> VALUES( position )'
		. ' AND CAST( label AS BINARY ) <=> CONVERT( VALUES( label ) USING binary ) ), UTC_TIMESTAMP(), updated_at ),'
		. ' sku = IF( ' . self::OWN_COMBINATION . ', VALUES( sku ), sku ),'
		. ' generation = IF( ' . self::OWN_COMBINATION . ', VALUES( generation ), generation ),'
		. ' is_enabled = IF( ' . self::OWN_COMBINATION . ', VALUES( is_enabled ), is_enabled ),'
		. ' weight_grams = IF( ' . self::OWN_COMBINATION . ', VALUES( weight_grams ), weight_grams ),'
		. ' position = IF( ' . self::OWN_COMBINATION . ', VALUES( position ), position ),'
		. ' label = IF( ' . self::OWN_COMBINATION . ', VALUES( label ), label )';

	/**
	 * Inserts a new variant of a product. A SKU another variant holds is refused with the table's duplicate key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_VARIANT = 'INSERT INTO {variants} ( uuid, product_id, sku, combination_hash, generation, is_enabled, weight_grams, position, label, created_at, updated_at )'
		. " VALUES ( %s, %d, %s, %s, %d, %d, NULLIF( %d, -1 ), %d, NULLIF( %s, '' ), UTC_TIMESTAMP(), UTC_TIMESTAMP() )";

	/**
	 * The ids, combinations and SKUs of a product's variants of one generation, on the `product_generation` key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VARIANTS_AT_GENERATION = 'SELECT id, combination_hash, sku FROM {variants} WHERE product_id = %d AND generation = %d';

	/**
	 * Disables the variants of a product's generation that a save's set leaves out.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DISABLE_LEFT_OUT = 'UPDATE {variants} SET is_enabled = 0, updated_at = UTC_TIMESTAMP() WHERE product_id = %d AND generation = %d AND is_enabled = 1 AND id NOT IN ({list})';

	/**
	 * Writes a product's option axis, or its label and position.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPSERT_AXIS = 'INSERT INTO {option_axes} ( product_id, axis_key, label, position, created_at, updated_at ) VALUES ( %d, %s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )'
		. ' ON DUPLICATE KEY UPDATE updated_at = IF( CAST( label AS BINARY ) <=> CONVERT( VALUES( label ) USING binary ) AND position <=> VALUES( position ), updated_at, UTC_TIMESTAMP() ),'
		. ' label = VALUES( label ), position = VALUES( position )';

	/**
	 * The ids of a product's option axes, by key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AXIS_IDS = 'SELECT id, axis_key FROM {option_axes} WHERE product_id = %d';

	/**
	 * Writes an option value of an axis, or its label, position, image and swatch.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPSERT_VALUE = 'INSERT INTO {option_values} ( axis_id, value_key, label, position, image_attachment_id, swatch_hex, created_at, updated_at )'
		. " VALUES ( %d, %s, %s, %d, NULLIF( %d, 0 ), NULLIF( %s, '' ), UTC_TIMESTAMP(), UTC_TIMESTAMP() )"
		. ' ON DUPLICATE KEY UPDATE updated_at = IF( CAST( label AS BINARY ) <=> CONVERT( VALUES( label ) USING binary ) AND position <=> VALUES( position )'
		. ' AND image_attachment_id <=> VALUES( image_attachment_id ) AND swatch_hex <=> VALUES( swatch_hex ), updated_at, UTC_TIMESTAMP() ),'
		. ' label = VALUES( label ), position = VALUES( position ), image_attachment_id = VALUES( image_attachment_id ), swatch_hex = VALUES( swatch_hex )';

	/**
	 * Deletes the selections of the option values a product keeps no more, `axis_key=value_key` not among those given.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_SELECTIONS_OF_VALUES = "DELETE j FROM {variant_option_values} j JOIN {option_values} ov ON ov.id = j.option_value_id JOIN {option_axes} a ON a.id = ov.axis_id WHERE a.product_id = %d AND CONCAT( a.axis_key, '=', ov.value_key ) NOT IN ({list})";

	/**
	 * Deletes the option values a product keeps no more, `axis_key=value_key` not among those given.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_VALUES = "DELETE ov FROM {option_values} ov JOIN {option_axes} a ON a.id = ov.axis_id WHERE a.product_id = %d AND CONCAT( a.axis_key, '=', ov.value_key ) NOT IN ({list})";

	/**
	 * Deletes the option axes a product keeps no more, their values deleted first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_AXES = 'DELETE FROM {option_axes} WHERE product_id = %d AND axis_key NOT IN ({list})';

	/**
	 * Writes the option values some variants select, found by their keys; a selection there already is left as it is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_SELECTIONS = 'INSERT IGNORE INTO {variant_option_values} ( variant_id, option_value_id, axis_id ) SELECT t.variant_id, ov.id, a.id'
		. ' FROM ( SELECT %d AS variant_id, CONVERT( %s USING ascii ) COLLATE ascii_bin AS axis_key, CONVERT( %s USING ascii ) COLLATE ascii_bin AS value_key ) AS t'
		. ' JOIN {option_axes} a ON a.product_id = %d AND a.axis_key = t.axis_key'
		. ' JOIN {option_values} ov ON ov.axis_id = a.id AND ov.value_key = t.value_key';

	/**
	 * Writes a variant's price in one currency. `updated_at` is assigned first, so it compares the stored values: an unchanged price keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPSERT_PRICE = 'INSERT INTO {variant_prices} ( variant_id, currency, amount_basis, price_minor, compare_at_minor, created_at, updated_at )'
		. ' VALUES ( %d, %s, %s, %d, NULLIF( %d, -1 ), UTC_TIMESTAMP(), UTC_TIMESTAMP() )'
		. ' ON DUPLICATE KEY UPDATE updated_at = IF( CAST( amount_basis AS BINARY ) <=> CONVERT( VALUES( amount_basis ) USING binary ) AND price_minor <=> VALUES( price_minor )'
		. ' AND compare_at_minor <=> VALUES( compare_at_minor ), updated_at, UTC_TIMESTAMP() ),'
		. ' amount_basis = VALUES( amount_basis ), price_minor = VALUES( price_minor ), compare_at_minor = VALUES( compare_at_minor )';

	/**
	 * Deletes the prices of some variants that are not among the `(variant_id, currency)` pairs given.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_OTHER_PRICES = 'DELETE FROM {variant_prices} WHERE variant_id IN ({list}) AND ( variant_id, currency ) NOT IN ( {pairs} )';

	/**
	 * Publishes the generation a save wrote, with its counts, only while the product still publishes the generation the save loaded.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PUBLISH_GENERATION = 'UPDATE {products} SET source_post_id = NULLIF( %d, 0 ), active_variant_generation = %d, variant_count = %d, enabled_variant_count = %d,'
		. ' updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND active_variant_generation = %d';

	/**
	 * Whether a product's row exists, after its generation could not be published.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRODUCT_EXISTS = 'SELECT id FROM {products} WHERE id = %d';

	/**
	 * Deletes the option values some variants select.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_SELECTIONS_OF_VARIANTS = 'DELETE FROM {variant_option_values} WHERE variant_id IN ({list})';

	/**
	 * Deletes the option values of a product's axes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_VALUES_OF_PRODUCT = 'DELETE ov FROM {option_values} ov JOIN {option_axes} a ON a.id = ov.axis_id WHERE a.product_id = %d';

	/**
	 * Deletes a product's option axes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_AXES_OF_PRODUCT = 'DELETE FROM {option_axes} WHERE product_id = %d';

	/**
	 * The option axes whose product no longer exists (doctor check 8).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORPHAN_AXES = 'SELECT a.id, a.product_id FROM {option_axes} a LEFT JOIN {products} p ON p.id = a.product_id WHERE p.id IS NULL ORDER BY a.id LIMIT %d';

	/**
	 * The option values whose axis no longer exists (doctor check 8).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORPHAN_VALUES = 'SELECT ov.id, ov.axis_id FROM {option_values} ov LEFT JOIN {option_axes} a ON a.id = ov.axis_id WHERE a.id IS NULL ORDER BY ov.id LIMIT %d';

	/**
	 * The selections whose variant or value no longer exists (doctor check 8).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORPHAN_SELECTIONS = 'SELECT j.variant_id, j.option_value_id FROM {variant_option_values} j LEFT JOIN {variants} v ON v.id = j.variant_id LEFT JOIN {option_values} ov ON ov.id = j.option_value_id'
		. ' WHERE v.id IS NULL OR ov.id IS NULL ORDER BY j.variant_id, j.option_value_id LIMIT %d';

	/**
	 * The option values whose image attachment no longer exists (doctor check 8): `%i` is the site's posts table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VALUES_WITHOUT_IMAGE = "SELECT ov.id, ov.image_attachment_id FROM {option_values} ov LEFT JOIN %i wp ON wp.ID = ov.image_attachment_id AND wp.post_type = 'attachment'"
		. ' WHERE ov.image_attachment_id IS NOT NULL AND wp.ID IS NULL ORDER BY ov.id LIMIT %d';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db           The connection.
	 * @param callable $baseCurrency Returns the store's base currency (a Currency), when a price is read or written.
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 */
	public function __construct( Database $db, callable $baseCurrency ) {
		$this->db           = $db;
		$this->baseCurrency = $baseCurrency;
	}

	/**
	 * Loads a product by its id.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function find( int $productId ): ?Product {
		return $this->load( $productId );
	}

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function findByPost( int $postId ): ?Product {
		return $this->loadWhere( $this->productOf( $postId, false ) );
	}

	/**
	 * Loads the product whose source binding is a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when no product has that source post.
	 */
	public function findBySourcePost( int $postId ): ?Product {
		return $this->loadWhere( $this->db->fetchValue( 'SELECT id FROM %i WHERE source_post_id = %d', $this->table( CatalogTables::PRODUCTS ), $postId ) );
	}

	/**
	 * Loads the product a variant belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant's id.
	 * @return Product|null The product, or null when no variant has that id.
	 */
	public function findByVariant( int $variantId ): ?Product {
		return $this->loadWhere( $this->db->fetchValue( 'SELECT product_id FROM %i WHERE id = %d', $this->table( CatalogTables::VARIANTS ), $variantId ) );
	}

	/**
	 * Writes a product with its bindings, its options, its variants and their prices, and gives it its ids.
	 *
	 * A stored product's statements come in this order: its bindings; its options, when they
	 * changed; the SKUs to release; the variants of the set; their ids, when the set has new ones;
	 * the variants it leaves out, disabled; the option values they select; their prices; and last,
	 * the product's row, which publishes the set's generation with its counts only while the
	 * product still publishes the generation it was loaded with.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the set, the options, and the conditional publication.
	 *
	 * @throws CodedException With CatalogError::SkuTaken when another product's variant holds a SKU of the set,
	 *                        CatalogError::SkuDuplicate when two of its variants hold one, or CatalogError::WriteConflict
	 *                        when the product publishes another generation than the one it was loaded with.
	 *
	 * @param Product $product The product.
	 */
	public function save( Product $product ): void {
		$id = $product->id();

		if ( null === $id ) {
			$id = $this->insertProduct( $product );

			$this->addBindings( $id, $product, false );
			$product->identify( $id, $this->writeVariants( $id, $product ) );

			return;
		}

		$this->addBindings( $id, $product, true );

		$ids = $this->writeVariants( $id, $product );

		$this->publishGeneration( $id, $product );
		$product->identify( $id, $ids );
	}

	/**
	 * Marks a product `updating`, whatever its marker was, and returns the marker it replaced and the instant of the mark.
	 *
	 * Three statements: a locking read of the marker, the mark, and the read of the instant it
	 * wrote. The lock is held until the transaction ends, so both reads are this mark's.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, so the reads might be another writer's.
	 *
	 * @param int $productId The product's id.
	 * @return UpdatingMark|null The mark; null when there is no such product.
	 */
	public function markUpdating( int $productId ): ?UpdatingMark {
		$this->requireTransaction( __FUNCTION__ );

		$before = $this->db->fetchValue( 'SELECT generation_state FROM %i WHERE id = %d FOR UPDATE', $this->table( CatalogTables::PRODUCTS ), $productId );

		if ( null === $before || 1 !== $this->remark( $productId ) ) {
			return null;
		}

		return new UpdatingMark(
			GenerationState::fromStored( (string) $before ),
			(string) $this->db->fetchValue( 'SELECT updated_at FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId )
		);
	}

	/**
	 * Marks a product `updating` again as the first statement of a write's window, taking its row lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return bool True when the product exists and was marked.
	 */
	public function relock( int $productId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->remark( $productId );
	}

	/**
	 * Takes a product out of `updating`, only while it is `updating`.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $to        The marker to set.
	 * @return bool True when the product was `updating` and now has the marker.
	 */
	public function leaveUpdating( int $productId, GenerationState $to ): bool {
		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$to->value,
			$productId,
			GenerationState::Updating->value
		);
	}

	/**
	 * Puts back the marker a product had before a rolled-back write, only while this write's own mark is there.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $before    The marker the product had before the write marked it.
	 * @param string          $markedAt  The instant markUpdating() returned for this write's mark.
	 * @return bool True when this write's mark was still there and the marker is put back.
	 */
	public function restoreMark( int $productId, GenerationState $before, string $markedAt ): bool {
		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s AND updated_at = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$before->value,
			$productId,
			GenerationState::Updating->value,
			$markedAt
		);
	}

	/**
	 * Loads a product under its row lock: every read a locking read, the product's row first.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function lock( int $productId ): ?Product {
		$this->requireTransaction( __FUNCTION__ );

		return $this->load( $productId, true );
	}

	/**
	 * Loads the product a post is bound to under its row lock, as lockWithPost() locks it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws CodedException With CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function lockByPost( int $postId ): ?Product {
		foreach ( $this->lockWithPost( $postId ) as $product ) {
			if ( null !== $product->bindingOf( $postId ) ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Locks the rows of some products and of the product a post is bound to, in ascending order of id, then the post's binding, and loads each product with locking reads.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws CodedException With CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId        The post.
	 * @param int ...$productIds Other products to lock with it.
	 * @return array<int, Product> The products that exist, by id: the post's, when it presents one, among them.
	 */
	public function lockWithPost( int $postId, int ...$productIds ): array {
		$this->requireTransaction( __FUNCTION__ );

		// A read without a lock names the post's product, whose row is locked before its binding.
		$current = self::intOrNull( $this->productOf( $postId, false ) );
		$locked  = array();

		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt ) {
			$ids = array_unique( array_merge( $productIds, null === $current ? array() : array( $current ) ) );

			sort( $ids );

			foreach ( $ids as $id ) {
				if ( ! array_key_exists( $id, $locked ) ) {
					$locked[ $id ] = $this->load( $id, true );
				}
			}

			// Under the locks, a locking read of the binding names the product the post presents now.
			$now = self::intOrNull( $this->productOf( $postId, true ) );

			if ( null === $now || array_key_exists( $now, $locked ) ) {
				return array_filter( $locked, static fn( ?Product $product ): bool => null !== $product );
			}

			$current = $now;
		}

		CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
	}

	/**
	 * Locks the bindings of some posts, in ascending order of post id, and returns the product each presents now: one locking read of each binding.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int ...$postIds The posts.
	 * @return array<int, int> The product each post presents, by post id; a post that presents none is left out.
	 */
	public function lockBindings( int ...$postIds ): array {
		$this->requireTransaction( __FUNCTION__ );

		$postIds = array_unique( $postIds );
		$bound   = array();

		sort( $postIds );

		foreach ( $postIds as $postId ) {
			$productId = self::intOrNull( $this->productOf( $postId, true ) );

			if ( null !== $productId ) {
				$bound[ $postId ] = $productId;
			}
		}

		return $bound;
	}

	/**
	 * Locks every variant of a product until the transaction ends, and returns their ids: one locking read.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return list<int> The variants' ids, ascending.
	 */
	public function lockVariants( int $productId ): array {
		$this->requireTransaction( __FUNCTION__ );

		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i WHERE product_id = %d ORDER BY id FOR UPDATE', $this->table( CatalogTables::VARIANTS ), $productId ), 'id' ) );
	}

	/**
	 * Loads the variants a product holds for some combinations, each with every price: one statement.
	 *
	 * @since 0.2.0
	 *
	 * @param int                             $productId  The product's id.
	 * @param array<string, VariantSelection> $selections The combinations, by hash; at least one.
	 * @return list<Variant> The variants that exist, each with its selection.
	 */
	public function variantsOfCombinations( int $productId, array $selections ): array {
		$rows = $this->rows( self::VARIANTS_OF_COMBINATIONS, $productId, array_keys( $selections ) );
		$made = array();

		foreach ( $rows as $row ) {
			$made[ (int) $row['id'] ] = $selections[ (string) $row['combination_hash'] ];
		}

		return $this->variantsOf( $rows, $made );
	}

	/**
	 * Deletes a product's rows, children first: the option values its variants select, its variants' prices, its option values and axes, its variants, its bindings, then the product itself.
	 *
	 * Fails with CatalogError::ProductNotFound when the product's row is gone.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, or the product was never stored.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param Product $product The product, as lock() loaded it.
	 * @return array<int, string> The SKU of each variant deleted, by the variant's id, ascending.
	 */
	public function delete( Product $product ): array {
		$this->requireTransaction( __FUNCTION__ );

		$productId = $product->id();

		if ( null === $productId ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message naming the product's public identifier; it is never rendered.
			throw new \LogicException( sprintf( 'Product %s was never stored, so there is nothing to delete.', $product->uuid() ) );
		}

		// A locking read: every variant committed so far, of every generation, whatever this transaction's snapshot.
		$skus = array();

		foreach ( $this->db->fetchAll( 'SELECT id, sku FROM %i WHERE product_id = %d ORDER BY id FOR UPDATE', $this->table( CatalogTables::VARIANTS ), $productId ) as $variant ) {
			$skus[ (int) $variant['id'] ] = (string) $variant['sku'];
		}

		$variantIds = array_keys( $skus );

		if ( array() !== $variantIds ) {
			$this->write( self::DELETE_SELECTIONS_OF_VARIANTS, $variantIds );
			$this->db->execute(
				'DELETE FROM %i WHERE variant_id IN ( ' . implode( ', ', array_fill( 0, count( $variantIds ), '%d' ) ) . ' )',
				$this->table( CatalogTables::VARIANT_PRICES ),
				...$variantIds
			);
		}

		// The options go whether or not a variant selects them: a product keeps its axes while it has no variant.
		$this->write( self::DELETE_VALUES_OF_PRODUCT, $productId );
		$this->write( self::DELETE_AXES_OF_PRODUCT, $productId );

		if ( array() !== $variantIds ) {
			$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->table( CatalogTables::VARIANTS ), $productId );
		}

		$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $productId );

		if ( 1 !== $this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId ) ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		return $skus;
	}

	/**
	 * Reads, in one query, the facts Sellability judges each variant on.
	 *
	 * The variant is joined to its product, the product to its binding to its source post, and
	 * that binding to the post only when the post is a product post; whether the variant has a
	 * price in the base currency is one EXISTS. The variant's SKU and the post's title come with
	 * them, for a sale to record.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists; none for an unknown id.
	 */
	public function sellabilityFacts( int ...$variantIds ): array {
		return $this->facts( null, $variantIds );
	}

	/**
	 * Reads, in one query, the facts Sellability judges each variant on in one locale.
	 *
	 * The same query as sellabilityFacts(), joined to the product's binding in the locale instead
	 * of its source binding, through the `(product_id, locale)` key.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale        The locale.
	 * @param int    ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists; none for an unknown id.
	 */
	public function sellabilityFactsIn( Locale $locale, int ...$variantIds ): array {
		return $this->facts( $locale, $variantIds );
	}

	/**
	 * Reads, in one query, the prices a merchant authored for some variants in some currencies.
	 *
	 * Both lists become IN lists of one statement on the `(variant_id, currency)` key, so the
	 * number of queries does not grow with the variants or the currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $variantIds    The variants' ids.
	 * @param Currency ...$currencies The currencies.
	 * @return list<array{variantId: int, price: VariantPrice, taxClassId: int|null}> The prices, in no particular order.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function explicitPrices( array $variantIds, Currency ...$currencies ): array {
		$ids   = array_values( array_unique( array_filter( $variantIds, static fn( int $id ): bool => $id > 0 ) ) );
		$codes = array_values( array_unique( array_map( static fn( Currency $currency ): string => $currency->code(), $currencies ) ) );

		if ( array() === $ids || array() === $codes ) {
			return array();
		}

		$rows = $this->db->fetchAll(
			'SELECT variant_id, currency, amount_basis, price_minor, compare_at_minor, tax_class_id FROM %i'
				. ' WHERE variant_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )'
				. ' AND currency IN ( ' . implode( ', ', array_fill( 0, count( $codes ), '%s' ) ) . ' )',
			$this->table( CatalogTables::VARIANT_PRICES ),
			...$ids,
			...$codes
		);

		return array_map(
			static fn( array $row ): array => array(
				'variantId'  => (int) $row['variant_id'],
				'price'      => VariantPrice::stored( Currency::of( (string) $row['currency'] ), (int) $row['price_minor'], self::intOrNull( $row['compare_at_minor'] ), (string) $row['amount_basis'] ),
				'taxClassId' => self::intOrNull( $row['tax_class_id'] ),
			),
			$rows
		);
	}

	/**
	 * Moves a post's binding to another locale.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::LocaleTaken when the product has a post in that locale.
	 *
	 * @param int    $postId The post.
	 * @param Locale $locale The locale.
	 * @return bool True when the binding moved; false when the post presents no product, or has that locale already.
	 */
	public function moveBinding( int $postId, Locale $locale ): bool {
		try {
			return 1 === $this->db->execute( 'UPDATE %i SET locale = %s WHERE post_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $locale->toString(), $postId );
		} catch ( DuplicateKey $taken ) {
			CodedException::raise(
				CatalogError::LocaleTaken,
				array(
					'product_id' => (int) $this->db->fetchValue( 'SELECT product_id FROM %i WHERE post_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $postId ),
					'locale'     => $locale->toString(),
				)
			);
		}
	}

	/**
	 * Binds a post to a product, in a locale.
	 *
	 * When a unique key refuses the row, a locking read of the post's binding tells which one:
	 * the post is bound already, or the product has a post in the locale.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::PostBoundElsewhere or CatalogError::LocaleTaken.
	 *
	 * @param int                $productId The product's id.
	 * @param ProductPostBinding $binding   The binding.
	 */
	public function addBinding( int $productId, ProductPostBinding $binding ): void {
		try {
			$this->db->execute(
				'INSERT INTO %i ( post_id, product_id, locale, linked_at, linked_by_adapter, created_at ) VALUES ( %d, %d, %s, %s, ' . self::placeholder( $binding->linkedByAdapter() ) . ', UTC_TIMESTAMP() )',
				...self::given(
					$this->table( CatalogTables::PRODUCT_POSTS ),
					$binding->postId(),
					$productId,
					$binding->locale()->toString(),
					self::formatInstant( $binding->linkedAt() ),
					$binding->linkedByAdapter()
				)
			);
		} catch ( DuplicateKey $taken ) {
			$holder = $this->productOf( $binding->postId(), true );

			if ( null !== $holder ) {
				CodedException::raise(
					CatalogError::PostBoundElsewhere,
					array(
						'post_id'    => $binding->postId(),
						'product_id' => (int) $holder,
					)
				);
			}

			CodedException::raise(
				CatalogError::LocaleTaken,
				array(
					'product_id' => $productId,
					'locale'     => $binding->locale()->toString(),
				)
			);
		}
	}

	/**
	 * Removes a post's binding to a product, unless it is the product's source binding.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @param int $postId    The post.
	 * @return bool True when the binding was removed.
	 */
	public function removeBinding( int $productId, int $postId ): bool {
		return 1 === $this->db->execute(
			'DELETE pp FROM %i pp JOIN %i p ON p.id = pp.product_id WHERE pp.post_id = %d AND pp.product_id = %d AND NOT ( p.source_post_id <=> pp.post_id )',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$postId,
			$productId
		);
	}

	/**
	 * Moves a product's source binding from one of its posts to another, in one conditional statement.
	 *
	 * The statement changes the row only while the source is still the post the caller read, so
	 * two promotions of one product cannot both apply, and a promotion never names a post that is
	 * not one of the product's bindings. With no post named, the statement picks the post itself,
	 * in its own order: published bindings first, then by the time each was bound, then by post id.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $productId  The product's id.
	 * @param int      $fromPostId The post that must be the source now.
	 * @param int|null $toPostId   The post to promote, or null for the oldest remaining binding.
	 * @return int|null The new source post, or null when nothing changed.
	 */
	public function promoteSource( int $productId, int $fromPostId, ?int $toPostId ): ?int {
		if ( null !== $toPostId ) {
			$changed = $this->db->execute(
				'UPDATE %i SET source_post_id = %d, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND source_post_id = %d'
					. ' AND EXISTS ( SELECT 1 FROM %i pp WHERE pp.post_id = %d AND pp.product_id = %d )',
				$this->table( CatalogTables::PRODUCTS ),
				$toPostId,
				$productId,
				$fromPostId,
				$this->table( CatalogTables::PRODUCT_POSTS ),
				$toPostId,
				$productId
			);
		} else {
			$changed = $this->db->execute(
				'UPDATE %i p JOIN ( SELECT pp.post_id FROM %i pp LEFT JOIN %i wp ON wp.ID = pp.post_id'
					. ' WHERE pp.product_id = %d AND pp.post_id <> %d'
					. ' ORDER BY ( wp.post_status = %s ) DESC, pp.linked_at, pp.post_id LIMIT 1 ) next ON 1 = 1'
					. ' SET p.source_post_id = next.post_id, p.updated_at = ' . self::NEXT_INSTANT
					. ' WHERE p.id = %d AND p.source_post_id = %d',
				$this->table( CatalogTables::PRODUCTS ),
				$this->table( CatalogTables::PRODUCT_POSTS ),
				$this->db->prefix() . 'posts',
				$productId,
				$fromPostId,
				self::PUBLISHED,
				$productId,
				$fromPostId
			);
		}

		if ( 1 !== $changed ) {
			return null;
		}

		return (int) $this->db->fetchValue( 'SELECT source_post_id FROM %i WHERE id = %d FOR UPDATE', $this->table( CatalogTables::PRODUCTS ), $productId );
	}

	/**
	 * Reads the facts of variants, judged on the product's source binding or on its binding in a locale.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale|null $locale     The locale, or null for the source binding.
	 * @param int[]       $variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists.
	 */
	private function facts( ?Locale $locale, array $variantIds ): array {
		$ids = array_values( array_unique( array_filter( $variantIds, static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$binding = null === $locale ? 'pp.post_id = p.source_post_id AND pp.product_id = p.id' : 'pp.product_id = p.id AND pp.locale = %s';
		$rows    = $this->db->fetchAll(
			'SELECT v.id AS variant_id, v.is_enabled, v.generation, v.sku, p.id AS product_id, p.generation_state, p.active_variant_generation, p.source_post_id, pp.post_id, wp.post_status, wp.post_title,'
				. ' EXISTS ( SELECT 1 FROM %i vp WHERE vp.variant_id = v.id AND vp.currency = %s ) AS has_base_price'
				. ' FROM %i v'
				. ' JOIN %i p ON p.id = v.product_id'
				. ' LEFT JOIN %i pp ON ' . $binding
				. ' LEFT JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s'
				. ' WHERE v.id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
			...array_merge(
				array(
					$this->table( CatalogTables::VARIANT_PRICES ),
					$this->baseCurrency()->code(),
					$this->table( CatalogTables::VARIANTS ),
					$this->table( CatalogTables::PRODUCTS ),
					$this->table( CatalogTables::PRODUCT_POSTS ),
				),
				null === $locale ? array() : array( $locale->toString() ),
				array( $this->db->prefix() . 'posts', ProductCapabilities::POST_TYPE ),
				$ids
			)
		);

		$facts = array();

		foreach ( $rows as $row ) {
			$facts[] = new SellabilityFacts(
				(int) $row['variant_id'],
				(int) $row['product_id'],
				GenerationState::fromStored( (string) $row['generation_state'] ),
				(int) $row['active_variant_generation'],
				(int) $row['generation'],
				1 === (int) $row['is_enabled'],
				self::intOrNull( $row['source_post_id'] ),
				self::intOrNull( $row['post_id'] ),
				null === $row['post_status'] ? null : (string) $row['post_status'],
				1 === (int) $row['has_base_price'],
				null === $locale || null !== $row['post_id'],
				(string) $row['sku'],
				(string) $row['post_title']
			);
		}

		return $facts;
	}

	/**
	 * Lists products with no `product_posts` row at all (doctor check 1).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function unboundProductIds( int $afterId, int $limit ): array {
		return self::intColumn(
			$this->keysetPage(
				self::UNBOUND_PRODUCT_IDS_FROM_START,
				self::UNBOUND_PRODUCT_IDS,
				array( $this->table( CatalogTables::PRODUCTS ), $this->table( CatalogTables::PRODUCT_POSTS ) ),
				$afterId,
				$limit
			),
			'id'
		);
	}

	/**
	 * Lists products whose source binding is invalid (doctor check 2).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function invalidSourceBindings( int $afterId, int $limit ): array {
		return self::intColumn(
			$this->keysetPage(
				self::INVALID_SOURCE_BINDINGS_FROM_START,
				self::INVALID_SOURCE_BINDINGS,
				array( $this->table( CatalogTables::PRODUCTS ), $this->table( CatalogTables::PRODUCT_POSTS ), $this->postsTable(), ProductCapabilities::POST_TYPE ),
				$afterId,
				$limit
			),
			'id'
		);
	}

	/**
	 * Lists `product_posts` rows whose post no longer exists (doctor check 3), the source binding excluded.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{post_id: int, product_id: int}> The bindings.
	 */
	public function danglingBindings( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT pp.post_id, pp.product_id FROM %i pp'
				. ' LEFT JOIN %i p ON p.id = pp.product_id'
				. ' LEFT JOIN %i wpp ON wpp.ID = pp.post_id'
				. ' WHERE ( wpp.ID IS NULL OR p.id IS NULL ) AND ( p.id IS NULL OR p.source_post_id IS NULL OR p.source_post_id <> pp.post_id )'
				. ' ORDER BY pp.post_id LIMIT %d',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$this->postsTable(),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'post_id'    => (int) $row['post_id'],
				'product_id' => (int) $row['product_id'],
			),
			$rows
		);
	}

	/**
	 * Deletes one dangling binding (doctor --repair, check 3), re-stating both conditions in the statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The binding's post.
	 * @param int $productId The binding's product.
	 * @return bool True when the row was deleted.
	 */
	public function deleteDanglingBinding( int $postId, int $productId ): bool {
		return 1 === $this->db->execute(
			'DELETE pp FROM %i pp'
				. ' LEFT JOIN %i p ON p.id = pp.product_id'
				. ' LEFT JOIN %i wpp ON wpp.ID = pp.post_id'
				. ' WHERE pp.post_id = %d AND pp.product_id = %d AND ( wpp.ID IS NULL OR p.id IS NULL ) AND ( p.id IS NULL OR p.source_post_id IS NULL OR p.source_post_id <> pp.post_id )',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$this->postsTable(),
			$postId,
			$productId
		);
	}

	/**
	 * Lists product posts (not `auto-draft`) with no `product_posts` row (doctor check 4).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only posts above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The post ids, ascending.
	 */
	public function unboundPostIds( int $afterId, int $limit ): array {
		return self::intColumn(
			$this->keysetPage(
				self::UNBOUND_POST_IDS_FROM_START,
				self::UNBOUND_POST_IDS,
				array( $this->postsTable(), $this->table( CatalogTables::PRODUCT_POSTS ), ProductCapabilities::POST_TYPE, ProductCapabilities::AUTO_DRAFT ),
				$afterId,
				$limit
			),
			'ID'
		);
	}

	/**
	 * Locks a post's row and returns its type and status, current: never through WordPress's
	 * post cache, which a concurrent write can leave stale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array{type: string, status: string}|null The post's type and status, or null when the row is gone.
	 */
	public function lockedPostTypeAndStatus( int $postId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		// LOCK IN SHARE MODE, which MySQL and MariaDB both read; MariaDB refuses FOR SHARE.
		$row = $this->db->fetchRow( 'SELECT post_type, post_status FROM %i WHERE ID = %d LOCK IN SHARE MODE', $this->postsTable(), $postId );

		if ( null === $row ) {
			return null;
		}

		return array(
			'type'   => (string) $row['post_type'],
			'status' => (string) $row['post_status'],
		);
	}

	/**
	 * Lists `product_posts` bindings, every bound post with the product it presents and the
	 * locale its binding names (doctor check 11).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only bindings whose post is above this id.
	 * @param int $limit   The most to list.
	 * @return list<array{post_id: int, product_id: int, locale: string}> The bindings, ascending by post id.
	 */
	public function boundPostBindings( int $afterId, int $limit ): array {
		$rows = $this->keysetPage( self::BOUND_POST_BINDINGS_FROM_START, self::BOUND_POST_BINDINGS, array( $this->table( CatalogTables::PRODUCT_POSTS ) ), $afterId, $limit );

		return array_map(
			static fn( array $row ): array => array(
				'post_id'    => (int) $row['post_id'],
				'product_id' => (int) $row['product_id'],
				'locale'     => (string) $row['locale'],
			),
			$rows
		);
	}

	/**
	 * Lists complete products with zero enabled variants in the generation they publish, or with
	 * an enabled variant of it that has no price in the base currency (doctor check 5).
	 *
	 * Scoped to `generation_state = 'complete'`: an incomplete product missing a price is the
	 * ordinary, expected state of a product still being set up, not a finding. A disabled variant
	 * may lack a price.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every enabled variant of the published generation, not the default variant alone.
	 *
	 * @param string $baseCurrency The store's base currency code.
	 * @param int    $afterId      Only products above this id.
	 * @param int    $limit        The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function incompleteMismatchIds( string $baseCurrency, int $afterId, int $limit ): array {
		return self::intColumn(
			$this->keysetPage(
				self::INCOMPLETE_MISMATCH_IDS_FROM_START,
				self::INCOMPLETE_MISMATCH_IDS,
				array(
					$this->table( CatalogTables::PRODUCTS ),
					$this->table( CatalogTables::VARIANTS ),
					$this->table( CatalogTables::VARIANT_PRICES ),
					$baseCurrency,
					GenerationState::Complete->value,
				),
				$afterId,
				$limit
			),
			'id'
		);
	}

	/**
	 * Lists products left `updating` for longer than the given threshold (doctor check 7).
	 *
	 * @since 0.1.0
	 *
	 * @param string $before The threshold instant (UTC).
	 * @param int    $limit  The most to list.
	 * @return list<array{product_id: int, updated_at: string}> The products.
	 */
	public function stuckUpdating( string $before, int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT id AS product_id, updated_at FROM %i WHERE generation_state = %s AND updated_at < %s ORDER BY id LIMIT %d',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Updating->value,
			$before,
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'product_id' => (int) $row['product_id'],
				'updated_at' => (string) $row['updated_at'],
			),
			$rows
		);
	}

	/**
	 * Lists `variants` rows whose product no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{variant_id: int, product_id: int}> The orphans.
	 */
	public function orphanVariants( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT v.id AS variant_id, v.product_id FROM %i v LEFT JOIN %i p ON p.id = v.product_id WHERE p.id IS NULL ORDER BY v.id LIMIT %d',
			$this->table( CatalogTables::VARIANTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'variant_id' => (int) $row['variant_id'],
				'product_id' => (int) $row['product_id'],
			),
			$rows
		);
	}

	/**
	 * Lists `variant_prices` rows whose variant no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{price_id: int, variant_id: int}> The orphans.
	 */
	public function orphanPrices( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT vp.id AS price_id, vp.variant_id FROM %i vp LEFT JOIN %i v ON v.id = vp.variant_id WHERE v.id IS NULL ORDER BY vp.id LIMIT %d',
			$this->table( CatalogTables::VARIANT_PRICES ),
			$this->table( CatalogTables::VARIANTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'price_id'   => (int) $row['price_id'],
				'variant_id' => (int) $row['variant_id'],
			),
			$rows
		);
	}

	/**
	 * Lists `product_price_projections` rows whose product no longer exists, by id (doctor check 8).
	 *
	 * Walks the rows in id order and stops at the limit, each row's product found by its primary key.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{id: int, product_id: int}> The orphans.
	 */
	public function orphanPriceProjections( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT pr.id, pr.product_id FROM %i pr LEFT JOIN %i p ON p.id = pr.product_id WHERE p.id IS NULL ORDER BY pr.id LIMIT %d',
			$this->table( CatalogTables::PRICE_PROJECTIONS ),
			$this->table( CatalogTables::PRODUCTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'product_id' => (int) $row['product_id'],
			),
			$rows
		);
	}

	/**
	 * Lists `product_search_index` rows whose product no longer exists, by product and locale (doctor check 8).
	 *
	 * Walks the rows in primary-key order and stops at the limit, each row's product found by its primary key.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{product_id: int, locale: string}> The orphans.
	 */
	public function orphanSearchDocuments( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT si.product_id, si.locale FROM %i si LEFT JOIN %i p ON p.id = si.product_id WHERE p.id IS NULL ORDER BY si.product_id, si.locale LIMIT %d',
			$this->table( CatalogTables::SEARCH_INDEX ),
			$this->table( CatalogTables::PRODUCTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'product_id' => (int) $row['product_id'],
				'locale'     => (string) $row['locale'],
			),
			$rows
		);
	}

	/**
	 * Lists the option rows whose parent is gone, and the option values whose image attachment is gone (doctor check 8).
	 *
	 * Each read walks its table in order and stops at the limit; a row's parent is found by its
	 * primary key.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most to list of each kind.
	 * @return array<string, list<array<string, int>>> The four lists, as ProductRepository::orphanOptionRows() says.
	 *
	 * @phpstan-return array{axes: list<array{id: int, product_id: int}>, values: list<array{id: int, axis_id: int}>, selections: list<array{variant_id: int, option_value_id: int}>, images: list<array{id: int, image_attachment_id: int}>}
	 */
	public function orphanOptionRows( int $limit ): array {
		$pairs = static fn( array $rows, string $first, string $second ): array => array_map(
			static fn( array $row ): array => array(
				$first  => (int) $row[ $first ],
				$second => (int) $row[ $second ],
			),
			$rows
		);

		return array(
			'axes'       => $pairs( $this->rows( self::ORPHAN_AXES, $limit ), 'id', 'product_id' ),
			'values'     => $pairs( $this->rows( self::ORPHAN_VALUES, $limit ), 'id', 'axis_id' ),
			'selections' => $pairs( $this->rows( self::ORPHAN_SELECTIONS, $limit ), 'variant_id', 'option_value_id' ),
			'images'     => $pairs( $this->rows( self::VALUES_WITHOUT_IMAGE, $this->postsTable(), $limit ), 'id', 'image_attachment_id' ),
		);
	}

	/**
	 * Counts products marked `incomplete` (doctor check 10).
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function incompleteCount(): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM %i WHERE generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Incomplete->value
		);
	}

	/**
	 * Lists every variant's id whose product still exists (doctor check 6).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only variants above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function variantIds( int $afterId, int $limit ): array {
		return self::intColumn(
			$this->keysetPage(
				self::VARIANT_IDS_FROM_START,
				self::VARIANT_IDS,
				array( $this->table( CatalogTables::VARIANTS ), $this->table( CatalogTables::PRODUCTS ) ),
				$afterId,
				$limit
			),
			'id'
		);
	}

	/**
	 * Filters a list of variant ids to the ones that still have a `variants` row (the reverse
	 * line).
	 *
	 * @since 0.1.0
	 *
	 * @param array $variantIds The ids to test.
	 * @return list<int> The ones that exist.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function variantsExisting( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		return self::intColumn(
			$this->db->fetchAll(
				'SELECT id FROM %i WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $variantIds ), '%d' ) ) . ' )',
				$this->table( CatalogTables::VARIANTS ),
				...$variantIds
			),
			'id'
		);
	}

	/**
	 * Reloads a product under its row lock, for a repair that must re-check the defect before it
	 * writes: load()'s own locking read (the one lock() also uses), plus the exact stored
	 * `updated_at` the repair's compare-and-set must match, which the aggregate itself does not carry.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return array{product: Product, state: GenerationState, updatedAt: string}|null The reload, or null.
	 */
	public function reloadUnderLock( int $productId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		$product = $this->load( $productId, true );

		if ( null === $product ) {
			return null;
		}

		// The row's lock is already held by load()'s own read above; this is a plain read of the
		// same, now-locked row, only for the one column the aggregate does not carry.
		$updatedAt = $this->db->fetchValue( 'SELECT updated_at FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId );

		return array(
			'product'   => $product,
			'state'     => $product->generation(),
			'updatedAt' => (string) $updatedAt,
		);
	}

	/**
	 * Settles a product's marker, only while it still has exactly the state (and, when given, the
	 * exact `updated_at`) the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId      The product's id.
	 * @param GenerationState $from           The marker the repair read.
	 * @param GenerationState $to             The marker settle() computed.
	 * @param string|null     $updatedAtMatch Optional. Also requires this exact `updated_at`.
	 * @return bool True when the row still matched and was changed.
	 */
	public function settleIfUnchanged( int $productId, GenerationState $from, GenerationState $to, ?string $updatedAtMatch = null ): bool {
		if ( null !== $updatedAtMatch ) {
			return 1 === $this->db->execute(
				'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s AND updated_at = %s',
				$this->table( CatalogTables::PRODUCTS ),
				$to->value,
				$productId,
				$from->value,
				$updatedAtMatch
			);
		}

		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$to->value,
			$productId,
			$from->value
		);
	}

	/**
	 * Returns the full name of `wp_posts` on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string The prefixed name.
	 */
	private function postsTable(): string {
		return $this->db->prefix() . 'posts';
	}

	/**
	 * Reads one integer column of every row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param string                           $column The column.
	 * @return list<int> The values.
	 */
	private static function intColumn( array $rows, string $column ): array {
		return array_map( 'intval', array_column( $rows, $column ) );
	}

	/**
	 * Reads one page of a keyset scan: the first page, or the page after a cursor.
	 *
	 * Every caller in this codebase pages from 0, so the first page is its own statement, with
	 * no cursor comparison. `id > 0` matches every row of the table anyway, and keeping the
	 * comparison would still make MySQL cost a primary-key range over it, which it estimates
	 * near half the table for a keyset scan like these, defeating the cheap, LIMIT-bounded plan
	 * the check is meant to get for no reason. A genuine cursor (a caller walking a table page by
	 * page) gets the statement that compares. In every scan the cursor is the argument before the
	 * limit, and the limit is the last.
	 *
	 * @since 0.2.0
	 *
	 * @param string $fromStart   The statement of the first page: no cursor, the limit last.
	 * @param string $afterCursor The statement of a page after a cursor: the cursor, then the limit, last.
	 * @param array  $arguments   The statement's arguments before the cursor.
	 * @param int    $afterId     The cursor; the first page when it is not positive.
	 * @param int    $limit       The most rows to read.
	 * @return list<array<string, mixed>> The rows.
	 *
	 * @phpstan-param list<mixed> $arguments
	 */
	private function keysetPage( string $fromStart, string $afterCursor, array $arguments, int $afterId, int $limit ): array {
		if ( $afterId > 0 ) {
			return $this->db->fetchAll( $afterCursor, ...$arguments, ...array( $afterId, $limit ) );
		}

		return $this->db->fetchAll( $fromStart, ...$arguments, ...array( $limit ) );
	}

	/**
	 * Loads a product by its id, when a lookup found one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $productId The id a lookup returned, or null.
	 * @return Product|null The product, or null.
	 */
	private function loadWhere( mixed $productId ): ?Product {
		return null === $productId ? null : $this->load( (int) $productId );
	}

	/**
	 * Reads the product a post's binding names: a plain read, or a locking read that takes the binding's row lock and sees the newest committed row.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $postId  The post's id.
	 * @param bool $locking Whether the read is a locking read.
	 * @return mixed The product's id as the database returns it, or null when the post is bound to none.
	 */
	private function productOf( int $postId, bool $locking ): mixed {
		return $this->db->fetchValue( 'SELECT product_id FROM %i WHERE post_id = %d' . ( $locking ? self::LOCKING : '' ), $this->table( CatalogTables::PRODUCT_POSTS ), $postId );
	}

	/**
	 * Loads a product, its bindings, the variants of the generation it publishes with their prices, and its options.
	 *
	 * A product without options loads in three statements: its row, its bindings, and its
	 * variants with their prices. One with options loads in five: its axes with their values, and
	 * the values its variants select. One without a variant reads its axes too, in four, since a
	 * product may keep axes while it publishes no variant.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the published generation, with every price, and the options.
	 *
	 * @param int  $productId The product's id.
	 * @param bool $locking   Optional. Whether every read is a locking read, the product's row first. Default false.
	 * @return Product|null The product, or null when there is no such row.
	 */
	private function load( int $productId, bool $locking = false ): ?Product {
		$lock = $locking ? self::LOCKING : '';
		$row  = $this->db->fetchRow(
			'SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM %i WHERE id = %d' . $lock,
			$this->table( CatalogTables::PRODUCTS ),
			$productId
		);

		if ( null === $row ) {
			return null;
		}

		$bindings = array();

		foreach ( $this->db->fetchAll( 'SELECT post_id, locale, linked_at, linked_by_adapter FROM %i WHERE product_id = %d ORDER BY linked_at, post_id' . $lock, $this->table( CatalogTables::PRODUCT_POSTS ), $productId ) as $binding ) {
			$bindings[] = new ProductPostBinding(
				(int) $binding['post_id'],
				Locale::of( (string) $binding['locale'] ),
				self::instant( (string) $binding['linked_at'] ),
				null === $binding['linked_by_adapter'] ? null : (string) $binding['linked_by_adapter']
			);
		}

		$variants   = $this->rows( $locking ? self::LOCKED_VARIANTS_OF_GENERATION : self::VARIANTS_OF_GENERATION, $productId, (int) $row['active_variant_generation'] );
		$hasOptions = array() === $variants;

		foreach ( $variants as $variant ) {
			$hasOptions = $hasOptions || Variant::defaultCombinationHash() !== (string) $variant['combination_hash'];
		}

		// A product with options, or without a variant, which may keep options from a set it no longer publishes.
		$options    = $hasOptions ? $this->loadOptions( $productId, $locking ) : OptionMatrix::none();
		$selections = $hasOptions && array() !== $variants ? $this->loadSelections( array_values( array_unique( array_map( 'intval', array_column( $variants, 'id' ) ) ) ), $locking ) : array();

		return Product::stored(
			(int) $row['id'],
			(string) $row['uuid'],
			self::intOrNull( $row['source_post_id'] ),
			$bindings,
			GenerationState::fromStored( (string) $row['generation_state'] ),
			(int) $row['active_variant_generation'],
			$this->variantsOf( $variants, $selections ),
			$options
		);
	}

	/**
	 * Builds variants from their rows, a row per variant and price, with the selection each makes.
	 *
	 * @since 0.2.0
	 *
	 * @param array                        $rows       The rows, a variant's rows together.
	 * @param array<int, VariantSelection> $selections The selection of each variant with options, by id.
	 * @return list<Variant> The variants, in the rows' order.
	 *
	 * @phpstan-param list<array<string, mixed>> $rows
	 */
	private function variantsOf( array $rows, array $selections ): array {
		$base     = $this->baseCurrency();
		$variants = array();
		$prices   = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			$variants[ $id ] ??= $row;
			$prices[ $id ]   ??= array();

			if ( null !== $row['price_minor'] ) {
				$prices[ $id ][] = VariantPrice::stored( Currency::of( (string) $row['currency'] ), (int) $row['price_minor'], self::intOrNull( $row['compare_at_minor'] ), (string) $row['amount_basis'] );
			}
		}

		$built = array();

		foreach ( $variants as $id => $row ) {
			$basePrice = null;
			$others    = array();

			foreach ( $prices[ $id ] as $price ) {
				if ( $price->currency()->equals( $base ) ) {
					$basePrice = $price;
				} else {
					$others[] = $price;
				}
			}

			$built[] = Variant::stored(
				$id,
				(string) $row['uuid'],
				Sku::of( (string) $row['sku'] ),
				(string) $row['combination_hash'],
				(int) $row['generation'],
				1 === (int) $row['is_enabled'],
				self::intOrNull( $row['weight_grams'] ),
				$basePrice,
				$selections[ $id ] ?? null,
				(int) $row['position'],
				null === $row['label'] ? null : (string) $row['label'],
				$others
			);
		}

		return $built;
	}


	/**
	 * Loads a product's option axes with their values: one statement.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $productId The product's id.
	 * @param bool $locking   Whether the read is a locking read.
	 * @return OptionMatrix The axes; the empty matrix for a product without options.
	 */
	private function loadOptions( int $productId, bool $locking ): OptionMatrix {
		$axes   = array();
		$values = array();

		foreach ( $this->rows( $locking ? self::LOCKED_OPTIONS_OF_PRODUCT : self::OPTIONS_OF_PRODUCT, $productId ) as $row ) {
			$key = (string) $row['axis_key'];

			$axes[ $key ]   ??= $row;
			$values[ $key ] ??= array();

			if ( null !== $row['value_key'] ) {
				$values[ $key ][] = OptionValue::of(
					(string) $row['value_key'],
					(string) $row['value_label'],
					(int) $row['value_position'],
					self::intOrNull( $row['image_attachment_id'] ),
					null === $row['swatch_hex'] ? null : (string) $row['swatch_hex']
				);
			}
		}

		$built = array();

		foreach ( $axes as $key => $row ) {
			$built[] = OptionAxis::of( (string) $key, (string) $row['axis_label'], (int) $row['axis_position'], $values[ $key ] );
		}

		return OptionMatrix::of( $built );
	}

	/**
	 * Loads the option values some variants select: one statement.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param bool  $locking    Whether the read is a locking read.
	 * @return array<int, VariantSelection> The selection of each variant that selects a value, by id.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	private function loadSelections( array $variantIds, bool $locking ): array {
		$pairs = array();

		foreach ( $this->rows( $locking ? self::LOCKED_SELECTIONS_OF_VARIANTS : self::SELECTIONS_OF_VARIANTS, $variantIds ) as $row ) {
			$pairs[ (int) $row['variant_id'] ][ (string) $row['axis_key'] ] = (string) $row['value_key'];
		}

		return array_map( static fn( array $selected ): VariantSelection => VariantSelection::of( $selected ), $pairs );
	}

	/**
	 * Creates a product's row, with its marker.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product.
	 * @return int The id the row was given.
	 */
	private function insertProduct( Product $product ): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at )'
				. ' VALUES ( %s, ' . self::placeholder( $product->sourcePostId() ) . ', %s, %d, %d, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
			...self::given(
				$this->table( CatalogTables::PRODUCTS ),
				$product->uuid(),
				$product->sourcePostId(),
				$product->generation()->value,
				$product->activeGeneration(),
				$product->variantCount(),
				$product->enabledVariantCount()
			)
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Publishes the generation a save wrote, with its counts and its source post: one conditional statement.
	 *
	 * It changes the row only while the product still publishes the generation it was loaded with,
	 * so a generation moved meanwhile, by a write that did not take the product's locks, is never
	 * overwritten. The row always changes when it matches, because `updated_at` only moves forward.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::WriteConflict when the product publishes another generation by now,
	 *                        or CatalogError::ProductNotFound when its row is gone.
	 *
	 * @param int     $productId The product's id.
	 * @param Product $product   The product.
	 */
	private function publishGeneration( int $productId, Product $product ): void {
		$changed = $this->write(
			self::PUBLISH_GENERATION,
			$product->sourcePostId() ?? 0,
			$product->activeGeneration(),
			$product->variantCount(),
			$product->enabledVariantCount(),
			$productId,
			$product->loadedGeneration()
		);

		if ( 1 === $changed ) {
			return;
		}

		if ( array() === $this->rows( self::PRODUCT_EXISTS, $productId ) ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => (int) $product->sourcePostId() ) );
	}

	/**
	 * Adds the bindings a product has and its stored rows do not.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $productId     The product's id.
	 * @param Product $product       The product.
	 * @param bool    $productStored Whether the product was stored before, so bindings may exist.
	 */
	private function addBindings( int $productId, Product $product, bool $productStored ): void {
		$stored = $productStored
			? array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT post_id FROM %i WHERE product_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $productId ), 'post_id' ) )
			: array();

		foreach ( $product->bindings() as $binding ) {
			if ( in_array( $binding->postId(), $stored, true ) ) {
				continue;
			}

			if ( $productStored ) {
				// A stored product gaining a post: a refusal names the post or the locale that is taken.
				$this->addBinding( $productId, $binding );

				continue;
			}

			$this->db->execute(
				'INSERT INTO %i ( post_id, product_id, locale, linked_at, linked_by_adapter, created_at ) VALUES ( %d, %d, %s, %s, ' . self::placeholder( $binding->linkedByAdapter() ) . ', UTC_TIMESTAMP() )',
				...self::given(
					$this->table( CatalogTables::PRODUCT_POSTS ),
					$binding->postId(),
					$productId,
					$binding->locale()->toString(),
					self::formatInstant( $binding->linkedAt() ),
					$binding->linkedByAdapter()
				)
			);
		}
	}

	/**
	 * Writes a product's options when they changed, its set of variants, the option values they select and their prices, and returns their ids.
	 *
	 * The set is every variant the product publishes but those it leaves out, which are disabled
	 * where they stand. A SKU the set gives a variant that did not have it is first released by any
	 * other variant of the product; the stored variants are then written by one statement and the
	 * new ones by another, and their ids read back when the set has new ones. Another product's SKU
	 * refuses the write with the table's duplicate key before its holder is read under a lock, so a
	 * holder committed after the window's snapshot is named on MariaDB as on MySQL.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::SkuTaken or CatalogError::SkuDuplicate, as save() says.
	 *
	 * @param int     $productId The product's id.
	 * @param Product $product   The product.
	 * @return array<string, int> The id of each variant of the set, by combination hash.
	 */
	private function writeVariants( int $productId, Product $product ): array {
		if ( $product->optionsChanged() ) {
			$this->writeOptions( $productId, $product->options(), $product->loadedOptions() );
		}

		$leftOut = array_map( static fn( Variant $variant ): string => $variant->combinationHash(), $product->leftOutVariants() );
		$set     = array_values( array_filter( $product->variants(), static fn( Variant $variant ): bool => ! in_array( $variant->combinationHash(), $leftOut, true ) ) );
		$ids     = array();

		if ( array() !== $set ) {
			$this->releaseSkus( $productId, $set, $product->loadedVariants() );

			$known = array_values( array_filter( $set, static fn( Variant $variant ): bool => null !== $variant->id() ) );
			$new   = array_values( array_filter( $set, static fn( Variant $variant ): bool => null === $variant->id() ) );

			try {
				if ( array() !== $known ) {
					$this->upsertVariants( $productId, $known );
				}

				if ( array() !== $new ) {
					$this->insertVariants( $productId, $new );
				}
			} catch ( DuplicateKey $duplicate ) {
				$this->refuseSkuCollision( $productId, $set );
			}

			$ids = $this->idsOf( $productId, $product->activeGeneration(), $set );
		}

		if ( array() !== $leftOut ) {
			$this->write( self::DISABLE_LEFT_OUT, $productId, $product->activeGeneration(), array_merge( array( 0 ), array_values( $ids ) ) );
		}

		if ( ! $product->options()->isEmpty() && array() !== $set ) {
			$this->writeSelections( $productId, $set, $ids );
		}

		$this->writePrices( $set, $ids, $product );

		return $ids;
	}

	/**
	 * Writes a product's whole set of option axes and values, removing the ones it no longer has, with the selections of the values removed.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $productId The product's id.
	 * @param OptionMatrix $desired   The axes and values the product has now.
	 * @param OptionMatrix $loaded    The axes and values it had when it was loaded.
	 */
	private function writeOptions( int $productId, OptionMatrix $desired, OptionMatrix $loaded ): void {
		$axes   = array_map( static fn( OptionAxis $axis ): string => $axis->key, $desired->axes );
		$values = self::valueKeys( $desired );

		// The empty key is never an option's, so a list that keeps nothing still names a value.
		if ( array() !== array_diff( self::valueKeys( $loaded ), $values ) ) {
			$this->write( self::DELETE_SELECTIONS_OF_VALUES, $productId, array_merge( array( '' ), $values ) );
			$this->write( self::DELETE_VALUES, $productId, array_merge( array( '' ), $values ) );
		}

		if ( array() !== array_diff( array_map( static fn( OptionAxis $axis ): string => $axis->key, $loaded->axes ), $axes ) ) {
			$this->write( self::DELETE_AXES, $productId, array_merge( array( '' ), $axes ) );
		}

		if ( $desired->isEmpty() ) {
			return;
		}

		$this->writeRows( self::UPSERT_AXIS, array_map( static fn( OptionAxis $axis ): array => array( $productId, $axis->key, $axis->label, $axis->position ), $desired->axes ) );

		if ( 0 === $desired->valueCount() ) {
			return;
		}

		$axisIds = array_column( $this->rows( self::AXIS_IDS, $productId ), 'id', 'axis_key' );
		$rows    = array();

		foreach ( $desired->axes as $axis ) {
			foreach ( $axis->values as $value ) {
				$rows[] = array( (int) $axisIds[ $axis->key ], $value->key, $value->label, $value->position, $value->imageAttachmentId ?? 0, $value->swatchHex ?? '' );
			}
		}

		$this->writeRows( self::UPSERT_VALUE, $rows );
	}

	/**
	 * Releases the SKUs the set gives its variants from every other variant of the product that holds one, of any generation.
	 *
	 * Sends nothing when no variant of the set is new or given another SKU than it had.
	 *
	 * @since 0.2.0
	 *
	 * @param int       $productId The product's id.
	 * @param Variant[] $set       The variants written.
	 * @param Variant[] $loaded    The variants as storage holds them for this write.
	 *
	 * @phpstan-param list<Variant> $set
	 * @phpstan-param list<Variant> $loaded
	 */
	private function releaseSkus( int $productId, array $set, array $loaded ): void {
		$stored = array();

		foreach ( $loaded as $variant ) {
			$stored[ $variant->combinationHash() ] = $variant->sku()->toString();
		}

		$moved = array_filter( $set, static fn( Variant $variant ): bool => null === $variant->id() || ( $stored[ $variant->combinationHash() ] ?? null ) !== $variant->sku()->toString() );

		if ( array() === $moved ) {
			return;
		}

		$skus    = array_map( static fn( Variant $variant ): string => $variant->sku()->toString(), $set );
		$release = array();

		foreach ( $this->rows( self::OWN_VARIANTS, $skus, $productId ) as $row ) {
			$wantedBy = $set[ (int) $row['wanted'] - 1 ] ?? null;

			if ( null !== $wantedBy && (string) $row['combination_hash'] !== $wantedBy->combinationHash() ) {
				$release[] = (int) $row['id'];
			}
		}

		if ( array() !== $release ) {
			$this->write( self::RELEASE_SKUS, $productId, $release );
		}
	}

	/**
	 * Writes the stored variants of a set: one statement for every ROWS_PER_STATEMENT of them.
	 *
	 * @since 0.2.0
	 *
	 * @param int       $productId The product's id.
	 * @param Variant[] $set       The variants, each with an id.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function upsertVariants( int $productId, array $set ): void {
		$this->writeRows(
			self::UPSERT_VARIANT,
			array_map(
				static fn( Variant $variant ): array => array(
					(int) $variant->id(),
					$variant->uuid(),
					$productId,
					$variant->sku()->toString(),
					$variant->combinationHash(),
					$variant->generation(),
					$variant->isEnabled() ? 1 : 0,
					$variant->weightGrams() ?? -1,
					$variant->position(),
					$variant->label() ?? '',
				),
				$set
			)
		);
	}

	/**
	 * Inserts the new variants of a set: one statement for every ROWS_PER_STATEMENT of them.
	 *
	 * @since 0.2.0
	 *
	 * @throws DuplicateKey When another variant holds a SKU of the set.
	 *
	 * @param int       $productId The product's id.
	 * @param Variant[] $set       The new variants.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function insertVariants( int $productId, array $set ): void {
		$this->writeRows(
			self::INSERT_VARIANT,
			array_map(
				static fn( Variant $variant ): array => array(
					$variant->uuid(),
					$productId,
					$variant->sku()->toString(),
					$variant->combinationHash(),
					$variant->generation(),
					$variant->isEnabled() ? 1 : 0,
					$variant->weightGrams() ?? -1,
					$variant->position(),
					$variant->label() ?? '',
				),
				$set
			)
		);
	}

	/**
	 * Returns the ids of a set's variants by combination hash: those it knew, and the new ones read back from their generation, each checked to hold its SKU.
	 *
	 * A new variant the upsert left unwritten, or wrote without its SKU, met a variant that holds
	 * that SKU; the save is refused as it would be had the key refused it.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::SkuTaken or CatalogError::SkuDuplicate for a variant left unwritten.
	 *
	 * @param int       $productId  The product's id.
	 * @param int       $generation The generation the set was written at.
	 * @param Variant[] $set        The variants.
	 * @return array<string, int> The ids, by combination hash.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function idsOf( int $productId, int $generation, array $set ): array {
		$ids = array();

		foreach ( $set as $variant ) {
			if ( null !== $variant->id() ) {
				$ids[ $variant->combinationHash() ] = $variant->id();
			}
		}

		if ( count( $ids ) === count( $set ) ) {
			return $ids;
		}

		$written = array();

		foreach ( $this->rows( self::VARIANTS_AT_GENERATION, $productId, $generation ) as $row ) {
			$written[ (string) $row['combination_hash'] ] = $row;
		}

		foreach ( $set as $variant ) {
			$row = $written[ $variant->combinationHash() ] ?? null;

			if ( null === $row || (string) $row['sku'] !== $variant->sku()->toString() ) {
				$this->refuseSkuCollision( $productId, $set );
			}

			$ids[ $variant->combinationHash() ] = (int) $row['id'];
		}

		return $ids;
	}

	/**
	 * Writes the option values the set's variants select, found by key: a selection there already is left as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param int                $productId The product's id.
	 * @param Variant[]          $set       The variants.
	 * @param array<string, int> $ids       Their ids, by combination hash.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function writeSelections( int $productId, array $set, array $ids ): void {
		$rows = array();

		foreach ( $set as $variant ) {
			foreach ( $variant->selection()->pairs() as $axis => $value ) {
				$rows[] = array( $ids[ $variant->combinationHash() ], $axis, $value );
			}
		}

		foreach ( array_chunk( $rows, self::ROWS_PER_STATEMENT ) as $chunk ) {
			// The rows' values come first, then the product the axes are looked up in.
			$this->write( ModuleStatements::forDerivedRows( self::INSERT_SELECTIONS, count( $chunk ) ), ...array_merge( array_merge( ...$chunk ), array( $productId ) ) );
		}
	}

	/**
	 * Writes the prices of the set's variants, and deletes the stored prices a variant no longer has.
	 *
	 * Every price of the set is written by one statement, an unchanged one keeping its time. A
	 * variant loses a price when its set of currencies lost one against what storage holds for it, a
	 * variant of an earlier generation the save brings back as any other; a new variant has none to lose.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[]          $set     The variants.
	 * @param array<string, int> $ids     Their ids, by combination hash.
	 * @param Product            $product The product.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function writePrices( array $set, array $ids, Product $product ): void {
		$stored = array();

		foreach ( $product->loadedVariants() as $variant ) {
			$stored[ $variant->combinationHash() ] = self::currenciesOf( $variant );
		}

		$rows    = array();
		$replace = array();

		foreach ( $set as $variant ) {
			$hash = $variant->combinationHash();
			$id   = $ids[ $hash ];

			foreach ( $variant->prices() as $price ) {
				$rows[] = array( $id, $price->currency()->code(), $price->amountBasis(), $price->priceMinor(), $price->compareAtMinor() ?? -1 );
			}

			if ( isset( $stored[ $hash ] ) && array() !== array_diff( $stored[ $hash ], self::currenciesOf( $variant ) ) ) {
				$replace[ $id ] = self::currenciesOf( $variant );
			}
		}

		$this->writeRows( self::UPSERT_PRICE, $rows );

		foreach ( array_chunk( $replace, self::ROWS_PER_STATEMENT, true ) as $chunk ) {
			$kept = array();

			foreach ( $chunk as $id => $currencies ) {
				foreach ( $currencies as $currency ) {
					$kept[] = array( $id, $currency );
				}
			}

			// A pair no price has, so a list that keeps nothing still names one.
			$kept = array() === $kept ? array( array( 0, '' ) ) : $kept;

			$this->write(
				str_replace( '{pairs}', implode( ', ', array_fill( 0, count( $kept ), '( %d, %s )' ) ), self::DELETE_OTHER_PRICES ),
				array_keys( $chunk ),
				...array_merge( ...$kept )
			);
		}
	}

	/**
	 * Reads every variant, of any product, that holds one of the SKUs of a set, each with the variant of the set that asked for it and its combination when it is the product's: a locking read and a read of the product's variants.
	 *
	 * A locking read sees the newest committed rows, whatever this transaction's snapshot is. The
	 * product's own variants are written only under its lock, so its consistent read names them all.
	 *
	 * @since 0.2.0
	 *
	 * @param int       $productId The product's id.
	 * @param Variant[] $set       The variants.
	 * @return list<array{id: int, own_combination: string|null, wanted_by: Variant}> The holders, by id; another product's has no combination here.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function skuHolders( int $productId, array $set ): array {
		$skus    = array_map( static fn( Variant $variant ): string => $variant->sku()->toString(), $set );
		$own     = array();
		$holders = array();

		foreach ( $this->rows( self::OWN_VARIANTS, $skus, $productId ) as $row ) {
			$own[ (int) $row['id'] ] = (string) $row['combination_hash'];
		}

		foreach ( $this->rows( self::SKU_HOLDERS, $skus, $skus ) as $row ) {
			$wantedBy = $set[ (int) $row['wanted'] - 1 ] ?? null;

			if ( null === $wantedBy ) {
				continue;
			}

			$holders[] = array(
				'id'              => (int) $row['id'],
				'own_combination' => $own[ (int) $row['id'] ] ?? null,
				'wanted_by'       => $wantedBy,
			);
		}

		return $holders;
	}

	/**
	 * Refuses a set whose write met a SKU held by another variant: `catalog.sku_taken` when another product's variant holds it, `catalog.sku_duplicate` when another variant of the set does.
	 *
	 * With no holder in sight, the set gives one SKU twice as the table's collation compares them,
	 * where the check before the window, which folds ASCII letters only, saw two, such as two that
	 * differ by an accent. No other key of the table can refuse a variant of the set: the window
	 * holds the product's lock, the ids and stored rows it writes by are the product's as read under
	 * it, and what it inserts has a UUID minted for it: the one set that carries a stored variant's
	 * UUID, made from options given alone, names that variant only by the combination its row stands
	 * for, so the variant is found and written by its id. The refusal then names a variant that has
	 * a SKU: only those can collide, and a client is never told a placeholder.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException The refusal.
	 *
	 * @param int       $productId The product's id.
	 * @param Variant[] $set       The variants written.
	 * @return never
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private function refuseSkuCollision( int $productId, array $set ): never {
		foreach ( $this->skuHolders( $productId, $set ) as $holder ) {
			if ( null === $holder['own_combination'] ) {
				self::raiseSkuTaken( $holder['wanted_by'] );
			}

			if ( $holder['own_combination'] !== $holder['wanted_by']->combinationHash() ) {
				self::raiseSkuDuplicate( $holder['wanted_by'], $set, $holder['own_combination'] );
			}
		}

		$pair = self::lookalikes( $set ) ?? array( array_values( array_filter( $set, static fn( Variant $variant ): bool => $variant->hasSku() ) )[0] ?? $set[0], null );

		self::raiseSkuDuplicate( $pair[0], $set, $pair[1] );
	}

	/**
	 * Returns a variant of a set whose SKU reads as an earlier one's once case and accents are set aside, with that one's combination hash: the pair a refusal names.
	 *
	 * The table's collation finds more SKUs equal than this does; when it finds none, the caller
	 * names a variant of the set alone.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[] $set The variants.
	 * @return array{0: Variant, 1: string}|null The later variant and the earlier one's combination hash, or null.
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private static function lookalikes( array $set ): ?array {
		$seen = array();

		foreach ( $set as $variant ) {
			$folded = mb_strtolower( remove_accents( $variant->sku()->toString(), 'en_US' ), 'UTF-8' );

			if ( isset( $seen[ $folded ] ) ) {
				return array( $variant, $seen[ $folded ] );
			}

			$seen[ $folded ] = $variant->combinationHash();
		}

		return null;
	}

	/**
	 * Raises `catalog.sku_taken` for a variant of a set, with its combination in the details.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always.
	 *
	 * @param Variant $variant The variant whose SKU another product's variant holds.
	 * @return never
	 */
	private static function raiseSkuTaken( Variant $variant ): never {
		$combination = array();

		foreach ( $variant->selection()->pairs() as $axis => $value ) {
			$combination[] = array(
				'axis'  => (string) $axis,
				'value' => $value,
			);
		}

		CodedException::raise( CatalogError::SkuTaken, array( 'sku' => $variant->sku()->toString() ), array( 'combination' => $combination ) );
	}

	/**
	 * Raises `catalog.sku_duplicate` for a variant of a set and the other variant that holds its SKU.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always.
	 *
	 * @param Variant     $variant The variant.
	 * @param Variant[]   $set     The set.
	 * @param string|null $holder  The combination hash of the variant that holds the SKU, or null when it is not known.
	 * @return never
	 *
	 * @phpstan-param list<Variant> $set
	 */
	private static function raiseSkuDuplicate( Variant $variant, array $set, ?string $holder ): never {
		$combinations = array( $variant->selection()->toString() );

		foreach ( $set as $other ) {
			if ( $other->combinationHash() === $holder ) {
				$combinations[] = $other->selection()->toString();
			}
		}

		CodedException::raise(
			CatalogError::SkuDuplicate,
			array(
				'sku'          => $variant->sku()->toString(),
				'combinations' => implode( '; ', $combinations ),
			)
		);
	}

	/**
	 * Returns the `axis_key=value_key` of every value of a matrix.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionMatrix $matrix The matrix.
	 * @return list<string> The keys.
	 */
	private static function valueKeys( OptionMatrix $matrix ): array {
		$keys = array();

		foreach ( $matrix->axes as $axis ) {
			foreach ( $axis->values as $value ) {
				$keys[] = $axis->key . '=' . $value->key;
			}
		}

		return $keys;
	}

	/**
	 * Returns the currencies a variant has prices in.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant $variant The variant.
	 * @return list<string> The currency codes.
	 */
	private static function currenciesOf( Variant $variant ): array {
		return array_map( static fn( VariantPrice $price ): string => $price->currency()->code(), $variant->prices() );
	}

	/**
	 * Sends a one-row statement for several rows: one statement for every ROWS_PER_STATEMENT rows, nothing for none.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement A one-row insert constant of this class.
	 * @param array  $rows      Each row's values, in placeholder order.
	 *
	 * @phpstan-param list<list<mixed>> $rows
	 */
	private function writeRows( string $statement, array $rows ): void {
		foreach ( array_chunk( $rows, self::ROWS_PER_STATEMENT ) as $chunk ) {
			$this->write( ModuleStatements::forRows( $statement, count( $chunk ) ), ...array_merge( ...$chunk ) );
		}
	}

	/**
	 * Sends a statement of this class that changes rows, its table tokens and lists expanded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement One of this class's constants with table tokens.
	 * @param mixed  ...$values Its values, in placeholder order; a `{list}` takes a list.
	 * @return int The rows affected.
	 */
	private function write( string $statement, mixed ...$values ): int {
		list( $sql, $arguments ) = ModuleStatements::expand( $statement, $values, CatalogTables::names(), array( $this->db, 'table' ), 'catalog' );

		return $this->db->execute( $sql, ...$arguments );
	}

	/**
	 * Sends a query of this class, its table tokens and lists expanded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement One of this class's constants with table tokens.
	 * @param mixed  ...$values Its values, in placeholder order; a `{list}` takes a list.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows( string $statement, mixed ...$values ): array {
		list( $sql, $arguments ) = ModuleStatements::expand( $statement, $values, CatalogTables::names(), array( $this->db, 'table' ), 'catalog' );

		return $this->db->fetchAll( $sql, ...$arguments );
	}

	/**
	 * Writes the `updating` mark with a new instant: one statement.
	 *
	 * The instant is always later than the row's last one, so the row always changes and the
	 * answer counts it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return int The rows changed: 1, or 0 when there is no such product.
	 */
	private function remark( int $productId ): int {
		return $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Updating->value,
			$productId
		);
	}

	/**
	 * Refuses a marker statement outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param string $method The statement's method, for the message.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message naming one of this class's methods; it is never rendered.
			throw new \LogicException( sprintf( '%s() runs inside a transaction: the mark must be read under the lock it takes, and committed or rolled back with the write it belongs to.', $method ) );
		}
	}

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	private function baseCurrency(): Currency {
		return ( $this->baseCurrency )();
	}

	/**
	 * Returns the full name of a catalog table on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of CatalogTables' names.
	 * @return string The prefixed name.
	 */
	private function table( string $name ): string {
		return $this->db->table( $name );
	}

	/**
	 * Returns the placeholder of a value that may be NULL: the literal NULL for null, or the value's placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null $value The value.
	 * @return string `NULL`, `%d` or `%s`.
	 */
	private static function placeholder( int|string|null $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		return is_int( $value ) ? '%d' : '%s';
	}

	/**
	 * Returns the arguments of a statement whose NULL values are written as literals by placeholder().
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null ...$values The values, in placeholder order, nulls included.
	 * @return list<int|string> The values without the nulls.
	 */
	private static function given( int|string|null ...$values ): array {
		return array_values( array_filter( $values, static fn( int|string|null $value ): bool => null !== $value ) );
	}

	/**
	 * Reads a column that holds an integer or NULL.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The column's value.
	 * @return int|null The integer, or null.
	 */
	private static function intOrNull( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Reads a DATETIME column, which holds UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The column's value.
	 * @return \DateTimeImmutable The instant.
	 */
	private static function instant( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Writes an instant as a DATETIME column holds it: UTC, to the second.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant.
	 * @return string The value.
	 */
	private static function formatInstant( \DateTimeImmutable $instant ): string {
		return $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::DATETIME );
	}
}
