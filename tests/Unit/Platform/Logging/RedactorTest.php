<?php
/**
 * Tests that the redactor takes its sensitive names from the declarations and makes every value safe to log
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\DataRegistry\Contribution;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\Logging\DeclaredFields;
use SEOCart\Tests\Support\Logging\TestCards;
use SEOCart\Tests\Support\SeededCases;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Redaction is driven by the one classification: nothing is redacted that no declaration names.
 *
 * The registry here plants a table with a `pii` column (`shopper_email`), a `secret` column
 * (`api_token`) and a `pii` column that an operation declares `secret` (`audit_token`), and a
 * `secret` option. The operation registry holds the fixture stock operation, whose `note` is
 * personal data and whose `audit_token` is secret. WordPress is not loaded, so building and
 * using the redactor calls no WordPress function.
 *
 * Planted violations, each confirmed red then removed: in fromDeclarations(), skip the data
 * registry's columns (the planted `pii` column survives); drop the `secret` branch of the
 * fields (the operation's `audit_token` survives); compare keys with their case (a nested
 * `SHOPPER_EMAIL` survives); in withoutLiterals(), drop the quoted-string pattern (the email
 * of a duplicate-key message survives).
 *
 * @since 0.1.0
 */
final class RedactorTest extends TestCase {

	/**
	 * A planted personal-data value.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const EMAIL = 'jane.doe@example.com';

	/**
	 * A planted secret value.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TOKEN = 'planted-secret-token-value';

	/**
	 * The network test card number used throughout.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CARD = '4111111111111111';

	/**
	 * Tests that a declared personal-data name is replaced and a declared secret dropped, at every depth and in any case.
	 *
	 * @since 0.1.0
	 */
	public function test_declared_personal_data_is_replaced_and_secrets_are_dropped(): void {
		$redacted = self::redactor()->context(
			array(
				'shopper_email'       => self::EMAIL,
				'api_token'           => self::TOKEN,
				'seocart_planted_key' => self::TOKEN,
				'note'                => 'Deliver to Jane at the back door.',
				'audit_token'         => self::TOKEN,
				'order_number'        => 'SC-1001',
				'nested'              => array(
					'SHOPPER_EMAIL' => self::EMAIL,
					' Api_Token '   => self::TOKEN,
					'deeper'        => array( 'note' => array( 'even' => 'an array' ) ),
				),
			)
		);

		$this->assertSame(
			array(
				'shopper_email' => Redactor::REDACTED,
				'note'          => Redactor::REDACTED,
				'order_number'  => 'SC-1001',
				'nested'        => array(
					'SHOPPER_EMAIL' => Redactor::REDACTED,
					'deeper'        => array( 'note' => Redactor::REDACTED ),
				),
			),
			$redacted
		);
	}

	/**
	 * Tests that the names come from the declarations and nowhere else.
	 *
	 * The production redactor, built without WordPress from the production data registry and
	 * the fields of every production operation and setting, collected here as the kernel
	 * collects them, redacts the personal-data columns the production tables declare
	 * (`user_id` and `context_json` of the log) and nothing a declaration does not name. A
	 * redactor built from no declaration redacts nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_names_come_from_the_declarations_only(): void {
		$context = array(
			'user_id'       => 7,
			'context_json'  => '{}',
			'shopper_email' => 'Jane Doe',
			'migration_id'  => '20260922_0001_platform_bootstrap',
		);

		$this->assertSame(
			array(
				'user_id'       => Redactor::REDACTED,
				'context_json'  => Redactor::REDACTED,
				'shopper_email' => 'Jane Doe',
				'migration_id'  => '20260922_0001_platform_bootstrap',
			),
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() )->context( $context ),
			'The production redactor redacts what the production declarations name, and only that.'
		);

		$this->assertSame( $context, Redactor::fromDeclarations( new DataRegistry( new CapabilityDeclaration() ) )->context( $context ), 'Without declarations nothing is redacted.' );
	}

	/**
	 * Tests that the logging module reads declared fields, never the application layer's registries.
	 *
	 * Planted violation: import OperationRegistry in Redactor.php again.
	 *
	 * @since 0.1.0
	 */
	public function test_the_logging_module_imports_nothing_from_the_application_layer(): void {
		$imports = array();

		foreach ( PhpSource::files( 'src/Platform/Logging' ) as $path => $source ) {
			if ( 1 === preg_match( '/^use\s+SEOCart\\\\Application\\\\/m', $source ) ) {
				$imports[] = $path;
			}
		}

		$this->assertSame( array(), $imports, 'Platform code builds the redactor from FieldSpecs; the kernel collects them from the registries.' );
	}

