<?php
/**
 * MysqlStockRepository: every stock statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure;

use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\BackorderPolicy;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\LocationInUse;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockPolicy;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The stock repository on MySQL: the one class that sends SQL to the stock tables.
 *
 * Owns one fact: the text of every stock statement. Each is a public constant, so a
 * concurrency test sends exactly the statement this class sends. A statement names its tables
 * as tokens of the inventory tables, such as `{stock_items}` and `{stock_policies}`, and an IN
 * list as `{list}`; expand() turns them into wpdb placeholders and arguments. An insert of
 * several rows is its one-row constant with the VALUES tuple repeated once per row
 * (ModuleStatements::forRows(), through insertRows()). Written that way, a test can read from the
 * constants alone that no statement updates or deletes the ledger.
 *
 * It keeps the one-lock rule StockRepository states: the claims, of a hold and of an allocation,
 * the conversion of a hold into an allocation, the upsert CREATE_ITEM_AT, which an adjustment and
 * a transfer's receipt send first and TRANSFER_IN follows, the two statements that send a
 * transfer's units, TRANSFER_OUT_ALLOCATED and TRANSFER_OUT_AVAILABLE, and the item lock are the
 * statements that take an item's row lock, and every
 * reclaim begins with the item lock, so its hold rows are claimed, given back and deleted by the
 * item's lock holder alone; a conversion deletes or reduces its hold row only once its own
 * statement holds the lock. Every conditional update of an item sets `updated_at` from the database clock, and
 * the item lock moves it forward by at least a microsecond, so one affected row always means the
 * WHERE clause matched. Reads made under a lock are locking reads, so they see the latest
 * committed row and never an older snapshot of the transaction.
 *
 * The reads for `doctor` live here too, because they are stock SQL; they are not part of the
 * port the service sees.
 *
 * @since 0.1.0
 */
final class MysqlStockRepository implements StockRepository {

	/**
	 * Each variant's policy, the default location, and the database's time plus a TTL: the one read before any lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONFIGURATION = 'SELECT p.variant_id, p.track, p.backorder_policy, p.low_stock_threshold, ( SELECT l.id FROM {locations} l WHERE l.is_default = 1 ) AS default_location_id, UTC_TIMESTAMP() + INTERVAL %d SECOND AS expires_at FROM {stock_policies} p WHERE p.variant_id IN ({list})';

	/**
	 * The claim: adds to an item's `held` only when that many units are available there.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM = 'UPDATE {stock_items} SET held = held + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND ( on_hand - allocated - held ) >= %d';

	/**
	 * A hold row, inserted once every line of the hold was claimed, the hold's rows together; 0 stands for no cart or no order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_HOLD = 'INSERT INTO {stock_holds} ( variant_id, location_id, cart_id, order_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, NULLIF( %d, 0 ), NULLIF( %d, 0 ), %s, %d, %s, UTC_TIMESTAMP(6) )';

	/**
	 * The units of each item one hold has rows of, ascending: what an allocation converts, and a release gives back.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOLD_ROWS = 'SELECT variant_id, location_id, SUM( quantity ) AS quantity FROM {stock_holds} WHERE hold_group = %s GROUP BY variant_id, location_id ORDER BY variant_id, location_id';

	/**
	 * The conversion of held units into allocated ones: only when `held` covers them, which the hold's own row does.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONVERT_HOLD = 'UPDATE {stock_items} SET held = held - %d, allocated = allocated + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND held >= %d';

	/**
	 * The proof that converted units were the hold's: its row of the item, of exactly those units, deleted under the item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_HOLD_ROW = 'DELETE FROM {stock_holds} WHERE hold_group = %s AND variant_id = %d AND location_id = %d AND quantity = %d AND reclaim_token IS NULL';

	/**
	 * The same proof when part of the row converted: the row, of exactly the units read, keeps the rest, under the item's lock.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REDUCE_HOLD_ROW = 'UPDATE {stock_holds} SET quantity = quantity - %d WHERE hold_group = %s AND variant_id = %d AND location_id = %d AND quantity = %d AND reclaim_token IS NULL';

	/**
	 * Undoes a conversion whose hold row was gone, in the same transaction and under the same lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNDO_CONVERT = 'UPDATE {stock_items} SET held = held + %d, allocated = allocated - %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d';

	/**
	 * The claim of an allocation without a hold: adds to an item's `allocated` only when that many units are available there.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_ALLOCATION = 'UPDATE {stock_items} SET allocated = allocated + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND ( on_hand - allocated - held ) >= %d';

	/**
	 * An open allocation row, inserted once every line of the order had its units added to `allocated`; the rows of one order go in together.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_ALLOCATION = "INSERT INTO {stock_allocations} ( variant_id, location_id, order_id, order_line_id, quantity, posted_quantity, state, created_at ) VALUES ( %d, %d, %d, %d, %d, 0, 'open', UTC_TIMESTAMP(6) )";

	/**
	 * The most rows one insert of hold or allocation rows writes, so that a hold or an order of any size sends statements of a few kilobytes each.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ROWS_PER_INSERT = 100;

	/**
	 * The item lock: the first statement of every reclaim. Moves `updated_at` forward, so it always changes the row.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_ITEM = 'UPDATE {stock_items} SET updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE variant_id = %d AND location_id = %d';

	/**
	 * Every item of a variant, locked in ascending location order: the first lock of a variant's delete.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_ITEMS_OF = 'SELECT location_id FROM {stock_items} WHERE variant_id = %d ORDER BY location_id FOR UPDATE';

	/**
	 * Claims the item's expired hold rows no other reclaim has claimed, by token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_EXPIRED = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE variant_id = %d AND location_id = %d AND expires_at <= UTC_TIMESTAMP() AND reclaim_token IS NULL';

	/**
	 * Claims the item's rows of one hold, expired or not, that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_GROUP = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE hold_group = %s AND variant_id = %d AND location_id = %d AND reclaim_token IS NULL';

	/**
	 * Claims every hold row of the item that no other reclaim has claimed.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLAIM_ITEM = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE variant_id = %d AND location_id = %d AND reclaim_token IS NULL';

	/**
	 * Gives the claimed quantity back to the item, only when `held` covers it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GIVE_BACK = 'UPDATE {stock_items} SET held = held - ( SELECT COALESCE( SUM( h.quantity ), 0 ) FROM {stock_holds} h WHERE h.reclaim_token = %s ), updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND held >= ( SELECT COALESCE( SUM( c.quantity ), 0 ) FROM {stock_holds} c WHERE c.reclaim_token = %s )';

	/**
	 * The claimed rows, for the events: read after they were given back, before they are deleted.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIMED_ROWS = 'SELECT id, hold_group, quantity, expires_at FROM {stock_holds} WHERE reclaim_token = %s ORDER BY id';

	/**
	 * Deletes the claimed rows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_CLAIMED = 'DELETE FROM {stock_holds} WHERE reclaim_token = %s';

	/**
	 * The locations each of some variants has an item at, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ITEM_LOCATIONS = 'SELECT variant_id, location_id FROM {stock_items} WHERE variant_id IN ({list}) ORDER BY variant_id, location_id';

	/**
	 * A page of items with expired, unclaimed hold rows, ascending from an item.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EXPIRED_ITEMS = 'SELECT DISTINCT variant_id, location_id FROM {stock_holds} WHERE expires_at <= UTC_TIMESTAMP() AND reclaim_token IS NULL AND ( variant_id > %d OR ( variant_id = %d AND location_id > %d ) ) ORDER BY variant_id, location_id LIMIT %d';

	/**
	 * A page of variant ids that have a stock policy, ascending from a variant id above a cursor.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ITEM_VARIANT_IDS = 'SELECT variant_id FROM {stock_policies} WHERE variant_id > %d ORDER BY variant_id LIMIT %d';

	/**
	 * The same page, from the very first variant id.
	 *
	 * Its own statement, not ITEM_VARIANT_IDS with a 0: MySQL costs `variant_id > 0` as a
	 * primary-key range and estimates roughly half of a very large table for it, defeating the
	 * cheap, LIMIT-bounded plan a first page should get for a comparison every row satisfies
	 * anyway. itemVariantIds() sends this one for that page; every caller does so today.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ITEM_VARIANT_IDS_FROM_START = 'SELECT variant_id FROM {stock_policies} ORDER BY variant_id LIMIT %d';

	/**
	 * Whether a variant has a policy and a location exists, both locked in share mode until the commit: an adjustment's first statement.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_ADJUSTABLE = 'SELECT p.variant_id, l.id AS location_id FROM {stock_policies} p LEFT JOIN {locations} l ON l.id = %d WHERE p.variant_id = %d LOCK IN SHARE MODE';

	/**
	 * Creates an item at zero, or leaves it as it is: either way the statement holds the row's lock until the commit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE_ITEM_AT = 'INSERT INTO {stock_items} ( variant_id, location_id, updated_at ) VALUES ( %d, %d, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id';

	/**
	 * Creates a variant's policy at the declared defaults, or leaves it as it is; one affected row means it was created.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE_POLICY = 'INSERT INTO {stock_policies} ( variant_id, updated_at ) VALUES ( %d, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id';

	/**
	 * Sets a recreated item's `held` to the sum of its hold rows, under the lock its creation took.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESTORE_HELD = 'UPDATE {stock_items} SET held = ( SELECT COALESCE( SUM( h.quantity ), 0 ) FROM {stock_holds} h WHERE h.variant_id = %d AND h.location_id = %d ), updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d';

	/**
	 * The adjustment: adds to an item's on_hand only when the result is not negative.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUST = 'UPDATE {stock_items} SET on_hand = on_hand + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND on_hand + %d >= 0';

	/**
	 * The adjustment with a precondition: also only when on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUST_EXPECTING = 'UPDATE {stock_items} SET on_hand = on_hand + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND on_hand + %d >= 0 AND on_hand = %d';

	/**
	 * A ledger entry; 0 stands for no actor and no transfer, and an empty string for no correlation id.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The transfer.
	 *
	 * @var string
	 */
	public const APPEND_LEDGER = "INSERT INTO {stock_ledger} ( variant_id, location_id, delta, on_hand_after, reason, transfer_id, actor_type, actor_id, correlation_id, created_at ) VALUES ( %d, %d, %d, %d, %s, NULLIF( %d, 0 ), %s, NULLIF( %d, 0 ), NULLIF( %s, '' ), UTC_TIMESTAMP(6) )";

