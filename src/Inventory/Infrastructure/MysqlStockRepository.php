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

use SEOCart\Inventory\Domain\BackorderPolicy;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The stock repository on MySQL: the one class that sends SQL to the inventory tables.
 *
 * Owns one fact: the text of every stock statement. Each is a public constant, so a
 * concurrency test sends exactly the statement this class sends. A statement names its tables
 * as `{stock_items}`, `{stock_holds}`, `{stock_ledger}` and `{stock_allocations}`, and an IN
 * list as `{list}`; expand() turns them into wpdb placeholders and arguments. Written that way,
 * a test can read from the constants alone that no statement updates or deletes the ledger.
 *
 * It keeps the one-lock rule StockRepository states: the claim and the item lock are the only
 * statements that take an item's row lock, and every reclaim begins with the item lock, so its
 * hold rows are claimed, given back and deleted by the item's lock holder alone. Every
 * conditional update of an item sets `updated_at` from the database clock, and the item lock
 * moves it forward by at least a microsecond, so one affected row always means the WHERE clause
 * matched. Reads made under a lock are locking reads, so they see the latest committed row and
 * never an older snapshot of the transaction.
 *
 * The reads for `doctor` live here too, because they are stock SQL; they are not part of the
 * port the service sees.
 *
 * @since 0.1.0
 */
final class MysqlStockRepository implements StockRepository {

