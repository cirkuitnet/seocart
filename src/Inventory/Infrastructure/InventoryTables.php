<?php
/**
 * InventoryTables: the declarations of the four stock tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `stock_items`, `stock_ledger`, `stock_holds` and `stock_allocations`.
 *
 * Owns one fact: the shape of the inventory tables. The migration that creates them and the
 * data registry that lists them call the same factories.
 *
 * `stock_items.updated_at` is `datetime(6)` and every conditional UPDATE of an item sets it from
 * `UTC_TIMESTAMP(6)`, so a statement's affected-row count says whether its WHERE matched, never
 * whether a value happened to change. The time columns other code compares are written by the
 * database clock. The two tokens of a hold, `hold_group` and `reclaim_token`, are version 7 UUIDs
 * from the id generator, so they are `char(36)`.
 *
 * `stock_allocations` has no writer yet: order placement creates, posts and cancels its rows.
 * Its readers today are the variant delete, which is refused while an allocation is open, and
 * the stock projection check, which holds `allocated` to it.
 *
 * @since 0.1.0
 */
final class InventoryTables {

	/**
	 * The unprefixed name of the stock item table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ITEMS = 'stock_items';

	/**
	 * The unprefixed name of the stock ledger table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LEDGER = 'stock_ledger';

	/**
	 * The unprefixed name of the checkout hold table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOLDS = 'stock_holds';

	/**
	 * The unprefixed name of the order allocation table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ALLOCATIONS = 'stock_allocations';

	/**
	 * The retention policy of the checkout holds, whose period is doctor's tolerance for an expired hold.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOLDS_RETENTION = 'stock_holds';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Inventory';

	/**
	 * Returns the four declarations.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The item, ledger, hold and allocation tables, in that order.
	 */
	public static function all(): array {
		return array( self::items(), self::ledger(), self::holds(), self::allocations() );
	}