	/**
	 * Variants' levels at the default location, with their policies, a plain read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LEVELS = 'SELECT i.variant_id, i.location_id, i.on_hand, i.allocated, i.held, p.track, p.backorder_policy FROM {stock_policies} p JOIN {stock_items} i ON i.variant_id = p.variant_id AND i.location_id = ( SELECT l.id FROM {locations} l WHERE l.is_default = 1 ) WHERE p.variant_id IN ({list}) ORDER BY p.variant_id';

	/**
	 * Variants' levels at every location they have an item at, with their policies, a plain read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LEVELS_AT = 'SELECT i.variant_id, i.location_id, i.on_hand, i.allocated, i.held, p.track, p.backorder_policy FROM {stock_policies} p JOIN {stock_items} i ON i.variant_id = p.variant_id WHERE p.variant_id IN ({list}) ORDER BY p.variant_id, i.location_id';

	/**
	 * Variants' policies, each with its items at every location it has one at, a plain read: the stock list. A variant with a policy and no item reads as one row with no location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STOCK_LIST = 'SELECT p.variant_id, p.track, p.backorder_policy, p.low_stock_threshold, i.location_id, i.on_hand, i.allocated, i.held FROM {stock_policies} p LEFT JOIN {stock_items} i ON i.variant_id = p.variant_id WHERE p.variant_id IN ({list}) ORDER BY p.variant_id, i.location_id';

	/**
	 * What some locations have of some variants, row by row, with each variant's policy: routing's one read of stock. A variant with no row at a location reads as no units there.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AVAILABILITY = 'SELECT p.variant_id, p.track, p.backorder_policy, p.low_stock_threshold, i.location_id, ( i.on_hand - i.allocated - i.held ) AS available FROM {stock_policies} p LEFT JOIN {stock_items} i ON i.variant_id = p.variant_id AND i.location_id IN ({list}) WHERE p.variant_id IN ({list}) ORDER BY p.variant_id, i.location_id';

	/**
	 * Each variant's units available over some locations, summed and at the one that has most, each location at no fewer than zero: the figure a shopper is shown.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SALE_FIGURES = 'SELECT p.variant_id, p.track, p.backorder_policy, p.low_stock_threshold, COALESCE( SUM( GREATEST( i.on_hand - i.allocated - i.held, 0 ) ), 0 ) AS total, COALESCE( MAX( GREATEST( i.on_hand - i.allocated - i.held, 0 ) ), 0 ) AS largest FROM {stock_policies} p LEFT JOIN {stock_items} i ON i.variant_id = p.variant_id AND i.location_id IN ({list}) WHERE p.variant_id IN ({list}) GROUP BY p.variant_id, p.track, p.backorder_policy, p.low_stock_threshold ORDER BY p.variant_id';

	/**
	 * One item's level, a locking read of the item; its policy and its variant's totals at every location are read with it, by subqueries that lock nothing.
	 *
	 * A locking read locks the rows of its own FROM clause only, not those its subqueries read, so
	 * the variant's other items are read as last committed and stay free for their own holders:
	 * an item is never locked out of the lock order by another item's read.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The variant's totals.
	 *
	 * @var string
	 */
	public const LOCKED_LEVEL = 'SELECT i.variant_id, i.location_id, i.on_hand, i.allocated, i.held, ( SELECT p.track FROM {stock_policies} p WHERE p.variant_id = i.variant_id ) AS track, ( SELECT b.backorder_policy FROM {stock_policies} b WHERE b.variant_id = i.variant_id ) AS backorder_policy, ( SELECT SUM( t.on_hand ) FROM {stock_items} t WHERE t.variant_id = i.variant_id ) AS variant_on_hand, ( SELECT SUM( a.on_hand - a.allocated - a.held ) FROM {stock_items} a WHERE a.variant_id = i.variant_id ) AS variant_available FROM {stock_items} i WHERE i.variant_id = %d AND i.location_id = %d FOR UPDATE';

	/**
	 * One allocation of a variant, at any location, open or travelling with a transfer, a locking read.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 In transit too.
	 *
	 * @var string
	 */
	public const OPEN_ALLOCATION = "SELECT id FROM {stock_allocations} WHERE variant_id = %d AND state IN ('open','in_transit') LIMIT 1 FOR UPDATE";

