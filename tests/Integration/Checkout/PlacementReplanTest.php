<?php
/**
 * Tests that a placement whose plan went stale plans once more, and what it answers in each mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartService;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Pricing\Application\ShippingRateQuoter;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Infrastructure\Quotes\FlatRateShippingQuoter;
use SEOCart\Support\Decimal;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\OriginPricedShipping;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\SecondDatabase;

defined( 'ABSPATH' ) || exit;

/**
 * Another checkout takes a planned unit, or the destination goes, between the plan and the claim: the placement plans once more, never twice, and answers by mode.
 *
 * Planted violations: in PlaceOrder::isStalePlan(), answer false (no re-plan: the placement is
 * refused); in PlaceOrder::replanned(), price twice (two re-prices); drop the fingerprint check (an
 * unchanged plan is run again: a third unit in the log); drop the transfer check (a flip to a
 * transfer is placed silently); in StockService::holdInside(), skip the destination's lock (an
 * order then names a location deleted while it was placed).
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class PlacementReplanTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;
	use RoutedPlacements;

	/**
	 * Deletes the users this test created, then closes what the parent opened.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a placement racing the deletion of the location its order would ship from, which holds none of its units, never makes an order naming a location that is gone: it plans again or is refused.
	 *
	 * The deletion commits on connection B after the placement's plan and before its hold, when
	 * nothing of the stock, no hold and no order refers to the location: the location's row is
	 * deleted and the cached list forgotten, as a deletion leaves them (the deletion's own refusal
	 * of a location an order names is LocationOperationsTest's; its service cannot run inside this
	 * process's open transaction). The hold's lock of the destination's row then finds it gone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_racing_its_destination_s_deletion_never_names_a_location_that_is_gone(): void {
		$variant     = $this->sellable( 0 );
		$destination = $this->plantLocation( -1 );
		$warehouse   = $this->plantLocation( 5, true, false );

		// The order ships from the first location, which holds none of its units: a warehouse sends them.
		$this->stockAt( $variant, $warehouse, 2 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$input   = $this->placeInput();
		$b       = $this->secondConnection();
		$barrier = $this->beforeStatement(
			self::HOLD_CONFIGURATION,
			function () use ( $b, $destination ): void {
				$b->query( sprintf( 'DELETE FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $destination ) );
				wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );
			}
		);
		$answer  = null;
		$refused = null;

		try {
			$answer = $this->placement->place( $input, self::guest() );
		} catch ( CodedException $thrown ) {
			$refused = $thrown;
		}

		$this->assertTrue( $barrier->fired );
		$this->assertSame( '0', (string) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $destination ) ), 'The deletion did not commit; the placement answered ' . ( null === $refused ? 'its order' : $refused->errorCode()->value ) . '.' );
		$this->assertSame( '0', (string) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` o LEFT JOIN `%s` l ON l.id = o.fulfilment_location_id WHERE o.fulfilment_location_id IS NOT NULL AND l.id IS NULL', $this->table( OrderTables::ORDERS ), $this->table( InventoryTables::LOCATIONS ) ) ), 'An order names a location that is gone.' );

		if ( null === $refused ) {
			$this->assertSame( 'approved', $answer['outcome'] ?? null, 'A placement that planned again did not place.' );
		} else {
			$this->assertContains( $refused->errorCode(), array( InventoryError::LocationMissing, InventoryError::RoutingRefused, InventoryError::NoFulfillingLocation ), 'The placement was refused for another reason.' );
		}
	}

	/**
	 * Tests that a location deleted while the placement plans, between the router's read of the locations and the plan's lookup of its destination, is planned around: the router is asked once more, and the order ships from a location that exists.
	 *
	 * Planted violation: in InventoryFulfilmentRouter::propose(), do not ask again: the placement
	 * fails with a \LogicException.
	 *
	 * @since 0.2.0
	 */
	public function test_a_destination_deleted_while_the_placement_plans_is_planned_around(): void {
		$variant     = $this->sellable( 0 );
		$destination = $this->plantLocation( -1 );
		$warehouse   = $this->plantLocation( 5, true, false );

		$this->stockAt( $variant, $warehouse, 2 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$input   = $this->placeInput();
		$second  = new SecondDatabase( $this->reporter() );
		$other   = $this->kernelOver( $second->db(), new FakeCartTokens() );
		$barrier = $this->beforeStatement( '/^SELECT p\.variant_id, p\.track, p\.backorder_policy, p\.low_stock_threshold, i\.location_id, /', $this->deletion( $other, $this->userGranted( LocationOperations::CAPABILITY ), $this->uuidOfLocation( $destination ) ) );

		try {
			$answer = $this->placement->place( $input, self::guest() );
		} finally {
			$second->close();
		}

		$order = $this->routedOrder( (string) $answer['order_uuid'] );

		$this->assertTrue( $barrier->fired );
		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertSame( array( $this->defaultLocation(), 3 ), array( $order['fulfilment_location_id'], $order['transfer_lead_days'] ), 'The order ships from the first location left, after a transfer.' );
	}

	/**
	 * Returns the deletion of a location by another request, on its own connection, as a manager.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $other   The other request's container.
	 * @param Actor     $manager Who deletes.
	 * @param string    $uuid    The location's uuid.
	 * @return \Closure The deletion.
	 */
	private function deletion( Container $other, Actor $manager, string $uuid ): \Closure {
		return static function () use ( $other, $manager, $uuid ): void {
			$other->get( Locations::class )->deleteLocation( array( 'location_uuid' => $uuid ), $manager );
		};
	}

	/**
	 * What a hold begins with: the configuration of its variants, with the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const HOLD_CONFIGURATION = '/^SELECT p\.variant_id, p\.track, p\.backorder_policy, p\.low_stock_threshold, \( SELECT l\.id FROM /';

	/**
	 * What a placement's claim of a planned unit begins with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CLAIM = '/^UPDATE `[^`]+stock_items` SET held = held \+ /';

	/**
	 * Tests that two placements plan the same last unit, the loser's claim is refused after its reclaim, and it plans once more and places at the other location.
	 *
	 * @since 0.2.0
	 */
	public function test_the_placement_that_loses_the_unit_plans_once_more_and_places_elsewhere(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->readyCart( array( $variant => 1 ) );

		$theirs = $this->placeInput( 'theirs', StubGateway::REQUIRES_ACTION );
		$token  = $this->tokens->presented;

		$this->readyCart( array( $variant => 1 ) );

		$input   = $this->placeInput( 'ours', StubGateway::REQUIRES_ACTION );
		$report  = null;
		$answer  = null;
		$barrier = $this->beforeStatement(
			self::CLAIM,
			function () use ( $token, $theirs, &$report ): void {
				$this->assertNotNull( $token );

				// The other checkout plans the same unit and places its order first.
				$report = $this->startPlacement( $token, $theirs )->finish();
			}
		);
		$log     = $this->captureQueries(
			function () use ( $input, &$answer ): void {
				$answer = $this->placement->place( $input, self::guest() );
			}
		);
		$first   = $this->defaultLocation();

		$this->assertTrue( $barrier->fired );
		$this->assertSame( 'requires_action', json_decode( (string) ( $report['answer_json'] ?? '' ), true )['outcome'] ?? $report, 'The other checkout placed its order at the first location.' );
		$this->assertSame( 'requires_action', $answer['outcome'] );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'COMMIT' ), $this->control( $log ), 'One rolled-back unit, then the two.' );
		$this->assertSame( 2, $log->matching( $this->availabilityRead() )->count(), 'The placement priced and planned exactly once more.' );
		$this->assertSame( $second, $this->routedOrder( (string) $answer['order_uuid'] )['fulfilment_location_id'] );
		$this->assertSame( array( 1, 1 ), array( $this->heldAt( $variant, $first ), $this->heldAt( $variant, $second ) ) );
		$this->assertSame( $answer['order_uuid'], $this->placement->place( $input, self::guest() )['order_uuid'], 'A retry of the key answers the order it placed.' );
	}

	/**
	 * Tests that, under `split`, a placement whose re-plan finds no unit anywhere answers the router's refusal, after one rolled-back unit.
	 *
	 * @since 0.2.0
	 */
	public function test_in_split_mode_nothing_left_answers_the_routing_refusal(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->readyCart( array( $variant => 1 ) );

		$input = $this->placeInput();

		$this->takeBefore( 1, array( $variant => array( $this->defaultLocation(), $second ) ) );

		$refused = $this->refusal( $input, $log );

		$this->assertSame( InventoryError::RoutingRefused, $refused->errorCode() );
		$this->assertSame( array( 'quantity', array( $variant ), 0 ), array( $refused->details()['reason'] ?? null, $refused->details()['variant_ids'] ?? null, $refused->details()['available'] ?? null ) );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->control( $log ) );
	}

	/**
	 * Tests that, under `split`, a second refusal after the one re-plan is the answer: never a third unit.
	 *
	 * @since 0.2.0
	 */
	public function test_in_split_mode_a_second_refusal_is_the_answer(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->readyCart( array( $variant => 1 ) );

		$input = $this->placeInput();

		// The first claim and its retry after the reclaim are at the first location; the third claim is the fresh plan's.
		$this->takeBefore( 1, array( $variant => array( $this->defaultLocation() ) ) );
		$this->takeBefore( 3, array( $variant => array( $second ) ) );

		$refused = $this->refusal( $input, $log );

		$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
		$this->assertSame( $second, $refused->details()['location_id'] ?? null, 'The answer is the fresh plan\'s refusal.' );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK' ), $this->control( $log ) );
		$this->assertSame( 2, $log->matching( $this->availabilityRead() )->count() );
	}

	/**
	 * Tests that a fresh plan shipping from a location whose rate is dearer moves the total, and the client is told the new totals.
	 *
	 * @since 0.2.0
	 */
	public function test_a_fresh_plan_whose_origin_moves_the_total_answers_totals_changed(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->useKernel( $this->kernelShippingDearerFrom( $second ) );
		$this->readyCart( array( $variant => 1 ) );

		$input = $this->scopedInput();

		$this->takeBefore( 1, array( $variant => array( $this->defaultLocation() ) ) );

		$refused = $this->refusal( $input, $log );

		$this->assertSame( CheckoutError::TotalsChanged, $refused->errorCode() );
		$this->assertGreaterThan( $input['grand_total_minor'], $refused->details()['totals']['summary']['grand_minor'] ?? 0, 'The total moved with the origin.' );
		$this->assertSame(
			array(
				'requires_transfer' => false,
				'transfer_days'     => null,
			),
			$refused->details()['delivery'] ?? null
		);
	}

	/**
	 * Tests that, under `transfer`, a fresh plan that must bring units together, where the shopper was told it need not, is not placed: the client is told the new delivery.
	 *
	 * @since 0.2.0
	 */
	public function test_in_transfer_mode_a_flip_to_a_transfer_answers_totals_changed_with_the_delivery(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5, true, false );

		$this->stockAt( $variant, $second, 1 );
		$this->routeIn( FulfilmentMode::Transfer, 4 );
		$this->readyCart( array( $variant => 1 ) );

		$input = $this->placeInput();

		$this->takeBefore( 1, array( $variant => array( $this->defaultLocation() ) ) );

		$refused = $this->refusal( $input, $log );

		$this->assertSame( CheckoutError::TotalsChanged, $refused->errorCode(), 'A plan that now needs a transfer was placed without the shopper being told.' );
		$this->assertSame(
			array(
				'requires_transfer' => true,
				'transfer_days'     => 4,
			),
			$refused->details()['delivery'] ?? null
		);
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->control( $log ) );
	}

	/**
	 * Tests that, under `refuse`, a re-plan that finds no one location with the units answers the refusal with its reason.
	 *
	 * @since 0.2.0
	 */
	public function test_in_refuse_mode_a_re_plan_with_no_location_answers_the_refusal_and_its_reason(): void {
		$variant = $this->sellable( 1 );

		$this->routeIn( FulfilmentMode::Refuse );
		$this->readyCart( array( $variant => 1 ) );

		$input = $this->placeInput();

		$this->takeBefore( 1, array( $variant => array( $this->defaultLocation() ) ) );

		$refused = $this->refusal( $input, $log );

		$this->assertSame( InventoryError::RoutingRefused, $refused->errorCode() );
		$this->assertSame( array( 'quantity', array( $variant ) ), array( $refused->details()['reason'] ?? null, $refused->details()['variant_ids'] ?? null ) );
	}

	/**
	 * Tests that a destination deactivated under a plan the request cannot see change answers the refusal without running the same plan again.
	 *
	 * The request read its locations once; a deactivation committed after that is seen by the
	 * destination's lock but not by the fresh plan, which is then the stale one again.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unchanged_fresh_plan_answers_the_refusal_without_another_unit(): void {
		$variant     = $this->sellable( 1 );
		$destination = $this->plantLocation( -1 );
		$warehouse   = $this->plantLocation( 5, true, false );

		// No one location has both units, so the order ships from the first, which holds none of them.
		$this->stockAt( $variant, $warehouse, 1 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$input = $this->placeInput();
		$b     = $this->secondConnection();

		$this->beforeStatement(
			'/^SELECT .+ FROM `[^`]+locations` WHERE id = \d+ LOCK IN SHARE MODE/',
			function () use ( $b, $destination ): void {
				$b->query( sprintf( 'UPDATE `%s` SET active = 0 WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $destination ) );
			}
		);

		$refused = $this->refusal( $input, $log );

		$this->assertSame( array( InventoryError::LocationInactive, $destination ), array( $refused->errorCode(), $refused->context()['location_id'] ?? null ) );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->control( $log ), 'The same plan was run again.' );
	}

	/**
	 * Places, expecting a refusal, and returns it with the placement's query log.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The placement's input.
	 * @param QueryLog|null        $log   Set to the placement's queries.
	 * @return CodedException The refusal.
	 *
	 * @phpstan-param-out QueryLog $log
	 */
	private function refusal( array $input, ?QueryLog &$log = null ): CodedException {
		$refused = null;
		$log     = $this->captureQueries(
			function () use ( $input, &$refused ): void {
				try {
					$this->placement->place( $input, self::guest() );
				} catch ( CodedException $thrown ) {
					$refused = $thrown;
				}
			}
		);

		$this->assertInstanceOf( CodedException::class, $refused, 'The placement was not refused.' );

		return $refused;
	}

	/**
	 * Has another checkout hold one unit of each variant at each location named, committed on a second connection, right before the nth claim of the placement.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $nth   Which claim it comes before, counting the retry after a reclaim.
	 * @param array<int, list<int>> $where The locations, by variant.
	 */
	private function takeBefore( int $nth, array $where ): void {
		$b = $this->secondConnection();

		$this->beforeStatement(
			self::CLAIM,
			function () use ( $b, $where ): void {
				foreach ( $where as $variant => $locations ) {
					foreach ( $locations as $location ) {
						$b->query( sprintf( 'UPDATE `%s` SET held = held + 1 WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variant, $location ) );
						$b->query( sprintf( "INSERT INTO `%s` ( variant_id, location_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, '%s', 1, UTC_TIMESTAMP() + INTERVAL 600 SECOND, UTC_TIMESTAMP(6) )", $this->table( InventoryTables::HOLDS ), $variant, $location, wp_generate_uuid4() ) );
					}
				}
			},
			$nth
		);
	}

	/**
	 * Returns the transactions' control statements of a log, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The log.
	 * @return list<string> START TRANSACTION, COMMIT and ROLLBACK.
	 */
	private function control( QueryLog $log ): array {
		return $log->matching( '/^(START TRANSACTION|COMMIT|ROLLBACK)$/' )->sqls();
	}

	/**
	 * Returns the pattern of the plan's availability read: one per pricing.
	 *
	 * @since 0.2.0
	 *
	 * @return string The pattern.
	 */
	private function availabilityRead(): string {
		return '/^' . preg_quote( strtok( MysqlStockRepository::AVAILABILITY, '{' ), '/' ) . '/';
	}

	/**
	 * Returns the units held of a variant at a location, as committed.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return int The units held.
	 */
	private function heldAt( int $variantId, int $locationId ): int {
		return (int) $this->secondConnection()->fetchValue( sprintf( 'SELECT held FROM `%s` WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variantId, $locationId ) );
	}

	/**
	 * Returns a placement's input for the request's cart at the totals a checkout prices it at, plan included.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The input.
	 */
	private function scopedInput(): array {
		$carts = $this->kernel->get( CartService::class );
		$cart  = $carts->current();

		$this->assertNotNull( $cart );

		$grand = $carts->calculation( $cart, null, RoutingScope::checkout() )->totals->summary->grand;

		return array(
			'idempotency_key'   => 'scoped',
			'cart_version'      => $cart->version,
			'grand_total_minor' => $grand->minorUnits(),
			'currency'          => $grand->currency()->code(),
			'payment_data'      => array( 'payment_token' => self::APPROVE ),
		);
	}

	/**
	 * Builds the placement's kernel with shipping dearer from one location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $far The location shipping is dearer from.
	 * @return Container The kernel.
	 */
	private function kernelShippingDearerFrom( int $far ): Container {
		return PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				ShippingRateQuoter::class => static fn( Container $c ): ShippingRateQuoter => new OriginPricedShipping(
					new FlatRateShippingQuoter( $c->get( IdGenerator::class ), Decimal::of( FlatRateShippingQuoter::RATE ), AmountBasis::Net, FlatRateShippingQuoter::TAX_CLASS ),
					new FlatRateShippingQuoter( $c->get( IdGenerator::class ), Decimal::of( '9.75' ), AmountBasis::Net, FlatRateShippingQuoter::TAX_CLASS ),
					$far
				),
			)
		);
	}

	/**
	 * Makes a kernel the test's: its placement is the one the test sends.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $kernel The kernel.
	 */
	private function useKernel( Container $kernel ): void {
		$this->kernel    = $kernel;
		$this->placement = $kernel->get( PlaceOrder::class );
	}
}
