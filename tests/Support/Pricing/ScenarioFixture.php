<?php
/**
 * ScenarioFixture: one pricing scenario read from its JSON file, run through the engine and checked
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\CustomerTaxFacts;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Engine\PhaseAResult;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

/**
 * A reference scenario: its input, the quotes it is priced with, and the figures it must come to.
 *
 * Owns one fact: how a scenario file maps onto the engine. The file states the input as a cart
 * would hand it in, with its prices and its quotes, and the expected figures as decimal strings,
 * never floats. The scenario runs the engine directly: no calculator, no port, no WordPress.
 * Promotions are evaluated by FactsEvaluator.
 *
 * The file's shape: `scenario` and `source` (what it tests, and where the case comes from, in
 * words), `input`, `expected`, and an optional one-line `note` where the expected figures
 * deliberately differ from what an older system produced.
 *
 * @since 0.1.0
 */
final class ScenarioFixture {

	/**
	 * Where the scenario families live, from the repository's root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROOT = 'tests/Fixtures/ReferenceScenarios/pricing';

	/**
	 * When every quote of a scenario expires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const EXPIRY = '2026-01-15T10:15:00+00:00';

	/**
	 * Holds a scenario.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     The file's name without its extension.
	 * @param array  $document The decoded file.
	 *
	 * @phpstan-param array<string, mixed> $document
	 */
	private function __construct(
		public readonly string $name,
		public readonly array $document
	) {
	}

	/**
	 * Returns every scenario of a family, for a data provider.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the family has no scenario.
	 *
	 * @param string $family The family's directory, such as `legacy-informed`.
	 * @return array<string, array{ScenarioFixture}> The scenarios, by name.
	 */
	public static function inFamily( string $family ): array {
		$files = glob( dirname( __DIR__, 3 ) . '/' . self::ROOT . '/' . $family . '/*.json' );

		if ( false === $files || array() === $files ) {
			throw new \RuntimeException( "The family {$family} has no scenario." );
		}

		sort( $files );

		$scenarios = array();

		foreach ( $files as $file ) {
			$scenario = self::load( $file );

			$scenarios[ $scenario->name ] = array( $scenario );
		}

		return $scenarios;
	}

	/**
	 * Reads a scenario file.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the file is not a scenario.
	 *
	 * @param string $file The file.
	 * @return self The scenario.
	 */
	public static function load( string $file ): self {
		$document = json_decode( (string) file_get_contents( $file ), true, 64, JSON_THROW_ON_ERROR );

		foreach ( array( 'scenario', 'source', 'input', 'expected' ) as $key ) {
			if ( ! is_array( $document ) || ! isset( $document[ $key ] ) || array() === $document[ $key ] || '' === $document[ $key ] ) {
				throw new \RuntimeException( basename( $file ) . " has no {$key}." );
			}
		}

		return new self( basename( $file, '.json' ), $document );
	}

	/**
	 * Returns the input the scenario describes.
	 *
	 * @since 0.1.0
	 *
	 * @param array $changes Optional. Input keys to replace, as the file writes them. Default none.
	 * @return CalculationInput The input.
	 *
	 * @phpstan-param array<string, mixed> $changes
	 */
	public function input( array $changes = array() ): CalculationInput {
		$input    = array_replace( $this->document['input'], $changes );
		$currency = Currency::of( $input['currency'] );
		$context  = $this->context( $input );
		$shipping = $input['shipping'] ?? array();

		return new CalculationInput(
			$currency,
			$context->baseCurrency(),
			$context,
			CurrencyRoundingRule::defaultFor( $currency ),
			array_map( fn( array $line ): InputLine => $this->line( $line, $currency, $context ), $input['lines'] ),
			isset( $shipping['destination'] ) ? new Address( $shipping['destination'] ) : null,
			new CustomerTaxFacts( (bool) ( $input['customer']['exempt'] ?? false ) ),
			array_map( fn( array $promotion ): PromotionFacts => $this->promotion( $promotion, $currency ), $input['promotions'] ?? array() ),
			array(),
			$shipping['selected'] ?? null,
			array_map( fn( array $fee ): FeeDefinition => $this->fee( $fee, $currency ), $input['fees'] ?? array() ),
			CrossZonePolicy::from( $input['policy'] ?? CrossZonePolicy::FixedNet->value ),
			TaxRoundingMode::from( $input['roundingMode'] ?? TaxRoundingMode::PerLine->value ),
			new \DateTimeImmutable( Inputs::AT )
		);
	}

	/**
	 * Returns the quotes the scenario is priced with, taken for the first phase's lines.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAResult $phaseA The first phase's result.
	 * @return Quotes The quotes.
	 */
	public function quotes( PhaseAResult $phaseA ): Quotes {
		return new Quotes( $this->shippingRates(), $this->taxQuote(), $phaseA->packagesFingerprint() );
	}

