<?php
/**
 * RefundPlan: a refund worked out before the gateway is asked
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Order\Domain\RefundableOrder;
use SEOCart\Order\Domain\RefundedUnits;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a refund will write, worked out from plain reads before the gateway is asked: its uuid, the order and intent, each line's and the shipping's portion, and the total.
 *
 * Owns one fact: the refund document as it was checked. Its figures were allocated from the
 * stored rows by RefundAllocation; the transaction that writes them only checks, by its
 * conditional statements, that nothing it was worked out from has changed since.
 *
 * @since 0.1.0
 */
final readonly class RefundPlan {

	/**
	 * Records the plan.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $uuid       The refund's public identifier, RefundIdentity's, also the gateway's idempotency key.
	 * @param RefundableOrder      $order      The order, as read.
	 * @param RefundableIntent     $intent     The intent the money goes back through, as read.
	 * @param LinePortion[]        $lines      What each line asked for returns.
	 * @param ShippingPortion|null $shipping   What the shipping returns; null when it is not asked for, or the order has none.
	 * @param Share                $total      What the whole refund returns: the lines' and the shipping's figures together.
	 * @param string               $reasonCode Why the order is refunded.
	 *
	 * @phpstan-param list<LinePortion> $lines
	 */
	public function __construct(
		public string $uuid,
		public RefundableOrder $order,
		public RefundableIntent $intent,
		public array $lines,
		public ?ShippingPortion $shipping,
		public Share $total,
		public string $reasonCode
	) {
	}

	/**
	 * Returns every tax component the refund returns a share of: the lines' and then the shipping's.
	 *
	 * A component of which nothing is returned, such as one of a shipping an earlier refund gave
	 * back, is left out: it has no row to write.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ComponentPortion> The components.
	 */
	public function components(): array {
		$components = array();

		foreach ( $this->lines as $line ) {
			$components = array_merge( $components, $line->components );
		}

		$components = array_merge( $components, null === $this->shipping ? array() : $this->shipping->components );

		return array_values( array_filter( $components, static fn( ComponentPortion $portion ): bool => ! $portion->share->isNothing() ) );
	}

	/**
	 * Returns the units each line gives back, with the refunded quantity the line had when the refund was worked out.
	 *
	 * @since 0.1.0
	 *
	 * @return list<RefundedUnits> One entry per line.
	 */
	public function units(): array {
		return array_map( static fn( LinePortion $line ): RefundedUnits => new RefundedUnits( $line->line->id, $line->quantity, $line->line->refundedQuantity ), $this->lines );
	}
}