	/**
	 * Tests that line() says whether a card number was removed, wherever it was, and only then.
	 *
	 * A value that is replaced or dropped because of its declared name is checked before it
	 * goes, and nothing of it is kept.
	 *
	 * Planted violations: in Redactor::value(), leave an integer card number unflagged; in
	 * Redactor::entries(), replace or drop a value without noteCardNumberIn() (the cards in the
	 * personal-data and secret values are not reported).
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_says_whether_a_card_number_was_removed_from_it(): void {
		$redactor = self::redactor();
		$removed  = static fn( string $message, array $context ): bool => $redactor->line( $message, 500, $context )['card_number_removed'];

		$this->assertTrue( $removed( 'card ' . self::CARD, array() ), 'In the message.' );
		$this->assertTrue( $removed( 'plain', array( 'a' => array( 'b' => '4111-1111-1111-1111' ) ) ), 'In a nested string.' );
		$this->assertTrue( $removed( 'plain', array( 'n' => (int) self::CARD ) ), 'As an integer.' );
		$this->assertTrue( $removed( 'plain', array( 'n' => (float) self::CARD ) ), 'As a float.' );
		$this->assertTrue( $removed( 'plain', array( (int) self::CARD => 'x' ) ), 'As a key.' );
		$this->assertFalse( $removed( 'Order 1234567890123456 placed.', array( 'order' => 1234567890123456 ) ), 'A number that fails the checksum is no card.' );
		$this->assertTrue( $removed( 'plain', array( 'shopper_email' => self::CARD ) ), 'In a personal-data value that is replaced.' );
		$this->assertTrue( $removed( 'plain', array( 'api_token' => array( 'raw' => '4111 1111 1111 1111' ) ) ), 'In a secret value that is dropped.' );
		$this->assertTrue( $removed( 'plain', array( 'audit_token' => (float) self::CARD ) ), 'In a dropped float.' );
		$this->assertFalse( $removed( 'plain', array( 'shopper_email' => 'jane' ) ), 'A replaced value without a card.' );
		$this->assertTrue( $removed( 'api_key=' . self::CARD, array() ), 'In a secret pair removed from free text.' );

		$line = $redactor->line( 'card ' . self::CARD, 500, array() );

		$this->assertSame( 'card ' . CardNumbers::MARKER, $line['message'] );
		$this->assertFalse( $removed( 'plain', array() ), 'Each line starts clean.' );
	}

	/**
	 * Tests that a card number is removed from a string, an integer, a float, a key and a nested value.
	 *
	 * A float is checked in the exact text the JSON encoder writes for it, so a card in its
	 * fraction is found too.
	 *
	 * Planted violation: in Redactor::number(), check `sprintf( '%.17g' )` instead of the
	 * encoder's text (the fraction survives).
	 *
	 * @since 0.1.0
	 */
	public function test_card_numbers_are_removed_from_every_kind_of_value(): void {
		$redacted = self::redactor()->context(
			array(
				'message'           => 'card ' . self::CARD . ' declined',
				'as_integer'        => (int) self::CARD,
				'as_float'          => (float) self::CARD,
				'as_fraction'       => 0.4111111111111111,
				(int) self::CARD    => 'keyed by the card',
				'key ' . self::CARD => 'a key holding the card',
				'nested'            => array( array( array( '4111-1111-1111-1111' ) ) ),
				'order'             => 1234567890123456,
			)
		);

		$json = (string) json_encode( $redacted );

		$this->assertStringNotContainsString( self::CARD, (string) preg_replace( '/\D/', '', $json ) );
		$this->assertSame( CardNumbers::MARKER, $redacted['as_integer'] );
		$this->assertSame( CardNumbers::MARKER, $redacted['as_float'] );
		$this->assertSame( CardNumbers::MARKER, $redacted['as_fraction'] );
		$this->assertSame( 1234567890123456, $redacted['order'], 'A number that fails the checksum is kept.' );
		$this->assertSame( 0.25, self::redactor()->context( array( 'n' => 0.25 ) )['n'], 'An ordinary float is kept.' );
	}

