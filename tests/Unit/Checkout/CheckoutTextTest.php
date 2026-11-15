<?php
/**
 * Tests the characters the checkout refuses in a value a shopper types
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Domain\Fields\CheckoutText;

/**
 * Control and invisible format characters are refused, the two joiners Persian and Indic writing need are kept, a textarea alone keeps a line feed, and text that is not UTF-8 is refused.
 *
 * Planted violations: in CheckoutText::refused(), leave out `|\p{Cf}`: the format characters'
 * rows are accepted; add `\x{200C}` back to the refused class (take it out of JOINERS): the
 * Persian name is refused; accept the line feed on one line: its one-line row is accepted.
 *
 * @since 0.2.0
 */
final class CheckoutTextTest extends TestCase {

	/**
	 * Provides values and whether a one-line field and a textarea accept each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool, bool}> The value; whether one line accepts it; whether a textarea does.
	 */
	public static function values(): array {
		return array(
			'plain'                         => array( 'Ada Lovelace', true, true ),
			'Zoë'                           => array( 'Zoë', true, true ),
			'Αθηνά'                         => array( 'Αθηνά', true, true ),
			'Иван'                          => array( 'Иван', true, true ),
			'Persian with U+200C'           => array( "علی\u{200C}رضا", true, true ),
			'Devanagari with U+200D'        => array( "क्\u{200D}ष", true, true ),
			'U+000A line feed'              => array( "a\nb", false, true ),
			'U+000D carriage return'        => array( "a\rb", false, false ),
			'U+0000 nul'                    => array( "a\0b", false, false ),
			'U+0007 bell'                   => array( "a\u{0007}b", false, false ),
			'U+0085 next line'              => array( "a\u{0085}b", false, false ),
			'U+2028 line separator'         => array( "a\u{2028}b", false, false ),
			'U+2029 paragraph separator'    => array( "a\u{2029}b", false, false ),
			'U+202E right-to-left override' => array( "a\u{202E}b", false, false ),
			'U+200E left-to-right mark'     => array( "a\u{200E}b", false, false ),
			'U+FEFF zero width no-break'    => array( "\u{FEFF}a", false, false ),
			'U+00AD soft hyphen'            => array( "a\u{00AD}b", false, false ),
			'not UTF-8'                     => array( "a\xC3b", false, false ),
		);
	}

	/**
	 * Tests each value on one line and in a textarea.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider values
	 *
	 * @param string $value    The value.
	 * @param bool   $oneLine  Whether a one-line field accepts it.
	 * @param bool   $textarea Whether a textarea accepts it.
	 */
	public function test_what_is_accepted( string $value, bool $oneLine, bool $textarea ): void {
		$this->assertSame( array( $oneLine, $textarea ), array( CheckoutText::accepts( $value, false ), CheckoutText::accepts( $value, true ) ) );
	}
}