	/**
	 * A variant's policy, locked: the first lock of a variant's delete.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_POLICY = 'SELECT variant_id FROM {stock_policies} WHERE variant_id = %d FOR UPDATE';

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_ITEM = 'DELETE FROM {stock_items} WHERE variant_id = %d AND location_id = %d';

	/**
	 * Deletes a variant's policy.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_POLICY = 'DELETE FROM {stock_policies} WHERE variant_id = %d';

	/**
	 * Items whose `held` is not the sum of their hold rows, expired rows included.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HELD_DRIFT = 'SELECT i.variant_id, i.location_id, i.held, COALESCE( SUM( h.quantity ), 0 ) AS rows_sum FROM {stock_items} i LEFT JOIN {stock_holds} h ON h.variant_id = i.variant_id AND h.location_id = i.location_id GROUP BY i.variant_id, i.location_id, i.held HAVING i.held <> rows_sum ORDER BY i.variant_id, i.location_id LIMIT %d';

	/**
	 * Items whose on_hand is not the sum of their ledger, or not their newest entry's on_hand_after.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ON_HAND_DRIFT = 'SELECT i.variant_id, i.location_id, i.on_hand, COALESCE( SUM( l.delta ), 0 ) AS ledger_sum, ( SELECT n.on_hand_after FROM {stock_ledger} n WHERE n.variant_id = i.variant_id AND n.location_id = i.location_id ORDER BY n.id DESC LIMIT 1 ) AS newest FROM {stock_items} i LEFT JOIN {stock_ledger} l ON l.variant_id = i.variant_id AND l.location_id = i.location_id GROUP BY i.variant_id, i.location_id, i.on_hand HAVING ledger_sum <> i.on_hand OR ( newest IS NOT NULL AND newest <> i.on_hand ) ORDER BY i.variant_id, i.location_id LIMIT %d';

	/**
	 * Items whose `allocated` is not the unposted sum of their open allocations; an allocation in transit counts at no item.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ALLOCATED_DRIFT = "SELECT i.variant_id, i.location_id, i.allocated, COALESCE( SUM( a.quantity - a.posted_quantity ), 0 ) AS open_sum FROM {stock_items} i LEFT JOIN {stock_allocations} a ON a.variant_id = i.variant_id AND a.location_id = i.location_id AND a.state = 'open' GROUP BY i.variant_id, i.location_id, i.allocated HAVING i.allocated <> open_sum ORDER BY i.variant_id, i.location_id LIMIT %d";

	/**
	 * Items with hold rows that expired longer ago than a tolerance and were never reclaimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STALE_HOLDS = 'SELECT variant_id, location_id, COUNT(*) AS stale_rows, TIMESTAMPDIFF( SECOND, MIN( expires_at ), UTC_TIMESTAMP() ) AS oldest_seconds FROM {stock_holds} WHERE expires_at < UTC_TIMESTAMP() - INTERVAL %d SECOND GROUP BY variant_id, location_id ORDER BY variant_id, location_id LIMIT %d';

	/**
	 * Items with committed hold rows that still carry a reclaim token, which only a reclaim inside its own transaction may.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TOKENED_HOLDS = 'SELECT variant_id, location_id, COUNT(*) AS tokened_rows FROM {stock_holds} WHERE reclaim_token IS NOT NULL GROUP BY variant_id, location_id ORDER BY variant_id, location_id LIMIT %d';

	/**
	 * Open allocations whose item is gone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ORPHAN_ALLOCATIONS = "SELECT a.id, a.variant_id, a.location_id, a.order_id FROM {stock_allocations} a LEFT JOIN {stock_items} i ON i.variant_id = a.variant_id AND i.location_id = a.location_id WHERE a.state = 'open' AND i.variant_id IS NULL ORDER BY a.id LIMIT %d";

	/**
	 * The units each hold holds at an item that is gone, which no reclaim can give back.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORPHAN_HOLDS = 'SELECT h.variant_id, h.location_id, h.hold_group, SUM( h.quantity ) AS quantity FROM {stock_holds} h LEFT JOIN {stock_items} i ON i.variant_id = h.variant_id AND i.location_id = h.location_id WHERE i.variant_id IS NULL GROUP BY h.variant_id, h.location_id, h.hold_group ORDER BY h.variant_id, h.location_id, h.hold_group LIMIT %d';

	/**
	 * Open allocations at a location that is gone.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALLOCATIONS_AT_LOST_LOCATIONS = "SELECT a.id, a.variant_id, a.location_id, a.order_id FROM {stock_allocations} a LEFT JOIN {locations} l ON l.id = a.location_id WHERE a.state = 'open' AND l.id IS NULL ORDER BY a.id LIMIT %d";

	/**
	 * Items at a location that is gone.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ITEMS_AT_LOST_LOCATIONS = 'SELECT i.variant_id, i.location_id FROM {stock_items} i LEFT JOIN {locations} l ON l.id = i.location_id WHERE l.id IS NULL ORDER BY i.variant_id, i.location_id LIMIT %d';

	/**
	 * The locations the stock ledger names that no longer exist, read one ledger entry per location from the location_created index.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LEDGER_AT_LOST_LOCATIONS = 'SELECT d.location_id FROM ( SELECT DISTINCT location_id FROM {stock_ledger} ) d LEFT JOIN {locations} l ON l.id = d.location_id WHERE l.id IS NULL ORDER BY d.location_id LIMIT %d';

	/**
	 * Variants with an item but no policy.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ITEMS_WITHOUT_POLICY = 'SELECT DISTINCT i.variant_id FROM {stock_items} i LEFT JOIN {stock_policies} p ON p.variant_id = i.variant_id WHERE p.variant_id IS NULL ORDER BY i.variant_id LIMIT %d';

	/**
	 * Variants with a policy but no item at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const POLICIES_WITHOUT_DEFAULT_ITEM = 'SELECT p.variant_id FROM {stock_policies} p LEFT JOIN {stock_items} i ON i.variant_id = p.variant_id AND i.location_id = ( SELECT l.id FROM {locations} l WHERE l.is_default = 1 ) WHERE i.variant_id IS NULL ORDER BY p.variant_id LIMIT %d';

	/**
	 * The units available at each of some locations, each item at no fewer than zero: a location's total, for the deactivated ones doctor watches.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_UNITS = 'SELECT location_id, SUM( GREATEST( on_hand - allocated - held, 0 ) ) AS available FROM {stock_items} WHERE location_id IN ({list}) GROUP BY location_id ORDER BY location_id';

	/**
	 * The units on hand at each of some locations, from the location_on_hand index alone: the list of locations shows them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_ON_HAND = 'SELECT location_id, SUM( on_hand ) AS on_hand FROM {stock_items} WHERE location_id IN ({list}) GROUP BY location_id ORDER BY location_id';

	/**
	 * The open allocations at each of some locations, from the location_state index alone: the list of locations shows them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_OPEN_ALLOCATIONS = "SELECT location_id, COUNT(*) AS open_allocations FROM {stock_allocations} WHERE location_id IN ({list}) AND state = 'open' GROUP BY location_id ORDER BY location_id";

	/**
	 * What refers to one location, one EXISTS per kind of record: its ledger, its items with any units, the holds of its items, its allocations, and its transfers out and in. A plain read.
	 *
	 * The holds are found through the location's items, by the item_expires index: a hold whose
	 * item is gone is a doctor finding, not a use.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_USE = 'SELECT EXISTS ( SELECT 1 FROM {stock_ledger} WHERE location_id = %d ) AS ledger, EXISTS ( SELECT 1 FROM {stock_items} WHERE location_id = %d AND ( on_hand <> 0 OR allocated <> 0 OR held <> 0 ) ) AS stock, EXISTS ( SELECT 1 FROM {stock_items} i JOIN {stock_holds} h ON h.variant_id = i.variant_id AND h.location_id = i.location_id WHERE i.location_id = %d ) AS holds, EXISTS ( SELECT 1 FROM {stock_allocations} WHERE location_id = %d ) AS allocations, EXISTS ( SELECT 1 FROM {stock_transfers} WHERE source_location_id = %d ) AS transfers_out, EXISTS ( SELECT 1 FROM {stock_transfers} WHERE destination_location_id = %d ) AS transfers_in';

	/**
	 * Deletes a location's items that count nothing: no unit on hand, allocated or held.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_EMPTY_ITEMS_AT = 'DELETE FROM {stock_items} WHERE location_id = %d AND on_hand = 0 AND allocated = 0 AND held = 0';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Turns a statement's tokens into wpdb placeholders and its values into arguments, in order.
	 *
	 * The plugin's one token expansion (ModuleStatements::expand()) over the inventory tables: a
	 * table token becomes `%i` with the table's full name as its argument; `{list}` becomes one
	 * placeholder per id of the next value, or NULL for an empty list, which matches no row; `%d`,
	 * `%s` and `%i` each take the next value. The caller prepares the result with wpdb.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a token names no inventory table, or the values do not match the placeholders.
	 *
	 * @param string   $statement One of this class's constants.
	 * @param array    $values    The values, in placeholder order.
	 * @param callable $tableName Returns a table's full name from its unprefixed name (string).
	 * @return array{0: string, 1: list<mixed>} The statement with wpdb placeholders, and its arguments.
	 *
	 * @phpstan-param list<mixed>                $values
	 * @phpstan-param callable(string): string $tableName
	 */
	public static function expand( string $statement, array $values, callable $tableName ): array {
		return ModuleStatements::expand( $statement, $values, InventoryTables::names(), $tableName, 'inventory' );
	}

