<?php
/**
 * Tests the router's rules, row by row
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\Routing\Candidate;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\Routing\Router;
use SEOCart\Inventory\Domain\Routing\RoutingPlan;
use SEOCart\Inventory\Domain\Routing\RoutingRefused;
use SEOCart\Inventory\Domain\Routing\RoutingRequest;

/**
 * Each row is an order, the active locations with what they have of it, and the mode; and the plan or the refusal the router must give.
 *
 * Locations 1, 2 and 3 fulfil online orders at priorities 0, 1 and 2 unless a row says otherwise;
 * location 9 is active and does not. Variants 11 and 12 are tracked; 13 may be sold beyond what is
 * available. Every row is planned twice more, from its candidates in reverse and in a shuffled
 * order, and must give the same answer: the router orders locations itself.
 *
 * Planted violations, each shown red and removed: in Router::plan(), try the fulfilling locations
 * in descending priority: the whole-order rows pick the wrong location; in Router::split(), take
 * from every candidate, not the fulfilling ones: the row with a location that ships nothing splits
 * through it; in Router::transfer(), source from the fulfilling locations only: the unit at the
 * location that ships nothing is missed and the row is refused; make the destination of a
 * transfer the first location that gave anything: the destination rows end at location 2; in
 * Router::refusal(), report the sum of the locations as a `quantity` refusal's figure: the refuse
 * rows show 7 where one location has 4; in Router::pickup(), refuse a short line in `transfer`
 * mode as the other modes do: the pickup that waits for a transfer is refused; in
 * Router::ordered(), keep the order the candidates were given in: the shuffled runs disagree.
 *
 * @since 0.2.0
 */
final class RouterTest extends TestCase {

	/**
	 * Tests each row's plan or refusal, from its candidates in their order, in reverse and shuffled.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_rows
	 *
	 * @param FulfilmentMode $mode        The mode.
	 * @param array[]        $lines       Each line's variant and units.
	 * @param array[]        $candidates  The candidates, as Candidate arguments.
	 * @param int|null       $pickup      The pickup location, or null.
	 * @param int|null       $destination The destination, or null.
	 * @param array          $expected    The plan, or `refused` with its reason, variants, requested and available.
	 *
	 * @phpstan-param list<array{0: int, 1: int}> $lines
	 * @phpstan-param list<list<mixed>>           $candidates
	 * @phpstan-param array<string, mixed>        $expected
	 */
	public function test_each_row_is_planned_as_the_rules_say( FulfilmentMode $mode, array $lines, array $candidates, ?int $pickup, ?int $destination, array $expected ): void {
		$units    = self::map( $lines );
		$built    = array_map( static fn( array $arguments ): Candidate => new Candidate( ...$arguments ), $candidates );
		$shuffled = $built;

		mt_srand( count( $built ) * 7 + array_sum( $units ) );
		shuffle( $shuffled );

		$orders = array(
			'given'    => $built,
			'reversed' => array_reverse( $built ),
			'shuffled' => $shuffled,
		);

		foreach ( $orders as $order => $list ) {
			$this->assertSame( $expected, self::answer( new RoutingRequest( $units, $list, $mode, $pickup, $destination ) ), 'Candidates ' . $order );
		}
	}

