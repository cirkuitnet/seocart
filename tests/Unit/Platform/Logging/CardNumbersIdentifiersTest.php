<?php
/**
 * Tests that the card-number detector leaves identifiers whole and still removes a card number next to one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\FallbackLog;
use SEOCart\Support\SystemIdGenerator;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Logging\TestCards;
use SEOCart\Tests\Support\SeededCases;

/**
 * A UUID, or a hexadecimal word as long as a dashless UUID, is an identifier whose digits are never read as a card number.
 *
 * About one time-ordered UUID in 400 holds a run of digits that crosses its dashes and passes the
 * checksum, so a detector that read only the digits cut the identifier out of a log line. The
 * rule under test is the one in CardNumbers' docblock; the cases here are its edges: the
 * identifier stays whole, and a card number beside one, or touching a letter that no hexadecimal
 * word holds, is still removed. A single unbroken run of 13 to 19 digits that passes the
 * checksum is a card number wherever it stands, so it is removed from a hexadecimal word and
 * from a UUID written without dashes too.
 *
 * The 20 000 identifiers come from the production generator over a seeded random engine and a
 * clock that moves on a fixed step, so every run draws the same ones.
 *
 * Planted violations, each confirmed red then removed: in scrubChain(), stop skipping the groups
 * inside an identifier (the named UUID, the dashless one and the generated ones are cut); skip
 * every group of a chain that holds an identifier, instead of that identifier's own (the card
 * beside a UUID survives); read the letters a to z as hexadecimal in a long word (the card in
 * a long word that holds an x survives); drop the minimum length of a hexadecimal word (the
 * cards touching hexadecimal letters survive); let a group inside an identifier stand
 * alone no longer (the card inside a hexadecimal word survives).
 *
 * @since 0.2.0
 */
final class CardNumbersIdentifiersTest extends TestCase {

	/**
	 * The seed of the generated UUIDs.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SEED = 20261009;

	/**
	 * How many UUIDs the generated test draws.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const UUIDS = 20000;

	/**
	 * How far the generator's clock moves between two UUIDs, in milliseconds: about two hours and twelve minutes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CLOCK_STEP = 7919003;

	/**
	 * A UUID whose digits across the dashes, 9-7242-9777-409181, pass the checksum.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CUT_UUID = '01a12284-eea9-7242-9777-409181ee1128';

	/**
	 * The run of digits of CUT_UUID that a detector reading only digits would remove.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CUT_DIGITS = '972429777409181';

	/**
	 * A published test card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CARD = '4111111111111111';

	/**
	 * Tests that the UUID this scrubber used to cut is kept, in every way a log line holds one.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider identifiers
	 *
	 * @param string $text The text.
	 */
	public function test_an_identifier_whose_digits_pass_the_checksum_is_kept_whole( string $text ): void {
		$this->assertTrue( CardNumbers::passesLuhn( self::CUT_DIGITS ), 'The case must hold a run that a digits-only detector removes.' );
		$this->assertSame( $text, CardNumbers::scrub( $text ) );
		$this->assertFalse( CardNumbers::contains( $text ) );
	}

	/**
	 * Returns texts that hold an identifier whose digits, read alone, look like a card number.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function identifiers(): array {
		return array(
			'the UUID itself' => array( self::CUT_UUID ),
			'in a sentence'   => array( 'intent ' . self::CUT_UUID . ' was captured.' ),
			'in JSON'         => array( '{"intent_uuid":"' . self::CUT_UUID . '","status":"captured"}' ),
			'in capitals'     => array( strtoupper( self::CUT_UUID ) ),
			'after a prefix'  => array( 'ord_' . self::CUT_UUID ),
			'in braces'       => array( '{' . self::CUT_UUID . '}' ),
			'twice'           => array( self::CUT_UUID . ' ' . self::CUT_UUID ),
		);
	}

	/**
	 * Tests that none of 20 000 generated time-ordered UUIDs is changed, alone or in a log line.
	 *
	 * @since 0.2.0
	 */
	public function test_no_generated_time_ordered_uuid_is_changed(): void {
		$changed = array();

		foreach ( self::uuids( self::UUIDS ) as $uuid ) {
			foreach ( array( $uuid, 'intent ' . $uuid . ' captured' ) as $text ) {
				if ( CardNumbers::scrub( $text ) !== $text ) {
					$changed[ $uuid ] = $text . '  ->  ' . CardNumbers::scrub( $text );
				}
			}
		}

		$this->assertSame( array(), $changed, sprintf( '%d of %d UUIDs lost part of themselves, with seed %d.', count( $changed ), self::UUIDS, self::SEED ) );
	}

