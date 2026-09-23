<?php
/**
 * Tests the Luhn detector: every card-shaped number is found, and nothing else is touched
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Tests\Support\Logging\TestCards;
use SEOCart\Tests\Support\SeededCases;

/**
 * A card number never survives scrub(), however it is written; a number that is not one is kept.
 *
 * The numbers are the card networks' published test numbers and numbers generated with a
 * valid check digit, never real cards. The property test draws from a fixed seed, so every
 * run checks the same cases and a failure names its input.
 *
 * Planted violations, each confirmed red then removed: in passesLuhn(), stop doubling every
 * second digit; in scrubChain(), check only the whole chain instead of every sequence of
 * groups (the card after "qty 2" survives); allow up to three separators instead of eight
 * (the four-space case survives); leave white space out of SEPARATOR (tabs, line breaks and
 * non-breaking spaces survive); leave the format characters out (zero-width spaces survive);
 * read ASCII digits only (full-width and Arabic-Indic digits survive); skip any text holding a
 * UUID shape (the card in a UUID shape survives); put the dot back in SEPARATOR (the IP
 * addresses, versions and decimals whose digits pass the checksum are destroyed).
 *
 * @since 0.1.0
 */
final class CardNumbersTest extends TestCase {

	/**
	 * The networks' published test card numbers, 13 to 16 digits.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const TEST_CARDS = array(
		'4111111111111111',
		'4012888888881881',
		'4222222222222',
		'5555555555554444',
		'5105105105105100',
		'378282246310005',
		'371449635398431',
		'6011111111111117',
		'6011000990139424',
		'30569309025904',
		'38520000023237',
		'3530111333300000',
		'3566002020360505',
	);

	/**
	 * Tests that the published test numbers pass the checksum, and fail it once a digit changes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_published_test_numbers_pass_the_checksum_and_a_changed_digit_fails(): void {
		foreach ( self::TEST_CARDS as $card ) {
			$this->assertTrue( CardNumbers::passesLuhn( $card ), $card );
			$this->assertFalse( CardNumbers::passesLuhn( TestCards::breakCheckDigit( $card ) ), TestCards::breakCheckDigit( $card ) );
			$this->assertSame( CardNumbers::MARKER, CardNumbers::scrub( $card ), $card );
		}
	}

	/**
	 * Tests that a card number is removed in each way people write one, and only the card number.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider writtenCards
	 *
	 * @param string $text     The text.
	 * @param string $expected What scrub() must return.
	 */
	public function test_a_card_number_is_removed_however_it_is_written( string $text, string $expected ): void {
		$this->assertSame( $expected, CardNumbers::scrub( $text ) );
		$this->assertTrue( CardNumbers::contains( $text ) );
	}

