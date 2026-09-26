<?php
/**
 * PromotionFacts: a promotion that applies to the calculation, as it was resolved before it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A promotion whose code, window and status were checked when it was resolved, ready to be evaluated.
 *
 * Owns one fact: what the promotion evaluator is told about a promotion. Everything in it was
 * read before the calculation started, so evaluating promotions reads nothing. Its usage limit
 * is not here: a limit is claimed when an order is placed, not checked while a cart is priced.
 *
 * @since 0.1.0
 */
final readonly class PromotionFacts {

	/**
	 * Holds the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $id       The promotion's id.
	 * @param string          $uuid     The promotion's uuid, which its adjustments' source names.
	 * @param string          $code     The code the customer entered.
	 * @param PromotionEffect $effect   What the promotion does.
	 * @param int             $priority The order promotions apply in, lowest first.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $code,
		public PromotionEffect $effect,
		public int $priority
	) {
	}

	/**
	 * Returns the facts as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|null> The facts.
	 */
	public function toArray(): array {
		return array_merge(
			array(
				'promotion_id' => $this->id,
				'uuid'         => $this->uuid,
				'code'         => $this->code,
				'priority'     => $this->priority,
			),
			$this->effect->toArray()
		);
	}
}
