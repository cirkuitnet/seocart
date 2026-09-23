<?php
/**
 * Tests the three generated reference documents: abilities, commands and error codes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Rest\ErrorShape;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStoreError;
use SEOCart\Tools\Docs\AbilitiesReference;
use SEOCart\Tools\Docs\CliReference;
use SEOCart\Tools\Docs\ErrorsReference;
use SEOCart\Tools\Docs\FieldDocs;
use SEOCart\Tools\Docs\Generator;

/**
 * Each document, generated for the fixture operation, is compared with a committed file under
 * Fixtures/ that was reviewed by hand; each is also generated for an empty registry.
 *
 * The committed files are Prettier-formatted markdown, like the documents themselves, so a change
 * to a generator that Prettier would reformat fails `npm run format:check` as well as this test.
 * When a generator changes on purpose, write its new output into the committed file and review
 * the difference.
 *
 * @since 0.1.0
 */
final class ReferenceDocumentsTest extends TestCase {

	/**
	 * Sets up Brain Monkey: gettext returns its text, as it does when no translation is loaded.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Provides each generator for the fixture registry, with its committed expected output.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{callable(OperationRegistry, ErrorTable): Generator, string, string}> Factory, expected file, target.
	 */
	public static function generators(): array {
		return array(
			'abilities' => array(
				static fn( OperationRegistry $registry, ErrorTable $errors ): Generator => new AbilitiesReference( $registry, $errors ),
				'abilities-fixture.md',
				'docs/reference/abilities.md',
			),
			'commands'  => array(
				static fn( OperationRegistry $registry, ErrorTable $errors ): Generator => new CliReference( $registry, $errors ),
				'cli-fixture.md',
				'docs/reference/cli.md',
			),
			'errors'    => array(
				static fn( OperationRegistry $registry, ErrorTable $errors ): Generator => new ErrorsReference( $errors ),
				'errors-fixture.md',
				'docs/reference/errors.md',
			),
		);
	}

	/**
	 * Tests each document for the fixture operation against its reviewed, committed form.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider generators
	 *
	 * @param callable $factory  Builds the generator.
	 * @param string   $expected The committed expected output, under Fixtures/.
	 * @param string   $target   The document the generator owns.
	 *
	 * @phpstan-param callable(OperationRegistry, ErrorTable): Generator $factory
	 */
	public function test_the_fixture_is_documented_as_reviewed( callable $factory, string $expected, string $target ): void {
		$generator = $factory( self::fixtureRegistry(), self::fixtureErrors() );
		$result    = $generator->generate( '' );

		$this->assertSame( $target, $generator->target() );
		$this->assertSame( array(), $generator->expectedSkips() );
		$this->assertSame( array(), $result->skipped );
		$this->assertSame(
			(string) file_get_contents( __DIR__ . '/Fixtures/' . $expected ),
			$result->content,
			'The document for the fixture differs from Fixtures/' . $expected . '. If the change is intended, write the new output there and review the difference.'
		);
	}

	/**
	 * Tests that an empty registry is documented as such, with no section.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_registry_is_documented_as_empty(): void {
		$abilities = ( new AbilitiesReference( new OperationRegistry(), self::fixtureErrors() ) )->generate( '' )->content;
		$commands  = ( new CliReference( new OperationRegistry(), self::fixtureErrors() ) )->generate( '' )->content;

		$this->assertStringEndsWith( "\n\nSEOCart has no abilities yet.\n", $abilities );
		$this->assertStringNotContainsString( '## ', $abilities );
		$this->assertStringEndsWith( "\n\nSEOCart has no operation commands yet.\n", $commands );
		$this->assertStringNotContainsString( '## ', $commands );
	}

	/**
	 * Tests that the error reference states the shape once, from ErrorShape, and marks every internal row.
	 *
	 * @since 0.1.0
	 */
	public function test_the_error_reference_states_the_shape_and_marks_internal_rows(): void {
		$errors = ( new ErrorsReference( ErrorTable::compose( SupportError::class, DatabaseError::class ) ) )->generate( '' )->content;

		$this->assertSame( 1, substr_count( $errors, '## The shape of an error' ) );

		foreach ( ErrorShape::members() as $name => $description ) {
			$this->assertSame( 1, substr_count( $errors, '- `' . $name . '`: ' . $description ), "The member {$name} is not stated exactly once." );
		}

		$sections = array();

		foreach ( array_slice( explode( "\n## `", $errors ), 1 ) as $section ) {
			$sections[ strstr( $section, '`', true ) ] = $section;
		}

		$this->assertCount( 1 + count( DatabaseError::cases() ), $sections );

		foreach ( $sections as $code => $section ) {
			$this->assertSame( str_starts_with( $code, 'database.' ), str_contains( $section, "\n- Internal: " ), "{$code} is marked internal exactly when its row is." );
		}
	}

