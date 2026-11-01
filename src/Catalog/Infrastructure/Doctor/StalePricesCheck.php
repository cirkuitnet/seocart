<?php
/**
 * StalePricesCheck: doctor's count of the sort prices converted at an earlier exchange-rate version
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the sort prices converted at an exchange-rate version older than the current one, and fails while there is any.
 *
 * Owns one fact: whether the repricing after a rate change has caught up. A new version queues the
 * repricing job, which rewrites them a page at a time within seconds on a real catalog, so a count
 * that stays above zero means the job failed or never ran; until they are rewritten, a price order
 * in another currency sorts those products at the earlier rate. The failure names the job and the
 * command that rewrites them as well. It never repairs. A store without a rate version converts
 * nothing, and passes without a read.
 *
 * @since 0.2.0
 */
final class StalePricesCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'stale_prices';

	/**
	 * The command that rewrites them.
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
	 * The current exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @var CurrencyTerms
	 */
	private CurrencyTerms $terms;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PriceProjections $projections The sort prices.
	 * @param CurrencyTerms    $terms       The current exchange-rate version.
	 */
	public function __construct( PriceProjections $projections, CurrencyTerms $terms ) {
		$this->projections = $projections;
		$this->terms       = $terms;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stale_prices`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the converted sort prices behind the current version.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed at none; failed with the count, the job and the command otherwise.
	 */
	public function run(): CheckResult {
		$version = $this->terms->rateVersion();
		$count   = null === $version ? 0 : $this->projections->staleCount( $version );

		if ( 0 === $count ) {
			return CheckResult::pass( self::NAME, 'Every converted sort price is at the current exchange-rate version.' );
		}

		return CheckResult::fail(
			self::NAME,
			sprintf( '%1$d converted sort %2$s converted at an exchange-rate version older than %3$d, so the price orders in other currencies sort %4$s at the earlier rate. The `%5$s` job rewrites them after each new version; run `%6$s` if it has not.', $count, 1 === $count ? 'price is' : 'prices are', $version, 1 === $count ? 'its product' : 'their products', PriceProjector::REPRICE_JOB, self::COMMAND )
		);
	}
}
