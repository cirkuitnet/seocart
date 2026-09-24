<?php
/**
 * FakeStockRepository: a StockRepository in memory, for unit tests of the services that sequence stock changes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Inventory\Domain\BackorderPolicy;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Database\TransactionManager;

/**
 * The unit-test stand-in for MysqlStockRepository, as the stock service sees it.
 *
 * Owns one fact: what each statement group does to stock, without a database. Every method
 * applies its statement's WHERE clause to rows in memory and answers as the statement would;
 * the ones that change stock or read under a lock refuse to run at depth 0 of the transaction
 * manager it is given, as the real repository does. Time is a number of seconds the test sets;
 * a hold has expired once its expiry is at or before it.
 *
 * It records each call as `method:arguments` in calls(), so a test can assert the order of the
 * statements. It does not undo anything when a transaction rolls back; a test of rollback
 * belongs in the integration suite, against the real tables.
 *
 * @since 0.1.0
 */
final class FakeStockRepository implements StockRepository {

	/**
	 * The unit of work, whose depth the writes check.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * The items, by variant id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{on_hand: int, allocated: int, held: int, track: bool, policy: BackorderPolicy}>
	 */
	private array $items = array();

	/**
	 * The hold rows, by id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{variant_id: int, hold_group: string, quantity: int, expires: int, token: string|null}>
	 */
	private array $holds = array();

	/**
	 * The ledger entries, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{id: int, variant_id: int, delta: int, on_hand_after: int, reason: string, actor_type: string, actor_id: int|null, correlation_id: string|null}>
	 */
	private array $ledger = array();

	/**
	 * The variants with an open allocation.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, true>
	 */
	private array $openAllocations = array();

	/**
	 * The current time, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $now = 1000000;

	/**
	 * The last hold id handed out.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $lastHoldId = 0;

	/**
	 * Every call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $calls = array();

	/**
	 * Creates the double.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $tx The unit of work the service runs in.
	 */
	public function __construct( TransactionManager $tx ) {
		$this->tx = $tx;
	}

	/**
	 * Plants an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $variantId The variant.
	 * @param int  $onHand    Optional. Units on hand. Default 0.
	 * @param int  $allocated Optional. Units allocated. Default 0.
	 * @param int  $held      Optional. Units held, without rows. Default 0.
	 * @param bool $track     Optional. Whether stock is counted. Default true.
	 */
	public function plantItem( int $variantId, int $onHand = 0, int $allocated = 0, int $held = 0, bool $track = true ): void {
		$this->items[ $variantId ] = array(
			'on_hand'   => $onHand,
			'allocated' => $allocated,
			'held'      => $held,
			'track'     => $track,
			'policy'    => BackorderPolicy::No,
		);
	}

	/**
	 * Plants a hold row and adds its quantity to the item's `held`.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId        The variant, planted first.
	 * @param int    $quantity         The units.
	 * @param int    $expiresInSeconds Seconds from now; 0 or less is expired.
	 * @param string $holdGroup        Optional. The hold's id. Default a fixed id.
	 * @return int The row's id.
	 */
	public function plantHold( int $variantId, int $quantity, int $expiresInSeconds, string $holdGroup = 'planted-hold' ): int {
		$this->items[ $variantId ]['held'] += $quantity;

		return $this->addHold( $variantId, $quantity, $holdGroup, $this->now + $expiresInSeconds );
	}

	/**
	 * Plants an open allocation of an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 */
	public function plantOpenAllocation( int $variantId ): void {
		$this->openAllocations[ $variantId ] = true;
	}

	/**
	 * Returns the calls made, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> `method:arguments` per call.
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * Returns the hold rows left.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{variant_id: int, hold_group: string, quantity: int, expires: int, token: string|null}> The rows, by id.
	 */
	public function holdRows(): array {
		return array_values( $this->holds );
	}

	/**
	 * Returns the ledger.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{id: int, variant_id: int, delta: int, on_hand_after: int, reason: string, actor_type: string, actor_id: int|null, correlation_id: string|null}> The entries.
	 */
	public function ledger(): array {
		return $this->ledger;
	}

