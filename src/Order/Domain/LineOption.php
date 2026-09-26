<?php
/**
 * LineOption: one option an order line was sold with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * An option axis of a line and the value chosen for it, with the labels the shopper saw.
 *
 * Owns one fact: one `order_line_options` row without its storage metadata. The same value is
 * written when an order is placed and read back when it is shown, so the labels on an invoice
 * are the ones the shopper chose from, never today's.
 *
 * @since 0.1.0
 */
final readonly class LineOption {

	/**
	 * Records the option.
	 *
	 * @since 0.1.0
	 *
	 * @param string $axisKey    The option axis, for example `size`.
	 * @param string $axisLabel  The axis label the shopper saw.
	 * @param string $valueKey   The value chosen, for example `m`.
	 * @param string $valueLabel The value label the shopper saw.
	 */
	public function __construct(
		public string $axisKey,
		public string $axisLabel,
		public string $valueKey,
		public string $valueLabel
	) {
	}
}