	/**
	 * Tests that a database failure is written with the shape of its statement and without the values the statement and the server quoted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_database_failure_is_written_without_the_values_it_quotes(): void {
		$failure = QueryFailed::fromErrno(
			1062,
			'23000',
			"INSERT INTO `wp_seocart_planted` ( email, card, n ) VALUES ( 'jane.doe@example.com', '4111 1111 1111 1111', 42 )",
			"Duplicate entry 'jane.doe@example.com' for key 'email'",
			false
		);

		$written = self::redactor()->context( array( 'exception' => $failure ) )['exception'];
		$json    = (string) json_encode( $written );

		$this->assertStringNotContainsString( self::EMAIL, $json );
		$this->assertStringNotContainsString( self::CARD, (string) preg_replace( '/\D/', '', $json ) );
		$this->assertSame( 'database.duplicate_key', $written['code'] );
		$this->assertSame(
			array(
				'errno'    => 1062,
				'sqlstate' => '23000',
			),
			$written['context']
		);
		$this->assertSame( 'INSERT INTO `wp_seocart_planted` ( email, card, n ) VALUES ( ?, ?, ? )', $written['previous']['statement'] );
		$this->assertSame( 'Duplicate entry ? for key ?', $written['previous']['server_message'] );
	}

	/**
	 * Tests the free-text policy on an exception, its previous exception and plain text.
	 *
	 * Email addresses become REDACTED; a pair named after declared personal data keeps its name
	 * and loses its value; a pair named after a declared secret goes whole, in any case and in
	 * any of the three forms. `api_key` is a secret field the test declares, `note` the fixture
	 * operation's personal-data field and `audit_token` its secret. Nothing else is touched.
	 *
	 * Planted violations: in Redactor::freeText(), skip the email pattern (the addresses
	 * survive); skip the secret pairs (`api_key` and its value survive).
	 *
	 * @since 0.1.0
	 */
	public function test_free_text_loses_email_addresses_and_declared_pairs(): void {
		$redactor = self::redactor();
		$failure  = new \RuntimeException(
			'email=jane@example.com api_key=sk_planted_value note: first-floor "audit_token":"tok-1" user id 42 kept',
			0,
			new \RuntimeException( 'caused by shopper_email=someone@example.org and API_KEY: "quoted secret"' )
		);
		$written  = $redactor->context( array( 'exception' => $failure ) )['exception'];

		$this->assertSame( 'email=' . Redactor::REDACTED . '  note: ' . Redactor::REDACTED . '  user id 42 kept', $written['message'] );
		$this->assertSame( 'caused by shopper_email=' . Redactor::REDACTED . ' and ', $written['previous']['message'] );
		$this->assertSame( '{"order":"SC-1001","note":"' . Redactor::REDACTED . '"}', $redactor->text( '{"order":"SC-1001","note":"Ring twice"}' ), 'JSON in free text keeps its shape.' );
		$this->assertSame( 'footnote=kept; notes: kept', $redactor->text( 'footnote=kept; notes: kept' ), 'Only a whole declared name is a pair.' );
		$this->assertSame( 'Jane Doe lives at 1 Main Street', $redactor->text( 'Jane Doe lives at 1 Main Street' ), 'Nothing else in prose is guessed at.' );

		$json = (string) json_encode( $written );

		foreach ( array( 'jane@example.com', 'sk_planted_value', 'first-floor', 'tok-1', 'someone@example.org', 'quoted secret', 'api_key', 'audit_token' ) as $gone ) {
			$this->assertStringNotContainsStringIgnoringCase( $gone, $json );
		}
	}

