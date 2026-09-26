<?php
/**
 * FlatRateShippingQuoter: the shipping provider the plugin ships with, one flat rate for everything
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure\Quotes;

use SEOCart\Pricing\Application\ShippingQuoteRequest;
use SEOCart\Pricing\Application\ShippingRateQuoter;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes one flat shipping rate, set in the base currency, wherever the order goes.
 *
 * Owns one fact: the stand-in shipping provider, until a store configures real ones. It never
 * fails and never reaches the network. The rate is an amount of the base currency; a cart in
 * another currency gets it converted at the calculation's frozen rate. Each quote has an id of
 * its own and holds for fifteen minutes from when the calculation was asked for.
 *
 * @since 0.1.0
 */
final class FlatRateShippingQuoter implements ShippingRateQuoter {

	/**
	 * The rate the plugin is set up with, in major units of the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RATE = '5.00';

	/**
	 * The tax class of the rate the plugin is set up with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TAX_CLASS = 'standard';

	/**
	 * The key of the one method it quotes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const METHOD_KEY = 'flat';

	/**
	 * How long a quote holds.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIFETIME = 'PT15M';

	/**
	 * The provider and the version of its answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FINGERPRINT = 'stub:flat:v1';

	/**
	 * Creates the quoter. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param IdGenerator $ids      Mints each quote's id.
	 * @param Decimal     $rate     The rate, in major units of the base currency.
	 * @param AmountBasis $basis    Whether the rate includes tax.
	 * @param string      $taxClass The rate's tax class.
	 */
	public function __construct(
		private IdGenerator $ids,
		private Decimal $rate,
		private AmountBasis $basis,
		private string $taxClass
	) {
	}

	/**
	 * Quotes the flat rate, in the cart's currency.
	 *
	 * @since 0.1.0
	 *
	 * @param ShippingQuoteRequest $request What to quote for.
	 * @return list<ShippingRateQuote> The one rate.
	 */
	public function quote( ShippingQuoteRequest $request ): array {
		$context = $request->conversionContext;
		$mode    = CurrencyRoundingRule::defaultFor( $request->currency )->roundingMode();
		$rate    = $context->convertToQuoteMoney( Money::ofDecimal( $this->rate, $context->baseCurrency(), $mode ), $mode );

		return array(
			new ShippingRateQuote(
				$this->ids->generate(),
				self::METHOD_KEY,
				'flat_rate',
				new AuthoredAmount( $rate, $this->basis ),
				$this->taxClass,
				$request->calculatedAt->add( new \DateInterval( self::LIFETIME ) ),
				self::FINGERPRINT
			),
		);
	}
}
