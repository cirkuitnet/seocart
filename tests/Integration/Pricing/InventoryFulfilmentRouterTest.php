<?php
/**
 * Tests that the stock module's plans become the calculation's routing proposals unchanged
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\RoutingContext;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\Routing\RoutingPlan;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Pricing\Domain\Quote\RoutingProposal;
use SEOCart\Pricing\Infrastructure\Routing\InventoryFulfilmentRouter;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter maps every line, finds the destination's uuid in the directory, reads the lead days only for a transfer, and lets a refusal through.
 *
 * @since 0.2.0
 */
final class InventoryFulfilmentRouterTest extends StockTestCase {

	/**
	 * How many times the lead-days setting was read.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $leadDaysReads = 0;

	/**
	 * Tests that a whole-order plan is mapped line for line, with the destination's uuid, and reads no lead days.
	 *
	 * @since 0.2.0
	 */
	public function test_a_whole_order_plan_is_mapped_without_reading_the_lead_days(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 2 );

		$proposal = $this->router()->propose( array( $variant => 2 ), RoutingScope::checkout() );

		$this->assertMapped( $this->plan( array( $variant => 2 ) ), $proposal );
		$this->assertSame( array( false, false, 'whole_order', null ), array( $proposal->requiresTransfer, $proposal->split, $proposal->reason, $proposal->transferLeadDays ) );
		$this->assertTrue( $proposal->holdsAtDestination() );
		$this->assertSame( 0, $this->leadDaysReads, 'The lead days were read for a plan that needs no transfer.' );
	}

	/**
	 * Tests that a split plan keeps the line at each location, and reads no lead days.
	 *
	 * @since 0.2.0
	 */
	public function test_a_split_plan_keeps_every_location_s_line(): void {
		$variant = self::variant();
		$second  = $this->plantLocation( 5 );

		$this->stockItem( $variant, 1 );
		$this->service->adjust( $variant, $second, 1, LedgerReason::Received, Actor::user( 1 ) );

		$this->mode = FulfilmentMode::Split;
		$proposal   = $this->router()->propose( array( $variant => 2 ), RoutingScope::checkout() );

		$this->assertMapped( $this->plan( array( $variant => 2 ) ), $proposal );
		$this->assertCount( 2, $proposal->lines, 'A location\'s line was dropped.' );
		$this->assertSame( array( true, false, null ), array( $proposal->split, $proposal->requiresTransfer, $proposal->transferLeadDays ) );
		$this->assertSame( 0, $this->leadDaysReads );
	}

	/**
	 * Tests that a transfer plan is mapped with the days the store tells shoppers, read once, and survives its array form.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_plan_carries_the_lead_days_and_round_trips(): void {
		$variant = self::variant();
		$second  = $this->plantLocation( 5 );

		$this->stockItem( $variant, 1 );
		$this->service->adjust( $variant, $second, 1, LedgerReason::Received, Actor::user( 1 ) );

		$this->mode = FulfilmentMode::Transfer;
		$proposal   = $this->router()->propose( array( $variant => 2 ), RoutingScope::checkout() );

		$this->assertMapped( $this->plan( array( $variant => 2 ) ), $proposal );
		$this->assertCount( 2, $proposal->lines, 'A location\'s line was dropped.' );
		$this->assertSame( array( true, false, 'transfer', 3 ), array( $proposal->requiresTransfer, $proposal->split, $proposal->reason, $proposal->transferLeadDays ) );
		$this->assertSame( 1, $this->leadDaysReads, 'The lead days are read once, for a plan that needs a transfer.' );

		$again = RoutingProposal::fromArray( array( 'record' => 'routing' ) + $proposal->toArray() );

		$this->assertEquals( $proposal, $again );
		$this->assertSame( $proposal->fingerprint(), $again->fingerprint() );
	}

	/**
	 * Tests that the stock module's refusal reaches the caller unchanged, with its details.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refusal_passes_through_unchanged(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 1 );

		$this->mode = FulfilmentMode::Refuse;

		try {
			$this->router()->propose( array( $variant => 2 ), RoutingScope::checkout() );
			$this->fail( 'A plan was proposed for more units than the store has.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::RoutingRefused, $refused->errorCode() );
			$this->assertSame(
				array(
					'reason'      => 'quantity',
					'variant_ids' => array( $variant ),
					'available'   => 1,
				),
				$refused->details()
			);
		}
	}

	/**
	 * Asserts that a proposal is the plan, mapped: the same lines, the same destination, and its uuid from the directory.
	 *
	 * @since 0.2.0
	 *
	 * @param RoutingPlan     $plan     The stock module's plan for the same units.
	 * @param RoutingProposal $proposal The adapter's proposal.
	 */
	private function assertMapped( RoutingPlan $plan, RoutingProposal $proposal ): void {
		$lines = array_map(
			static fn( HoldLine $line ): array => array(
				'variant_id'  => $line->variantId,
				'location_id' => (int) $line->locationId,
				'quantity'    => $line->quantity,
			),
			$plan->lines
		);

		$this->assertSame( $lines, $proposal->lines );
		$this->assertSame( $plan->destinationLocationId, $proposal->destinationLocationId );
		$this->assertSame( $this->directory()->find( $plan->destinationLocationId )?->uuid, $proposal->destinationLocationUuid );
		$this->assertSame( $plan->destinationLocationId, $proposal->originLocationId() );
	}

	/**
	 * Returns the stock module's own plan for units, under the test's mode.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $units The units of each variant.
	 * @return RoutingPlan The plan.
	 */
	private function plan( array $units ): RoutingPlan {
		return $this->routing()->propose( $units, RoutingContext::none() );
	}

	/**
	 * Returns the adapter, over the test's database and mode, with the lead days at 3.
	 *
	 * @since 0.2.0
	 *
	 * @return InventoryFulfilmentRouter The adapter.
	 */
	private function router(): InventoryFulfilmentRouter {
		return new InventoryFulfilmentRouter(
			$this->routing(),
			$this->directory(),
			function (): int {
				++$this->leadDaysReads;

				return 3;
			}
		);
	}

	/**
	 * Returns the stock module's router over the test's database and mode.
	 *
	 * @since 0.2.0
	 *
	 * @return FulfilmentRouting The router.
	 */
	private function routing(): FulfilmentRouting {
		return new FulfilmentRouting( $this->directory(), $this->repository, fn(): FulfilmentMode => $this->mode );
	}

	/**
	 * Returns the location directory over the test's database.
	 *
	 * @since 0.2.0
	 *
	 * @return LocationDirectory The directory.
	 */
	private function directory(): LocationDirectory {
		return new LocationDirectory( new MysqlLocationRepository( $this->db ) );
	}
}
