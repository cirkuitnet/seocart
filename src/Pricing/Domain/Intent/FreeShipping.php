<?php
/**
 * FreeShipping: the selected shipping rate, taken off
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Intent;

use SEOCart\Pricing\Domain\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the selected shipping rate off, as an adjustment of its own beside the rate.
 *
 * Owns one fact: the free-shipping intent. The rate stays in the totals and a second, negating
 * adjustment names the promotion, so the audit trail shows both and the tax on shipping nets to
 * zero.
 *
 * @since 0.1.0
 */
final readonly class FreeShipping implements PromotionIntent {

	/**
	 * Holds the intent.
	 *
	 * @since 0.1.0
	 *
	 * @param Source $source What the intent comes from.
	 */
	public function __construct( private Source $source ) {
	}

	/**
	 * Returns the kind of intent.
	 *
	 * @since 0.1.0
	 *
	 * @return string `free_shipping`.
	 */
	public function type(): string {
		return 'free_shipping';
	}

	/**
	 * Returns what the intent comes from.
	 *
	 * @since 0.1.0
	 *
	 * @return Source The source.
	 */
	public function source(): Source {
		return $this->source;
	}
}