	/**
	 * Reads each variant's policy and the default location, and computes the expiry of a hold taken now. Locks nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param int   $ttlSeconds How long the hold lasts.
	 * @return array{policies: array<int, StockPolicy>, default_location_id: int|null, expires_at: string|null} The policies, the default location and the expiry.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array {
		$policies  = array();
		$default   = null;
		$expiresAt = null;

		foreach ( $this->rows( self::CONFIGURATION, $ttlSeconds, array_values( $variantIds ) ) as $row ) {
			$policies[ (int) $row['variant_id'] ] = self::policy( $row );
			$default                              = null === $row['default_location_id'] ? null : (int) $row['default_location_id'];
			$expiresAt                            = (string) $row['expires_at'];
		}

		return array(
			'policies'            => $policies,
			'default_location_id' => $default,
			'expires_at'          => $expiresAt,
		);
	}

	/**
	 * Adds a quantity to an item's `held`, if that many units are available there.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when the claim matched.
	 */
	public function claim( int $variantId, int $locationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::CLAIM, $quantity, $variantId, $locationId, $quantity );
	}

	/**
	 * Inserts a hold's rows, in the order given, after their claims: one statement for every ROWS_PER_INSERT rows.
	 *
	 * @since 0.1.0
	 *
	 * @param HoldLine[] $lines     The lines claimed, one per item, each naming its location; none sends nothing.
	 * @param string     $holdGroup The hold's id.
	 * @param string     $expiresAt The expiry, UTC.
	 * @param int|null   $cartId    The cart, or null.
	 * @param int|null   $orderId   The order, or null.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	public function insertHolds( array $lines, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->insertRows(
			self::INSERT_HOLD,
			array_map( static fn( HoldLine $line ): array => array( $line->variantId, self::located( $line->locationId ), $cartId ?? 0, $orderId ?? 0, $holdGroup, $line->quantity, $expiresAt ), $lines )
		);
	}

	/**
	 * Reads one hold's rows, item by item.
	 *
	 * @since 0.2.0
	 *
	 * @param string $holdGroup The hold.
	 * @return list<array{variant_id: int, location_id: int, quantity: int}> The units of each item, ascending.
	 */
	public function holdRows( string $holdGroup ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'quantity'    => (int) $row['quantity'],
			),
			$this->rows( self::HOLD_ROWS, $holdGroup )
		);
	}

	/**
	 * Turns some or all of a hold's units of one item into allocated units, when the hold still has its row of the item.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The part of a row.
	 *
	 * @throws \LogicException When the units are not from 1 to the row's: a caller's bug, before any statement.
	 *
	 * @param string $holdGroup   The hold.
	 * @param int    $variantId   The item's variant.
	 * @param int    $locationId  The item's location.
	 * @param int    $quantity    The units to convert.
	 * @param int    $rowQuantity The units the hold's row holds, as read.
	 * @return bool True when the units are allocated and the row is gone or holds only the rest; false when nothing changed.
	 */
	public function convertHold( string $holdGroup, int $variantId, int $locationId, int $quantity, int $rowQuantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		if ( $quantity < 1 || $quantity > $rowQuantity ) {
			throw new \LogicException( sprintf( 'A conversion takes from 1 to the %d units of the hold\'s row; %d were asked for.', $rowQuantity, $quantity ) );
		}

		if ( 1 !== $this->write( self::CONVERT_HOLD, $quantity, $quantity, $variantId, $locationId, $quantity ) ) {
			return false;
		}

		$proof = $quantity === $rowQuantity
			? $this->write( self::DELETE_HOLD_ROW, $holdGroup, $variantId, $locationId, $rowQuantity )
			: $this->write( self::REDUCE_HOLD_ROW, $quantity, $holdGroup, $variantId, $locationId, $rowQuantity );

		if ( 1 === $proof ) {
			return true;
		}

		// The hold's row is gone, so the units converted were another hold's: give them back.
		$this->write( self::UNDO_CONVERT, $quantity, $quantity, $variantId, $locationId );

		return false;
	}

	/**
	 * Adds a quantity to an item's `allocated`, if that many units are available there.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when the claim matched.
	 */
	public function claimAllocation( int $variantId, int $locationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::CLAIM_ALLOCATION, $quantity, $variantId, $locationId, $quantity );
	}

	/**
	 * Inserts an order's open allocation rows, in the order given: one statement for every ROWS_PER_INSERT rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $orderId     The order.
	 * @param Allocation[] $allocations The order lines, each with its variant, location and units; none sends nothing.
	 *
	 * @phpstan-param list<Allocation> $allocations
	 */
	public function insertAllocations( int $orderId, array $allocations ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->insertRows(
			self::INSERT_ALLOCATION,
			array_map( static fn( Allocation $allocation ): array => array( $allocation->variantId, self::located( $allocation->locationId ), $orderId, $allocation->orderLineId, $allocation->quantity ), $allocations )
		);
	}

	/**
	 * Takes the item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the item exists and is now locked.
	 */
	public function lockItem( int $variantId, int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::LOCK_ITEM, $variantId, $locationId );
	}

	/**
	 * Gives back the item's expired hold rows that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimExpired( int $variantId, int $locationId, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $locationId, $token, self::CLAIM_EXPIRED, array( $token, $variantId, $locationId ) );
	}

	/**
	 * Gives back the item's rows of one hold.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $holdGroup  The hold.
	 * @param string $token      A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimGroup( int $variantId, int $locationId, string $holdGroup, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $locationId, $token, self::CLAIM_GROUP, array( $token, $holdGroup, $variantId, $locationId ) );
	}

	/**
	 * Gives back every hold row of the item.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimItem( int $variantId, int $locationId, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $locationId, $token, self::CLAIM_ITEM, array( $token, $variantId, $locationId ) );
	}

	/**
	 * Lists the locations each of some variants has an item at.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<int>> The locations by variant, both ascending.
	 */
	public function itemLocations( array $variantIds ): array {
		$locations = array();

		if ( array() === $variantIds ) {
			return $locations;
		}

		foreach ( $this->rows( self::ITEM_LOCATIONS, array_values( $variantIds ) ) as $row ) {
			$locations[ (int) $row['variant_id'] ][] = (int) $row['location_id'];
		}

		return $locations;
	}

	/**
	 * Locks every item of a variant, ascending, and lists their locations.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return list<int> The locations, ascending.
	 */
	public function lockItemsOf( int $variantId ): array {
		$this->requireTransaction( __FUNCTION__ );

		return array_map( static fn( array $row ): int => (int) $row['location_id'], $this->rows( self::LOCK_ITEMS_OF, $variantId ) );
	}

	/**
	 * Lists items with expired, unclaimed hold rows.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterVariantId  Only items after this variant and location.
	 * @param int $afterLocationId See $afterVariantId.
	 * @param int $limit           The most items.
	 * @return list<array{0: int, 1: int}> Each item's variant and location, ascending.
	 */
	public function expiredItems( int $afterVariantId, int $afterLocationId, int $limit ): array {
		return array_map( static fn( array $row ): array => array( (int) $row['variant_id'], (int) $row['location_id'] ), $this->rows( self::EXPIRED_ITEMS, $afterVariantId, $afterVariantId, $afterLocationId, $limit ) );
	}

	/**
	 * Lists the variant ids that have a stock policy.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only variants with a higher id.
	 * @param int $limit The most variants to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemVariantIds( int $after, int $limit ): array {
		if ( $after > 0 ) {
			return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::ITEM_VARIANT_IDS, $after, $limit ) );
		}

		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::ITEM_VARIANT_IDS_FROM_START, $limit ) );
	}

	/**
	 * Tells whether a variant has a policy and a location exists, with a shared lock on both.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return array{policy: bool, location: bool} Whether each exists.
	 */
	public function lockAdjustable( int $variantId, int $locationId ): array {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->rows( self::LOCK_ADJUSTABLE, $locationId, $variantId )[0] ?? null;

		return array(
			'policy'   => null !== $row,
			'location' => null !== $row && null !== $row['location_id'],
		);
	}

	/**
	 * Creates an item at zero when it does not exist, and takes its lock either way.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 */
	public function createItemAt( int $variantId, int $locationId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->write( self::CREATE_ITEM_AT, $variantId, $locationId );
	}

	/**
	 * Recreates an item its hold rows outlived, when it does not exist: at zero, then `held` from its rows, under its lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the item was created; false when it existed, and was left as it is.
	 */
	public function restoreItem( int $variantId, int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		// An upsert affects one row when it inserted, and none when the row was there.
		if ( 1 !== $this->write( self::CREATE_ITEM_AT, $variantId, $locationId ) ) {
			return false;
		}

		$this->write( self::RESTORE_HELD, $variantId, $locationId, $variantId, $locationId );

		return true;
	}

	/**
	 * Adds a delta to an item's on_hand, if the result is not negative and on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId      The item's variant.
	 * @param int      $locationId     The item's location.
	 * @param int      $delta          The change.
	 * @param int|null $expectedOnHand The on_hand the caller read, or null.
	 * @return bool True when the update matched.
	 */
	public function adjust( int $variantId, int $locationId, int $delta, ?int $expectedOnHand ): bool {
		$this->requireTransaction( __FUNCTION__ );

		if ( null === $expectedOnHand ) {
			return 1 === $this->write( self::ADJUST, $delta, $variantId, $locationId, $delta );
		}

		return 1 === $this->write( self::ADJUST_EXPECTING, $delta, $variantId, $locationId, $delta, $expectedOnHand );
	}

	/**
	 * Appends a ledger entry.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $variantId     The item's variant.
	 * @param int          $locationId    The item's location.
	 * @param int          $delta         The change.
	 * @param int          $onHandAfter   on_hand after it.
	 * @param LedgerReason $reason        Why.
	 * @param string       $actorType     `user` or `system`.
	 * @param int|null     $actorId       The user, or null.
	 * @param string|null  $correlationId The correlation id, or null.
	 * @param int|null     $transferId    Optional. The transfer, or null. Default null.
	 * @return int The entry's id.
	 */
	public function appendLedger( int $variantId, int $locationId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId, ?int $transferId = null ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->write( self::APPEND_LEDGER, $variantId, $locationId, $delta, $onHandAfter, $reason->value, $transferId ?? 0, $actorType, $actorId ?? 0, $correlationId ?? '' );

		return $this->db->lastInsertId();
	}

	/**
	 * Reads variants' levels at the default location, a plain read.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, StockLevel> The levels, by variant id.
	 */
	public function levels( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		$levels = array();

		foreach ( $this->rows( self::LEVELS, array_values( $variantIds ) ) as $row ) {
			$level                       = self::level( $row );
			$levels[ $level->variantId ] = $level;
		}

		return $levels;
	}

	/**
	 * Reads variants' levels at every location, a plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<StockLevel>> The levels by variant id, ascending by location.
	 */
	public function levelsAt( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		$levels = array();

		foreach ( $this->rows( self::LEVELS_AT, array_values( $variantIds ) ) as $row ) {
			$level                         = self::level( $row );
			$levels[ $level->variantId ][] = $level;
		}

		return $levels;
	}

	/**
	 * Reads variants' policies, each with its levels at every location it has an item at. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, array{policy: StockPolicy, levels: list<StockLevel>}> Each variant that has a policy, ascending, by variant id; its levels ascending by location.
	 */
	public function stockList( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		$stock = array();

		foreach ( $this->rows( self::STOCK_LIST, array_values( $variantIds ) ) as $row ) {
			$variantId = (int) $row['variant_id'];

			$stock[ $variantId ] ??= array(
				'policy' => self::policy( $row ),
				'levels' => array(),
			);

			if ( null !== $row['location_id'] ) {
				$stock[ $variantId ]['levels'][] = self::level( $row );
			}
		}

		return $stock;
	}

	/**
	 * Reads what some locations have of some variants, with each variant's policy. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations.
	 * @return array{policies: array<int, StockPolicy>, available: array<int, array<int, int>>} The policies by variant, and the units by location then variant.
	 */
	public function availability( array $variantIds, array $locationIds ): array {
		$policies  = array();
		$available = array();

		if ( array() === $variantIds ) {
			return array(
				'policies'  => $policies,
				'available' => $available,
			);
		}

		foreach ( $this->rows( self::AVAILABILITY, array_values( $locationIds ), array_values( $variantIds ) ) as $row ) {
			$policies[ (int) $row['variant_id'] ] = self::policy( $row );

			if ( null !== $row['location_id'] ) {
				$available[ (int) $row['location_id'] ][ (int) $row['variant_id'] ] = (int) $row['available'];
			}
		}

		return array(
			'policies'  => $policies,
			'available' => $available,
		);
	}

	/**
	 * Reads each variant's units available over some locations, summed and at the one that has most. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations.
	 * @return array<int, array{policy: StockPolicy, total: int, largest: int}> The figures, by variant id.
	 */
	public function saleFigures( array $variantIds, array $locationIds ): array {
		$figures = array();

		if ( array() === $variantIds ) {
			return $figures;
		}

		foreach ( $this->rows( self::SALE_FIGURES, array_values( $locationIds ), array_values( $variantIds ) ) as $row ) {
			$figures[ (int) $row['variant_id'] ] = array(
				'policy'  => self::policy( $row ),
				'total'   => (int) $row['total'],
				'largest' => (int) $row['largest'],
			);
		}

		return $figures;
	}

	/**
	 * Reads one item's level with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return StockLevel|null The level, or null when the item does not exist.
	 */
	public function lockedLevel( int $variantId, int $locationId ): ?StockLevel {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->rows( self::LOCKED_LEVEL, $variantId, $locationId )[0] ?? null;

		return null === $row ? null : self::level( $row );
	}

	/**
	 * Gives variants their policy and their item at a location, at zero, leaving any that exist unchanged: two statements per variant, ascending.
	 *
	 * A variant is reported when either upsert inserted its row; one whose two rows were both there
	 * already, whoever wrote them, is not.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param int   $locationId The location of the items.
	 * @return list<int> The variants whose policy row or row at the location this call inserted, ascending.
	 */
	public function createItems( array $variantIds, int $locationId ): array {
		$this->requireTransaction( __FUNCTION__ );

		$created = array();

		foreach ( $variantIds as $variantId ) {
			// An upsert affects one row when it inserted, and none when the row was there: the answer is the inserts' own.
			$policy = $this->write( self::CREATE_POLICY, (int) $variantId );
			$item   = $this->write( self::CREATE_ITEM_AT, (int) $variantId, $locationId );

			if ( 1 === $policy || 1 === $item ) {
				$created[] = (int) $variantId;
			}
		}

		return $created;
	}

	/**
	 * Tells whether a variant has an open allocation at any location, with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when one is open.
	 */
	public function hasOpenAllocation( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return array() !== $this->rows( self::OPEN_ALLOCATION, $variantId );
	}

	/**
	 * Takes the lock of a variant's policy.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when it has one.
	 */
	public function lockPolicy( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return array() !== $this->rows( self::LOCK_POLICY, $variantId );
	}

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the row was deleted.
	 */
	public function deleteItem( int $variantId, int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::DELETE_ITEM, $variantId, $locationId );
	}

	/**
	 * Deletes a variant's policy.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when the row was deleted.
	 */
	public function deletePolicy( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::DELETE_POLICY, $variantId );
	}

	/**
	 * Lists items whose `held` is not the sum of their hold rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{variant_id: int, location_id: int, held: int, rows_sum: int}> The items, ascending.
	 */
	public function heldDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'held'        => (int) $row['held'],
				'rows_sum'    => (int) $row['rows_sum'],
			),
			$this->rows( self::HELD_DRIFT, $limit )
		);
	}

	/**
	 * Lists items whose on_hand disagrees with their ledger.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{variant_id: int, location_id: int, on_hand: int, ledger_sum: int, newest: int|null}> The items, ascending.
	 */
	public function onHandDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'on_hand'     => (int) $row['on_hand'],
				'ledger_sum'  => (int) $row['ledger_sum'],
				'newest'      => null === $row['newest'] ? null : (int) $row['newest'],
			),
			$this->rows( self::ON_HAND_DRIFT, $limit )
		);
	}

	/**
	 * Lists items whose `allocated` is not the unposted sum of their open allocations.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{variant_id: int, location_id: int, allocated: int, open_sum: int}> The items, ascending.
	 */
	public function allocatedDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'allocated'   => (int) $row['allocated'],
				'open_sum'    => (int) $row['open_sum'],
			),
			$this->rows( self::ALLOCATED_DRIFT, $limit )
		);
	}

	/**
	 * Lists items with hold rows that expired longer ago than a tolerance.
	 *
	 * @since 0.1.0
	 *
	 * @param int $toleranceSeconds How long after its expiry a row may still be there.
	 * @param int $limit            The most items to list.
	 * @return list<array{variant_id: int, location_id: int, stale_rows: int, oldest_seconds: int}> The items, ascending.
	 */
	public function staleHolds( int $toleranceSeconds, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'     => (int) $row['variant_id'],
				'location_id'    => (int) $row['location_id'],
				'stale_rows'     => (int) $row['stale_rows'],
				'oldest_seconds' => (int) $row['oldest_seconds'],
			),
			$this->rows( self::STALE_HOLDS, $toleranceSeconds, $limit )
		);
	}

	/**
	 * Lists items with committed hold rows that carry a reclaim token.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{variant_id: int, location_id: int, tokened_rows: int}> The items, ascending.
	 */
	public function tokenedHolds( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'   => (int) $row['variant_id'],
				'location_id'  => (int) $row['location_id'],
				'tokened_rows' => (int) $row['tokened_rows'],
			),
			$this->rows( self::TOKENED_HOLDS, $limit )
		);
	}

	/**
	 * Lists open allocations whose item is gone.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most allocations to list.
	 * @return list<array{id: int, variant_id: int, location_id: int, order_id: int}> The allocations, by id.
	 */
	public function orphanAllocations( int $limit ): array {
		return self::allocationRows( $this->rows( self::ORPHAN_ALLOCATIONS, $limit ) );
	}

	/**
	 * Lists the units each hold holds at an item that is gone.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most holds to list.
	 * @return list<array{variant_id: int, location_id: int, hold_group: string, quantity: int}> Each hold's units at each such item, ascending by item.
	 */
	public function orphanHolds( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'hold_group'  => (string) $row['hold_group'],
				'quantity'    => (int) $row['quantity'],
			),
			$this->rows( self::ORPHAN_HOLDS, $limit )
		);
	}

	/**
	 * Lists open allocations at a location that is gone.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most allocations to list.
	 * @return list<array{id: int, variant_id: int, location_id: int, order_id: int}> The allocations, by id.
	 */
	public function allocationsAtLostLocations( int $limit ): array {
		return self::allocationRows( $this->rows( self::ALLOCATIONS_AT_LOST_LOCATIONS, $limit ) );
	}

	/**
	 * Lists items at a location that is gone.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{0: int, 1: int}> Each item's variant and location, ascending.
	 */
	public function itemsAtLostLocations( int $limit ): array {
		return array_map( static fn( array $row ): array => array( (int) $row['variant_id'], (int) $row['location_id'] ), $this->rows( self::ITEMS_AT_LOST_LOCATIONS, $limit ) );
	}

	/**
	 * Lists the locations the stock ledger names that are gone.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most locations to list.
	 * @return list<int> The location ids, ascending.
	 */
	public function ledgerAtLostLocations( int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['location_id'], $this->rows( self::LEDGER_AT_LOST_LOCATIONS, $limit ) );
	}

	/**
	 * Lists variants with an item but no policy.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most variants to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemsWithoutPolicy( int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::ITEMS_WITHOUT_POLICY, $limit ) );
	}

	/**
	 * Lists variants with a policy but no item at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most variants to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function policiesWithoutDefaultItem( int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::POLICIES_WITHOUT_DEFAULT_ITEM, $limit ) );
	}

	/**
	 * Reads the units available at each of some locations.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $locationIds The locations; none reads nothing.
	 * @return array<int, int> The units of each location that has items, by location id.
	 */
	public function locationUnits( array $locationIds ): array {
		$units = array();

		if ( array() === $locationIds ) {
			return $units;
		}

		foreach ( $this->rows( self::LOCATION_UNITS, array_values( $locationIds ) ) as $row ) {
			$units[ (int) $row['location_id'] ] = (int) $row['available'];
		}

		return $units;
	}

	/**
	 * Reads the units on hand and the open allocations at each of some locations: two grouped reads.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $locationIds The locations; none reads nothing.
	 * @return array<int, array{on_hand: int, open_allocations: int}> Each location's figures, by location id; zeros for one with neither.
	 */
	public function locationTotals( array $locationIds ): array {
		$totals = array();

		if ( array() === $locationIds ) {
			return $totals;
		}

		foreach ( $locationIds as $locationId ) {
			$totals[ (int) $locationId ] = array(
				'on_hand'          => 0,
				'open_allocations' => 0,
			);
		}

		foreach ( $this->rows( self::LOCATION_ON_HAND, array_values( $locationIds ) ) as $row ) {
			$totals[ (int) $row['location_id'] ]['on_hand'] = (int) $row['on_hand'];
		}

		foreach ( $this->rows( self::LOCATION_OPEN_ALLOCATIONS, array_values( $locationIds ) ) as $row ) {
			$totals[ (int) $row['location_id'] ]['open_allocations'] = (int) $row['open_allocations'];
		}

		return $totals;
	}

	/**
	 * Says what refers to a location, in the order a refused deletion names it: its ledger, its units, its holds, its allocations, its transfers.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location.
	 * @return LocationInUse|null The first use found, or null when nothing refers to the location.
	 */
	public function locationUse( int $locationId ): ?LocationInUse {
		$row = $this->rows( self::LOCATION_USE, $locationId, $locationId, $locationId, $locationId, $locationId, $locationId )[0] ?? array();
		$use = array(
			'ledger'        => LocationInUse::Ledger,
			'stock'         => LocationInUse::Stock,
			'holds'         => LocationInUse::Holds,
			'allocations'   => LocationInUse::Allocations,
			'transfers_out' => LocationInUse::Transfers,
			'transfers_in'  => LocationInUse::Transfers,
		);

		foreach ( $use as $column => $reason ) {
			if ( '1' === (string) ( $row[ $column ] ?? '0' ) ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Deletes a location's items that count nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location.
	 * @return int How many items were deleted.
	 */
	public function deleteEmptyItemsAt( int $locationId ): int {
		$this->requireTransaction( __FUNCTION__ );

		return $this->write( self::DELETE_EMPTY_ITEMS_AT, $locationId );
	}

	/**
	 * Gives claimed hold rows back to their item: the one way any path returns held units.
	 *
	 * Four statements, in the caller's transaction: the item lock; the claim, by token, with the
	 * caller's predicate; the give-back, which subtracts the claimed sum from `held` only when
	 * `held` covers it; then the claimed rows are read for the events and deleted. When the claim
	 * matched nothing, there is nothing to give back and the other statements are skipped.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      A fresh token.
	 * @param string $claim      The claim statement: CLAIM_EXPIRED, CLAIM_GROUP or CLAIM_ITEM.
	 * @param array  $values     The claim statement's values.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-param list<mixed> $values
	 */
	private function reclaim( int $variantId, int $locationId, string $token, string $claim, array $values ): ReclaimedRows {
		if ( ! $this->lockItem( $variantId, $locationId ) ) {
			return ReclaimedRows::itemMissing( $variantId, $locationId );
		}

		if ( 0 === $this->write( $claim, ...$values ) ) {
			return ReclaimedRows::of( $variantId, $locationId, array() );
		}

		if ( 1 !== $this->write( self::GIVE_BACK, $token, $variantId, $locationId, $token ) ) {
			return ReclaimedRows::corrupt( $variantId, $locationId );
		}

		$rows = array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'hold_group' => (string) $row['hold_group'],
				'quantity'   => (int) $row['quantity'],
				'expires_at' => (string) $row['expires_at'],
			),
			$this->rows( self::CLAIMED_ROWS, $token )
		);

		$this->write( self::DELETE_CLAIMED, $token );

		return ReclaimedRows::of( $variantId, $locationId, $rows );
	}

	/**
	 * Sends a statement that changes rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement One of this class's constants.
	 * @param mixed  ...$values Its values.
	 * @return int The rows affected.
	 */
	private function write( string $statement, mixed ...$values ): int {
		list( $sql, $arguments ) = self::expand( $statement, $values, array( $this->db, 'table' ) );

		return $this->db->execute( $sql, ...$arguments );
	}

	/**
	 * Sends a one-row insert for several rows, in the order given: its VALUES tuple once per row, ROWS_PER_INSERT rows to a statement at most.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement One of this class's one-row insert constants.
	 * @param array  $rows      Each row's values, in placeholder order; none sends nothing.
	 *
	 * @phpstan-param list<list<mixed>> $rows
	 */
	private function insertRows( string $statement, array $rows ): void {
		foreach ( array_chunk( $rows, self::ROWS_PER_INSERT ) as $chunk ) {
			$this->write( ModuleStatements::forRows( $statement, count( $chunk ) ), ...array_merge( ...$chunk ) );
		}
	}

	/**
	 * Sends a query.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement One of this class's constants.
	 * @param mixed  ...$values Its values.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows( string $statement, mixed ...$values ): array {
		list( $sql, $arguments ) = self::expand( $statement, $values, array( $this->db, 'table' ) );

		return $this->db->fetchAll( $sql, ...$arguments );
	}

	/**
	 * Refuses to change stock outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlStockRepository::%s() runs only inside a transaction: its statement is one of a group that must commit together.', $method ) );
		}
	}

	/**
	 * Returns the location a row is written at, refusing one with none.
	 *
	 * A multi-row insert would store a missing location as 0 on a server that is not strict, so
	 * the service resolves every row's location before it reaches here.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the location is null: a caller's bug.
	 *
	 * @param int|null $locationId The location.
	 * @return int The location.
	 */
	private static function located( ?int $locationId ): int {
		if ( null === $locationId ) {
			throw new \LogicException( 'A stock row is written at a location; resolve the default location before writing it.' );
		}

		return $locationId;
	}

	/**
	 * Builds the allocation rows doctor reports.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 * @return list<array{id: int, variant_id: int, location_id: int, order_id: int}> The allocations.
	 */
	private static function allocationRows( array $rows ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'order_id'    => (int) $row['order_id'],
			),
			$rows
		);
	}

	/**
	 * Builds a variant's policy from a row that has its columns.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return StockPolicy The policy.
	 */
	private static function policy( array $row ): StockPolicy {
		return new StockPolicy(
			(int) $row['variant_id'],
			'1' === (string) $row['track'],
			BackorderPolicy::from( (string) $row['backorder_policy'] ),
			null === $row['low_stock_threshold'] ? null : (int) $row['low_stock_threshold']
		);
	}

	/**
	 * Builds a level from a row.
	 *
	 * An item without a policy row, which doctor reports, reads as tracked with no backorders, so
	 * it never passes for a variant whose stock is not counted. The variant's totals are there only
	 * when the read asked for them, as LOCKED_LEVEL does.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The variant's totals.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return StockLevel The level.
	 */
	private static function level( array $row ): StockLevel {
		return new StockLevel(
			(int) $row['variant_id'],
			(int) $row['location_id'],
			(int) $row['on_hand'],
			(int) $row['allocated'],
			(int) $row['held'],
			null === $row['track'] || '1' === (string) $row['track'],
			BackorderPolicy::tryFrom( (string) $row['backorder_policy'] ) ?? BackorderPolicy::No,
			isset( $row['variant_on_hand'] ) ? (int) $row['variant_on_hand'] : null,
			isset( $row['variant_available'] ) ? (int) $row['variant_available'] : null
		);
	}

	/**
	 * One allocation of an order, in any state, on the allocations' order key: whether its lines were allocated.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_ALLOCATION = 'SELECT id FROM {stock_allocations} WHERE order_id = %d LIMIT 1';

	/**
	 * Tells whether an order holds an allocation, in any state: one read on the allocations' order key.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when it holds one.
	 */
	public function hasAllocationsFor( int $orderId ): bool {
		return array() !== $this->rows( self::ORDER_ALLOCATION, $orderId );
	}

	/**
	 * One line of a variant on a transfer requested or in transit, a locking read of the line and its transfer: a variant's delete waits for, or is refused by, a transfer of its units.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_IN_FLIGHT = "SELECT l.id FROM {stock_transfer_lines} l JOIN {stock_transfers} t ON t.id = l.transfer_id WHERE l.variant_id = %d AND t.state IN ('requested','in_transit') LIMIT 1 FOR UPDATE";

	/**
	 * Sends an order's allocated units from an item: they leave on_hand and `allocated` together, as a post does, only when the item has them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_OUT_ALLOCATED = 'UPDATE {stock_items} SET on_hand = on_hand - %d, allocated = allocated - %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND allocated >= %d AND on_hand >= %d';

	/**
	 * Sends available units from an item: only when that many are available, the claim's own predicate.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_OUT_AVAILABLE = 'UPDATE {stock_items} SET on_hand = on_hand - %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND ( on_hand - allocated - held ) >= %d';

	/**
	 * Receives units at an item, the allocated ones with them: after CREATE_ITEM_AT, which holds the item.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_IN = 'UPDATE {stock_items} SET on_hand = on_hand + %d, allocated = allocated + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d';

	/**
	 * An open allocation at a location sets out with its units: in transit, it counts at no item.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALLOCATION_IN_TRANSIT = "UPDATE {stock_allocations} SET state = 'in_transit' WHERE id = %d AND state = 'open' AND location_id = %d";

	/**
	 * An allocation in transit, a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const IN_TRANSIT_ALLOCATION = "SELECT order_id, order_line_id, quantity FROM {stock_allocations} WHERE id = %d AND state = 'in_transit' FOR UPDATE";

	/**
	 * The units a transfer brought an order line at its destination: its open allocation there, created or added to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALLOCATE_RECEIVED = "INSERT INTO {stock_allocations} ( variant_id, location_id, order_id, order_line_id, quantity, posted_quantity, state, created_at ) VALUES ( %d, %d, %d, %d, %d, 0, 'open', UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES( quantity )";

	/**
	 * Takes units received or lost off an allocation in transit that keeps some on the way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SHRINK_IN_TRANSIT = "UPDATE {stock_allocations} SET quantity = quantity - %d WHERE id = %d AND state = 'in_transit' AND quantity > %d";

	/**
	 * Deletes an allocation in transit whose last units were received or lost.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_IN_TRANSIT = "DELETE FROM {stock_allocations} WHERE id = %d AND state = 'in_transit' AND quantity = %d";

	/**
	 * An order's open allocations away from one location, ascending by location then variant: what a waiting order needs brought to it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPEN_ALLOCATIONS_AWAY = "SELECT id, variant_id, location_id, quantity FROM {stock_allocations} WHERE order_id = %d AND state = 'open' AND location_id <> %d ORDER BY location_id, variant_id";

	/**
	 * An order's allocations open or in transit, ascending by variant then location: what its cancel gives back or detaches.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_ALLOCATIONS = "SELECT id, variant_id, location_id, quantity, posted_quantity, state FROM {stock_allocations} WHERE order_id = %d AND state IN ('open','in_transit') ORDER BY variant_id, location_id, id";

	/**
	 * Cancels an open allocation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCEL_OPEN_ALLOCATION = "UPDATE {stock_allocations} SET state = 'cancelled' WHERE id = %d AND state = 'open'";

	/**
	 * Gives units a cancelled allocation promised back to its item, only when `allocated` covers them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GIVE_BACK_ALLOCATED = 'UPDATE {stock_items} SET allocated = allocated - %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND location_id = %d AND allocated >= %d';

	/**
	 * Cancels an allocation in transit; its units stay on the transfer's line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCEL_IN_TRANSIT_ALLOCATION = "UPDATE {stock_allocations} SET state = 'cancelled' WHERE id = %d AND state = 'in_transit'";

	/**
	 * A page of a location's variants with units available, ascending after a variant: what a deactivation's move takes next.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MOVABLE_ITEMS = 'SELECT variant_id FROM {stock_items} WHERE location_id = %d AND variant_id > %d AND on_hand - allocated - held > 0 ORDER BY variant_id LIMIT %d';

	/**
	 * Tells whether any line of a variant is on a transfer requested or in transit, with a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when one is.
	 */
	public function hasTransferInFlight( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return array() !== $this->rows( self::TRANSFER_IN_FLIGHT, $variantId );
	}

	/**
	 * Sends an order's allocated units from an item.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when the update matched.
	 */
	public function transferOutAllocated( int $variantId, int $locationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::TRANSFER_OUT_ALLOCATED, $quantity, $quantity, $variantId, $locationId, $quantity, $quantity );
	}

	/**
	 * Sends available units from an item.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when the update matched.
	 */
	public function transferOutAvailable( int $variantId, int $locationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::TRANSFER_OUT_AVAILABLE, $quantity, $variantId, $locationId, $quantity );
	}

	/**
	 * Receives units at an item.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units received.
	 * @param int $allocated  How many of them an order waits for.
	 * @return bool True when the item was there.
	 */
	public function transferIn( int $variantId, int $locationId, int $quantity, int $allocated ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::TRANSFER_IN, $quantity, $allocated, $variantId, $locationId );
	}

	/**
	 * Moves an open allocation to in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @param int $locationId   The location its units leave.
	 * @return bool True when it was open there.
	 */
	public function allocationInTransit( int $allocationId, int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::ALLOCATION_IN_TRANSIT, $allocationId, $locationId );
	}

	/**
	 * Reads an allocation in transit with a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @return array{order_id: int, order_line_id: int, quantity: int}|null The allocation, or null.
	 */
	public function lockInTransitAllocation( int $allocationId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->rows( self::IN_TRANSIT_ALLOCATION, $allocationId )[0] ?? null;

		return null === $row ? null : array(
			'order_id'      => (int) $row['order_id'],
			'order_line_id' => (int) $row['order_line_id'],
			'quantity'      => (int) $row['quantity'],
		);
	}

	/**
	 * Gives an order line its received units at the destination.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId   The variant.
	 * @param int $locationId  The destination.
	 * @param int $orderId     The order.
	 * @param int $orderLineId The order line.
	 * @param int $quantity    The units.
	 */
	public function allocateReceived( int $variantId, int $locationId, int $orderId, int $orderLineId, int $quantity ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->write( self::ALLOCATE_RECEIVED, $variantId, $locationId, $orderId, $orderLineId, $quantity );
	}

	/**
	 * Takes units off an allocation in transit that keeps some.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @param int $quantity     The units.
	 * @return bool True when it held more.
	 */
	public function shrinkInTransit( int $allocationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::SHRINK_IN_TRANSIT, $quantity, $allocationId, $quantity );
	}

	/**
	 * Deletes an allocation in transit whose last units arrived or were lost.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @param int $quantity     The units it holds.
	 * @return bool True when it held exactly that many.
	 */
	public function deleteInTransit( int $allocationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::DELETE_IN_TRANSIT, $allocationId, $quantity );
	}

	/**
	 * Lists an order's open allocations away from a location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId    The order.
	 * @param int $locationId The location to leave out.
	 * @return list<array{id: int, variant_id: int, location_id: int, quantity: int}> The allocations.
	 */
	public function openAllocationsAway( int $orderId, int $locationId ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'variant_id'  => (int) $row['variant_id'],
				'location_id' => (int) $row['location_id'],
				'quantity'    => (int) $row['quantity'],
			),
			$this->rows( self::OPEN_ALLOCATIONS_AWAY, $orderId, $locationId )
		);
	}

	/**
	 * Lists an order's allocations open or in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{id: int, variant_id: int, location_id: int, quantity: int, posted_quantity: int, in_transit: bool}> The allocations.
	 */
	public function orderAllocations( int $orderId ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'              => (int) $row['id'],
				'variant_id'      => (int) $row['variant_id'],
				'location_id'     => (int) $row['location_id'],
				'quantity'        => (int) $row['quantity'],
				'posted_quantity' => (int) $row['posted_quantity'],
				'in_transit'      => 'in_transit' === (string) $row['state'],
			),
			$this->rows( self::ORDER_ALLOCATIONS, $orderId )
		);
	}

	/**
	 * Cancels an open allocation.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @return bool True when it was open.
	 */
	public function cancelOpenAllocation( int $allocationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::CANCEL_OPEN_ALLOCATION, $allocationId );
	}

	/**
	 * Gives a cancelled allocation's units back to its item.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when `allocated` covered them.
	 */
	public function giveBackAllocated( int $variantId, int $locationId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::GIVE_BACK_ALLOCATED, $quantity, $variantId, $locationId, $quantity );
	}

	/**
	 * Cancels an allocation in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $allocationId The allocation.
	 * @return bool True when it was in transit.
	 */
	public function cancelInTransitAllocation( int $allocationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::CANCEL_IN_TRANSIT_ALLOCATION, $allocationId );
	}

	/**
	 * Lists a page of a location's variants with units available.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId     The location.
	 * @param int $afterVariantId Only variants with a higher id, or 0.
	 * @param int $limit          The most variants.
	 * @return list<int> The variants, ascending.
	 */
	public function movableItems( int $locationId, int $afterVariantId, int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::MOVABLE_ITEMS, $locationId, $afterVariantId, $limit ) );
	}
}
