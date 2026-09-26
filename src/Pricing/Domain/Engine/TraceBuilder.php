<?php
/**
 * TraceBuilder: writes a calculation's trace as it runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\Totals\CalculationTrace;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\RoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * The trace of one phase of one calculation while it is being written.
 *
 * Owns one fact: the running record of a calculation, and the two things every entry of it
 * shares: the step being run, and the rounding mode every boundary of this calculation applies,
 * which each rounding entry records and the Rounder reads from here. A phase starts a builder of
 * its own, carrying on from the trace the previous phase froze, so running a phase twice never
 * writes into the other run's record.
 *
 * @since 0.1.0
 */
final class TraceBuilder {

	/**
	 * The entries so far.
	 *
	 * @since 0.1.0
	 *
	 * @var list<TraceEntry>
	 */
	private array $entries;

	/**
	 * The step being run, which every new entry is filed under.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $step = 'input';

	/**
	 * Starts a trace, or carries one on.
	 *
	 * @since 0.1.0
	 *
	 * @param RoundingMode          $mode    How this calculation's amounts are rounded at its boundaries.
	 * @param CalculationTrace|null $earlier Optional. The trace an earlier phase froze. Default null.
	 */
	public function __construct( private RoundingMode $mode, ?CalculationTrace $earlier = null ) {
		$this->entries = null === $earlier ? array() : $earlier->entries;
	}

	/**
	 * Returns how this calculation's amounts are rounded.
	 *
	 * @since 0.1.0
	 *
	 * @return RoundingMode The mode.
	 */
	public function mode(): RoundingMode {
		return $this->mode;
	}

	/**
	 * Files every entry from now on under a step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step The step, such as `b1.discounts`.
	 */
	public function enter( string $step ): void {
		$this->step = $step;
	}

	/**
	 * Records an event of the step being run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind One of TraceEntry's kinds.
	 * @param array  $data The event's data: strings, integers, booleans, nulls and lists of those.
	 *
	 * @phpstan-param array<string, mixed> $data
	 */
	public function record( string $kind, array $data ): void {
		$this->entries[] = new TraceEntry( $this->step, $kind, $data );
	}

	/**
	 * Records the input: its own facts first, then each line, promotion, rejected code and fee.
	 *
	 * Each entry says in `record` which of those it is.
	 *
	 * @since 0.1.0
	 *
	 * @param CalculationInput $input The input.
	 */
	public function input( CalculationInput $input ): void {
		$this->record( TraceEntry::INPUT, array( 'record' => 'calculation' ) + $input->toArray() );

		foreach ( $input->lines as $line ) {
			$this->record( TraceEntry::INPUT, array( 'record' => 'line' ) + $line->toArray() );
		}

		foreach ( $input->promotions as $promotion ) {
			$this->record( TraceEntry::INPUT, array( 'record' => 'promotion' ) + $promotion->toArray() );
		}

		foreach ( $input->rejectedCodes as $rejected ) {
			$this->record( TraceEntry::INPUT, array( 'record' => 'rejected_code' ) + $rejected->toArray() );
		}

		foreach ( $input->fees as $fee ) {
			$this->record( TraceEntry::INPUT, array( 'record' => 'fee' ) + $fee->toArray() );
		}
	}

	/**
	 * Returns the trace written so far.
	 *
	 * @since 0.1.0
	 *
	 * @return CalculationTrace The trace.
	 */
	public function freeze(): CalculationTrace {
		return new CalculationTrace( $this->entries );
	}
}
