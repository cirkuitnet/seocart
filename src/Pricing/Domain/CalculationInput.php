<?php
/**
 * CalculationInput: everything a calculation is a function of
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name values built by code, for the developer; they are never rendered.

/**
 * The complete, immutable input of one calculation: the priced lines and every fact they are priced under.
 *
 * Owns one fact: what a calculation depends on. The engine reads nothing else: no clock, no
 * setting, no repository, no rate. So the same input, with the same quotes, always gives the
 * same totals, and a stored input can be replayed.
 *
 * Every amount is in the one currency of the calculation, and the conversion context goes from
 * the base currency to that currency; the constructor refuses anything else, because a
 * currency mismatch inside a calculation is always a caller's mistake. Line keys are unique,
 * since every figure of a line is filed under its key.
 *
 * @since 0.1.0
 */
final readonly class CalculationInput {

	/**
	 * Checks and holds the input.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an amount is in another currency, the conversion context
	 *                                   or the rounding rule does not match the currencies, or two lines
	 *                                   share a key.
	 *
	 * @param Currency             $currency          The currency of the calculation, the cart's.
	 * @param Currency             $baseCurrency      The store's base currency.
	 * @param ConversionContext    $conversionContext The frozen rate from the base currency to the calculation's.
	 * @param CurrencyRoundingRule $roundingRule      How amounts in the calculation's currency are rounded.
	 * @param array                $lines             The lines, in cart order: the order every tie is broken in.
	 * @param Address|null         $destination       Where the order ships, or null before an address is known.
	 * @param CustomerTaxFacts     $customerTax       Whether the customer pays tax.
	 * @param array                $promotions        The promotions that apply, as resolved before the calculation.
	 * @param array                $rejectedCodes     The codes that did not become promotions.
	 * @param string|null          $shippingMethodKey The shipping method the customer chose, or null for the cheapest.
	 * @param array                $fees              The fees the order is charged.
	 * @param CrossZonePolicy      $crossZonePolicy   What stays fixed when a gross price crosses tax zones.
	 * @param TaxRoundingMode      $taxRoundingMode   Where tax is rounded.
	 * @param \DateTimeImmutable   $calculatedAt      When the calculation was asked for; recorded, never compared.
	 *
	 * @phpstan-param list<InputLine>      $lines
	 * @phpstan-param list<PromotionFacts> $promotions
	 * @phpstan-param list<RejectedCode>   $rejectedCodes
	 * @phpstan-param list<FeeDefinition>  $fees
	 */
	public function __construct(
		public Currency $currency,
		public Currency $baseCurrency,
		public ConversionContext $conversionContext,
		public CurrencyRoundingRule $roundingRule,
		public array $lines,
		public ?Address $destination,
		public CustomerTaxFacts $customerTax,
		public array $promotions,
		public array $rejectedCodes,
		public ?string $shippingMethodKey,
		public array $fees,
		public CrossZonePolicy $crossZonePolicy,
		public TaxRoundingMode $taxRoundingMode,
		public \DateTimeImmutable $calculatedAt
	) {
		if ( ! $conversionContext->baseCurrency()->equals( $baseCurrency ) || ! $conversionContext->quoteCurrency()->equals( $currency ) ) {
			throw new \InvalidArgumentException( 'The conversion context must go from the base currency to the currency of the calculation.' );
		}

		if ( ! $roundingRule->currency()->equals( $currency ) ) {
			throw new \InvalidArgumentException( 'The rounding rule must be the one of the currency of the calculation.' );
		}

		$keys = array();

		foreach ( $lines as $line ) {
			$this->assertCurrency( $line->unitPrice, 'The unit price of line "' . $line->key . '"' );

			if ( isset( $keys[ $line->key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Two lines share the key "%s".', $line->key ) );
			}

			$keys[ $line->key ] = true;
		}

		foreach ( $promotions as $promotion ) {
			if ( null !== $promotion->effect->amount ) {
				$this->assertCurrency( $promotion->effect->amount, 'The amount of promotion ' . $promotion->uuid );
			}
		}

		foreach ( $fees as $fee ) {
			if ( $fee->amount instanceof AuthoredAmount ) {
				$this->assertCurrency( $fee->amount, 'The amount of fee "' . $fee->key . '"' );
			}
		}
	}

	/**
	 * Returns the input's own facts as scalars, for the trace; lines, promotions, fees and rejected codes are traced one by one.
	 *
	 * The destination is traced by its country only: the trace is kept with the order for years
	 * and must hold no one's address.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|bool|null> The facts.
	 */
	public function toArray(): array {
		return array(
			'currency'                 => $this->currency->code(),
			'base_currency'            => $this->baseCurrency->code(),
			'conversion_context'       => $this->conversionContext->fingerprint(),
			'rate_version'             => $this->conversionContext->sourceVersion(),
			'rounding_mode'            => $this->roundingRule->roundingMode()->value,
			'cash_rounding_step_minor' => $this->roundingRule->cashRoundingStepMinor(),
			'destination_country'      => $this->destination?->country(),
			'customer_exempt'          => $this->customerTax->exempt,
			'exemption_reference'      => $this->customerTax->exemptionReference,
			'shipping_method'          => $this->shippingMethodKey,
			'cross_zone_policy'        => $this->crossZonePolicy->value,
			'tax_rounding_mode'        => $this->taxRoundingMode->value,
			'calculated_at'            => InstantFormat::of( $this->calculatedAt ),
		);
	}

	/**
	 * Refuses an amount in another currency than the calculation's.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the currencies differ.
	 *
	 * @param AuthoredAmount $amount The amount.
	 * @param string         $what   What the amount is, for the message.
	 */
	private function assertCurrency( AuthoredAmount $amount, string $what ): void {
		if ( ! $amount->currency()->equals( $this->currency ) ) {
			throw new \InvalidArgumentException( sprintf( '%s is in %s, not in %s, the currency of the calculation.', $what, $amount->currency()->code(), $this->currency->code() ) );
		}
	}
}