	/**
	 * Declares `stock_items`: one row per variant, the contended row every stock decision updates.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function items(): TableDefinition {
		return new TableDefinition(
			self::ITEMS,
			self::MODULE,
			'Holds, per variant, the units on hand and how many of them are allocated to orders or held by checkouts; every stock decision is one conditional update of its row.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant the item counts; its key.' ),
				new ColumnSpec( 'on_hand', 'int', Classification::Financial, 'Units physically in stock: the sum of the item\'s ledger.', defaultValue: '0' ),
				new ColumnSpec( 'allocated', 'int', Classification::Financial, 'Units promised to accepted orders: the sum of their open allocations.', defaultValue: '0' ),
				new ColumnSpec( 'held', 'int', Classification::Financial, 'Units held by checkouts: the sum of the item\'s hold rows, expired ones included until they are reclaimed.', defaultValue: '0' ),
				new ColumnSpec( 'track', 'tinyint(1)', Classification::Public, '1 when stock is counted; 0 for an item that is always available.', defaultValue: '1' ),
				new ColumnSpec( 'backorder_policy', 'varchar(8)', Classification::Public, 'no, notify or allow: whether an order may take on_hand below zero when it posts.', defaultValue: 'no', collation: 'ascii_bin' ),
				new ColumnSpec( 'low_stock_threshold', 'int', Classification::Public, 'The on_hand at or below which the item counts as low; NULL for none.', nullable: true ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'variant_id' ),
			array(),
			array(
				IndexSpec::key( 'track_threshold', array( 'track', 'low_stock_threshold' ), 'The low-stock listing: tracked items with a threshold.' ),
			),
			'entity_lifetime',
			array(
				'variants -> stock_items' => 'Cascade: deleting a variant deletes its item through the stock service. A variant without an item is given one at zero.',
			)
		);
	}

	/**
	 * Declares `stock_ledger`: every movement of on_hand, appended and never changed.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function ledger(): TableDefinition {
		return new TableDefinition(
			self::LEDGER,
			self::MODULE,
			'Records every change of an item\'s on_hand with the quantity after it, who made it and why; kept permanently because it is the record of every movement, and a deleted variant\'s rows stay with a final entry.',
			MutationPattern::AppendOnly,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key: the ledger entry id.', autoIncrement: true ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant whose stock moved; kept after the variant is deleted.' ),
				new ColumnSpec( 'delta', 'int', Classification::Financial, 'The change of on_hand, positive or negative; 0 only on the entry that records a deleted variant.' ),
				new ColumnSpec( 'on_hand_after', 'int', Classification::Financial, 'The item\'s on_hand once the change was applied.' ),
				new ColumnSpec( 'reason', 'varchar(32)', Classification::Public, 'Why the stock moved, for example received, recount or variant_deleted.', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the movement posted, when an order caused it.', nullable: true ),
				new ColumnSpec( 'allocation_id', 'bigint unsigned', Classification::Public, 'The allocation the movement posted, when an order caused it.', nullable: true ),
				new ColumnSpec( 'actor_type', 'varchar(16)', Classification::Public, 'user for a person acting in person, system for a process acting on a user\'s authority.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'actor_id',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user on whose authority the stock moved; NULL for none.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'The stock ledger is the permanent, append-only record of every stock movement; who made each one is part of that record.'
				),
				new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, 'The correlation id of the request that moved the stock, shared with its events.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the entry was appended, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'variant_created', array( 'variant_id', 'created_at' ), 'An item\'s movements in order, and the projection check\'s sum per item.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Every movement an order caused.' ),
				IndexSpec::key( 'created_at', array( 'created_at' ), 'Movements by date, for reporting.' ),
			),
			'permanent',
			array(
				'variants -> stock_ledger' => 'Retain: the entries outlive the variant, and its deletion appends one last entry.',
			)
		);
	}

	/**
	 * Declares `stock_holds`: expiring checkout holds, each summed into its item's `held`.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function holds(): TableDefinition {
		return new TableDefinition(
			self::HOLDS,
			self::MODULE,
			'Holds units for a checkout until an expiry; each row\'s quantity is part of its item\'s held, and is given back when the row is released or reclaimed after expiry.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant whose units are held.' ),
				new ColumnSpec( 'cart_id', 'bigint unsigned', Classification::Public, 'The cart the hold was taken for, when there is one.', nullable: true ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the hold was taken for, when there is one.', nullable: true ),
				new ColumnSpec( 'hold_group', 'char(36)', Classification::Public, 'The hold the row belongs to: one id for every line held together.', collation: 'ascii_bin' ),
				new ColumnSpec( 'quantity', 'int', Classification::Public, 'Units held, 1 or more.' ),
				new ColumnSpec( 'expires_at', 'datetime', Classification::Public, 'When the hold expires, UTC, from the database clock.' ),
				new ColumnSpec( 'reclaim_token', 'char(36)', Classification::Public, 'The token of the reclaim that is giving the row back, inside its transaction; NULL otherwise.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the hold was taken, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'variant_expires', array( 'variant_id', 'expires_at' ), 'The claim of one item\'s expired holds.' ),
				IndexSpec::key( 'reclaim_token', array( 'reclaim_token' ), 'Gives back and deletes the rows one reclaim claimed.' ),
				IndexSpec::key( 'hold_group', array( 'hold_group' ), 'Releases every row of one hold.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Releases the holds of an order whose payment was declined.' ),
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The sweep\'s search for expired holds.' ),
			),
			self::HOLDS_RETENTION,
			array(
				'variants -> stock_holds' => 'Released when the variant is deleted: the rows are given back before the item goes.',
			)
		);
	}

	/**
	 * Declares `stock_allocations`: units promised to accepted orders, summed into `allocated` while open.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function allocations(): TableDefinition {
		return new TableDefinition(
			self::ALLOCATIONS,
			self::MODULE,
			'Promises units to an accepted order line until they are posted to the ledger or the order is cancelled; each open row\'s unposted quantity is part of its item\'s allocated.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant whose units are allocated.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the units are promised to.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The order line the units are promised to.' ),
				new ColumnSpec( 'quantity', 'int', Classification::Financial, 'Units allocated.' ),
				new ColumnSpec( 'posted_quantity', 'int', Classification::Financial, 'Units of the allocation already posted to the ledger.', defaultValue: '0' ),
				new ColumnSpec( 'state', 'varchar(12)', Classification::Public, 'open, posted or cancelled.', defaultValue: 'open', collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the allocation was made, UTC, from the database clock.' ),
				new ColumnSpec( 'posted_at', 'datetime', Classification::Public, 'When the allocation was fully posted, UTC.', nullable: true ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_line_allocation', array( 'order_line_id', 'variant_id' ), 'One allocation per order line and variant.' ),
			),
			array(
				IndexSpec::key( 'variant_state', array( 'variant_id', 'state' ), 'An item\'s open allocations: the projection check and the refusal to delete a variant.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Posts or cancels every allocation of an order.' ),
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'Finds allocations left open too long.' ),
			),
			'entity_lifetime',
			array(
				'variants -> stock_allocations' => 'Block: a variant with an open allocation cannot be deleted. An open allocation whose item is gone is reported for a person.',
			)
		);
	}
}
