<?php
/**
 * FakeStockRepository: an in-memory stock repository that records the calls it receives
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\BackorderPolicy;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\ReclaimedRows;
use SEOCart\Inventory\Domain\StockLevel;
use SEOCart\Inventory\Domain\StockPolicy;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Database\TransactionManager;

/**
 * An in-memory StockRepository with the same contract as the MySQL one, for the service's unit tests.
 *
 * It keeps items, hold rows and a ledger in arrays, at one location, the store's default,
 * LOCATION, and answers every claim, conversion and reclaim the way the conditional statements
 * would: a claim matches only when the units are available, a give-back refuses when `held` is
 * below the claimed rows. A variant planted has its policy and its item there; anything asked
 * of another location finds no item. Every method that must run inside a transaction refuses at
 * depth 0, as the real one does. Its clock is a number of seconds that moves only when told, and
 * a hold has expired once its expiry is at or before it.
 *
 * It records each call as `method:arguments` in calls(), an item written `variant@location`, so a
 * test can assert the order of the statements. It does not undo anything when a transaction rolls
 * back; a test of rollback belongs in the integration suite, against the real tables.
 *
 * @since 0.1.0
 */
final class FakeStockRepository implements StockRepository {

	/**
	 * The one location the double keeps stock at: the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LOCATION = 1;

	/**
	 * The unit of work, whose depth the writes check.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * The items at the location, by variant id, each with its variant's policy.
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
	 * Plants a variant's policy and its item at the location.
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
	 * Plants an open allocation of a variant.
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
	public function holdRowsLeft(): array {
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
	 * Reads the policies and the default location, and computes an expiry.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param int   $ttlSeconds The TTL.
	 * @return array{policies: array<int, StockPolicy>, default_location_id: int|null, expires_at: string|null} The policies, the location and the expiry.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array {
		$this->calls[] = 'configuration:' . implode( ',', $variantIds );

		$policies = array();

		foreach ( $variantIds as $variantId ) {
			if ( isset( $this->items[ $variantId ] ) ) {
				$policies[ $variantId ] = new StockPolicy( $variantId, $this->items[ $variantId ]['track'], $this->items[ $variantId ]['policy'], null );
			}
		}

		return array(
			'policies'            => $policies,
			'default_location_id' => array() === $policies ? null : self::LOCATION,
			'expires_at'          => array() === $policies ? null : gmdate( 'Y-m-d H:i:s', $this->now + $ttlSeconds ),
		);
	}

	/**
	 * Claims units when available.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool Whether it matched.
	 */
	public function claim( int $variantId, int $locationId, int $quantity ): bool {
		$this->write( 'claim:' . $variantId . '@' . $locationId . ':' . $quantity );

		if ( ! $this->available( $variantId, $locationId, $quantity ) ) {
			return false;
		}

		$this->items[ $variantId ]['held'] += $quantity;

		return true;
	}

	/**
	 * Records a hold's rows, one call for all of them; none records nothing, as none sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param HoldLine[] $lines     The lines.
	 * @param string     $holdGroup The hold.
	 * @param string     $expiresAt The expiry.
	 * @param int|null   $cartId    The cart.
	 * @param int|null   $orderId   The order.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	public function insertHolds( array $lines, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): void {
		if ( array() === $lines ) {
			return;
		}

		$this->write( 'insertHolds:' . implode( ',', array_map( static fn( HoldLine $line ): string => $line->variantId . '@' . $line->locationId . ':' . $line->quantity, $lines ) ) );

		foreach ( $lines as $line ) {
			$this->addHold( $line->variantId, $line->quantity, $holdGroup, (int) strtotime( $expiresAt . ' UTC' ) );
		}
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
		$this->calls[] = 'holdRows';

		$units = array();

		foreach ( $this->holds as $row ) {
			if ( $holdGroup === $row['hold_group'] ) {
				$units[ $row['variant_id'] ] = ( $units[ $row['variant_id'] ] ?? 0 ) + $row['quantity'];
			}
		}

		ksort( $units );

		$rows = array();

		foreach ( $units as $variantId => $quantity ) {
			$rows[] = array(
				'variant_id'  => $variantId,
				'location_id' => self::LOCATION,
				'quantity'    => $quantity,
			);
		}

		return $rows;
	}

	/**
	 * Converts some or all of a hold's row of an item into allocated units, when the row is there.
	 *
	 * @since 0.1.0
	 *
	 * @param string $holdGroup   The hold.
	 * @param int    $variantId   The item's variant.
	 * @param int    $locationId  The item's location.
	 * @param int    $quantity    The units to convert.
	 * @param int    $rowQuantity The units the row holds, as read.
	 * @return bool Whether the row was there, and is gone or holds only the rest.
	 */
	public function convertHold( string $holdGroup, int $variantId, int $locationId, int $quantity, int $rowQuantity ): bool {
		$this->write( 'convertHold:' . $variantId . '@' . $locationId . ':' . $quantity );

		foreach ( $this->holds as $id => $row ) {
			if ( self::LOCATION === $locationId && $holdGroup === $row['hold_group'] && $variantId === $row['variant_id'] && $rowQuantity === $row['quantity'] && null === $row['token'] ) {
				if ( $quantity < $rowQuantity ) {
					$this->holds[ $id ]['quantity'] = $rowQuantity - $quantity;
				} else {
					unset( $this->holds[ $id ] );
				}

				$this->items[ $variantId ]['held']      -= $quantity;
				$this->items[ $variantId ]['allocated'] += $quantity;

				return true;
			}
		}

		return false;
	}