	/**
	 * Tests that a card number beside an identifier is removed, and the identifier is not.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider cardsBesideIdentifiers
	 *
	 * @param string $text     The text.
	 * @param string $expected What scrub() must return.
	 */
	public function test_a_card_number_beside_an_identifier_is_removed_and_the_identifier_is_not( string $text, string $expected ): void {
		$this->assertSame( $expected, CardNumbers::scrub( $text ) );
		$this->assertTrue( CardNumbers::contains( $text ) );
	}

	/**
	 * Returns texts that hold a card number next to an identifier, with what must remain of each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function cardsBesideIdentifiers(): array {
		$m = CardNumbers::MARKER;
		$u = self::CUT_UUID;

		return array(
			'the card after the UUID'                    => array( "{$u} card 4111 1111 1111 1111", "{$u} card {$m}" ),
			'the card before the UUID'                   => array( "4111 1111 1111 1111 {$u}", "{$m} {$u}" ),
			'a UUID that starts with digits'             => array( '4111 1111 1111 1111 01a12284-eea9-7242-9777-409181ee1128', "{$m} 01a12284-eea9-7242-9777-409181ee1128" ),
			'a UUID that ends with digits'               => array( 'ids 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5678 4111 1111 1111 1111', "ids 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5678 {$m}" ),
			'joined to the UUID by a comma'              => array( "{$u},4111111111111111", "{$u},{$m}" ),
			'joined to the UUID by a dash'               => array( "{$u}-4111-1111-1111-1111", "{$u}-{$m}" ),
			'in JSON'                                    => array( '{"id":"' . $u . '","note":"card 4111-1111-1111-1111"}', '{"id":"' . $u . '","note":"card ' . $m . '"}' ),
			'between two UUIDs'                          => array( "{$u} 4111111111111111 {$u}", "{$u} {$m} {$u}" ),
			'a hexadecimal word that starts with digits' => array( '4111 1111 1111 1111 18' . str_repeat( 'a', 30 ), "{$m} 18" . str_repeat( 'a', 30 ) ),
			'a UUID shape of digits, which is no UUID'   => array( 'id 41111111-1111-1111-1111-000000000000', "id {$m}-1111-000000000000" ),
		);
	}

	/**
	 * Tests that a card number touching letters is removed, even inside a hexadecimal word or a UUID without dashes.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider cardsTouchingLetters
	 *
	 * @param string $text     The text.
	 * @param string $expected What scrub() must return.
	 */
	public function test_a_card_number_touching_letters_or_inside_a_hexadecimal_word_is_removed( string $text, string $expected ): void {
		$this->assertSame( $expected, CardNumbers::scrub( $text ) );
	}