	/**
	 * Tests that a declared pair is found however its name is written: percent-encoded, in single quotes, as PHP prints an array, or as the last part of a bracketed name.
	 *
	 * The redactor is the production one, and `active_data_key` is the production secret that
	 * holds the data key. Each form is checked through text() and inside an exception message.
	 *
	 * Planted violations: in Redactor::declaredName(), skip the percent-decoding (the encoded
	 * names survive); match bare words only (the quoted and bracketed names survive).
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_pair_is_found_however_its_name_is_written(): void {
		$redactor = Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() );
		$written  = array(
			'percent-encoded'             => array( 'key active%5Fdata%5Fkey=k1:0123abcd:raw:SECRETVALUE end', 'key  end' ),
			'encoded twice, upper case'   => array( 'ACTIVE%255FDATA%255FKEY=SECRETVALUE end', ' end' ),
			'single-quoted'               => array( "{'active_data_key': 'k1:SECRETVALUE', 'kept': 'yes'}", "{, 'kept': 'yes'}" ),
			'var_export()'                => array( "array ( 'active_data_key' => 'k1:SECRETVALUE', )", 'array ( , )' ),
			'print_r()'                   => array( "Array\n(\n    [active_data_key] => k1:SECRETVALUE\n)", "Array\n(\n    \n)" ),
			'a bracketed form field'      => array( 'seocart_data_keys[active_data_key]=SECRETVALUE&x=1', 'seocart_data_keys&x=1' ),
			'an encoded form field'       => array( 'seocart_data_keys%5Bactive_data_key%5D=k1%3ASECRETVALUE&x=1', '&x=1' ),
			'inside a kept value'         => array( 'next=/x?active_data_key=SECRETVALUE&y=1', 'next=/x?&y=1' ),
			'names that only look alike'  => array( 'inactive_data_key=kept data_key=kept', 'inactive_data_key=kept data_key=kept' ),
			'a pair inside a removed one' => array( 'active_data_key="SECRETVALUE note=x" kept', ' kept' ),
		);

		foreach ( $written as $form => list( $text, $expected ) ) {
			$this->assertSame( $expected, $redactor->text( $text ), "Through text(): {$form}." );

			$message = $redactor->context( array( 'exception' => new \RuntimeException( 'Could not seal. ' . $text ) ) )['exception']['message'];

			$this->assertSame( 'Could not seal. ' . $expected, $message, "Inside an exception message: {$form}." );
			$this->assertStringNotContainsString( 'SECRETVALUE', $message );
		}

		// Planted violation: make PAIR_VALUE's quantifiers greedy (the engine runs out of stack, and the text becomes the placeholder).
		$this->assertSame( ' kept', $redactor->text( 'active_data_key="' . str_repeat( 'SECRETVALUE ', 2000 ) . '" kept' ), 'A long quoted value is read, and removed whole.' );
	}

	/**
	 * Tests that checking a replaced or dropped value for a card number is bounded, and a value past the bound is reported unchecked without being read.
	 *
	 * Two dropped values past the line's check budget are not read at all: a million groups of
	 * one digit, and a hundred thousand, which the detector would split into a hundred thousand
	 * groups and check for seconds. A smaller value is still read in full, and a card at its end
	 * is found; the budget is the line's, so two values that fit it apart but not together leave
	 * the second unchecked; and an array of many empty values runs it out as well.
	 *
	 * Planted violation: in Redactor::checks(), read the text whatever its length (the hundred
	 * thousand groups are built and checked: the time and the memory peak pass their bounds).
	 *
	 * @since 0.1.0
	 */
	public function test_checking_a_dropped_value_is_bounded(): void {
		$redactor = Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() );

		foreach ( array( 100000, 1000000 ) as $groups ) {
			$huge = str_repeat( '1 ', $groups );

			memory_reset_peak_usage();

			$before  = memory_get_peak_usage();
			$started = hrtime( true );
			$line    = $redactor->line( 'plain', 500, array( 'active_data_key' => $huge ) );
			$seconds = ( hrtime( true ) - $started ) / 1e9;

			$this->assertLessThan( 0.25, $seconds, "The value of {$groups} groups past the bound is not read." );
			$this->assertLessThan( 4 * 1024 * 1024, memory_get_peak_usage() - $before, 'The value is not split into its digit groups: a hundred thousand groups take several megabytes, and PHP builds differ by about one.' );
			$this->assertSame( array(), $line['context'], 'The secret is dropped.' );
			$this->assertTrue( $line['card_number_unchecked'] );
			$this->assertFalse( $line['card_number_removed'] );
		}

		$fits = str_repeat( 'x', 60000 );
		$flag = static fn( array $context ): array => array_intersect_key( $redactor->line( 'plain', 500, $context ), array_flip( array( 'card_number_removed', 'card_number_unchecked' ) ) );

