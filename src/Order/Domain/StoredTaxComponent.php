<?php
/**
 * StoredTaxComponent: one persisted tax component of an order, as a refund reads it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One row of `order_tax_components` at the order's current totals version: the allocation a refund returns part of.
 *
 * Owns one fact: whose tax a component is and what it stored. A component's net is what its
 * rate was charged on, the whole of its owner's net (plus, for a compound rate, the taxes it
 * compounds on), so only the taxes of an owner's components add up to the owner's tax. The rows
 * are append-only, so the figures read are the figures that hold.
 *
 * @since 0.1.0
 */
final readonly class StoredTaxComponent {

	/**
	 * Records the component.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $id     The component's internal id.
	 * @param int|null     $lineId The order line it taxes; null for a component of the order's shipping.
	 * @param StoredAmount $stored What it stored, with its owner's authored basis.
	 */
	public function __construct(
		public int $id,
		public ?int $lineId,
		public StoredAmount $stored
	) {
	}
}
