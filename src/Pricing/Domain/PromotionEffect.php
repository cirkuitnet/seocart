<?php
/**
 * PromotionEffect: what a promotion does when it applies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * One promotion effect: a percentage off the lines, a fixed amount off the order, or free shipping.
 *
 * Owns one fact: the effects a promotion can have, spelled as `promotions.effect_kind` stores
 * them. The percentage belongs to a percent effect and the amount to a fixed one; the other is
 * null. Neither may be negative: a promotion takes money off, never adds it.
 *
 * @since 0.1.0
 */
final readonly class PromotionEffect {

	/**
	 * A percentage off every line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PERCENT = 'percent';

	/**
	 * A fixed amount off the order, shared across the lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FIXED = 'fixed';

	/**
	 * The selected shipping rate, taken off.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FREE_SHIPPING = 'free_shipping';

	/**
	 * Holds the effect.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $kind       PERCENT, FIXED or FREE_SHIPPING.
	 * @param Percentage|null     $percentage The percentage of a percent effect, otherwise null.
	 * @param AuthoredAmount|null $amount     The amount of a fixed effect, otherwise null.
	 */
	private function __construct(
		public string $kind,
		public ?Percentage $percentage,
		public ?AuthoredAmount $amount
	) {
	}

	/**
	 * Returns a percentage off every line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the percentage is negative.
	 *
	 * @param Percentage $percentage The percentage.
	 * @return self The effect.
	 */
	public static function percent( Percentage $percentage ): self {
		if ( $percentage->micropercent() < 0 ) {
			throw new \InvalidArgumentException( 'A promotion takes a percentage off, never adds one.' );
		}

		return new self( self::PERCENT, $percentage, null );
	}

	/**
	 * Returns a fixed amount off the order.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the amount is negative.
	 *
	 * @param AuthoredAmount $amount The amount.
	 * @return self The effect.
	 */
	public static function fixed( AuthoredAmount $amount ): self {
		if ( $amount->amount->isNegative() ) {
			throw new \InvalidArgumentException( 'A promotion takes an amount off, never adds one.' );
		}

		return new self( self::FIXED, null, $amount );
	}

	/**
	 * Returns free shipping.
	 *
	 * @since 0.1.0
	 *
	 * @return self The effect.
	 */
	public static function freeShipping(): self {
		return new self( self::FREE_SHIPPING, null, null );
	}

	/**
	 * Returns the effect as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array{effect: string, percent_micropercent: int|null, amount_minor: int|null, amount_basis: string|null} The effect.
	 */
	public function toArray(): array {
		return array(
			'effect'               => $this->kind,
			'percent_micropercent' => $this->percentage?->micropercent(),
			'amount_minor'         => $this->amount?->amount->minorUnits(),
			'amount_basis'         => $this->amount?->basis->value,
		);
	}
}
