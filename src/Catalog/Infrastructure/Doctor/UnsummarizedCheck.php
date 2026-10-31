<?php
/**
 * UnsummarizedCheck: doctor's line for complete products whose stock is not summarized yet
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Counts the complete products with an enabled variant whose stock summary is still unknown, and fails while there is any.
 *
 * Owns one fact: whether the catalog's summaries have caught up. Every save summarizes its
 * product, so a complete product left unknown was saved before the summaries existed, or its
 * summary's write failed; the listing's in-stock filter leaves it out. A product whose every
 * variant is disabled is unknown by the rule, which the command cannot change, so it is not
 * counted. A summary a checkout's released hold left out of stock is not unknown, so this check
 * does not count it either.
 * The failure names the command that summarizes them. It never repairs: the command does, a page
 * at a time.
 *
 * @since 0.2.0
 */
final class UnsummarizedCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'unsummarized';

	/**
	 * The command that summarizes them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMMAND = 'wp seocart catalog summarize-stock';

	/**
	 * The summaries.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaries
	 */
	private StockSummaries $summaries;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param StockSummaries $summaries The summaries.
	 */
	public function __construct( StockSummaries $summaries ) {
		$this->summaries = $summaries;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `unsummarized`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the complete products whose summary is unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed at none; failed with the count and the command otherwise.
	 */
	public function run(): CheckResult {
		$count = $this->summaries->unsummarizedCount();

		if ( 0 === $count ) {
			return CheckResult::pass( self::NAME, 'Every complete product\'s stock is summarized for the listings.' );
		}

		return CheckResult::fail(
			self::NAME,
			sprintf( '%1$d complete %2$s no stock summary yet, so the in-stock filter leaves %3$s out. Run `%4$s`.', $count, 1 === $count ? 'product has' : 'products have', 1 === $count ? 'it' : 'them', self::COMMAND )
		);
	}
}
