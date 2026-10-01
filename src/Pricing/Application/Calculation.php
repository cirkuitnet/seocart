<?php
/**
 * Calculation: the totals of a request, and the lines they leave out
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TraceEntry;

defined( 'ABSPATH' ) || exit;

/**
 * What the calculator answers: the totals of every priced line, and the lines that could not be priced.
 *
 * Owns one fact: the result a cart shows and an order is placed from. A caller maps it without
 * arithmetic; while any line is unpriced, an order must not be placed. What an order records
 * beside the totals, and the totals do not name, is read here from the calculation's own trace,
 * so no caller reads the trace's entries itself: the promotions that applied, the shipping rate
 * selected and the fingerprint of the tax quote.
 *
 * @since 0.1.0
 */
final readonly class Calculation {

	/**
	 * Holds the result.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals        The totals of the priced lines.
	 * @param array  $unpricedLines The lines left out, in cart order.
	 *
	 * @phpstan-param list<UnpricedLine> $unpricedLines
	 */
	public function __construct(
		public Totals $totals,
		public array $unpricedLines
	) {
	}

	/**
	 * Returns the promotions that applied to the calculation, in the order of their codes: each one an order claims a use of.
	 *
	 * A promotion applies when its code was entered and it was resolved as active and in its
	 * window, whether or not it took anything off; a code turned away is not one. What each took
	 * off is Totals::discountOf() its source.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{id: int, uuid: string}> Each promotion's id and uuid.
	 */
	public function appliedPromotions(): array {
		$promotions = array();

		foreach ( $this->recorded( TraceEntry::INPUT, 'promotion' ) as $data ) {
			$promotions[] = array(
				'id'   => (int) $data['promotion_id'],
				'uuid' => (string) $data['uuid'],
			);
		}

		return $promotions;
	}

	/**
	 * Returns the shipping rate the calculation selected, as its trace recorded the quote.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>|null The quote of the rate selected: its id, method, amount, basis, tax
	 *                                   class, expiry and provider; null when no shipping was charged,
	 *                                   because there was no destination or no line.
	 */
	public function selectedShippingRate(): ?array {
		$selected = null;

		foreach ( $this->totals->trace->entries as $entry ) {
			if ( TraceEntry::SELECTION === $entry->kind && array_key_exists( 'selected', $entry->data ) ) {
				$selected = $entry->data['selected'];
			}
		}

		foreach ( $this->recorded( TraceEntry::QUOTE, 'shipping_rate' ) as $data ) {
			if ( null !== $selected && $selected === $data['method_key'] ) {
				unset( $data['record'] );

				return $data;
			}
		}

		return null;
	}

	/**
	 * Returns the fingerprint of the tax quote the calculation applied: the same quote, of the same rates, has the same one.
	 *
	 * It covers the quote's id, jurisdiction and provider and every rate of every class at the
	 * destination and at the reference, in the order the trace recorded them, but not the quote's
	 * expiry, which every calculation sets anew.
	 *
	 * @since 0.1.0
	 *
	 * @return string The SHA-256, in lower-case hexadecimal.
	 */
	public function taxQuoteFingerprint(): string {
		$lines = array();

		foreach ( $this->totals->trace->entries as $entry ) {
			$record = $entry->data['record'] ?? null;

			if ( TraceEntry::QUOTE !== $entry->kind || ! in_array( $record, array( 'tax', 'tax_rates' ), true ) ) {
				continue;
			}

			$data = $entry->data;

			unset( $data['expires_at'] );

			$lines[] = implode( "\t", array_map( static fn( mixed $value ): string => is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value, $data ) );
		}

		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/**
	 * Returns the data of the trace's entries of a kind that record one thing, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind   The entry's kind.
	 * @param string $record What the entry records, as its `record` names it.
	 * @return list<array<string, mixed>> Each entry's data.
	 */
	private function recorded( string $kind, string $record ): array {
		$found = array();

		foreach ( $this->totals->trace->entries as $entry ) {
			$recordOf = $entry->data['record'] ?? null;

			if ( $kind === $entry->kind && $record === $recordOf ) {
				$found[] = $entry->data;
			}
		}

		return $found;
	}
}
