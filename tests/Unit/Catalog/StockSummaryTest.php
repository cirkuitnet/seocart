<?php
/**
 * Tests the rule that sums a product's stock up for listings, and the shape of a search
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\Projection\StockSummary;
use SEOCart\Catalog\Application\Search\SearchQuery;
use SEOCart\Support\Locale;

/**
 * StockSummary::of(): in stock when any enabled variant has a unit for sale or no limit, out of
 * stock when every one has none, unknown with no variant; and SearchQuery's refusals.
 *
 * @since 0.2.0
 */
final class StockSummaryTest extends TestCase {

	/**
	 * Returns the cases of the rule: the figures for sale of the enabled variants, and the summary.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: array<int, int|null>, 1: string}> The cases.
	 */
	public static function figures(): array {
		return array(
			'no enabled variant'             => array( array(), StockSummary::UNKNOWN ),
			'one variant with units'         => array( array( 3 ), StockSummary::IN_STOCK ),
			'one variant with none'          => array( array( 0 ), StockSummary::OUT_OF_STOCK ),
			'one variant without a limit'    => array( array( null ), StockSummary::IN_STOCK ),
			'every variant with none'        => array( array( 0, 0, 0 ), StockSummary::OUT_OF_STOCK ),
			'the last variant with one unit' => array( array( 0, 0, 1 ), StockSummary::IN_STOCK ),
			'none, and one without a limit'  => array( array( 0, null ), StockSummary::IN_STOCK ),
		);
	}

	/**
	 * Tests the rule on each case.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider figures
	 *
	 * @param array<int, int|null> $forSale  The figures.
	 * @param string               $expected The summary.
	 */
	public function test_the_summary_of_the_figures( array $forSale, string $expected ): void {
		$this->assertSame( $expected, StockSummary::of( $forSale ) );
		$this->assertContains( $expected, StockSummary::values() );
	}

	/**
	 * Tests that a search's offset is the products of the pages before it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_search_starts_after_the_pages_before_it(): void {
		$this->assertSame( 0, self::query( 1, 24, array() )->offset() );
		$this->assertSame( 48, self::query( 3, 24, array( 'linen', 'shirt', 'blue' ) )->offset() );
	}

	/**
	 * Tests that a search no adapter could honour is refused: a page before the first, an empty page, a fourth word, another order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_search_no_adapter_could_honour_is_refused(): void {
		$refused = array();

		foreach ( array(
			'page 0'      => static fn(): SearchQuery => self::query( 0, 24, array() ),
			'per page 0'  => static fn(): SearchQuery => self::query( 1, 0, array() ),
			'four words'  => static fn(): SearchQuery => self::query( 1, 24, array( 'a', 'b', 'c', 'd' ) ),
			'title order' => static fn(): SearchQuery => new SearchQuery( Locale::of( 'en_US' ), 1, 24, 'title', array(), array(), null, null, false ),
		) as $case => $build ) {
			try {
				$build();
			} catch ( \InvalidArgumentException $expected ) {
				$refused[] = $case;
			}
		}

		$this->assertSame( array( 'page 0', 'per page 0', 'four words', 'title order' ), $refused );
	}

	/**
	 * Builds a search in en_US, newest first, with no filter.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $page    The page.
	 * @param int      $perPage The page's length.
	 * @param string[] $words   The words.
	 * @return SearchQuery The search.
	 *
	 * @phpstan-param list<string> $words
	 */
	private static function query( int $page, int $perPage, array $words ): SearchQuery {
		return new SearchQuery( Locale::of( 'en_US' ), $page, $perPage, SearchQuery::NEWEST, $words, array(), null, null, false );
	}
}
