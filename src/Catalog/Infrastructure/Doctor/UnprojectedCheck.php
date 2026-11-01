<?php
/**
 * UnprojectedCheck: doctor's count of the complete products without a sort price in the base currency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the complete products that a price order leaves out for want of a sort price in the base currency, and fails while there is any.
 *
 * Owns one fact: whether the sort prices have caught up with the catalog. Every save projects its
 * product, so a complete product whose listed variant has a base price but no row in the base
 * currency was saved before the projections existed, or its projection's write failed; the price
 * orders leave it out. A product whose listed variant has no base price has no row by the rule, and
 * is not counted. The failure names the command that projects them. It never repairs: the command
 * does, a page at a time.
 *
 * @since 0.2.0
 */
final class UnprojectedCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'unprojected';

	/**
	 * The command that projects them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMMAND = 'wp seocart catalog project-prices';

	/**
	 * The sort prices.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjections
	 */
	private PriceProjections $projections;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PriceProjections $projections  The sort prices.
	 * @param callable         $baseCurrency Returns the store's base currency (Currency).
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 */
	public function __construct( PriceProjections $projections, callable $baseCurrency ) {
		$this->projections  = $projections;
		$this->baseCurrency = $baseCurrency;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `unprojected`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the complete products without a sort price in the base currency.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed at none; failed with the count and the command otherwise.
	 */
	public function run(): CheckResult {
		$count = $this->projections->unprojectedCount( ( $this->baseCurrency )()->code() );

		if ( 0 === $count ) {
			return CheckResult::pass( self::NAME, 'Every complete product has its sort price for the price orders.' );
		}

		return CheckResult::fail(
			self::NAME,
			sprintf( '%1$d complete %2$s no sort price yet, so the price orders leave %3$s out. Run `%4$s`.', $count, 1 === $count ? 'product has' : 'products have', 1 === $count ? 'it' : 'them', self::COMMAND )
		);
	}
}