		$this->assertSame(
			array(
				'card_number_removed'   => false,
				'card_number_unchecked' => false,
			),
			$flag( array( 'active_data_key' => $fits ) ),
			'A value within the bound is read in full.'
		);
		$this->assertSame(
			array(
				'card_number_removed'   => true,
				'card_number_unchecked' => false,
			),
			$flag( array( 'active_data_key' => $fits . ' ' . self::CARD ) ),
			'A card at its end is found.'
		);
		$this->assertTrue(
			$flag(
				array(
					'active_data_key'   => $fits,
					'retiring_data_key' => $fits,
				)
			)['card_number_unchecked'],
			'The budget is the line\'s.'
		);
		$this->assertTrue( $flag( array( 'active_data_key' => array_fill( 0, 100000, '' ) ) )['card_number_unchecked'], 'Many empty values run it out too.' );
	}

	/**
	 * Tests that a card nested deep inside a dropped value is found within the depth limit, and a subtree past it is reported unchecked.
	 *
	 * Planted violation: in Redactor::noteCardNumberIn(), return quietly past MAX_DEPTH (the
	 * card twenty arrays down raises nothing).
	 *
	 * @since 0.1.0
	 */
	public function test_a_card_nested_deep_in_a_dropped_value_is_reported(): void {
		$redactor = Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() );
		$nest     = static function ( int $levels ): array {
			$value = array( 'inner' => self::CARD );

			for ( $level = 1; $level < $levels; $level++ ) {
				$value = array( 'inner' => $value );
			}

			return $value;
		};

		$seven  = $redactor->line( 'plain', 500, array( 'active_data_key' => $nest( 7 ) ) );
		$twenty = $redactor->line( 'plain', 500, array( 'active_data_key' => $nest( 20 ) ) );

		$this->assertTrue( $seven['card_number_removed'], 'Seven arrays down is within the limit: the card is found.' );
		$this->assertFalse( $twenty['card_number_removed'] );
		$this->assertTrue( $twenty['card_number_unchecked'], 'Twenty arrays down is past it: reported unchecked.' );
		$this->assertSame( array(), $seven['context'] );
		$this->assertSame( array(), $twenty['context'] );
		$this->assertSame(
			array( false, false ),
			array_values( array_slice( $redactor->line( 'plain', 500, array( 'active_data_key' => array( 'a' => array( 'b' => 'k1:0123abcd' ) ) ) ), 2 ) ),
			'A shallow value without a card raises nothing.'
		);
	}

	/**
	 * Tests that an exception is written with its class, message and trace, and never an argument value.
	 *
	 * @since 0.1.0
	 */
	public function test_an_exception_is_written_without_argument_values(): void {
		$written = array();

		try {
			self::fail_with( 'the-argument-value-' . self::EMAIL );
		} catch ( \RuntimeException $failure ) {
			$written = self::redactor()->context( array( 'exception' => $failure ) )['exception'];
		}

		$json = (string) json_encode( $written );

		$this->assertSame( \RuntimeException::class, $written['class'] );
		$this->assertSame( 'Failed on purpose.', $written['message'] );
		$this->assertStringContainsString( 'fail_with()', $written['trace'][0] );
		$this->assertStringNotContainsString( 'the-argument-value', $json );
		$this->assertLessThanOrEqual( 10, count( $written['trace'] ) );
	}

	/**
	 * Tests that objects are summarized and sizes are bounded.
	 *
	 * @since 0.1.0
	 */
	public function test_objects_are_summarized_and_sizes_are_bounded(): void {
		$deep = 'bottom';

		for ( $level = 0; $level < 10; $level++ ) {
			$deep = array( 'down' => $deep );
		}

		$redacted = self::redactor()->context(
			array(
				'object'   => new \stdClass(),
				'enum'     => Level::Warning,
				'when'     => new \DateTimeImmutable( '2026-09-23 12:00:00.5', new \DateTimeZone( 'UTC' ) ),
				'json'     => new class() implements \JsonSerializable {
					/**
					 * Returns a value holding declared personal data.
					 *
					 * @return array<string, string> The value.
					 */
					public function jsonSerialize(): array {
						return array( 'shopper_email' => 'jane.doe@example.com' );
					}
				},
				'deep'     => $deep,
				'wide'     => range( 1, 150 ),
				'long'     => str_repeat( 'a', 5000 ),
				'nan'      => NAN,
				'resource' => STDIN,
			)
		);

		$this->assertSame( '[object stdClass]', $redacted['object'] );
		$this->assertSame( 'warning', $redacted['enum'] );
		$this->assertSame( '2026-09-23T12:00:00.500000+00:00', $redacted['when'] );
		$this->assertSame( array( 'shopper_email' => Redactor::REDACTED ), $redacted['json'] );
		$this->assertStringContainsString( 'nested too deep', (string) json_encode( $redacted['deep'] ) );
		$this->assertCount( 101, $redacted['wide'], 'One hundred entries, and one saying how many were left out.' );
		$this->assertSame( '50 more entries were left out', $redacted['wide']['[left out]'] );
		$this->assertLessThanOrEqual( Redactor::STRING_LENGTH, mb_strlen( $redacted['long'] ) );
		$this->assertStringEndsWith( '[cut]', $redacted['long'] );
		$this->assertSame( 'NAN', $redacted['nan'] );
		$this->assertSame( '[resource (stream)]', $redacted['resource'] );
	}

	/**
	 * Tests that a text is never longer than its limit, and that a cut never leaves a card number or the start of one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cut_text_keeps_its_limit_and_holds_no_card_number(): void {
		$redactor = self::redactor();

		SeededCases::check(
			20260924,
			2000,
			static function ( Randomizer $random ): array {
				// Groups of digits that each start with the fake prefix, so any card-shaped run is an obvious fake.
				$text = '';

				for ( $token = 0; $token < 12; $token++ ) {
					$text .= 0 === $token % 2 ? TestCards::PREFIX . substr( (string) SeededCases::int( $random, 100000000, 999999999 ), 0, SeededCases::int( $random, 1, 9 ) ) : array( ' ', '-', ' - ', 'x', 'é' )[ SeededCases::int( $random, 0, 4 ) ];
				}

				return array( $text, SeededCases::int( $random, 30, 70 ) );
			},
			function ( string $text, int $limit ) use ( $redactor ): void {
				$result = $redactor->text( $text, $limit );

				$this->assertLessThanOrEqual( $limit, mb_strlen( $result ) );
				$this->assertFalse( CardNumbers::contains( $result ), 'A card-shaped run reached the output.' );
				$this->assertSame( 1, preg_match( '//u', $result ), 'The result is valid UTF-8.' );
			}
		);

		$this->assertSame( '?(', $redactor->text( "\xC3\x28" ), 'In invalid UTF-8, every byte outside ASCII becomes a question mark.' );
	}

	/**
	 * Returns the redactor over the planted registry and the fixture operation.
	 *
	 * @since 0.1.0
	 *
	 * @return Redactor The redactor.
	 */
	private static function redactor(): Redactor {
		$operations = new OperationRegistry();
		FixtureStockOperation::register( $operations );

		return Redactor::fromDeclarations(
			new DataRegistry(
				new CapabilityDeclaration(),
				new Contribution(
					tables: array(
						new TableDefinition(
							'planted_customers',
							'Planted',
							'A table the redaction test plants.',
							MutationPattern::Config,
							array(
								new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
								new ColumnSpec( 'shopper_email', 'varchar(191)', Classification::Pii, 'Planted personal data.', erasure: ColumnSpec::ERASE_DESTROY ),
								new ColumnSpec( 'api_token', 'char(64)', Classification::Secret, 'A planted secret.' ),
								new ColumnSpec( 'audit_token', 'char(64)', Classification::Pii, 'Personal here, secret in the operation.', erasure: ColumnSpec::ERASE_DESTROY ),
							),
							array( 'id' ),
							array(),
							array(),
							'permanent',
							array()
						),
					),
					options: array( new OptionDefinition( 'seocart_planted_key', 'Planted', 'A planted secret option.', false, Classification::Secret ) )
				)
			),
			...array_merge(
				DeclaredFields::ofOperations( $operations ),
				array(
					new FieldSpec(
						name: 'api_key',
						type: FieldType::String,
						description: 'A secret field the redaction test plants.',
						label: static fn(): string => 'API key',
						example: 'planted',
						privacy: Privacy::Secret
					),
				)
			)
		);
	}

	/**
	 * Throws, so the exception's trace holds a frame with an argument.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string $argument A value that must never reach the log.
	 * @return never
	 */
	private static function fail_with( string $argument ): never {
		unset( $argument );

		throw new \RuntimeException( 'Failed on purpose.' );
	}
}