	/**
	 * Returns texts that hold a card number glued to letters, with what must remain of each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function cardsTouchingLetters(): array {
		$m = CardNumbers::MARKER;
		$c = self::CARD;

		return array(
			'after an x'                         => array( "x{$c}", "x{$m}" ),
			'before a g'                         => array( "{$c}g", "{$m}g" ),
			'after a z, before a z'              => array( "z{$c}z", "z{$m}z" ),
			'after a word'                       => array( "card{$c}", "card{$m}" ),
			'after a word that ends in a to f'   => array( "id{$c}", "id{$m}" ),
			'between a to f and another letter'  => array( "{$c}fx", "{$m}fx" ),
			'after an accented letter'           => array( "\u{00E9}{$c}", "\u{00E9}{$m}" ),
			'before a hexadecimal letter'        => array( "{$c}f", "{$m}f" ),
			'after hexadecimal letters'          => array( "cafe{$c}", "cafe{$m}" ),
			'between hexadecimal letters'        => array( "cafe{$c}babe", "cafe{$m}babe" ),
			'in a long word with an x'           => array( 'x' . str_repeat( 'a', 30 ) . '4111 1111 1111 1111', 'x' . str_repeat( 'a', 30 ) . $m ),
			'in a payment reference'             => array( 'payment_ref=4111111111111111aabbccddeeff0011', "payment_ref={$m}aabbccddeeff0011" ),
			'in a 36-character hexadecimal word' => array( "cafe{$c}deadbeefdeadbeef", "cafe{$m}deadbeefdeadbeef" ),
			'in a hexadecimal hash'              => array( 'f' . self::CUT_DIGITS . 'e0abcdef1234567890', "f{$m}e0abcdef1234567890" ),
			'in a UUID written without dashes'   => array( str_replace( '-', '', self::CUT_UUID ), "01a12284eea{$m}ee1128" ),
			'a spaced card, a letter after it'   => array( '4111 1111 1111 1111f', "{$m}f" ),
		);
	}

	/**
	 * Tests, over generated cards and UUIDs, that the card goes and the UUID stays, in every layout.
	 *
	 * @since 0.2.0
	 */
	public function test_no_generated_card_survives_beside_a_generated_uuid_and_the_uuid_stays(): void {
		$uuids = self::uuids( 2000 );

		SeededCases::check(
			self::SEED,
			2000,
			static function ( Randomizer $random ) use ( &$uuids ): array {
				$card      = TestCards::draw( $random, 13, 19 );
				$separator = array( ' ', '-', ' - ' )[ SeededCases::int( $random, 0, 2 ) ];
				$written   = 1 === SeededCases::int( $random, 0, 1 ) ? implode( $separator, str_split( $card, 4 ) ) : $card;
				$uuid      = (string) array_shift( $uuids );
				$text      = array(
					"{$uuid} {$written}",
					"{$written} {$uuid}",
					"{$uuid},{$written}",
					"{$uuid}: {$written}",
					"{$written}; {$uuid}",
					'{"id":"' . $uuid . '","note":"' . $written . '"}',
				)[ SeededCases::int( $random, 0, 5 ) ];

				return array( $card, $uuid, $text );
			},
			function ( string $card, string $uuid, string $text ): void {
				$scrubbed = CardNumbers::scrub( $text );

				$this->assertStringContainsString( $uuid, $scrubbed, 'The UUID lost part of itself.' );
				$this->assertStringContainsString( CardNumbers::MARKER, $scrubbed, 'The card number survived.' );
				$this->assertStringNotContainsString( $card, TestCards::digitsOf( str_replace( $uuid, '', $scrubbed ) ), 'The card number\'s digits survived.' );
			}
		);
	}

	/**
	 * Tests that the logger's fallback keeps a UUID whole, and that no caller has a scrubber of its own.
	 *
	 * The logger and the redactor go through CardNumbers::scrub() and ::contains(), as does the fallback
	 * log, the command line's failure line and doctor's output; the checksum is written once. The
	 * redactor is tested with a UUID in RedactorTest.
	 *
	 * @since 0.2.0
	 */
	public function test_every_caller_goes_through_the_one_detector_and_keeps_a_uuid_whole(): void {
		$written  = array();
		$fallback = new FallbackLog(
			static function ( string $line ) use ( &$written ): void {
				$written[] = $line;
			},
			static function ( callable $work ): void {
				unset( $work );
			}
		);

		$fallback->lost( 'a reason', 'A line could not be written for ' . self::CUT_UUID . '.' );

		$this->assertSame( array( 'A line could not be written for ' . self::CUT_UUID . '. Later losses for the same reason are only counted, and the count is written when the process ends.' ), $written );

		$source = dirname( __DIR__, 4 ) . '/src/';

		foreach ( array( 'Platform/Logging/Redactor.php', 'Platform/Logging/FallbackLog.php', 'Interfaces/Operations/CliCommand.php', 'Platform/Cli/DoctorCommand.php', 'Payment/Application/RefundService.php' ) as $caller ) {
			$this->assertMatchesRegularExpression( '/\bCardNumbers::(?:scrub|contains)\(/', (string) file_get_contents( $source . $caller ), $caller ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
		}

		$checksums = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match( '/function\s+\w*luhn/i', (string) file_get_contents( $file->getPathname() ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
				$checksums[] = $file->getFilename();
			}
		}

		$this->assertSame( array( 'CardNumbers.php' ), $checksums, 'The checksum is written once.' );
	}

	/**
	 * Draws time-ordered UUIDs the way production mints them: the version 7 generator, a seeded random engine and a clock on a fixed step.
	 *
	 * @since 0.2.0
	 *
	 * @param int $count How many.
	 * @return list<string> The UUIDs, in minting order.
	 */
	private static function uuids( int $count ): array {
		$clock     = FrozenClock::at( '2026-10-09 12:00:00.000' );
		$generator = new SystemIdGenerator( $clock, new Randomizer( new Xoshiro256StarStar( self::SEED ) ) );
		$uuids     = array();

		for ( $minted = 0; $minted < $count; $minted++ ) {
			$uuids[] = $generator->generate();

			$clock->setTo( $clock->now()->modify( '+' . self::CLOCK_STEP . ' msec' ) );
		}

		return $uuids;
	}
}