	/**
	 * Adds units to an item's `allocated` when available.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool Whether it matched.
	 */
	public function claimAllocation( int $variantId, int $locationId, int $quantity ): bool {
		$this->write( 'claimAllocation:' . $variantId . '@' . $locationId . ':' . $quantity );

		if ( ! $this->available( $variantId, $locationId, $quantity ) ) {
			return false;
		}

		$this->items[ $variantId ]['allocated'] += $quantity;

		return true;
	}

	/**
	 * Records an order's open allocations, one call for all of them.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $orderId     The order.
	 * @param Allocation[] $allocations The allocations.
	 *
	 * @phpstan-param list<Allocation> $allocations
	 */
	public function insertAllocations( int $orderId, array $allocations ): void {
		$rows = array();

		foreach ( $allocations as $allocation ) {
			$rows[] = $allocation->orderLineId . ':' . $allocation->variantId . '@' . $allocation->locationId . ':' . $allocation->quantity;

			$this->openAllocations[ $allocation->variantId ] = true;
		}

		$this->write( 'insertAllocations:' . $orderId . ':' . implode( ',', $rows ) );
	}

	/**
	 * Takes an item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool Whether it exists.
	 */
	public function lockItem( int $variantId, int $locationId ): bool {
		$this->write( 'lockItem:' . $variantId . '@' . $locationId );

		return self::LOCATION === $locationId && isset( $this->items[ $variantId ] );
	}

	/**
	 * Gives back expired rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimExpired( int $variantId, int $locationId, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimExpired:' . $variantId . '@' . $locationId;

		return $this->reclaim( $variantId, $locationId, fn( array $row ): bool => $row['expires'] <= $this->now );
	}

	/**
	 * Gives back one hold's rows.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $holdGroup  The hold.
	 * @param string $token      The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimGroup( int $variantId, int $locationId, string $holdGroup, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimGroup:' . $variantId . '@' . $locationId;

		return $this->reclaim( $variantId, $locationId, static fn( array $row ): bool => $holdGroup === $row['hold_group'] );
	}

	/**
	 * Gives back every row of an item.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      The token.
	 * @return ReclaimedRows The result.
	 */
	public function reclaimItem( int $variantId, int $locationId, string $token ): ReclaimedRows {
		$this->calls[] = 'reclaimItem:' . $variantId . '@' . $locationId;

		return $this->reclaim( $variantId, $locationId, static fn(): bool => true );
	}

	/**
	 * Lists the locations each variant has an item at: the one location, for each planted variant.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<int>> The locations by variant.
	 */
	public function itemLocations( array $variantIds ): array {
		$this->calls[] = 'itemLocations';

		$locations = array();

		foreach ( $variantIds as $variantId ) {
			if ( isset( $this->items[ $variantId ] ) ) {
				$locations[ $variantId ] = array( self::LOCATION );
			}
		}

		ksort( $locations );

		return $locations;
	}

	/**
	 * Locks every item of a variant.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return list<int> The locations.
	 */
	public function lockItemsOf( int $variantId ): array {
		$this->write( 'lockItemsOf:' . $variantId );

		return isset( $this->items[ $variantId ] ) ? array( self::LOCATION ) : array();
	}

	/**
	 * Lists items with expired, unclaimed rows.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterVariantId  Only after this item.
	 * @param int $afterLocationId See $afterVariantId.
	 * @param int $limit           The most items.
	 * @return list<array{0: int, 1: int}> The items, ascending.
	 */
	public function expiredItems( int $afterVariantId, int $afterLocationId, int $limit ): array {
		$variantIds = array();

		foreach ( $this->holds as $row ) {
			if ( $row['expires'] <= $this->now && null === $row['token'] && ( $row['variant_id'] > $afterVariantId || ( $row['variant_id'] === $afterVariantId && self::LOCATION > $afterLocationId ) ) ) {
				$variantIds[ $row['variant_id'] ] = $row['variant_id'];
			}
		}

		ksort( $variantIds );

		return array_map( static fn( int $variantId ): array => array( $variantId, self::LOCATION ), array_slice( array_values( $variantIds ), 0, $limit ) );
	}