	/**
	 * Reads whether each item is tracked, and computes an expiry.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @param int   $ttlSeconds The TTL.
	 * @return array{track: array<int, bool>, expires_at: string|null} Tracking and the expiry.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array {
		$this->calls[] = 'configuration:' . implode( ',', $variantIds );

		$track = array();

		foreach ( $variantIds as $variantId ) {
			if ( isset( $this->items[ $variantId ] ) ) {
				$track[ $variantId ] = $this->items[ $variantId ]['track'];
			}
		}

		return array(
			'track'      => $track,
			'expires_at' => array() === $track ? null : gmdate( 'Y-m-d H:i:s', $this->now + $ttlSeconds ),
		);
	}

	/**
	 * Claims units when available.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @param int $quantity  The units.
	 * @return bool Whether it matched.
	 */
	public function claim( int $variantId, int $quantity ): bool {
		$this->write( 'claim:' . $variantId . ':' . $quantity );

		$item = $this->items[ $variantId ] ?? null;

		if ( null === $item || ! $item['track'] || $item['on_hand'] - $item['allocated'] - $item['held'] < $quantity ) {
			return false;
		}

		$this->items[ $variantId ]['held'] += $quantity;

		return true;
	}

	/**
	 * Inserts a hold row.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId The item.
	 * @param int      $quantity  The units.
	 * @param string   $holdGroup The hold.
	 * @param string   $expiresAt The expiry.
	 * @param int|null $cartId    The cart.
	 * @param int|null $orderId   The order.
	 * @return int The row's id.
	 */
	public function insertHold( int $variantId, int $quantity, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): int {
		$this->write( 'insertHold:' . $variantId . ':' . $quantity );

		return $this->addHold( $variantId, $quantity, $holdGroup, (int) strtotime( $expiresAt . ' UTC' ) );
	}

	/**
	 * Takes an item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool Whether it exists.
	 */
	public function lockItem( int $variantId ): bool {
		$this->write( 'lockItem:' . $variantId );

		return isset( $this->items[ $variantId ] );
	}

	/**
	 * Gives back expired rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $token     The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimExpired( int $variantId, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimExpired:' . $variantId;

		return $this->reclaim( $variantId, $token, fn( array $row ): bool => $row['expires'] <= $this->now );
	}

	/**
	 * Gives back one hold's rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $holdGroup The hold.
	 * @param string $token     The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimGroup( int $variantId, string $holdGroup, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimGroup:' . $variantId;

		return $this->reclaim( $variantId, $token, static fn( array $row ): bool => $holdGroup === $row['hold_group'] );
	}

	/**
	 * Gives back every row of an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param string $token     The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimVariant( int $variantId, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimVariant:' . $variantId;

		return $this->reclaim( $variantId, $token, static fn( array $row ): bool => $variantId === $row['variant_id'] );
	}

	/**
	 * Lists a hold's items.
	 *
	 * @since 0.1.0
	 *
	 * @param string $holdGroup The hold.
	 * @return list<int> The variant ids, ascending.
	 */
	public function groupVariants( string $holdGroup ): array {
		$this->calls[] = 'groupVariants';

		$variantIds = array();

		foreach ( $this->holds as $row ) {
			if ( $holdGroup === $row['hold_group'] ) {
				$variantIds[ $row['variant_id'] ] = $row['variant_id'];
			}
		}

		ksort( $variantIds );

		return array_values( $variantIds );
	}

	/**
	 * Lists items with expired, unclaimed rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only above this id.
	 * @param int $limit The most items.
	 * @return list<int> The variant ids, ascending.
	 */
	public function expiredVariants( int $after, int $limit ): array {
		$variantIds = array();

		foreach ( $this->holds as $row ) {
			if ( $row['expires'] <= $this->now && null === $row['token'] && $row['variant_id'] > $after ) {
				$variantIds[ $row['variant_id'] ] = $row['variant_id'];
			}
		}

		ksort( $variantIds );

		return array_slice( array_values( $variantIds ), 0, $limit );
	}

