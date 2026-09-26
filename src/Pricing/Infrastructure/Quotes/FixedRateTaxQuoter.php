<?php
/**
 * FixedRateTaxQuoter: the tax provider the plugin ships with, one rate for every class
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure\Quotes;

use SEOCart\Pricing\Application\TaxQuoter;
use SEOCart\Pricing\Application\TaxQuoteRequest;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes one fixed rate, of one jurisdiction, for every tax class asked for, at the destination and at home alike.
 *
 * Owns one fact: the stand-in tax provider, until a store configures real ones. It never fails
 * and never reaches the network. Since its destination and reference rates are the same, the
 * two cross-zone policies price alike under it.
 *
 * @since 0.1.0
 */
final class FixedRateTaxQuoter implements TaxQuoter {

	/**
	 * The rate the plugin is set up with, in percent.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RATE = '20';

	/**
	 * The jurisdiction the plugin is set up with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const JURISDICTION = 'stub';

	/**
	 * The quote's id: the quote is the same every time.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const QUOTE_ID = 'stub-fixed-rate';

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
	private const FINGERPRINT = 'stub:fixed:v1';

	/**
	 * Creates the quoter.
	 *
	 * @since 0.1.0
	 *
	 * @param Percentage $rate             The rate.
	 * @param string     $jurisdictionCode The jurisdiction that levies it.
	 */
	public function __construct(
		private Percentage $rate,
		private string $jurisdictionCode
	) {
	}

	/**
	 * Quotes the rate for every class asked for.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxQuoteRequest $request What to quote for.
	 * @return TaxQuote The quote.
	 */
	public function quote( TaxQuoteRequest $request ): TaxQuote {
		$rates   = array( new TaxRateComponent( 'fixed', 'Tax', $this->rate, false, 1, $this->jurisdictionCode ) );
		$byClass = array_fill_keys( $request->taxClasses, $rates );

		return new TaxQuote( self::QUOTE_ID, $this->jurisdictionCode, self::FINGERPRINT, $byClass, $byClass, $request->calculatedAt->add( new \DateInterval( self::LIFETIME ) ) );
	}
}