	/**
	 * Lists the variant ids that have a stock policy.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only above this id.
	 * @param int $limit The most variants.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemVariantIds( int $after, int $limit ): array {
		$variantIds = array_values( array_filter( array_keys( $this->items ), static fn( int $id ): bool => $id > $after ) );

		sort( $variantIds );

		return array_slice( $variantIds, 0, $limit );
	}

	/**
	 * Tells whether a variant has a policy and the location exists.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return array{policy: bool, location: bool} Whether each exists.
	 */
	public function lockAdjustable( int $variantId, int $locationId ): array {
		$this->write( 'lockAdjustable:' . $variantId . '@' . $locationId );

		return array(
			'policy'   => isset( $this->items[ $variantId ] ),
			'location' => self::LOCATION === $locationId,
		);
	}

	/**
	 * Creates an item at zero when it does not exist.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 */
	public function createItemAt( int $variantId, int $locationId ): void {
		$this->write( 'createItemAt:' . $variantId . '@' . $locationId );
	}

	/**
	 * Recreates an item its hold rows outlived, at the location, when it does not exist.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the item was created.
	 */
	public function restoreItem( int $variantId, int $locationId ): bool {
		$this->write( 'restoreItem:' . $variantId . '@' . $locationId );

		if ( self::LOCATION !== $locationId || isset( $this->items[ $variantId ] ) ) {
			return false;
		}

		$held = 0;

		foreach ( $this->holds as $row ) {
			$held += $variantId === $row['variant_id'] ? $row['quantity'] : 0;
		}

		$this->items[ $variantId ] = array(
			'on_hand'   => 0,
			'allocated' => 0,
			'held'      => $held,
			'track'     => true,
			'policy'    => BackorderPolicy::No,
		);

		return true;
	}

