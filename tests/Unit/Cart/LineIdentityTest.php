<?php
/**
 * Tests the identity of a cart line, and how lines of one identity combine
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\LineIdentity;

/**
 * Identical lines merge, their quantities adding; a line with other add-ons or another
 * personalization never merges with it; and a plain line's identity is the digest of its variant,
 * whatever the add-on and personalization parts will add.
 *
 * @since 0.1.0
 */
final class LineIdentityTest extends TestCase {

	/**
	 * A personalization hash, as the personalization of a line would give.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ENGRAVING = 'aa4a4a3cb49adbd62cb7bc6a9f33bcce56f5f5ff4d7e4a9e5a26d8c2f0d0e7a1';

	/**
	 * Tests that a plain line's identity is the SHA-256 of `variant:` and its variant id, and stays so when the other parts are empty.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plain_line_is_the_digest_of_its_variant(): void {
		$expected = hash( 'sha256', 'variant:42' );

		$this->assertSame( $expected, LineIdentity::of( 42 )->value() );
		$this->assertSame( $expected, LineIdentity::of( 42, array(), '' )->value() );
		$this->assertSame( $expected, CartLine::of( 42, 1 )->identity->value() );
		$this->assertNotSame( $expected, LineIdentity::of( 43 )->value() );
	}

	/**
	 * Tests that identical lines merge, and that add-on choices are compared as a set.
	 *
	 * @since 0.1.0
	 */
	public function test_identical_lines_merge_whatever_order_their_add_ons_came_in(): void {
		$wrapped = LineIdentity::of( 7, array( 'gift_wrap:red', 'card:birthday' ), self::ENGRAVING );
		$same    = LineIdentity::of( 7, array( 'card:birthday', 'gift_wrap:red' ), self::ENGRAVING );

		$this->assertTrue( $wrapped->equals( $same ) );

		$merged = CartLine::merge( array( new CartLine( $wrapped, 7, 2 ), new CartLine( $same, 7, 3 ) ) );

		$this->assertCount( 1, $merged );
		$this->assertSame( 5, $merged[0]->quantity );
		$this->assertSame( $wrapped->value(), $merged[0]->identity->value() );
	}

	/**
	 * Tests that a line with other add-ons or another personalization does not merge with a line of the same variant.
	 *
	 * @since 0.1.0
	 */
	public function test_lines_with_other_add_ons_or_personalization_do_not_merge(): void {
		$plain       = LineIdentity::of( 7 );
		$wrapped     = LineIdentity::of( 7, array( 'gift_wrap:red' ) );
		$engraved    = LineIdentity::of( 7, array(), self::ENGRAVING );
		$reEngraved  = LineIdentity::of( 7, array(), str_repeat( 'b', 64 ) );
		$wrappedBlue = LineIdentity::of( 7, array( 'gift_wrap:blue' ) );

		$identities = array( $plain, $wrapped, $engraved, $reEngraved, $wrappedBlue );
		$values     = array_map( static fn( LineIdentity $identity ): string => $identity->value(), $identities );

		$this->assertCount( 5, array_unique( $values ), 'Two different lines of one variant were given the same identity.' );

		$merged = CartLine::merge( array_map( static fn( LineIdentity $identity ): CartLine => new CartLine( $identity, 7, 1 ), $identities ) );

		$this->assertCount( 5, $merged );
		$this->assertSame( $values, array_map( static fn( CartLine $line ): string => $line->identity->value(), $merged ), 'Merging kept each line, in the order it came.' );
	}

	/**
	 * Tests that an add-on choice holding the separator of the canonical text cannot pass for two choices.
	 *
	 * @since 0.1.0
	 */
	public function test_add_on_choices_are_kept_apart_whatever_they_hold(): void {
		$this->assertNotSame(
			LineIdentity::of( 7, array( 'a","b' ) )->value(),
			LineIdentity::of( 7, array( 'a', 'b' ) )->value()
		);
	}

	/**
	 * Tests that merging adds the quantities of each identity, in the order identities first appear, and fills a line to its cap at most.
	 *
	 * @since 0.1.0
	 */
	public function test_merging_adds_quantities_up_to_the_cap(): void {
		$merged = CartLine::merge(
			array(
				CartLine::of( 3, 1 ),
				CartLine::of( 5, 2 ),
				CartLine::of( 3, 4 ),
				CartLine::of( 9, CartLine::MAX_QUANTITY ),
				CartLine::of( 9, 1 ),
			)
		);

		$this->assertSame(
			array( '3:5', '5:2', '9:' . CartLine::MAX_QUANTITY ),
			array_map( static fn( CartLine $line ): string => $line->variantId . ':' . $line->quantity, $merged )
		);
	}

	/**
	 * Tests what is refused: a variant id below 1, a quantity out of range, an empty add-on choice, a personalization hash that is not a digest.
	 *
	 * @since 0.1.0
	 */
	public function test_malformed_lines_are_refused(): void {
		$refused = array(
			'variant 0'          => static fn() => LineIdentity::of( 0 ),
			'quantity 0'         => static fn() => CartLine::of( 1, 0 ),
			'quantity over cap'  => static fn() => CartLine::of( 1, CartLine::MAX_QUANTITY + 1 ),
			'empty add-on'       => static fn() => LineIdentity::of( 1, array( '' ) ),
			'short digest'       => static fn() => LineIdentity::of( 1, array(), 'abc' ),
			'upper-case digest'  => static fn() => LineIdentity::of( 1, array(), strtoupper( self::ENGRAVING ) ),
			'line of variant -1' => static fn() => new CartLine( LineIdentity::of( 1 ), -1, 1 ),
		);

		foreach ( $refused as $case => $build ) {
			try {
				$build();
				$this->fail( sprintf( 'Accepted: %s.', $case ) );
			} catch ( \InvalidArgumentException $expected ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Tests that an identity a client sends is read back only in its one written form.
	 *
	 * @since 0.1.0
	 */
	public function test_an_identity_is_read_back_only_in_its_written_form(): void {
		$identity = LineIdentity::of( 42 );
		$read     = LineIdentity::fromString( $identity->value() );

		$this->assertNotNull( $read );
		$this->assertTrue( $identity->equals( $read ) );

		foreach ( array( '', 'variant:42', strtoupper( $identity->value() ), $identity->value() . "\n", substr( $identity->value(), 1 ) ) as $text ) {
			$this->assertNull( LineIdentity::fromString( $text ), sprintf( 'Read "%s" as an identity.', $text ) );
		}
	}
}