	/**
	 * Which items exist and are tracked, and the database's time plus a TTL: the one read before any lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONFIGURATION = 'SELECT variant_id, track, UTC_TIMESTAMP() + INTERVAL %d SECOND AS expires_at FROM {stock_items} WHERE variant_id IN ({list})';

	/**
	 * The claim: adds to `held` only when the item is tracked and that many units are available.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM = 'UPDATE {stock_items} SET held = held + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND track = 1 AND ( on_hand - allocated - held ) >= %d';

	/**
	 * A hold row, inserted right after its claim; 0 stands for no cart or no order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INSERT_HOLD = 'INSERT INTO {stock_holds} ( variant_id, cart_id, order_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, NULLIF( %d, 0 ), NULLIF( %d, 0 ), %s, %d, %s, UTC_TIMESTAMP(6) )';

	/**
	 * The item lock: the first statement of every reclaim, and of the delete. Moves `updated_at` forward, so it always changes the row.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_ITEM = 'UPDATE {stock_items} SET updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE variant_id = %d';

	/**
	 * Claims the item's expired hold rows no other reclaim has claimed, by token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_EXPIRED = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE variant_id = %d AND expires_at <= UTC_TIMESTAMP() AND reclaim_token IS NULL';

	/**
	 * Claims the item's rows of one hold, expired or not, that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_GROUP = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE hold_group = %s AND variant_id = %d AND reclaim_token IS NULL';

	/**
	 * Claims every hold row of the item that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM_VARIANT = 'UPDATE {stock_holds} SET reclaim_token = %s WHERE variant_id = %d AND reclaim_token IS NULL';

	/**
	 * Gives the claimed quantity back to the item, only when `held` covers it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GIVE_BACK = 'UPDATE {stock_items} SET held = held - ( SELECT COALESCE( SUM( h.quantity ), 0 ) FROM {stock_holds} h WHERE h.reclaim_token = %s ), updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND held >= ( SELECT COALESCE( SUM( c.quantity ), 0 ) FROM {stock_holds} c WHERE c.reclaim_token = %s )';

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
	 * The items one hold has rows for, ascending.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP_VARIANTS = 'SELECT variant_id FROM {stock_holds} WHERE hold_group = %s GROUP BY variant_id ORDER BY variant_id';

	/**
	 * A page of items with expired, unclaimed hold rows, ascending from a variant id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXPIRED_VARIANTS = 'SELECT DISTINCT variant_id FROM {stock_holds} WHERE expires_at <= UTC_TIMESTAMP() AND reclaim_token IS NULL AND variant_id > %d ORDER BY variant_id LIMIT %d';

	/**
	 * A page of variant ids that have a stock item, ascending from a variant id above a cursor.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ITEM_VARIANT_IDS = 'SELECT variant_id FROM {stock_items} WHERE variant_id > %d ORDER BY variant_id LIMIT %d';

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
	public const ITEM_VARIANT_IDS_FROM_START = 'SELECT variant_id FROM {stock_items} ORDER BY variant_id LIMIT %d';

	/**
	 * The adjustment: adds to on_hand only when the result is not negative.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUST = 'UPDATE {stock_items} SET on_hand = on_hand + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND on_hand + %d >= 0';

	/**
	 * The adjustment with a precondition: also only when on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUST_EXPECTING = 'UPDATE {stock_items} SET on_hand = on_hand + %d, updated_at = UTC_TIMESTAMP(6) WHERE variant_id = %d AND on_hand + %d >= 0 AND on_hand = %d';

	/**
	 * A ledger entry; 0 stands for no actor, and an empty string for no correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPEND_LEDGER = "INSERT INTO {stock_ledger} ( variant_id, delta, on_hand_after, reason, actor_type, actor_id, correlation_id, created_at ) VALUES ( %d, %d, %d, %s, %s, NULLIF( %d, 0 ), NULLIF( %s, '' ), UTC_TIMESTAMP(6) )";

	/**
	 * Items' levels, a plain read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LEVELS = 'SELECT variant_id, on_hand, allocated, held, track, backorder_policy FROM {stock_items} WHERE variant_id IN ({list}) ORDER BY variant_id';

	/**
	 * One item's level, a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCKED_LEVEL = 'SELECT variant_id, on_hand, allocated, held, track, backorder_policy FROM {stock_items} WHERE variant_id = %d FOR UPDATE';

	/**
	 * Creates an item with the declared defaults, or leaves it as it is.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CREATE_ITEM = 'INSERT INTO {stock_items} ( variant_id, updated_at ) VALUES ( %d, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id';

	/**
	 * One open allocation of the item, a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPEN_ALLOCATION = "SELECT id FROM {stock_allocations} WHERE variant_id = %d AND state = 'open' LIMIT 1 FOR UPDATE";

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_ITEM = 'DELETE FROM {stock_items} WHERE variant_id = %d';

	/**
	 * Items whose `held` is not the sum of their hold rows, expired rows included.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HELD_DRIFT = 'SELECT i.variant_id, i.held, COALESCE( SUM( h.quantity ), 0 ) AS rows_sum FROM {stock_items} i LEFT JOIN {stock_holds} h ON h.variant_id = i.variant_id GROUP BY i.variant_id, i.held HAVING i.held <> rows_sum ORDER BY i.variant_id LIMIT %d';

	/**
	 * Items whose on_hand is not the sum of their ledger, or not their newest entry's on_hand_after.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ON_HAND_DRIFT = 'SELECT i.variant_id, i.on_hand, COALESCE( SUM( l.delta ), 0 ) AS ledger_sum, ( SELECT n.on_hand_after FROM {stock_ledger} n WHERE n.variant_id = i.variant_id ORDER BY n.id DESC LIMIT 1 ) AS newest FROM {stock_items} i LEFT JOIN {stock_ledger} l ON l.variant_id = i.variant_id GROUP BY i.variant_id, i.on_hand HAVING ledger_sum <> i.on_hand OR ( newest IS NOT NULL AND newest <> i.on_hand ) ORDER BY i.variant_id LIMIT %d';

	/**
	 * Items whose `allocated` is not the unposted sum of their open allocations.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ALLOCATED_DRIFT = "SELECT i.variant_id, i.allocated, COALESCE( SUM( a.quantity - a.posted_quantity ), 0 ) AS open_sum FROM {stock_items} i LEFT JOIN {stock_allocations} a ON a.variant_id = i.variant_id AND a.state = 'open' GROUP BY i.variant_id, i.allocated HAVING i.allocated <> open_sum ORDER BY i.variant_id LIMIT %d";

	/**
	 * Items with hold rows that expired longer ago than a tolerance and were never reclaimed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STALE_HOLDS = 'SELECT variant_id, COUNT(*) AS stale_rows, TIMESTAMPDIFF( SECOND, MIN( expires_at ), UTC_TIMESTAMP() ) AS oldest_seconds FROM {stock_holds} WHERE expires_at < UTC_TIMESTAMP() - INTERVAL %d SECOND GROUP BY variant_id ORDER BY variant_id LIMIT %d';

	/**
	 * Items with committed hold rows that still carry a reclaim token, which only a reclaim inside its own transaction may.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TOKENED_HOLDS = 'SELECT variant_id, COUNT(*) AS tokened_rows FROM {stock_holds} WHERE reclaim_token IS NOT NULL GROUP BY variant_id ORDER BY variant_id LIMIT %d';

	/**
	 * Open allocations whose item is gone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ORPHAN_ALLOCATIONS = "SELECT a.id, a.variant_id, a.order_id FROM {stock_allocations} a LEFT JOIN {stock_items} i ON i.variant_id = a.variant_id WHERE a.state = 'open' AND i.variant_id IS NULL ORDER BY a.id LIMIT %d";

	/**
	 * A table token, or the IN list token, or a value placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{([a-z_]+)\}|%[dsi]/';

	/**
	 * The token of an IN list, expanded to one %d per id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIST_TOKEN = 'list';

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
	 * A table token becomes `%i` with the table's full name as its argument; `{list}` becomes one
	 * `%d` per id of the next value, which must be a non-empty list; `%d`, `%s` and `%i` each take
	 * the next value. The caller prepares the result with wpdb.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a token names no inventory table, a list is empty, or the values do not match the placeholders.
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
		$tables    = array( InventoryTables::ITEMS, InventoryTables::LEDGER, InventoryTables::HOLDS, InventoryTables::ALLOCATIONS );
		$arguments = array();
		$next      = 0;

		$sql = (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $found ) use ( $tables, $values, $tableName, &$arguments, &$next ): string {
				$token = $found[1] ?? '';

				if ( '' !== $token && in_array( $token, $tables, true ) ) {
					$arguments[] = $tableName( $token );

					return '%i';
				}

				if ( '' !== $token && self::LIST_TOKEN !== $token ) {
					throw new \LogicException( sprintf( 'The statement names {%s}, which is not an inventory table.', $token ) );
				}

				if ( ! array_key_exists( $next, $values ) ) {
					throw new \LogicException( 'The statement has more placeholders than values.' );
				}

				$value = $values[ $next++ ];

				if ( '' === $token ) {
					$arguments[] = $value;

					return $found[0];
				}

				if ( ! is_array( $value ) || array() === $value ) {
					throw new \LogicException( 'An IN list needs at least one id.' );
				}

				foreach ( $value as $id ) {
					$arguments[] = (int) $id;
				}

				return implode( ', ', array_fill( 0, count( $value ), '%d' ) );
			},
			$statement
		);

		if ( count( $values ) !== $next ) {
			throw new \LogicException( 'The statement has fewer placeholders than values.' );
		}

		return array( $sql, $arguments );
	}

	/**
	 * Reads whether each item is tracked, and computes the expiry of a hold taken now. Locks nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @param int   $ttlSeconds How long the hold lasts.
	 * @return array{track: array<int, bool>, expires_at: string|null} Tracking by variant id, and the expiry.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array {
		$track     = array();
		$expiresAt = null;

		foreach ( $this->rows( self::CONFIGURATION, $ttlSeconds, array_values( $variantIds ) ) as $row ) {
			$track[ (int) $row['variant_id'] ] = '1' === (string) $row['track'];
			$expiresAt                         = (string) $row['expires_at'];
		}

		return array(
			'track'      => $track,
			'expires_at' => $expiresAt,
		);
	}

	/**
	 * Adds a quantity to a tracked item's `held`, if that many units are available.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @param int $quantity  The units.
	 * @return bool True when the claim matched.
	 */
	public function claim( int $variantId, int $quantity ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::CLAIM, $quantity, $variantId, $quantity );
	}

	/**
	 * Inserts a hold row, after its claim.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId The item.
	 * @param int      $quantity  The units.
	 * @param string   $holdGroup The hold's id.
	 * @param string   $expiresAt The expiry, UTC.
	 * @param int|null $cartId    The cart, or null.
	 * @param int|null $orderId   The order, or null.
	 * @return int The row's id.
	 */
	public function insertHold( int $variantId, int $quantity, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->write( self::INSERT_HOLD, $variantId, $cartId ?? 0, $orderId ?? 0, $holdGroup, $quantity, $expiresAt );

		return $this->db->lastInsertId();
	}

	/**
	 * Takes the item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool True when the item exists and is now locked.
	 */
	public function lockItem( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::LOCK_ITEM, $variantId );
	}

	/**
	 * Gives back the item's expired hold rows that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $token     A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimExpired( int $variantId, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $token, self::CLAIM_EXPIRED, array( $token, $variantId ) );
	}

	/**
	 * Gives back the item's rows of one hold.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $holdGroup The hold.
	 * @param string $token     A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimGroup( int $variantId, string $holdGroup, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $token, self::CLAIM_GROUP, array( $token, $holdGroup, $variantId ) );
	}

	/**
	 * Gives back every hold row of the item.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $token     A fresh token.
	 * @return ReclaimedRows What was given back.
	 */
	public function reclaimVariant( int $variantId, string $token ): ReclaimedRows {
		return $this->reclaim( $variantId, $token, self::CLAIM_VARIANT, array( $token, $variantId ) );
	}

	/**
	 * Lists the items one hold has rows for.
	 *
	 * @since 0.1.0
	 *
	 * @param string $holdGroup The hold.
	 * @return list<int> The variant ids, ascending.
	 */
	public function groupVariants( string $holdGroup ): array {
		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::GROUP_VARIANTS, $holdGroup ) );
	}

	/**
	 * Lists items with expired, unclaimed hold rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only items above this variant id.
	 * @param int $limit The most items.
	 * @return list<int> The variant ids, ascending.
	 */
	public function expiredVariants( int $after, int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::EXPIRED_VARIANTS, $after, $limit ) );
	}

	/**
	 * Lists the variant ids that have a stock item, whatever its counters.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only items with a higher variant id.
	 * @param int $limit The most items to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemVariantIds( int $after, int $limit ): array {
		if ( $after > 0 ) {
			return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::ITEM_VARIANT_IDS, $after, $limit ) );
		}

		return array_map( static fn( array $row ): int => (int) $row['variant_id'], $this->rows( self::ITEM_VARIANT_IDS_FROM_START, $limit ) );
	}

	/**
	 * Adds a delta to an item's on_hand, if the result is not negative and on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId      The item.
	 * @param int      $delta          The change.
	 * @param int|null $expectedOnHand The on_hand the caller read, or null.
	 * @return bool True when the update matched.
	 */
	public function adjust( int $variantId, int $delta, ?int $expectedOnHand ): bool {
		$this->requireTransaction( __FUNCTION__ );

		if ( null === $expectedOnHand ) {
			return 1 === $this->write( self::ADJUST, $delta, $variantId, $delta );
		}

		return 1 === $this->write( self::ADJUST_EXPECTING, $delta, $variantId, $delta, $expectedOnHand );
	}

	/**
	 * Appends a ledger entry.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $variantId     The item.
	 * @param int          $delta         The change.
	 * @param int          $onHandAfter   on_hand after it.
	 * @param LedgerReason $reason        Why.
	 * @param string       $actorType     `user` or `system`.
	 * @param int|null     $actorId       The user, or null.
	 * @param string|null  $correlationId The correlation id, or null.
	 * @return int The entry's id.
	 */
	public function appendLedger( int $variantId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->write( self::APPEND_LEDGER, $variantId, $delta, $onHandAfter, $reason->value, $actorType, $actorId ?? 0, $correlationId ?? '' );

		return $this->db->lastInsertId();
	}

	/**
	 * Reads items' levels, a plain read.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @return array<int, StockLevel> The levels of the items that exist, by variant id.
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
	 * Reads one item's level with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return StockLevel|null The level, or null when the item does not exist.
	 */
	public function lockedLevel( int $variantId ): ?StockLevel {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->rows( self::LOCKED_LEVEL, $variantId )[0] ?? null;

		return null === $row ? null : self::level( $row );
	}

	/**
	 * Creates items at zero, leaving any that exist unchanged: one statement per item, ascending.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 */
	public function createItems( array $variantIds ): void {
		$this->requireTransaction( __FUNCTION__ );

		foreach ( $variantIds as $variantId ) {
			$this->write( self::CREATE_ITEM, (int) $variantId );
		}
	}

	/**
	 * Tells whether an item has an open allocation, with a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool True when one is open.
	 */
	public function hasOpenAllocation( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return array() !== $this->rows( self::OPEN_ALLOCATION, $variantId );
	}

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool True when the row was deleted.
	 */
	public function deleteItem( int $variantId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->write( self::DELETE_ITEM, $variantId );
	}

	/**
	 * Lists items whose `held` is not the sum of their hold rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most items to list.
	 * @return list<array{variant_id: int, held: int, rows_sum: int}> The items, ascending.
	 */
	public function heldDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id' => (int) $row['variant_id'],
				'held'       => (int) $row['held'],
				'rows_sum'   => (int) $row['rows_sum'],
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
	 * @return list<array{variant_id: int, on_hand: int, ledger_sum: int, newest: int|null}> The items, ascending.
	 */
	public function onHandDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id' => (int) $row['variant_id'],
				'on_hand'    => (int) $row['on_hand'],
				'ledger_sum' => (int) $row['ledger_sum'],
				'newest'     => null === $row['newest'] ? null : (int) $row['newest'],
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
	 * @return list<array{variant_id: int, allocated: int, open_sum: int}> The items, ascending.
	 */
	public function allocatedDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id' => (int) $row['variant_id'],
				'allocated'  => (int) $row['allocated'],
				'open_sum'   => (int) $row['open_sum'],
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
	 * @return list<array{variant_id: int, stale_rows: int, oldest_seconds: int}> The items, ascending.
	 */
	public function staleHolds( int $toleranceSeconds, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'     => (int) $row['variant_id'],
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
	 * @return list<array{variant_id: int, tokened_rows: int}> The items, ascending.
	 */
	public function tokenedHolds( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'variant_id'   => (int) $row['variant_id'],
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
	 * @return list<array{id: int, variant_id: int, order_id: int}> The allocations, by id.
	 */
	public function orphanAllocations( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'variant_id' => (int) $row['variant_id'],
				'order_id'   => (int) $row['order_id'],
			),
			$this->rows( self::ORPHAN_ALLOCATIONS, $limit )
		);
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
	 * @param int    $variantId The item.
	 * @param string $token     A fresh token.
	 * @param string $claim     The claim statement: CLAIM_EXPIRED, CLAIM_GROUP or CLAIM_VARIANT.
	 * @param array  $values    The claim statement's values.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-param list<mixed> $values
	 */
	private function reclaim( int $variantId, string $token, string $claim, array $values ): ReclaimedRows {
		if ( ! $this->lockItem( $variantId ) ) {
			return ReclaimedRows::itemMissing( $variantId );
		}

		if ( 0 === $this->write( $claim, ...$values ) ) {
			return ReclaimedRows::of( $variantId, array() );
		}

		if ( 1 !== $this->write( self::GIVE_BACK, $token, $variantId, $token ) ) {
			return ReclaimedRows::corrupt( $variantId );
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

		return ReclaimedRows::of( $variantId, $rows );
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
	 * Builds a level from a row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return StockLevel The level.
	 */
	private static function level( array $row ): StockLevel {
		return new StockLevel(
			(int) $row['variant_id'],
			(int) $row['on_hand'],
			(int) $row['allocated'],
			(int) $row['held'],
			'1' === (string) $row['track'],
			BackorderPolicy::from( (string) $row['backorder_policy'] )
		);
	}
}