	/**
	 * Returns the shipping rates the scenario quotes, in its currency.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ShippingRateQuote> The rates.
	 */
	public function shippingRates(): array {
		$currency = Currency::of( $this->document['input']['currency'] );
		$rates    = array();

		foreach ( $this->document['input']['shipping']['rates'] ?? array() as $rate ) {
			$rates[] = new ShippingRateQuote( 'quote-' . $rate['method'], $rate['method'], $rate['method'], $this->authored( $rate, $currency ), $rate['taxClass'], new \DateTimeImmutable( self::EXPIRY ), 'scenario:shipping:v1' );
		}

		return $rates;
	}

	/**
	 * Returns the tax quote the scenario is priced with; its reference rates are the destination's unless it names others.
	 *
	 * @since 0.1.0
	 *
	 * @return TaxQuote The quote.
	 */
	public function taxQuote(): TaxQuote {
		$tax         = $this->document['input']['tax'];
		$destination = $this->rates( $tax['destination'] );
		$reference   = isset( $tax['reference'] ) ? $this->rates( $tax['reference'] ) : $destination;

		return new TaxQuote( 'quote-tax', 'scenario', 'scenario:tax:v1', $destination, $reference, new \DateTimeImmutable( self::EXPIRY ) );
	}

	/**
	 * Runs the scenario through the engine's two phases.
	 *
	 * @since 0.1.0
	 *
	 * @param array $changes Optional. Input keys to replace. Default none.
	 * @return Totals The totals.
	 *
	 * @phpstan-param array<string, mixed> $changes
	 */
	public function run( array $changes = array() ): Totals {
		$engine = new Engine();
		$phaseA = $engine->phaseA( $this->input( $changes ), new FactsEvaluator() );

		return $engine->phaseB( $phaseA, $this->quotes( $phaseA ) );
	}

	/**
	 * Compares the totals with the expected figures, and describes every difference.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return list<string> The differences; none when every expected figure is met.
	 */
	public function differences( Totals $totals ): array {
		$expected    = $this->document['expected'];
		$differences = array();
		$check       = static function ( string $what, string $want, Money $got ) use ( &$differences ): void {
			$have = $got->toDecimal()->toString();

			if ( ! Decimal::of( $want )->equals( $got->toDecimal() ) ) {
				$differences[] = "{$what}: expected {$want}, got {$have}";
			}
		};

		foreach ( array(
			'summary'     => '',
			'baseSummary' => 'base',
		) as $block => $prefix ) {
			foreach ( $expected[ $block ] ?? array() as $name => $want ) {
				$field = '' === $prefix ? $name : $prefix . ucfirst( $name );
				$check( "{$block}.{$name}", $want, $totals->summary->{$field} );
			}
		}

		$lines = array();

		foreach ( $totals->lines as $line ) {
			$lines[ $line->line->key ] = $line;
		}

		foreach ( $expected['lines'] ?? array() as $key => $figures ) {
			$line = $lines[ $key ] ?? null;

			if ( null === $line ) {
				$differences[] = "line {$key}: missing";
				continue;
			}

			$actual = array(
				'net'       => $line->amount->net(),
				'tax'       => $line->amount->tax(),
				'gross'     => $line->amount->gross(),
				'discount'  => $line->lineDiscount,
				'unitGross' => $line->unitGrossForDisplay,
			);

			foreach ( $figures as $name => $want ) {
				$check( "line {$key}.{$name}", $want, $actual[ $name ] );
			}
		}

		foreach ( $expected['adjustments'] ?? array() as $index => $want ) {
			$adjustment = $totals->adjustments[ $index ] ?? null;

			if ( null === $adjustment || $want['source'] !== $adjustment->source()->toString() ) {
				$differences[] = "adjustment {$index}: expected source {$want['source']}, got " . ( null === $adjustment ? 'none' : $adjustment->source()->toString() );
				continue;
			}

			$actual = array(
				'amount' => $adjustment->adjustment->authoredAmount->amount,
				'net'    => $adjustment->amount->net(),
				'tax'    => $adjustment->amount->tax(),
				'gross'  => $adjustment->amount->gross(),
			);

			foreach ( array_intersect_key( $want, $actual ) as $name => $figure ) {
				$check( "adjustment {$index} ({$want['source']}).{$name}", $figure, $actual[ $name ] );
			}

			foreach ( array( 'scope', 'type', 'line' ) as $name ) {
				$have = 'line' === $name ? $adjustment->adjustment->lineKey : $adjustment->adjustment->{$name}->value;

				if ( isset( $want[ $name ] ) && $want[ $name ] !== $have ) {
					$differences[] = "adjustment {$index}.{$name}: expected {$want[$name]}, got " . (string) $have;
				}
			}
		}

		if ( isset( $expected['adjustments'] ) && count( $expected['adjustments'] ) !== count( $totals->adjustments ) ) {
			$differences[] = sprintf( 'adjustments: expected %d, got %d', count( $expected['adjustments'] ), count( $totals->adjustments ) );
		}

		$components = array();

		foreach ( $totals->components() as $component ) {
			$components[ $component->componentKey ] = $component;
		}

		foreach ( $expected['components'] ?? array() as $key => $want ) {
			$component = $components[ $key ] ?? null;

			if ( null === $component ) {
				$differences[] = "component {$key}: missing";
				continue;
			}

			$check( "component {$key}.tax", $want['tax'], $component->amount->tax() );

			if ( isset( $want['residual'] ) && $want['residual'] !== $component->residualMinor ) {
				$differences[] = "component {$key}.residual: expected {$want['residual']}, got {$component->residualMinor}";
			}
		}

		return $differences;
	}

