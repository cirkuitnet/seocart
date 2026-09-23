<?php
/**
 * CurrencyRoundingRule: how a store rounds amounts in one currency
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The rounding a store applies to amounts in one currency.
 *
 * This class owns one fact: the merchant's rounding policy for a currency, as the columns
 * `currencies.rounding_mode` and `currencies.cash_rounding_step_minor` store it. It is one of
 * the four rounding concepts that target architecture §21.2 keeps apart: the ISO exponent
 * belongs to Currency, the tax rounding mode to the pipeline, price-ending rules do not exist
 * in 1.0, and this is the fourth — the mode and the lawful cash rounding step.
 *
 * The values come from configuration; defaultFor() gives the column defaults.
 *
 * @since 0.1.0
 */
final class CurrencyRoundingRule {

	/**
	 * The currency the rule applies to.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	private Currency $currency;

	/**
	 * How amounts in the currency are rounded, `currencies.rounding_mode`.
	 *
	 * @since 0.1.0
	 *
	 * @var RoundingMode
	 */
	private RoundingMode $roundingMode;

	/**
	 * The cash rounding step in minor units, `currencies.cash_rounding_step_minor`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $cashRoundingStepMinor;

	/**
	 * Creates a rule.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the cash rounding step is negative.
	 *
	 * @param Currency     $currency              The currency the rule applies to.
	 * @param RoundingMode $rounding_mode         How amounts in the currency are rounded.
	 * @param int          $cash_rounding_step_minor The cash rounding step in minor units, for
	 *                                            example 5 for Swiss francs rounded to 0.05. 0 or 1
	 *                                            means no cash rounding.
	 */
	public function __construct( Currency $currency, RoundingMode $rounding_mode, int $cash_rounding_step_minor ) {
		if ( $cash_rounding_step_minor < 0 ) {
			throw new \InvalidArgumentException( 'A cash rounding step is a number of minor units and cannot be negative.' );
		}

		$this->currency              = $currency;
		$this->roundingMode          = $rounding_mode;
		$this->cashRoundingStepMinor = $cash_rounding_step_minor;
	}

	/**
	 * Returns the rule a currency has before the merchant configures it.
	 *
	 * These are the column defaults: half-up rounding and no cash rounding.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency The currency.
	 * @return self The default rule.
	 */
	public static function defaultFor( Currency $currency ): self {
		return new self( $currency, RoundingMode::HalfUp, 0 );
	}

	/**
	 * Returns the currency the rule applies to.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	public function currency(): Currency {
		return $this->currency;
	}

	/**
	 * Returns how amounts in the currency are rounded.
	 *
	 * @since 0.1.0
	 *
	 * @return RoundingMode The rounding mode.
	 */
	public function roundingMode(): RoundingMode {
		return $this->roundingMode;
	}

	/**
	 * Returns the cash rounding step.
	 *
	 * @since 0.1.0
	 *
	 * @return int The step in minor units; 0 or 1 means no cash rounding.
	 */
	public function cashRoundingStepMinor(): int {
		return $this->cashRoundingStepMinor;
	}
}