	/**
	 * The rows.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: FulfilmentMode, 1: list<array{0: int, 1: int}>, 2: list<list<mixed>>, 3: int|null, 4: int|null, 5: array<string, mixed>}> The rows.
	 */
	public static function data_rows(): array {
		$split    = FulfilmentMode::Split;
		$transfer = FulfilmentMode::Transfer;
		$refuse   = FulfilmentMode::Refuse;

		return array(
			// Every mode ships an order one location can ship whole from the first that can.
			'whole order at the highest priority'       => array( $split, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 5 ) ) ) ), null, null, self::plan( array( array( 11, 1, 2 ) ), 1, false, false, 'whole_order' ) ),
			'a lower priority that ships whole beats a split' => array( $split, array( array( 11, 2 ), array( 12, 1 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 5 ), array( 12, 5 ) ) ) ), null, null, self::plan( array( array( 11, 2, 2 ), array( 12, 2, 1 ) ), 2, false, false, 'whole_order' ) ),
			'whole order in transfer mode too'          => array( $transfer, array( array( 11, 2 ), array( 12, 1 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 5 ), array( 12, 5 ) ) ) ), null, null, self::plan( array( array( 11, 2, 2 ), array( 12, 2, 1 ) ), 2, false, false, 'whole_order' ) ),
			'whole order in refuse mode too'            => array( $refuse, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 1 ) ) ), self::candidate( 2, 1, array( array( 11, 5 ) ) ) ), null, null, self::plan( array( array( 11, 2, 2 ) ), 2, false, false, 'whole_order' ) ),
			'ties by id'                                => array( $split, array( array( 11, 2 ) ), array( self::candidate( 2, 0, array( array( 11, 5 ) ) ), self::candidate( 1, 0, array( array( 11, 5 ) ) ) ), null, null, self::plan( array( array( 11, 1, 2 ) ), 1, false, false, 'whole_order' ) ),
			'split greedy in priority order'            => array( $split, array( array( 11, 5 ) ), array( self::candidate( 1, 0, array( array( 11, 3 ) ) ), self::candidate( 2, 1, array( array( 11, 4 ) ) ) ), null, null, self::plan( array( array( 11, 1, 3 ), array( 11, 2, 2 ) ), 1, false, true, 'split' ) ),
			'split from the first that gives anything'  => array( $split, array( array( 11, 2 ), array( 12, 2 ) ), array( self::candidate( 1, 0, array() ), self::candidate( 2, 1, array( array( 11, 2 ) ) ), self::candidate( 3, 2, array( array( 12, 2 ) ) ) ), null, null, self::plan( array( array( 11, 2, 2 ), array( 12, 3, 2 ) ), 2, false, true, 'split' ) ),
			'split refused with the sum it has'         => array( $split, array( array( 11, 9 ) ), array( self::candidate( 1, 0, array( array( 11, 3 ) ) ), self::candidate( 2, 1, array( array( 11, 4 ) ) ) ), null, null, self::refused( 'quantity', array( 11 ), 9, 7 ) ),
			'a location that ships nothing never ships' => array( $split, array( array( 11, 3 ) ), array( self::candidate( 9, 0, array( array( 11, 5 ) ), false ), self::candidate( 2, 1, array( array( 11, 2 ) ) ) ), null, null, self::refused( 'quantity', array( 11 ), 3, 2 ) ),
			'a negative figure counts as nothing'       => array( $split, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, -4 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ) ), null, null, self::refused( 'quantity', array( 11 ), 2, 1 ) ),
			'backorder allowed routes to the destination at 0' => array( $split, array( array( 12, 1 ), array( 13, 4 ) ), array( self::candidate( 1, 0, array( array( 12, 0 ) ) ), self::candidate( 2, 1, array( array( 12, 1 ) ) ) ), null, null, self::plan( array( array( 12, 2, 1 ), array( 13, 2, 4 ) ), 2, false, false, 'whole_order' ) ),
			'backorder allowed in a split goes where it ships from' => array( $split, array( array( 11, 3 ), array( 13, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 1 ) ) ), self::candidate( 2, 1, array( array( 11, 2 ) ) ) ), null, null, self::plan( array( array( 11, 1, 1 ), array( 11, 2, 2 ), array( 13, 1, 2 ) ), 1, false, true, 'split' ) ),
			'pickup ignores priority'                   => array( $split, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 2 ) ) ) ), 2, null, self::plan( array( array( 11, 2, 2 ) ), 2, false, false, 'pickup' ) ),
			'pickup short in split mode refuses its figure' => array( $split, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ) ), 2, null, self::refused( 'quantity', array( 11 ), 2, 1 ) ),
			'pickup short in refuse mode refuses its figure' => array( $refuse, array( array( 11, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ) ), 2, null, self::refused( 'quantity', array( 11 ), 2, 1 ) ),
			// Transfer mode, and the two reasons of refuse mode.
			'pickup short in transfer mode waits for a transfer' => array( $transfer, array( array( 11, 3 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ) ), 2, null, self::plan( array( array( 11, 1, 2 ), array( 11, 2, 1 ) ), 2, true, false, 'pickup' ) ),
			'transfer ends at the highest priority'     => array( $transfer, array( array( 11, 5 ) ), array( self::candidate( 1, 0, array( array( 11, 2 ) ) ), self::candidate( 2, 1, array( array( 11, 4 ) ) ) ), null, null, self::plan( array( array( 11, 1, 2 ), array( 11, 2, 3 ) ), 1, true, false, 'transfer' ) ),
			'transfer ends there though it has none'    => array( $transfer, array( array( 11, 5 ) ), array( self::candidate( 1, 0, array() ), self::candidate( 2, 1, array( array( 11, 3 ) ) ), self::candidate( 3, 2, array( array( 11, 3 ) ) ) ), null, null, self::plan( array( array( 11, 2, 3 ), array( 11, 3, 2 ) ), 1, true, false, 'transfer' ) ),
			'transfer takes from a location that ships nothing' => array( $transfer, array( array( 11, 4 ) ), array( self::candidate( 1, 0, array( array( 11, 2 ) ) ), self::candidate( 9, 5, array( array( 11, 3 ) ), false ) ), null, null, self::plan( array( array( 11, 1, 2 ), array( 11, 9, 2 ) ), 1, true, false, 'transfer' ) ),
			'transfer refused with the sum over active locations' => array( $transfer, array( array( 11, 9 ) ), array( self::candidate( 1, 0, array( array( 11, 2 ) ) ), self::candidate( 9, 5, array( array( 11, 3 ) ), false ) ), null, null, self::refused( 'quantity', array( 11 ), 9, 5 ) ),
			'transfer brings each line'                 => array( $transfer, array( array( 11, 1 ), array( 12, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 1 ) ) ), self::candidate( 2, 1, array( array( 12, 2 ) ) ) ), null, null, self::plan( array( array( 11, 1, 1 ), array( 12, 2, 2 ) ), 1, true, false, 'transfer' ) ),
			'transfer to a named destination first'     => array( $transfer, array( array( 11, 3 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ) ), null, 2, self::plan( array( array( 11, 1, 2 ), array( 11, 2, 1 ) ), 2, true, false, 'transfer' ) ),
			'a named destination that has it all'       => array( $transfer, array( array( 11, 3 ) ), array( self::candidate( 1, 0, array( array( 11, 5 ) ) ), self::candidate( 2, 1, array( array( 11, 3 ) ) ) ), null, 2, self::plan( array( array( 11, 2, 3 ) ), 2, false, false, 'transfer' ) ),
			'refuse names the largest one location has' => array( $refuse, array( array( 11, 5 ) ), array( self::candidate( 1, 0, array( array( 11, 3 ) ) ), self::candidate( 2, 1, array( array( 11, 4 ) ) ) ), null, null, self::refused( 'quantity', array( 11 ), 5, 4 ) ),
			'refuse names what cannot travel together'  => array( $refuse, array( array( 11, 2 ), array( 12, 2 ) ), array( self::candidate( 1, 0, array( array( 11, 2 ) ) ), self::candidate( 2, 1, array( array( 12, 2 ) ) ) ), null, null, self::refused( 'combination', array( 12 ), null, null ) ),
			'refuse names the lines the best location lacks' => array( $refuse, array( array( 11, 1 ), array( 12, 1 ), array( 13, 1 ) ), array( self::candidate( 1, 0, array( array( 12, 1 ) ) ), self::candidate( 2, 1, array( array( 11, 1 ) ) ), self::candidate( 3, 2, array( array( 11, 1 ) ) ) ), null, null, self::refused( 'combination', array( 11 ), null, null ) ),
			'refuse quantity before combination'        => array( $refuse, array( array( 11, 1 ), array( 12, 3 ) ), array( self::candidate( 1, 0, array( array( 11, 1 ), array( 12, 2 ) ) ), self::candidate( 2, 1, array( array( 12, 2 ) ) ) ), null, null, self::refused( 'quantity', array( 12 ), 3, 2 ) ),
		);
	}

	/**
	 * Tests that a transfer with no location that fulfils online orders and no named destination is a caller's error.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_with_nowhere_to_end_is_a_callers_error(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Router() )->plan( new RoutingRequest( array( 11 => 1 ), array( new Candidate( 9, 0, false, array( 11 => 0 ) ) ), FulfilmentMode::Transfer ) );
	}

	/**
	 * Plans a request, and writes the answer as the rows do.
	 *
	 * @since 0.2.0
	 *
	 * @param RoutingRequest $request The request.
	 * @return array<string, mixed> The plan, or `refused`.
	 */
	private static function answer( RoutingRequest $request ): array {
		try {
			$plan = ( new Router() )->plan( $request );
		} catch ( RoutingRefused $refused ) {
			return self::refused( $refused->reason, $refused->variantIds, $refused->requested, $refused->available );
		}

		return self::plan(
			array_map( static fn( HoldLine $line ): array => array( $line->variantId, (int) $line->locationId, $line->quantity ), $plan->lines ),
			$plan->destinationLocationId,
			$plan->requiresTransfer,
			$plan->split,
			$plan->reason->value
		);
	}

	/**
	 * Writes a candidate's arguments, variant 13 always unlimited.
	 *
	 * @since 0.2.0
	 *
	 * @param int     $id        The location.
	 * @param int     $priority  Its priority.
	 * @param array[] $available What it has: each variant and its units.
	 * @param bool    $fulfils   Optional. Whether it ships online orders. Default true.
	 * @return list<mixed> The arguments.
	 *
	 * @phpstan-param list<array{0: int, 1: int}> $available
	 */
	private static function candidate( int $id, int $priority, array $available, bool $fulfils = true ): array {
		return array( $id, $priority, $fulfils, self::map( $available ), array( 13 => true ) );
	}

	/**
	 * Turns a list of pairs into a map: the first of each pair is its key.
	 *
	 * @since 0.2.0
	 *
	 * @param array[] $pairs The pairs.
	 * @return array<int, int> The map.
	 *
	 * @phpstan-param list<array{0: int, 1: int}> $pairs
	 */
	private static function map( array $pairs ): array {
		$map = array();

		foreach ( $pairs as $pair ) {
			$map[ $pair[0] ] = $pair[1];
		}

		return $map;
	}

	/**
	 * Writes a plan as the rows do.
	 *
	 * @since 0.2.0
	 *
	 * @param array[] $lines       Each line's variant, location and units.
	 * @param int     $destination The destination.
	 * @param bool    $transfer    Whether it requires a transfer.
	 * @param bool    $split       Whether it ships from several locations.
	 * @param string  $reason      The reason's value.
	 * @return array<string, mixed> The plan.
	 *
	 * @phpstan-param list<list<int>> $lines
	 */
	private static function plan( array $lines, int $destination, bool $transfer, bool $split, string $reason ): array {
		return array(
			'lines'       => $lines,
			'destination' => $destination,
			'transfer'    => $transfer,
			'split'       => $split,
			'reason'      => $reason,
		);
	}

	/**
	 * Writes a refusal as the rows do.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $reason     The reason.
	 * @param int[]    $variantIds The variants refused.
	 * @param int|null $requested  The units asked for.
	 * @param int|null $available  The units that could be given.
	 * @return array<string, mixed> The refusal.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	private static function refused( string $reason, array $variantIds, ?int $requested, ?int $available ): array {
		return array( 'refused' => array( $reason, $variantIds, $requested, $available ) );
	}
}