	/**
	 * Returns texts that hold a card number, with what must remain of each.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function writtenCards(): array {
		$m = CardNumbers::MARKER;

		return array(
			'digits only'                   => array( '4111111111111111', $m ),
			'groups of four, spaces'        => array( 'Card 4111 1111 1111 1111 declined.', "Card {$m} declined." ),
			'groups of four, dashes'        => array( 'card=4111-1111-1111-1111;', "card={$m};" ),
			'fifteen digits in 4-6-5'       => array( 'Amex 3782 822463 10005.', "Amex {$m}." ),
			'thirteen digits'               => array( 'old visa 4222222222222 here', "old visa {$m} here" ),
			'every digit spaced'            => array( '4 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1', $m ),
			'spaced dashes'                 => array( '4111 - 1111 - 1111 - 1111', $m ),
			'mixed separators'              => array( '5555-5555 5555-4444', $m ),
			'in punctuation'                => array( '(6011111111111117)', "({$m})" ),
			'two cards'                     => array( '4111111111111111 then 5555555555554444', "{$m} then {$m}" ),
			'at the end of a number chain'  => array( 'lines 12 31 4111 1111 1111 1111', 'lines 12 31 ' . $m ),
			'with a group that also passes' => array( 'lines 12 30 4111 1111 1111 1111', 'lines 12 ' . $m ),
			'glued to letters'              => array( 'pan:4111111111111111end', "pan:{$m}end" ),
			'nineteen digits'               => array( 'x ' . TestCards::withCheckDigit( TestCards::PREFIX . '123456789012345' ) . ' y', "x {$m} y" ),
			'four spaces'                   => array( 'a 4111    1111    1111    1111 b', "a {$m} b" ),
			'eight spaces'                  => array( '4111        1111        1111        1111', $m ),
			'tabs'                          => array( "card\t4111\t1111\t1111\t1111\tend", "card\t{$m}\tend" ),
			'line breaks'                   => array( "4111\n1111\r\n1111\n1111", $m ),
			'non-breaking spaces'           => array( "4111\u{00A0}1111\u{00A0}1111\u{202F}1111", $m ),
			'an en dash and a minus sign'   => array( "4111\u{2013}1111\u{2212}1111-1111", $m ),
			'zero-width spaces'             => array( "4111\u{200B}1111\u{200B}1111\u{200D}1111", $m ),
			'a zero-width space in a group' => array( "41\u{200B}11111111111111", $m ),
			'full-width digits'             => array( 'x ' . TestCards::inScript( '4111 1111 1111 1111', 0xFF10 ) . ' y', "x {$m} y" ),
			'Arabic-Indic digits'           => array( TestCards::inScript( '4111111111111111', 0x0660 ), $m ),
			'a card in a UUID shape'        => array( 'id 41111111-1111-1111-1111-000000000000', "id {$m}-1111-000000000000" ),
		);
	}

	/**
	 * Tests that what is not a card number is left exactly as it was.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider notCards
	 *
	 * @param string $text The text.
	 */
	public function test_what_is_not_card_shaped_is_kept( string $text ): void {
		$this->assertSame( $text, CardNumbers::scrub( $text ) );
		$this->assertFalse( CardNumbers::contains( $text ) );
	}

	/**
	 * Returns texts without a card number.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function notCards(): array {
		return array(
			'a 16-digit order number'        => array( 'Order 1234567890123456 was placed.' ),
			'a test card with a wrong digit' => array( '4111111111111112' ),
			'twelve digits'                  => array( '411111111111' ),
			'more than nineteen digits'      => array( '12345678901234567890123' ),
			'a version 4 UUID'               => array( 'correlation f47ac10b-58cc-4372-a567-0e02b2c3d479 done' ),
			'a version 7 UUID'               => array( 'correlation 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b done' ),
			'a UUID of the test sequence'    => array( '00000000-0000-7000-8000-000000000001' ),
			'groups nine spaces apart'       => array( '4111         1111         1111         1111' ),
			'a timestamp'                    => array( '2026-09-23 12:00:00.123456' ),
			'an ISO date and time'           => array( 'stored at 2026-09-23T12:00:00.123456+00:00 then 2026-09-23T12:00:01.411111Z' ),
			'an IP address'                  => array( '203.0.113.195' ),
			'no digits'                      => array( 'Nothing to see here.' ),
		);
	}

	/**
	 * Tests that a chain of thousands of groups is read, not given up on: no sequence of ones passes the checksum, so it is kept whole, and a card after it is still found.
	 *
	 * Planted violation: make CHAIN's quantifiers greedy instead of possessive (the engine runs
	 * out of stack, and the whole text becomes the placeholder).
	 *
	 * @since 0.1.0
	 */
	public function test_a_chain_of_thousands_of_groups_is_read(): void {
		$chain = str_repeat( '1 ', 8000 );

		$this->assertSame( $chain, CardNumbers::scrub( $chain ) );
		$this->assertFalse( CardNumbers::contains( $chain ) );

		$scrubbed = CardNumbers::scrub( $chain . 'x 4111 1111 1111 1111' );

		$this->assertSame( $chain . 'x ' . CardNumbers::MARKER, $scrubbed );
	}

