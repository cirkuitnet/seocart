<?php
/**
 * Tests the guard of what a live workflow prints
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\MessageGuard;

/**
 * A message refuses an argument shaped like an address with a query, an authorization header, a card number (a run of 13 to 19 digits that passes the Luhn check) or a credential of the declaration's provider, and redact() blanks the same shapes out of any text; ids, words, uuids, commits and runs of digits that are no card pass.
 *
 * Every canary of a key is built when the test runs, so no key-shaped text is kept in the repository.
 *
 * Planted violations, each shown red and removed: the guard built without the declaration's
 * shapes: the fixture provider's canary is formatted into the message and left in the text; the
 * card-number check taken out of shapeOf() and redact(): a published test card's number is
 * printed by a guard whose declaration states no shape; the card shape without its Luhn check: an
 * id with a long run of digits that no card has is refused.
 *
 * @since 0.2.0
 */
final class MessageGuardTest extends TestCase {

	/**
	 * Tests that a message of ids, counts and words is formatted as asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_message_of_ids_and_counts_is_formatted(): void {
		$guard = StatedDeclaration::fixture()->guard();

		$this->assertSame( 'fp_3Abc answered requires_capture after 2 tries.', $guard->say( '%1$s answered %2$s after %3$d tries.', 'fp_3Abc', 'requires_capture', 2 ) );
		$this->assertNull( $guard->shapeOf( 'https://example.org/' ) );
	}

	/**
	 * Tests that each canary is refused as an argument, by its kind and never its value, and blanked out of a text.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider canaries
	 *
	 * @param string $kind   What the canary is shaped like.
	 * @param string $canary The canary.
	 */
	public function test_a_credential_is_refused_and_redacted( string $kind, string $canary ): void {
		$guard = StatedDeclaration::fixture()->guard();

		$this->assertSame( $kind, $guard->shapeOf( $canary ) );

		try {
			$guard->say( 'The answer was %s.', $canary );
			$this->fail( sprintf( 'A message carried %s.', $kind ) );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( $kind, $refused->getMessage() );
			$this->assertStringNotContainsString( $canary, $refused->getMessage() );
		}

		$redacted = $guard->redact( 'before ' . $canary . ' after' );

		$this->assertStringNotContainsString( $canary, $redacted );
		$this->assertSame( 'before [redacted: ' . $kind . '] after', $redacted );
	}

	/**
	 * Returns a canary of each shape, made up as the test runs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> Each shape's name and canary, by name.
	 */
	public static function canaries(): array {
		$random = static fn( int $length ): string => substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $length );

		return array(
			'the provider\'s test key' => array( 'a fixture key', 'fpk_test_' . $random( 24 ) ),
			'the provider\'s live key' => array( 'a fixture key', 'fpk_live_' . $random( 40 ) ),
			'a bearer header'          => array( 'an authorization header', 'Bearer ' . $random( 24 ) ),
			'a basic header'           => array( 'an authorization header', 'Basic ' . base64_encode( $random( 12 ) . ':' . $random( 12 ) ) ),
			'an address with a query'  => array( 'an address with a query', 'https://hooks.example/3d_secure?client_secret=' . $random( 12 ) ),
			'an escaped address'       => array( 'an address with a query', 'https:\/\/bank.example\/auth?token=' . $random( 24 ) ),
			'a test card, spaced'      => array( MessageGuard::CARD, '4012 8888 8888 1881' ),
			'a test card, run in text' => array( MessageGuard::CARD, '40128888888818810' ),
		);
	}

	/**
	 * Tests that an address json_encode() escaped, with the backslash before each slash, is blanked whole, its query's opaque value with it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_json_escaped_address_is_blanked_whole(): void {
		$opaque  = bin2hex( random_bytes( 12 ) );
		$encoded = json_encode( array( 'next' => 'https://bank.example/auth?token=' . $opaque ) );

		$this->assertStringContainsString( 'https:\/\/bank.example\/auth?token=', (string) $encoded );

		$redacted = StatedDeclaration::fixture()->guard()->redact( 'The answer was ' . $encoded . '.' );

		$this->assertStringNotContainsString( $opaque, $redacted );
		$this->assertStringNotContainsString( 'bank.example', $redacted );
		$this->assertStringStartsWith( 'The answer was {"next":"', $redacted );
	}

	/**
	 * Tests that a card number is refused and blanked by a guard whose declaration states no shape of its own: the check is no provider's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_is_guarded_with_no_shape_declared(): void {
		$guard = StatedDeclaration::with( array( 'shapes' => array() ) )->guard();

		$this->assertSame( MessageGuard::CARD, $guard->shapeOf( '4012 8888 8888 1881' ) );
		$this->assertSame( 'card [redacted: a card number] declined', $guard->redact( 'card 4012888888881881 declined' ) );
		$this->assertSame( 'an [redacted: an authorization header] header', $guard->redact( 'an Bearer UnitTestAccessTokenOfTheFixturesOnly header' ) );
	}

	/**
	 * Tests ids and words: printed as they are.
	 *
	 * @since 0.2.0
	 */
	public function test_ids_and_words_are_printed(): void {
		$guard = StatedDeclaration::fixture()->guard();

		foreach ( array( '5O190127TN364715T', '0C527818AF292391J', 'WH-2WR32451HC0233532-67976317FL4543714', '0199c0de-0000-7000-8000-00000000a001', '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'PAYER_CANNOT_PAY', '1234567890123', 'fpk_test_short' ) as $value ) {
			$this->assertNull( $guard->shapeOf( $value ), $value . ' is printed.' );
			$this->assertSame( 'The value ' . $value . '.', $guard->say( 'The value %s.', $value ) );
			$this->assertSame( $value, $guard->shown( $value ) );
		}
	}

	/**
	 * Tests an id whose digits read as a card: withheld when shown.
	 *
	 * @since 0.2.0
	 */
	public function test_an_id_whose_digits_read_as_a_card_is_withheld(): void {
		$guard = StatedDeclaration::fixture()->guard();

		$this->assertSame( '(withheld: shaped like a card number)', $guard->shown( '0VF52814937998046' ) );
		$this->assertSame( '5O190127TN364715T', $guard->shown( '5O190127TN364715T' ) );
	}

	/**
	 * Tests that the declaration's guard is one guard, and that a shape it cannot apply is refused rather than skipped.
	 *
	 * @since 0.2.0
	 */
	public function test_the_guard_is_built_once_and_fails_closed(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( $declaration->guard(), $declaration->guard() );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'The shape of a broken key is no regular expression' );

		new MessageGuard( StatedDeclaration::with( array( 'shapes' => array( 'a broken key' => '/[unclosed/' ) ) ) );
	}
}