	/**
	 * Adjusts on_hand when the floor and the precondition hold.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId      The item's variant.
	 * @param int      $locationId     The item's location.
	 * @param int      $delta          The change.
	 * @param int|null $expectedOnHand The expected on_hand.
	 * @return bool Whether it matched.
	 */
	public function adjust( int $variantId, int $locationId, int $delta, ?int $expectedOnHand ): bool {
		$this->write( 'adjust:' . $variantId . '@' . $locationId . ':' . $delta );

		$item = self::LOCATION === $locationId ? ( $this->items[ $variantId ] ?? null ) : null;

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
	 * @param int          $variantId     The item's variant.
	 * @param int          $locationId    The item's location.
	 * @param int          $delta         The change.
	 * @param int          $onHandAfter   on_hand after it.
	 * @param LedgerReason $reason        Why.
	 * @param string       $actorType     The actor type.
	 * @param int|null     $actorId       The actor.
	 * @param string|null  $correlationId The correlation id.
	 * @return int The entry's id.
	 */
	public function appendLedger( int $variantId, int $locationId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId ): int {
		$this->write( 'appendLedger:' . $variantId . '@' . $locationId . ':' . $delta );

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
	 * Reads levels at the location.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
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
	 * Reads levels at every location: the one.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<StockLevel>> The levels.
	 */
	public function levelsAt( array $variantIds ): array {
		return array_map( static fn( StockLevel $level ): array => array( $level ), $this->levels( $variantIds ) );
	}

	/**
	 * Reads what the location has of the variants, with their policies.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations.
	 * @return array{policies: array<int, StockPolicy>, available: array<int, array<int, int>>} The policies and the units.
	 */
	public function availability( array $variantIds, array $locationIds ): array {
		$policies  = array();
		$available = array();

		foreach ( $variantIds as $variantId ) {
			$level = $this->level( $variantId );

			if ( null === $level ) {
				continue;
			}

			$policies[ $variantId ] = new StockPolicy( $variantId, $level->track, $level->backorderPolicy, null );

			if ( in_array( self::LOCATION, $locationIds, true ) ) {
				$available[ self::LOCATION ][ $variantId ] = $level->available();
			}
		}

		return array(
			'policies'  => $policies,
			'available' => $available,
		);
	}

	/**
	 * Reads the variants' figures at the location.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations.
	 * @return array<int, array{policy: StockPolicy, total: int, largest: int}> The figures.
	 */
	public function saleFigures( array $variantIds, array $locationIds ): array {
		$figures = array();
		$read    = $this->availability( $variantIds, $locationIds );

		foreach ( $read['policies'] as $variantId => $policy ) {
			$units                 = max( 0, $read['available'][ self::LOCATION ][ $variantId ] ?? 0 );
			$figures[ $variantId ] = array(
				'policy'  => $policy,
				'total'   => $units,
				'largest' => $units,
			);
		}

		return $figures;
	}

	/**
	 * Reads one level under the lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return StockLevel|null The level.
	 */
	public function lockedLevel( int $variantId, int $locationId ): ?StockLevel {
		$this->write( 'lockedLevel:' . $variantId . '@' . $locationId );

		return self::LOCATION === $locationId ? $this->level( $variantId ) : null;
	}

	/**
	 * Creates policies and items at zero.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The variants.
	 * @param int   $locationId The location.
	 * @return list<int> The variants created.
	 */
	public function createItems( array $variantIds, int $locationId ): array {
		$this->write( 'createItems:' . implode( ',', $variantIds ) );

		$created = array();

		foreach ( $variantIds as $variantId ) {
			if ( ! isset( $this->items[ $variantId ] ) ) {
				$this->plantItem( $variantId );

				$created[] = (int) $variantId;
			}
		}

		return $created;
	}

	/**
	 * Tells whether a variant has an open allocation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 * @return bool Whether one is open.
	 */
	public function hasOpenAllocation( int $variantId ): bool {
		$this->write( 'hasOpenAllocation:' . $variantId );

		return isset( $this->openAllocations[ $variantId ] );
	}

	/**
	 * Takes a variant's policy lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool Whether it has a policy.
	 */
	public function lockPolicy( int $variantId ): bool {
		$this->write( 'lockPolicy:' . $variantId );

		return isset( $this->items[ $variantId ] );
	}

	/**
	 * Deletes an item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool Whether it was there.
	 */
	public function deleteItem( int $variantId, int $locationId ): bool {
		$this->write( 'deleteItem:' . $variantId . '@' . $locationId );

		$found = self::LOCATION === $locationId && isset( $this->items[ $variantId ] );

		if ( $found ) {
			unset( $this->items[ $variantId ] );
		}

		return $found;
	}

	/**
	 * Deletes a variant's policy, which the double keeps with its item.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool Whether it was there.
	 */
	public function deletePolicy( int $variantId ): bool {
		$this->write( 'deletePolicy:' . $variantId );

		return true;
	}

	/**
	 * Tells whether an item at the location has that many units available.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units.
	 * @return bool True when it has.
	 */
	private function available( int $variantId, int $locationId, int $quantity ): bool {
		$item = self::LOCATION === $locationId ? ( $this->items[ $variantId ] ?? null ) : null;

		return null !== $item && $item['on_hand'] - $item['allocated'] - $item['held'] >= $quantity;
	}

	/**
	 * Gives back the item's rows that match, as the four statements do.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $variantId  The item's variant.
	 * @param int      $locationId The item's location.
	 * @param callable $matches    Whether a row of the item, unclaimed, is claimed.
	 * @return ReclaimedRows The result.
	 */
	private function reclaim( int $variantId, int $locationId, callable $matches ): ReclaimedRows {
		if ( ! $this->lockItem( $variantId, $locationId ) ) {
			return ReclaimedRows::itemMissing( $variantId, $locationId );
		}

		$claimed = array();

		foreach ( $this->holds as $id => $row ) {
			if ( $variantId === $row['variant_id'] && null === $row['token'] && $matches( $row ) ) {
				$claimed[ $id ] = $row;
			}
		}

		if ( array() === $claimed ) {
			return ReclaimedRows::of( $variantId, $locationId, array() );
		}

		$sum = (int) array_sum( array_column( $claimed, 'quantity' ) );

		if ( $this->items[ $variantId ]['held'] < $sum ) {
			return ReclaimedRows::corrupt( $variantId, $locationId );
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

		return ReclaimedRows::of( $variantId, $locationId, $rows );
	}

	/**
	 * Adds a hold row.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $variantId The item's variant.
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
	 * Builds a level at the location.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 * @return StockLevel|null The level, or null when there is no item.
	 */
	private function level( int $variantId ): ?StockLevel {
		$item = $this->items[ $variantId ] ?? null;

		return null === $item ? null : new StockLevel( $variantId, self::LOCATION, $item['on_hand'], $item['allocated'], $item['held'], $item['track'], $item['policy'] );
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

	/**
	 * Tells whether the fake recorded an allocation of an order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when insertAllocations() recorded one.
	 */
	public function hasAllocationsFor( int $orderId ): bool {
		foreach ( $this->calls as $call ) {
			if ( str_starts_with( $call, 'insertAllocations:' . $orderId . ':' ) ) {
				return true;
			}
		}

		return false;
	}
}