	/**
	 * Tests that numbers written with dots are kept, even when their digits, read together, pass the checksum.
	 *
	 * Each case's digits are those of a published test card, so a detector that joined groups
	 * across a dot would remove them.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider dottedNumbers
	 *
	 * @param string $text The text.
	 */
	public function test_numbers_written_with_dots_are_kept( string $text ): void {
		$this->assertTrue( CardNumbers::passesLuhn( TestCards::digitsOf( $text ) ), 'The case must be one a dot-joining detector would remove.' );
		$this->assertSame( $text, CardNumbers::scrub( $text ) );
		$this->assertFalse( CardNumbers::contains( $text ) );
	}

	/**
	 * Returns texts of numbers written with dots, whose digits together pass the checksum.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function dottedNumbers(): array {
		return array(
			'two IPv4 addresses'        => array( 'from 41.11.11.11 11.11.11.11' ),
			'version strings'           => array( 'tested with 4.1.11 1.11.1 1.1.11 1.1.11' ),
			'decimal prices'            => array( 'prices 41.11 11.11 11.11 11.11' ),
			'a decimal'                 => array( 'total 411111111111.1111' ),
			'a date and time with dots' => array( '41.11.1111 11.11.11.11' ),
			'a card written with dots'  => array( 'pan 4111.1111.1111.1111.' ),
			'full-width dots'           => array( "4111\u{FF0E}1111\u{FF0E}1111\u{FF0E}1111" ),
		);
	}

	/**
	 * Tests, over generated card numbers, that none survives in any format or company.
	 *
	 * A number of 13 to 19 digits with a valid check digit is written plain, in groups of four
	 * joined by any separator the detector accepts, or with a separator between every digit,
	 * sometimes in full-width or Arabic-Indic digits; it is placed after a group of digits of
	 * its own, before more text. The digits of the card must be gone from the result, and a
	 * number that differs only in its check digit, written plain, must be kept.
	 *
	 * @since 0.1.0
	 */
	public function test_no_generated_card_number_survives_and_its_non_luhn_twin_is_kept(): void {
		SeededCases::check(
			20260923,
			3000,
			static function ( Randomizer $random ): array {
				$card      = TestCards::draw( $random, 13, 19 );
				$separator = array( ' ', '-', ' - ', "\t", "\n", "\u{202F}", "\u{00A0}", "\u{200B}", "\u{2013}", '    ', '        ' )[ SeededCases::int( $random, 0, 10 ) ];
				$written   = self::written( $card, SeededCases::int( $random, 0, 2 ), $separator );
				$script    = array( 0, 0, 0xFF10, 0x0660 )[ SeededCases::int( $random, 0, 3 ) ];
				$before    = array( '', 'qty ' . SeededCases::int( $random, 1, 99 ) . ' ', 'ref ', 'x' )[ SeededCases::int( $random, 0, 3 ) ];
				$after     = array( '', ' ok', ' 42', '.' )[ SeededCases::int( $random, 0, 3 ) ];

				return array( $card, $before . ( 0 === $script ? $written : TestCards::inScript( $written, $script ) ) . $after );
			},
			function ( string $card, string $text ): void {
				$scrubbed = CardNumbers::scrub( $text );

				$this->assertStringNotContainsString( $card, TestCards::digitsOf( $scrubbed ), 'The card\'s digits survived.' );
				$this->assertStringContainsString( CardNumbers::MARKER, $scrubbed );

				$twin = TestCards::breakCheckDigit( $card );

				$this->assertSame( $twin, CardNumbers::scrub( $twin ), 'A number that fails the checksum must be kept.' );
			}
		);
	}

	/**
	 * Writes digits in one of the formats people use.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits    The digits.
	 * @param int    $format    0 plain, 1 groups of four, 2 every digit on its own.
	 * @param string $separator What stands between two groups.
	 * @return string The written number.
	 */
	private static function written( string $digits, int $format, string $separator ): string {
		return match ( $format ) {
			0 => $digits,
			1 => implode( $separator, str_split( $digits, 4 ) ),
			default => implode( $separator, str_split( $digits ) ),
		};
	}
}
