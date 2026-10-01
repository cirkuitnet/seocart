<?php
/**
 * PromotionUsageCheck: every promotion's use count agrees with its usage rows
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Infrastructure\Doctor;

use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Promotion\Infrastructure\MysqlPromotionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Holds each promotion's `used` to the uses it counts, and reports; it never repairs.
 *
 * Owns one fact: when doctor calls promotion usage inconsistent. A promotion's `used` must equal
 * the number of its usage rows that are reserved or committed; a released use no longer counts.
 * A difference is critical, named with the promotion and both figures, and left for a person:
 * the count is what keeps a promotion within its limit, and a use given back from a count that
 * is already zero is refused. The promotions are read a page at a time, by id, each page one
 * bounded read, until a page comes back short or LIMIT promotions are listed: a store that hands
 * out single-use codes holds them in the thousands. It prints ids and counts only.
 *
 * @since 0.1.0
 */
final class PromotionUsageCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'promotion';

	/**
	 * The most promotions the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * How many promotions one read compares.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the page size is not positive.
	 *
	 * @param MysqlPromotionRepository $promotions The promotion statements.
	 * @param int                      $page       Optional. How many promotions one read compares. Default PAGE.
	 */
	public function __construct( private MysqlPromotionRepository $promotions, private int $page = self::PAGE ) {
		if ( $page < 1 ) {
			throw new \InvalidArgumentException( 'The check reads at least one promotion at a time.' );
		}
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `promotion`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Compares every promotion's use count with its reserved and committed uses.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every count agrees.
	 */
	public function run(): CheckResult {
		$findings = array();
		$after    = 0;

		do {
			$promotions = $this->promotions->usageCounts( $after, $this->page );
			$read       = count( $promotions );

			foreach ( $promotions as $promotion ) {
				if ( $promotion['used'] !== $promotion['counted'] && count( $findings ) < self::LIMIT ) {
					$findings[] = self::finding( $promotion );
				}

				$after = $promotion['promotion_id'];
			}

			$enough = count( $findings ) >= self::LIMIT;
		} while ( $this->page === $read && ! $enough );

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Every promotion\'s use count agrees with its reserved and committed uses.' );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d %s with a wrong use count.', count( $findings ), 1 === count( $findings ) ? 'promotion' : 'promotions' ), $findings );
	}

	/**
	 * Describes a promotion whose use count is not the count of its uses.
	 *
	 * @since 0.1.0
	 *
	 * @param array{promotion_id: int, used: int, counted: int} $promotion The promotion and both figures.
	 * @return string The finding.
	 */
	private static function finding( array $promotion ): string {
		return sprintf(
			'Critical: promotion %1$d counts %2$d %3$s, but it has %4$d reserved or committed usage %5$s. The count is what holds it to its limit; a person must correct it.',
			$promotion['promotion_id'],
			$promotion['used'],
			1 === $promotion['used'] ? 'use' : 'uses',
			$promotion['counted'],
			1 === $promotion['counted'] ? 'row' : 'rows'
		);
	}
}