	/**
	 * Adjusts on_hand when the floor and the precondition hold.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId      The item.
	 * @param int      $delta          The change.
	 * @param int|null $expectedOnHand The expected on_hand.
	 * @return bool Whether it matched.
	 */
	public function adjust( int $variantId, int $delta, ?int $expectedOnHand ): bool {
		$this->write( 'adjust:' . $variantId . ':' . $delta );

		$item = $this->items[ $variantId ] ?? null;

		if ( null === $item || $item['on_hand'] + $delta < 0 || ( null !== $expectedOnHand && $expectedOnHand !== $item['on_hand'] ) ) {
			return false;
		}

		$this->items[ $variantId ]['on_hand'] += $delta;

		return true;
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
	 * @param string       $actorType     The actor type.
	 * @param int|null     $actorId       The actor.
	 * @param string|null  $correlationId The correlation id.
	 * @return int The entry's id.
	 */
	public function appendLedger( int $variantId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId ): int {
		$this->write( 'appendLedger:' . $variantId . ':' . $delta );

		$id             = count( $this->ledger ) + 1;
		$this->ledger[] = array(
			'id'             => $id,
			'variant_id'     => $variantId,
			'delta'          => $delta,
			'on_hand_after'  => $onHandAfter,
			'reason'         => $reason->value,
			'actor_type'     => $actorType,
			'actor_id'       => $actorId,
			'correlation_id' => $correlationId,
		);

		return $id;
	}

	/**
	 * Reads levels.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @return array<int, StockLevel> The levels.
	 */
	public function levels( array $variantIds ): array {
		$levels = array();

		foreach ( $variantIds as $variantId ) {
			$level = $this->level( $variantId );

			if ( null !== $level ) {
				$levels[ $variantId ] = $level;
			}
		}

		return $levels;
	}

	/**
	 * Reads one level under the lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return StockLevel|null The level.
	 */
	public function lockedLevel( int $variantId ): ?StockLevel {
		$this->write( 'lockedLevel:' . $variantId );

		return $this->level( $variantId );
	}

	/**
	 * Creates items at zero.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 */
	public function createItems( array $variantIds ): void {
		$this->write( 'createItems:' . implode( ',', $variantIds ) );

		foreach ( $variantIds as $variantId ) {
			if ( ! isset( $this->items[ $variantId ] ) ) {
				$this->plantItem( $variantId );
			}
		}
	}

	/**
	 * Tells whether an item has an open allocation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool Whether one is open.
	 */
	public function hasOpenAllocation( int $variantId ): bool {
		$this->write( 'hasOpenAllocation:' . $variantId );

		return isset( $this->openAllocations[ $variantId ] );
	}

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return bool Whether it was there.
	 */
	public function deleteItem( int $variantId ): bool {
		$this->write( 'deleteItem:' . $variantId );

		$found = isset( $this->items[ $variantId ] );

		unset( $this->items[ $variantId ] );

		return $found;
	}

	/**
	 * Gives back the item's rows that match, as the four statements do.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId The item.
	 * @param string   $token     The token.
	 * @param callable $matches   Whether a row of the item, unclaimed, is claimed.
	 * @return ReclaimedRows The result.
	 */
	private function reclaim( int $variantId, string $token, callable $matches ): ReclaimedRows {
		if ( ! $this->lockItem( $variantId ) ) {
			return ReclaimedRows::itemMissing( $variantId );
		}

		$claimed = array();

		foreach ( $this->holds as $id => $row ) {
			if ( $variantId === $row['variant_id'] && null === $row['token'] && $matches( $row ) ) {
				$claimed[ $id ] = $row;
			}
		}

		if ( array() === $claimed ) {
			return ReclaimedRows::of( $variantId, array() );
		}

		$sum = (int) array_sum( array_column( $claimed, 'quantity' ) );

		if ( $this->items[ $variantId ]['held'] < $sum ) {
			return ReclaimedRows::corrupt( $variantId );
		}

		$this->items[ $variantId ]['held'] -= $sum;

		$rows = array();

		foreach ( $claimed as $id => $row ) {
			unset( $this->holds[ $id ] );

			$rows[] = array(
				'id'         => $id,
				'hold_group' => $row['hold_group'],
				'quantity'   => $row['quantity'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', $row['expires'] ),
			);
		}

		return ReclaimedRows::of( $variantId, $rows );
	}

	/**
	 * Adds a hold row.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item.
	 * @param int    $quantity  The units.
	 * @param string $holdGroup The hold.
	 * @param int    $expires   The expiry, in seconds.
	 * @return int The row's id.
	 */
	private function addHold( int $variantId, int $quantity, string $holdGroup, int $expires ): int {
		$id = ++$this->lastHoldId;

		$this->holds[ $id ] = array(
			'variant_id' => $variantId,
			'hold_group' => $holdGroup,
			'quantity'   => $quantity,
			'expires'    => $expires,
			'token'      => null,
		);

		return $id;
	}

	/**
	 * Builds a level.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The item.
	 * @return StockLevel|null The level, or null when there is no item.
	 */
	private function level( int $variantId ): ?StockLevel {
		$item = $this->items[ $variantId ] ?? null;

		return null === $item ? null : new StockLevel( $variantId, $item['on_hand'], $item['allocated'], $item['held'], $item['track'], $item['policy'] );
	}

	/**
	 * Records a call that must run inside a transaction, and refuses it outside one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException At depth 0.
	 *
	 * @param string $call The call.
	 */
	private function write( string $call ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'FakeStockRepository: %s runs only inside a transaction.', $call ) );
		}

		$this->calls[] = $call;
	}
}
