<?php
/**
 * ComponentPortion: what a refund returns of one persisted tax component
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Order\Domain\StoredTaxComponent;

defined( 'ABSPATH' ) || exit;

/**
 * One tax component of the order and the share of it a refund returns: a `refund_components` row.
 *
 * Owns one fact: which stored component a returned share belongs to, so the share is capped by
 * what is left of that component when it is written.
 *
 * @since 0.1.0
 */
final readonly class ComponentPortion {

	/**
	 * Records the portion.
	 *
	 * @since 0.1.0
	 *
	 * @param StoredTaxComponent $component The order's component, as stored.
	 * @param Share              $share     What the refund returns of it.
	 */
	public function __construct(
		public StoredTaxComponent $component,
		public Share $share
	) {
	}
}