	/**
	 * Reads the conversion context: the identity of the currency, or the frozen rate the file names.
	 *
	 * @since 0.1.0
	 *
	 * @param array $input The input part of the file.
	 * @return ConversionContext The context.
	 *
	 * @phpstan-param array<string, mixed> $input
	 */
	private function context( array $input ): ConversionContext {
		$currency = Currency::of( $input['currency'] );

		if ( ! isset( $input['context'] ) ) {
			return ConversionContext::identity( $currency );
		}

		$context = $input['context'];

		return new ConversionContext( Currency::of( $context['base'] ), $currency, ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( $context['rate'] ), (int) $context['scale'], 'manual', (int) $context['version'], new \DateTimeImmutable( '2026-01-01T00:00:00+00:00' ) );
	}

	/**
	 * Reads a line. A converted line's unit price is written in the base currency and converted here, as a price resolver converts it.
	 *
	 * @since 0.1.0
	 *
	 * @param array             $line     The line, as the file writes it.
	 * @param Currency          $currency The calculation's currency.
	 * @param ConversionContext $context  The conversion context.
	 * @return InputLine The line.
	 *
	 * @phpstan-param array<string, mixed> $line
	 */
	private function line( array $line, Currency $currency, ConversionContext $context ): InputLine {
		$source = PriceSource::from( $line['priceSource'] ?? PriceSource::Explicit->value );

		if ( PriceSource::Converted === $source ) {
			$base = $this->authored( $line['unit'], $context->baseCurrency() );
			$rule = CurrencyRoundingRule::defaultFor( $currency );
			$unit = $base->withAmount( $context->convertToQuoteMoney( $base->amount, $rule->roundingMode() )->roundToCashStep( $rule ) );
		} else {
			$unit = $this->authored( $line['unit'], $currency );
		}

		return new InputLine( $line['key'], (int) $line['variant'], (int) $line['quantity'], $unit, $source, $line['taxClass'] );
	}

	/**
	 * Reads a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $promotion The promotion, as the file writes it.
	 * @param Currency $currency  The calculation's currency.
	 * @return PromotionFacts The promotion.
	 *
	 * @phpstan-param array<string, mixed> $promotion
	 */
	private function promotion( array $promotion, Currency $currency ): PromotionFacts {
		$effect = $promotion['effect'];

		return new PromotionFacts(
			(int) ( $promotion['id'] ?? 1 ),
			$promotion['uuid'],
			$promotion['code'],
			match ( $effect['kind'] ) {
				PromotionEffect::PERCENT => PromotionEffect::percent( Percentage::fromString( $effect['percent'] ) ),
				PromotionEffect::FIXED   => PromotionEffect::fixed( $this->authored( $effect, $currency ) ),
				default                  => PromotionEffect::freeShipping(),
			},
			(int) $promotion['priority']
		);
	}

	/**
	 * Reads a fee.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $fee      The fee, as the file writes it.
	 * @param Currency $currency The calculation's currency.
	 * @return FeeDefinition The fee.
	 *
	 * @phpstan-param array<string, mixed> $fee
	 */
	private function fee( array $fee, Currency $currency ): FeeDefinition {
		return new FeeDefinition(
			$fee['key'],
			isset( $fee['percent'] ) ? Percentage::fromString( $fee['percent'] ) : $this->authored( $fee, $currency ),
			AdjustmentBase::from( $fee['base'] ),
			null === ( $fee['taxClass'] ?? null ) ? Taxability::notTaxable() : Taxability::taxable( $fee['taxClass'] )
		);
	}

	/**
	 * Reads an amount and its basis.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $amount   An object with `amount` and `basis`.
	 * @param Currency $currency The currency it is in.
	 * @return AuthoredAmount The amount.
	 *
	 * @phpstan-param array<string, mixed> $amount
	 */
	private function authored( array $amount, Currency $currency ): AuthoredAmount {
		return new AuthoredAmount( Inputs::money( $amount['amount'], $currency->code() ), AmountBasis::from( $amount['basis'] ) );
	}

	/**
	 * Reads the rates of each tax class.
	 *
	 * @since 0.1.0
	 *
	 * @param array $classes The rates, by class, as the file writes them.
	 * @return array<string, list<TaxRateComponent>> The rates, by class.
	 *
	 * @phpstan-param array<string, list<array<string, mixed>>> $classes
	 */
	private function rates( array $classes ): array {
		$rates = array();

		foreach ( $classes as $class => $components ) {
			$rates[ (string) $class ] = array_map(
				static fn( array $rate ): TaxRateComponent => new TaxRateComponent( $rate['jurisdiction'] . ':' . $rate['rate'], $rate['jurisdiction'] . ' tax', Percentage::fromString( $rate['rate'] ), (bool) ( $rate['compound'] ?? false ), (int) ( $rate['priority'] ?? 1 ), $rate['jurisdiction'] ),
				$components
			);
		}

		return $rates;
	}
}
