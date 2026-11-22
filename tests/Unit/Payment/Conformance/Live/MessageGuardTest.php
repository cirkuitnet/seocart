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
 * A message refuses an argument shaped like an address with a query, an authorization header, a card number (13 to 19 digits that pass the Luhn check, in a run with single spaces or in groups separated by spaces or hyphens) or a credential of the declaration's provider, and redact() blanks the same shapes out of any text; ids, words, uuids, nonces, dates, commits and runs of digits that are no card pass.
 *
 * Every canary of a key is built when the test runs, so no key-shaped text is kept in the repository.
 *
 * Planted violations, each shown red and removed: the guard built without the declaration's
 * shapes: the fixture provider's canary is formatted into the message and left in the text; the
 * card-number check taken out of shapeOf() and redact(): a published test card's number is
 * printed by a guard whose declaration states no shape; the card shape without its Luhn check: an
 * id with a long run of digits that no card has is refused; the grouped card numbers left out of
 * shapeOf() and redact(): a test card written with hyphens is printed.
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

		try {
			// The canary is made up for this run: a message that carried it may show it.
			$this->fail( sprintf( 'A message carried %1$s: %2$s', $kind, $guard->say( 'The answer was %s.', $canary ) ) );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( $kind, $refused->getMessage() );
			$this->assertStringNotContainsString( $canary, $refused->getMessage() );
		}

		$this->assertSame( 'before [redacted: ' . $kind . '] after', $guard->redact( 'before ' . $canary . ' after' ) );
		$this->assertSame( $kind, $guard->shapeOf( $canary ) );
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

		$this->assertSame( 'card [redacted: a card number] declined', $guard->redact( 'card 4012888888881881 declined' ) );
		$this->assertSame( MessageGuard::CARD, $guard->shapeOf( '4012 8888 8888 1881' ) );
		$this->assertSame( 'an [redacted: an authorization header] header', $guard->redact( 'an Bearer UnitTestAccessTokenOfTheFixturesOnly header' ) );
	}

	/**
	 * Tests that a card number written in groups, with hyphens or with spaces and hyphens between them, is blanked and refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider groupedCards
	 *
	 * @param string $written The card's number as written.
	 */
	public function test_a_card_number_in_groups_is_redacted( string $written ): void {
		$guard = StatedDeclaration::with( array( 'shapes' => array() ) )->guard();

		$this->assertSame( 'paid with [redacted: a card number] today', $guard->redact( 'paid with ' . $written . ' today' ) );
		$this->assertSame( MessageGuard::CARD, $guard->shapeOf( $written ) );
	}

	/**
	 * Returns published test cards' numbers written in groups.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The number as written; by name.
	 */
	public static function groupedCards(): array {
		return array(
			'four groups, hyphens'        => array( '4111-1111-1111-1111' ),
			'spaces and hyphens'          => array( '4012 8888-8888 1881' ),
			'four, six and five digits'   => array( '3782-822463-10005' ),
			'another network, hyphenated' => array( '6011-1111-1111-1117' ),
		);
	}

	/**
	 * Tests that values whose digits are joined by hyphens, dots or colons, but are no card, are printed as they are.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider hyphenatedValues
	 *
	 * @param string $value The value.
	 */
	public function test_hyphenated_values_that_are_no_card_are_printed( string $value ): void {
		$guard = StatedDeclaration::fixture()->guard();

		$this->assertNull( $guard->shapeOf( $value ), $value . ' is printed.' );
		$this->assertSame( 'x ' . $value . ' y', $guard->redact( 'x ' . $value . ' y' ) );
	}

	/**
	 * Returns values with digits joined by separators that are no card number.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The value; by name.
	 */
	public static function hyphenatedValues(): array {
		return array(
			'a uuid'                 => array( '019b76da-a800-7328-ab32-c90d699f9327' ),
			'a uuid of zeros'        => array( '0199c0de-0000-7000-8000-00000000a001' ),
			'a uuid of digits'       => array( '12345678-1234-1234-1234-123456789012' ),
			'a run nonce'            => array( 'r45012335462.1' ),
			'a date-time'            => array( '2026-11-22T07:01:33Z' ),
			'a date and a time'      => array( '2026-11-22 07:01:33' ),
			'a provider\'s event id' => array( 'WH-2WR32451HC0233532-67976317FL4543714' ),
			'groups no card has'     => array( '4242-42424242424-2' ),
		);
	}

	/**
	 * Tests that an authorization header is blanked whatever the letter case of its name and whatever its scheme, with the credentials after it, plain or in JSON.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider authorizationHeaders
	 *
	 * @param string $text     The text.
	 * @param string $redacted The text as the guard leaves it.
	 * @param string $secret   The credentials, which must not be left.
	 */
	public function test_an_authorization_header_of_any_case_and_scheme_is_redacted( string $text, string $redacted, string $secret ): void {
		$guard = StatedDeclaration::with( array( 'shapes' => array() ) )->guard();

		$this->assertSame( $redacted, $guard->redact( $text ) );
		$this->assertStringNotContainsString( $secret, $guard->redact( $text ) );
		$this->assertSame( 'an authorization header', $guard->shapeOf( $text ) );
	}

	/**
	 * Returns authorization headers with credentials made up as the test runs.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string}> The text, the text redacted, and the credentials; by name.
	 */
	public static function authorizationHeaders(): array {
		$secret = bin2hex( random_bytes( 12 ) );

		return array(
			'lower case, as HTTP/2 writes it' => array( 'header authorization: bearer ' . $secret, 'header [redacted: an authorization header]', $secret ),
			'another scheme'                  => array( 'header Authorization: Token ' . $secret, 'header [redacted: an authorization header]', $secret ),
			'a name in capitals, no scheme'   => array( 'AUTHORIZATION=' . $secret, '[redacted: an authorization header]', $secret ),
			'in JSON'                         => array( '{"Authorization":"Token ' . $secret . '","accept":"*/*"}', '{"[redacted: an authorization header]","accept":"*/*"}', $secret ),
			'in JSON inside a string'         => array( '"{\"authorization\":\"Basic ' . $secret . '\"}"', '"{\"[redacted: an authorization header]\"}"', $secret ),
		);
	}

	/**
	 * Tests that a text that only uses the word authorization, or holds it in a longer name, is printed as it is.
	 *
	 * @since 0.2.0
	 */
	public function test_the_word_authorization_alone_is_printed(): void {
		$guard = StatedDeclaration::fixture()->guard();

		foreach ( array( 'The authorization was voided before its capture.', 'Authorization declined by the issuer', 'reauthorization: none', 'authorization_id: 42' ) as $text ) {
			$this->assertNull( $guard->shapeOf( $text ), $text . ' is printed.' );
			$this->assertSame( $text, $guard->redact( $text ) );
		}
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
