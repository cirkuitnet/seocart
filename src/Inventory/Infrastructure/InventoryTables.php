<?php
/**
 * InventoryTables: the declarations of the stock and location tables
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
 * Declares the inventory module's ten tables: the stock rows, their ledger, holds and allocations, the variants' stock policies, the locations, and the transfers between them.
 *
 * Owns one fact: the shape of the inventory tables. The migrations that create and change them
 * and the data registry that lists them call the same factories.
 *
 * A stock item is one row of `stock_items` per variant and location: the contended row every stock
 * decision updates. Holds, allocations and ledger entries each name the variant and the location
 * of the row they count in, so a row's `held`, `allocated` and `on_hand` are each the sum of its
 * own rows. What is true of a variant wherever its units are, whether stock is counted, its
 * backorder policy and its low-stock threshold, is one row of `stock_policies`.
 *
 * `stock_items.updated_at` is `datetime(6)` and every conditional UPDATE of an item sets it from
 * `UTC_TIMESTAMP(6)`, so a statement's affected-row count says whether its WHERE matched, never
 * whether a value happened to change. The time columns other code compares are written by the
 * database clock. The two tokens of a hold, `hold_group` and `reclaim_token`, are version 7 UUIDs
 * from the id generator, so they are `char(36)`.
 *
 * Order placement creates allocation rows and a settlement converts holds into them. Units sent
 * from one location to another are on the transfer line between them, in neither location's
 * row; an allocation travelling with them is `in_transit` and counts in no row.
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
	 * The unprefixed name of the stock policy table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const POLICIES = 'stock_policies';

	/**
	 * The unprefixed name of the location table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATIONS = 'locations';

	/**
	 * The unprefixed name of the table of the markets each location serves.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_MARKETS = 'location_markets';

	/**
	 * The unprefixed name of the table of each location's pickup hours.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PICKUP_HOURS = 'location_pickup_hours';

	/**
	 * The unprefixed name of the stock transfer table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFERS = 'stock_transfers';

	/**
	 * The unprefixed name of the stock transfer line table.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_LINES = 'stock_transfer_lines';

	/**
	 * The retention policy of the checkout holds, whose period is doctor's tolerance for an expired hold.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOLDS_RETENTION = 'stock_holds';

	/**
	 * The retention policy of the transfers: kept as long as the financial records whose movements they explain.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TRANSFERS_RETENTION = 'financial';

	/**
	 * The module every table belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULE = 'Inventory';

	/**
	 * Returns the ten declarations.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The policy, location and transfer tables.
	 *
	 * @return list<TableDefinition> The item, ledger, hold, allocation, policy, location, location market, pickup hour,
	 *                               transfer and transfer line tables, in that order.
	 */
	public static function all(): array {
		return array(
			self::items(),
			self::ledger(),
			self::holds(),
			self::allocations(),
			self::policies(),
			self::locations(),
			self::locationMarkets(),
			self::pickupHours(),
			self::transfers(),
			self::transferLines(),
		);
	}

	/**
	 * Returns the unprefixed names of the ten tables, which the module's statements may name.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names, in the order all() declares them.
	 */
	public static function names(): array {
		return array( self::ITEMS, self::LEDGER, self::HOLDS, self::ALLOCATIONS, self::POLICIES, self::LOCATIONS, self::LOCATION_MARKETS, self::PICKUP_HOURS, self::TRANSFERS, self::TRANSFER_LINES );
	}

	/**
	 * Declares `stock_items`: one row per variant and location, the contended row every stock decision updates.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Keyed by the variant and the location; the policy columns moved to `stock_policies`.
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function items(): TableDefinition {
		return new TableDefinition(
			self::ITEMS,
			self::MODULE,
			'Holds, per variant and location, the units on hand there and how many of them are allocated to orders or held by checkouts; every stock decision is one conditional update of its row.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant the item counts; with the location, its key.' ),
				self::location( 'The location whose units the item counts; with the variant, its key.' ),
				new ColumnSpec( 'on_hand', 'int', Classification::Financial, 'Units physically in stock at the location: the sum of the item\'s ledger.', defaultValue: '0' ),
				new ColumnSpec( 'allocated', 'int', Classification::Financial, 'Units there promised to accepted orders: the sum of the item\'s open allocations.', defaultValue: '0' ),
				new ColumnSpec( 'held', 'int', Classification::Financial, 'Units there held by checkouts: the sum of the item\'s hold rows, expired ones included until they are reclaimed.', defaultValue: '0' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'variant_id', 'location_id' ),
			array(),
			array(
				IndexSpec::key( 'location_on_hand', array( 'location_id', 'on_hand' ), 'A location\'s units in stock, for the locations list, the deactivation of a location and doctor.' ),
			),
			'entity_lifetime',
			array(
				'variants -> stock_items'  => 'Cascade: deleting a variant deletes its items, at every location, through the stock service. A variant without its item at the default location is given one at zero.',
				'locations -> stock_items' => 'Block: a location that still has units, holds or allocations cannot be deleted; its items at zero go with it.',
			)
		);
	}

	/**
	 * Declares `stock_ledger`: every movement of on_hand, appended and never changed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location of each movement, and the transfer it belongs to.
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
				self::location( 'The location where the stock moved.' ),
				new ColumnSpec( 'delta', 'int', Classification::Financial, 'The change of on_hand, positive or negative; 0 only on the entry that records a deleted variant.' ),
				new ColumnSpec( 'on_hand_after', 'int', Classification::Financial, 'The item\'s on_hand once the change was applied.' ),
				new ColumnSpec( 'reason', 'varchar(32)', Classification::Public, 'Why the stock moved, for example received, recount or variant_deleted.', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the movement posted, when an order caused it.', nullable: true ),
				new ColumnSpec( 'allocation_id', 'bigint unsigned', Classification::Public, 'The allocation the movement posted, when an order caused it.', nullable: true ),
				self::transferId(),
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
				IndexSpec::key( 'item_created', array( 'variant_id', 'location_id', 'created_at' ), 'An item\'s movements in order, and the projection check\'s sum per item.' ),
				IndexSpec::key( 'location_created', array( 'location_id', 'created_at' ), 'A location\'s movements in order.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Every movement an order caused.' ),
				IndexSpec::key( 'transfer_id', array( 'transfer_id' ), 'The two ends of each movement of a transfer.' ),
				IndexSpec::key( 'created_at', array( 'created_at' ), 'Movements by date, for reporting.' ),
			),
			'permanent',
			array(
				'variants -> stock_ledger'  => 'Retain: the entries outlive the variant, and its deletion appends one last entry per location.',
				'locations -> stock_ledger' => 'Block: a location with ledger entries is deactivated, never deleted.',
			)
		);
	}

	/**
	 * Declares `stock_holds`: expiring checkout holds, each summed into its item's `held`.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location of each hold.
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
				self::location( 'The location whose units are held.' ),
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
				IndexSpec::key( 'item_expires', array( 'variant_id', 'location_id', 'expires_at' ), 'The claim of one item\'s expired holds.' ),
				IndexSpec::key( 'reclaim_token', array( 'reclaim_token' ), 'Gives back and deletes the rows one reclaim claimed.' ),
				IndexSpec::key( 'hold_group', array( 'hold_group' ), 'Releases every row of one hold.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Releases the holds of an order whose payment was declined.' ),
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The sweep\'s search for expired holds.' ),
			),
			self::HOLDS_RETENTION,
			array(
				'variants -> stock_holds' => 'Released when the variant is deleted: the rows are given back before the items go.',
			)
		);
	}

	/**
	 * Declares `stock_allocations`: units promised to accepted orders, summed into `allocated` while open.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location of each allocation, and the state of one travelling with a transfer.
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
				self::location( 'The location whose units are allocated.' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order the units are promised to.' ),
				new ColumnSpec( 'order_line_id', 'bigint unsigned', Classification::Public, 'The order line the units are promised to.' ),
				new ColumnSpec( 'quantity', 'int', Classification::Financial, 'Units allocated.' ),
				new ColumnSpec( 'posted_quantity', 'int', Classification::Financial, 'Units of the allocation already posted to the ledger.', defaultValue: '0' ),
				new ColumnSpec( 'state', 'varchar(12)', Classification::Public, 'open, in_transit while its units travel to another location, posted or cancelled.', defaultValue: 'open', collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the allocation was made, UTC, from the database clock.' ),
				new ColumnSpec( 'posted_at', 'datetime', Classification::Public, 'When the allocation was fully posted, UTC.', nullable: true ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'order_line_allocation', array( 'order_line_id', 'variant_id', 'location_id' ), 'One allocation per order line, variant and location.' ),
			),
			array(
				IndexSpec::key( 'item_state', array( 'variant_id', 'location_id', 'state' ), 'An item\'s open allocations: the projection check and the refusal to delete a variant.' ),
				IndexSpec::key( 'location_state', array( 'location_id', 'state' ), 'A location\'s open allocations, for its deactivation and doctor.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'Posts or cancels every allocation of an order.' ),
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'Finds allocations left open too long.' ),
			),
			'entity_lifetime',
			array(
				'variants -> stock_allocations'  => 'Block: a variant with an open allocation cannot be deleted. An open allocation whose item is gone is reported for a person.',
				'locations -> stock_allocations' => 'Block: a location with an allocation cannot be deleted.',
			)
		);
	}

	/**
	 * Declares `stock_policies`: one row per variant, what is true of its stock wherever its units are.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function policies(): TableDefinition {
		return new TableDefinition(
			self::POLICIES,
			self::MODULE,
			'Holds, per variant, whether its stock is counted, whether it may be sold beyond it, and the level below which it counts as low, at every location alike.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant the policy is for; its key.' ),
				new ColumnSpec( 'track', 'tinyint(1)', Classification::Public, '1 when stock is counted; 0 for a variant that is always available.', defaultValue: '1' ),
				new ColumnSpec( 'backorder_policy', 'varchar(8)', Classification::Public, 'no, notify or allow: whether an order may take on_hand below zero when it posts.', defaultValue: 'no', collation: 'ascii_bin' ),
				new ColumnSpec( 'low_stock_threshold', 'int', Classification::Public, 'The units available across the locations at or below which the variant counts as low; NULL for none.', nullable: true ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'variant_id' ),
			array(),
			array(
				IndexSpec::key( 'track_threshold', array( 'track', 'low_stock_threshold' ), 'The low-stock listing: tracked variants with a threshold.' ),
			),
			'entity_lifetime',
			array(
				'variants -> stock_policies' => 'Cascade: deleting a variant deletes its policy through the stock service, after its items. A variant without a policy is given one at the defaults.',
			)
		);
	}

	/**
	 * Declares `locations`: the places stock is kept and orders are fulfilled from.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function locations(): TableDefinition {
		return new TableDefinition(
			self::LOCATIONS,
			self::MODULE,
			'Lists the places the store keeps stock: a warehouse, a shop or a home, with its address, whether it fulfils online orders and offers pickup, and its priority when an order is routed; kept permanently because the stock ledger and orders name the location long after it stops trading.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key: the location id stock rows name.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The location\'s public identifier, a UUID.', collation: 'ascii_bin' ),
				new ColumnSpec( 'name', 'varchar(100)', Classification::Public, 'The name the merchant gave the location, such as Main warehouse.' ),
				new ColumnSpec( 'country', 'char(2)', Classification::Public, 'The ISO 3166-1 alpha-2 country code of the address; empty until the address is entered. The address is printed on labels and shown on pickup confirmations.', defaultValue: '', collation: 'ascii_bin' ),
				new ColumnSpec( 'line1', 'varchar(255)', Classification::Public, 'The first address line; empty until entered.', defaultValue: '' ),
				new ColumnSpec( 'line2', 'varchar(255)', Classification::Public, 'The second address line; empty when not given.', defaultValue: '' ),
				new ColumnSpec( 'city', 'varchar(100)', Classification::Public, 'The city or town; empty until entered.', defaultValue: '' ),
				new ColumnSpec( 'region', 'varchar(64)', Classification::Public, 'The state, province, county or other subdivision; empty when not given.', defaultValue: '' ),
				new ColumnSpec( 'postcode', 'varchar(32)', Classification::Public, 'The postal code; empty until entered.', defaultValue: '' ),
				new ColumnSpec(
					'phone',
					'varchar(32)',
					Classification::Pii,
					'The location\'s phone number for the merchant\'s staff; NULL when none was given.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'It is the merchant\'s own contact number for the location, not a shopper\'s: erasing a shopper\'s data never reaches it.'
				),
				new ColumnSpec( 'active', 'tinyint(1)', Classification::Public, '1 when orders may be routed to the location and its stock counts as available; 0 once it is deactivated.', defaultValue: '1' ),
				new ColumnSpec( 'fulfils_online', 'tinyint(1)', Classification::Public, '1 when the location ships online orders.', defaultValue: '1' ),
				new ColumnSpec( 'offers_pickup', 'tinyint(1)', Classification::Public, '1 when shoppers may collect orders there.', defaultValue: '0' ),
				new ColumnSpec( 'pickup_ready_in', 'varchar(32)', Classification::Public, 'How soon a pickup order is usually ready, as one of a declared list of keys; NULL when pickup is not offered.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'pickup_instructions', 'text', Classification::Public, 'What a shopper collecting an order is told; NULL for none.', nullable: true ),
				new ColumnSpec( 'priority', 'int', Classification::Public, 'The order in which locations are tried when an order is routed: the lowest first, ties by id.', defaultValue: '0' ),
				new ColumnSpec( 'is_default', 'tinyint(1)', Classification::Public, '1 on the store\'s default location, NULL on every other: the stock an order or an adjustment names no location for.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the location was created, UTC, from the database clock.' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'Finds a location by its public identifier.' ),
				IndexSpec::unique( 'is_default', array( 'is_default' ), 'At most one default location: every other row is NULL.' ),
			),
			array(
				IndexSpec::key( 'routing', array( 'active', 'fulfils_online', 'priority' ), 'The active locations that fulfil online orders, in priority order.' ),
			),
			'permanent',
			array()
		);
	}

	/**
	 * Declares `location_markets`: which markets each location ships to.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function locationMarkets(): TableDefinition {
		return new TableDefinition(
			self::LOCATION_MARKETS,
			self::MODULE,
			'Lists the markets each location ships to; while the store has no markets the table is empty and every active location that fulfils online orders serves every shopper.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				self::location( 'The location.' ),
				new ColumnSpec( 'market_id', 'bigint unsigned', Classification::Public, 'A market the location ships to.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'location_market', array( 'location_id', 'market_id' ), 'Each market once per location.' ),
			),
			array(
				IndexSpec::key( 'market_id', array( 'market_id' ), 'The locations that serve a market.' ),
			),
			'permanent',
			array(
				'locations -> location_markets' => 'Cascade: deleting a location deletes the markets it served.',
			)
		);
	}

	/**
	 * Declares `location_pickup_hours`: when each location that offers pickup is open, day by day.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function pickupHours(): TableDefinition {
		return new TableDefinition(
			self::PICKUP_HOURS,
			self::MODULE,
			'Holds the hours a location is open for pickup, one row per day of the week; a day with no row, or with no times, is closed.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				self::location( 'The location.' ),
				new ColumnSpec( 'weekday', 'tinyint unsigned', Classification::Public, 'The day of the week, 0 for Sunday to 6 for Saturday.' ),
				new ColumnSpec( 'opens_at', 'time', Classification::Public, 'When the location opens that day, local time; NULL when it is closed.', nullable: true ),
				new ColumnSpec( 'closes_at', 'time', Classification::Public, 'When the location closes that day, local time; NULL when it is closed.', nullable: true ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'location_weekday', array( 'location_id', 'weekday' ), 'One row per location and day.' ),
			),
			array(),
			'permanent',
			array(
				'locations -> location_pickup_hours' => 'Cascade: deleting a location deletes its hours.',
			)
		);
	}

	/**
	 * Declares `stock_transfers`: a movement of units from one location to another, as one document.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function transfers(): TableDefinition {
		return new TableDefinition(
			self::TRANSFERS,
			self::MODULE,
			'Records each movement of units between two locations, from its draft or request through its dispatch to its receipt; kept as long as the ledger entries it explains.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key: the transfer id its lines and ledger entries name.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'The transfer\'s public identifier, a UUID.', collation: 'ascii_bin' ),
				new ColumnSpec( 'source_location_id', 'bigint unsigned', Classification::Public, 'The location the units leave.' ),
				new ColumnSpec( 'destination_location_id', 'bigint unsigned', Classification::Public, 'The location the units arrive at.' ),
				new ColumnSpec( 'kind', 'varchar(12)', Classification::Public, 'manual for one a merchant made, order for one an order waits for, deactivation for the move of a deactivated location\'s stock.', collation: 'ascii_bin' ),
				new ColumnSpec( 'order_id', 'bigint unsigned', Classification::Public, 'The order that waits for the transfer, when it is of kind order.', nullable: true ),
				new ColumnSpec( 'state', 'varchar(12)', Classification::Public, 'draft, requested, in_transit, received or cancelled.', collation: 'ascii_bin' ),
				new ColumnSpec( 'note', 'text', Classification::Public, 'The merchant\'s note on the transfer; NULL for none.', nullable: true ),
				new ColumnSpec(
					'created_by',
					'bigint unsigned',
					Classification::Pii,
					'The WordPress user who created the transfer; NULL when the system did.',
					nullable: true,
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: 'A transfer explains ledger entries kept as financial records; who made it is part of that record.'
				),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the transfer was created, UTC, from the database clock.' ),
				new ColumnSpec( 'sent_at', 'datetime(6)', Classification::Public, 'When the units left the source, UTC; NULL until then.', nullable: true ),
				new ColumnSpec( 'received_at', 'datetime(6)', Classification::Public, 'When the last units were received or written off, UTC; NULL until then.', nullable: true ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'uuid', array( 'uuid' ), 'Finds a transfer by its public identifier.' ),
			),
			array(
				IndexSpec::key( 'state_created', array( 'state', 'created_at' ), 'The transfers list by state, and the requested transfers waiting too long.' ),
				IndexSpec::key( 'order_id', array( 'order_id' ), 'The transfers an order waits for.' ),
				IndexSpec::key( 'destination_state', array( 'destination_location_id', 'state' ), 'A location\'s open transfers in.' ),
				IndexSpec::key( 'source_state', array( 'source_location_id', 'state' ), 'A location\'s open transfers out.' ),
			),
			self::TRANSFERS_RETENTION,
			array(
				'locations -> stock_transfers' => 'Block: a location a transfer names cannot be deleted.',
			)
		);
	}

	/**
	 * Declares `stock_transfer_lines`: the units of one variant a transfer moves, and how many arrived.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function transferLines(): TableDefinition {
		return new TableDefinition(
			self::TRANSFER_LINES,
			self::MODULE,
			'Lists the units of each variant a transfer moves, how many were received and how many were lost; what is sent and neither received nor lost is in transit, in no location\'s stock.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'transfer_id', 'bigint unsigned', Classification::Public, 'The transfer the line belongs to.' ),
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant whose units move.' ),
				new ColumnSpec( 'quantity', 'int', Classification::Financial, 'Units sent.' ),
				new ColumnSpec( 'received_quantity', 'int', Classification::Financial, 'Units received at the destination so far.', defaultValue: '0' ),
				new ColumnSpec( 'lost_quantity', 'int', Classification::Financial, 'Units written off as lost on the way.', defaultValue: '0' ),
				new ColumnSpec( 'allocation_id', 'bigint unsigned', Classification::Public, 'The order\'s allocation the units travel with; NULL for units no order is waiting for.', nullable: true ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'transfer_id', array( 'transfer_id' ), 'A transfer\'s lines.' ),
				IndexSpec::key( 'allocation_id', array( 'allocation_id' ), 'The line an allocation travels with.' ),
				IndexSpec::key( 'variant_id', array( 'variant_id' ), 'The transfers that carry a variant.' ),
			),
			self::TRANSFERS_RETENTION,
			array(
				'stock_transfers -> stock_transfer_lines' => 'Cascade: a transfer\'s lines go with it.',
			)
		);
	}

	/**
	 * Declares `stock_ledger.transfer_id`, which a migration adds to a ledger created before transfers existed.
	 *
	 * @since 0.2.0
	 *
	 * @return ColumnSpec The column.
	 */
	public static function transferId(): ColumnSpec {
		return new ColumnSpec( 'transfer_id', 'bigint unsigned', Classification::Public, 'The transfer the movement belongs to, when units moved between locations.', nullable: true );
	}

	/**
	 * Declares a location column, NOT NULL.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description What the location is to the row.
	 * @return ColumnSpec The column.
	 */
	private static function location( string $description ): ColumnSpec {
		return new ColumnSpec( 'location_id', 'bigint unsigned', Classification::Public, $description );
	}
}
