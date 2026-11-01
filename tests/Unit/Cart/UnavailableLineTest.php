<?php
/**
 * Tests when a cart line wants more units than are for sale
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Cart\Domain\UnavailableLine;

/**
 * A line is flagged when its variant's figure is below the units the cart's lines of that variant
 * hold together: out of stock at 0, short with the figure above 0. No limit is never flagged, and
 * a variant the figures leave out has nothing for sale.
 *
 * Planted violations, each shown red and removed:
 *
 * - in UnavailableLine::of(), `<=` for `<`: a line at exactly its figure is flagged;
 * - read the figure as `$forSale[ $variantId ] ?? 0`: a line with no limit is flagged;
 * - judge each line against the figure alone, not the sum of its variant's lines: two lines of 3
 *   and 4 against 5 are not flagged.
 *
 * @since 0.2.0
 */
final class UnavailableLineTest extends TestCase {

	/**
	 * Tests the flag of one line of 3 units against each figure.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider figures
	 *
	 * @param array<int, int|null> $forSale  The figures, by variant id.
	 * @param string[]             $expected The lines flagged, as `reason:for_sale`.
	 *
	 * @phpstan-param list<string> $expected
	 */
	public function test_a_line_of_three_units_against_a_figure( array $forSale, array $expected ): void {
		$this->assertSame( $expected, self::flags( UnavailableLine::of( array( CartLine::of( 7, 3 ) ), $forSale ) ) );
	}

	/**
	 * Returns the figures of variant 7 and the flags of a line of 3 units of it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: array<int, int|null>, 1: list<string>}> The cases.
	 */
	public static function figures(): array {
		return array(
			'no limit'                   => array( array( 7 => null ), array() ),
			'not named'                  => array( array(), array( 'out_of_stock:0' ) ),
			'another variant named only' => array( array( 8 => 50 ), array( 'out_of_stock:0' ) ),
			'none for sale'              => array( array( 7 => 0 ), array( 'out_of_stock:0' ) ),
			'fewer than the line'        => array( array( 7 => 2 ), array( 'insufficient_stock:2' ) ),
			'exactly the line'           => array( array( 7 => 3 ), array() ),
			'more than the line'         => array( array( 7 => 4 ), array() ),
		);
	}

	/**
	 * Tests that a variant with no limit is never flagged, however many units its line holds.
	 *
	 * @since 0.2.0
	 */
	public function test_no_limit_is_never_flagged(): void {
		$this->assertSame( array(), UnavailableLine::of( array( CartLine::of( 7, CartLine::MAX_QUANTITY ) ), array( 7 => null ) ) );
	}

	/**
	 * Tests that the lines of one variant are judged together: 3 and 4 units against 5 both flag, with 5.
	 *
	 * @since 0.2.0
	 */
	public function test_the_lines_of_one_variant_are_summed(): void {
		$plain   = new CartLine( LineIdentity::of( 7 ), 7, 3 );
		$wrapped = new CartLine( LineIdentity::of( 7, array( 'gift_wrap:red' ) ), 7, 4 );
		$flagged = UnavailableLine::of( array( $plain, $wrapped ), array( 7 => 5 ) );

		$this->assertSame( array( 'insufficient_stock:5', 'insufficient_stock:5' ), self::flags( $flagged ) );
		$this->assertSame( array( $plain->identity->value(), $wrapped->identity->value() ), array_column( $flagged, 'lineIdentity' ) );
		$this->assertSame( array(), UnavailableLine::of( array( $plain, $wrapped ), array( 7 => 7 ) ), 'Seven units cover both lines.' );
	}

	/**
	 * Tests that the lines flagged come back in the cart's order, each with its own line, variant, reason and figure.
	 *
	 * @since 0.2.0
	 */
	public function test_lines_of_several_variants_come_back_in_cart_order(): void {
		$lines   = array( CartLine::of( 30, 2 ), CartLine::of( 10, 1 ), CartLine::of( 20, 5 ), CartLine::of( 40, 1 ) );
		$flagged = UnavailableLine::of(
			$lines,
			array(
				10 => 1,
				20 => 3,
				30 => 0,
				40 => null,
			)
		);

		$this->assertSame( array( 30, 20 ), array_column( $flagged, 'variantId' ) );
		$this->assertSame( array( $lines[0]->identity->value(), $lines[2]->identity->value() ), array_column( $flagged, 'lineIdentity' ) );
		$this->assertSame( array( UnavailableLine::OUT_OF_STOCK, UnavailableLine::INSUFFICIENT_STOCK ), array_column( $flagged, 'reason' ) );
		$this->assertSame( array( 0, 3 ), array_column( $flagged, 'forSale' ) );
	}

	/**
	 * Writes flagged lines as `reason:for_sale`, in their order.
	 *
	 * @since 0.2.0
	 *
	 * @param UnavailableLine[] $flagged The lines.
	 * @return list<string> The flags.
	 *
	 * @phpstan-param list<UnavailableLine> $flagged
	 */
	private static function flags( array $flagged ): array {
		return array_map( static fn( UnavailableLine $line ): string => $line->reason . ':' . $line->forSale, $flagged );
	}
}