	/**
	 * Tests that a code any write may raise is marked in the error reference and listed for every changing operation.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_any_write_may_raise_is_marked_and_listed(): void {
		$errors = ( new ErrorsReference( ErrorTable::compose( SupportError::class, FixtureStoreError::class ) ) )->generate( '' )->content;

		$this->assertStringContainsString( "## `fixture_store.unavailable`\n\n- HTTP status: 503\n- Any write: every operation that changes the store may answer with this code, whether or not it lists it.\n", $errors );
		$this->assertSame( 1, substr_count( $errors, '- Any write: ' ), 'Only the row that says so is marked.' );

		$table    = ErrorTable::compose( SupportError::class, FixtureStockError::class, FixtureStoreError::class );
		$commands = ( new CliReference( self::fixtureRegistry(), $table ) )->generate( '' )->content;

		$this->assertStringContainsString( '- Error codes: `fixture_stock.insufficient` (409), `fixture_store.unavailable` (503)', $commands );
		$this->assertSame( array( FixtureStockError::Insufficient, FixtureStoreError::Unavailable ), FieldDocs::errorCodes( FixtureStockOperation::definition(), $table ) );
	}

	/**
	 * Tests that the secret output field is never documented, and personal data says who may see it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_privacy_of_each_field_is_documented(): void {
		$abilities = ( new AbilitiesReference( self::fixtureRegistry(), self::fixtureErrors() ) )->generate( '' )->content;

		$this->assertStringNotContainsString( 'audit_token', $abilities, 'A secret output field is never returned, so it is not documented as output.' );
		$this->assertStringContainsString( 'Personal data: returned only to a user who holds the capability `seocart_view_customer_pii`.', $abilities );
	}

	/**
	 * Tests that a generator fails, rather than skips, when a declared code has no row in the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_code_without_a_row_fails_the_generator(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'fixture_stock.insufficient is not in this error table' );

		( new CliReference( self::fixtureRegistry(), ErrorTable::compose( SupportError::class ) ) )->generate( '' );
	}

	/**
	 * Tests the wording of each field constraint the fixture does not use.
	 *
	 * @since 0.1.0
	 */
	public function test_every_field_constraint_is_worded(): void {
		$integer = static fn( ?int $minimum, ?int $maximum ): FieldSpec => new FieldSpec(
			name: 'count',
			type: FieldType::Integer,
			description: 'A count.',
			label: static fn(): string => 'Count',
			example: 1,
			minimum: $minimum,
			maximum: $maximum
		);

		$this->assertSame( 'An integer of at least 1.', FieldDocs::describe( $integer( 1, null ), false ) );
		$this->assertSame( 'An integer of at most 9.', FieldDocs::describe( $integer( null, 9 ), false ) );
		$this->assertSame( 'An integer.', FieldDocs::describe( $integer( null, null ), false ) );
		$this->assertSame(
			'Text. Secret: accepted, never returned.',
			FieldDocs::describe(
				new FieldSpec(
					name: 'api_key',
					type: FieldType::String,
					description: 'A key.',
					label: static fn(): string => 'Key',
					example: 'key',
					privacy: Privacy::Secret
				),
				false
			)
		);
	}

	/**
	 * Returns a registry holding the fixture operation.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	private static function fixtureRegistry(): OperationRegistry {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		return $registry;
	}

	/**
	 * Returns the error table the fixture's documents are generated with.
	 *
	 * @since 0.1.0
	 *
	 * @return ErrorTable The shared kernel's catalog and the fixture's.
	 */
	private static function fixtureErrors(): ErrorTable {
		return ErrorTable::compose( SupportError::class, FixtureStockError::class );
	}
}
