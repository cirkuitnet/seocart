<?php
/**
 * CalculationTrace: the record of how a calculation arrived at its totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

defined( 'ABSPATH' ) || exit;

/**
 * The trace of one calculation: its input, the quotes, the intents, every adjustment and every rounding, in order.
 *
 * Owns one fact: the audit record an order keeps beside its totals. Every rounding boundary is
 * in it with the value before and after, so the totals can be checked from the trace alone.
 * The figures an order is refunded from are its persisted rows, not this record.
 *
 * @since 0.1.0
 */
final readonly class CalculationTrace {

	/**
	 * Holds the entries.
	 *
	 * @since 0.1.0
	 *
	 * @param array $entries The entries, in the order they happened.
	 *
	 * @phpstan-param list<TraceEntry> $entries
	 */
	public function __construct( public array $entries ) {
	}

	/**
	 * Returns the trace as arrays, as `order_totals.trace_json` stores it.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{step: string, kind: string, data: array<string, mixed>}> The entries.
	 */
	public function toArray(): array {
		return array_map( static fn( TraceEntry $entry ): array => $entry->toArray(), $this->entries );
	}
}
