<?php
/**
 * Tests the error table mechanism: rows, composition and rendering
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Error\ErrorTableException;
use SEOCart\Support\Money;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\DoubleRowError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\DuplicateCodeError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\ForeignRowError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\IntegerBackedError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\MalformedCodeError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\MissingRowError;

/**
 * Proves that the table is built only from well-formed catalogs, translates nothing while it
 * is built, and renders a message only when asked.
 *
 * Each catalog under Fixtures/ breaks exactly one rule and stays as a permanent planted
 * violation of it.
 *
 * @since 0.1.0
 */
final class ErrorTableTest extends TestCase {

	/**
	 * Sets up Brain Monkey, which stands in for WordPress's gettext functions.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
	 * Tests that a table holds every row of its catalogs, in code order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_table_holds_every_row_of_its_catalogs_in_code_order(): void {
		$table = ErrorTable::compose( SupportError::class, FixtureError::class );
		$codes = array_map( static fn( ErrorDefinition $row ): string => (string) $row->code()->value, $table->definitions() );

		$this->assertSame( array( 'currency.unknown', 'fixture.insufficient', 'fixture.not_found' ), $codes );
		$this->assertSame( 409, $table->definitionFor( FixtureError::Insufficient )->httpStatus() );
		$this->assertSame( array( 'requested', 'available' ), $table->definitionFor( FixtureError::Insufficient )->placeholders() );
		$this->assertSame( FixtureError::NotFound, $table->definitionFor( FixtureError::NotFound )->code() );
	}

	/**
	 * Tests that composing the table translates nothing, and that rendering is where translation happens.
	 *
	 * @since 0.1.0
	 */
	public function test_composing_translates_nothing_and_rendering_translates(): void {
		$translated = 0;

		Functions\when( '__' )->alias(
			static function ( string $text ) use ( &$translated ): string {
				++$translated;

				return $text;
			}
		);

		$table = ErrorTable::compose( SupportError::class, FixtureError::class );

		$this->assertSame( 0, $translated, 'Composing the table called a gettext function.' );
		$this->assertSame(
			'XYZ is not a currency code that SEOCart supports.',
			$table->definitionFor( SupportError::UnknownCurrency )->render( array( 'currency' => 'XYZ' ) )
		);
		$this->assertSame( 1, $translated, 'Rendering translates the message, once.' );
	}

	/**
	 * Tests that rendering fills numbered placeholders from the context, in any order a translation uses.
	 *
	 * @since 0.1.0
	 */
	public function test_render_fills_numbered_placeholders_from_the_context(): void {
		$row = ErrorDefinition::of( FixtureError::Insufficient );

		$this->assertSame(
			'You asked for 3, but only 1 are in stock (100%).',
			$row->render(
				array(
					'requested' => 3,
					'available' => 1,
				)
			)
		);

		$reordered = new ErrorDefinition( FixtureError::Insufficient, 409, static fn(): string => 'Nur %2$s von %1$s sind vorrätig.', array( 'requested', 'available' ) );

		$this->assertSame(
			'Nur 1 von 3 sind vorrätig.',
			$reordered->render(
				array(
					'requested' => 3,
					'available' => 1,
				)
			),
			'A translation may reorder the placeholders.'
		);
		$this->assertSame( 'Nothing was found.', ErrorDefinition::of( FixtureError::NotFound )->render( array() ) );
	}

	/**
	 * Tests that a code declared by two catalogs fails the build of the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_declared_by_two_catalogs_fails_the_build(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'The error code fixture.not_found is declared twice, by ' . FixtureError::class . ' and by ' . DuplicateCodeError::class . '.' );

		ErrorTable::compose( FixtureError::class, DuplicateCodeError::class );
	}

	/**
	 * Tests that composing the same catalog twice is the same mistake.
	 *
	 * @since 0.1.0
	 */
	public function test_a_catalog_composed_twice_fails_the_build(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'is declared twice' );

		ErrorTable::compose( SupportError::class, SupportError::class );
	}

	/**
	 * Tests that a code without a row fails the build of the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_without_a_row_fails_the_build(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'The error code missing.absent has no row in its catalog ' . MissingRowError::class . '.' );

		ErrorTable::compose( MissingRowError::class );
	}

	/**
	 * Tests that a row for another catalog's code fails the build of the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_for_another_catalogs_code_fails_the_build(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'declares a row for fixture.not_found, a code of ' . FixtureError::class );

		ErrorTable::compose( ForeignRowError::class );
	}

	/**
	 * Tests that two rows for one code fail the build of the table.
	 *
	 * @since 0.1.0
	 */
	public function test_two_rows_for_one_code_fail_the_build(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'The error code double.twice has more than one row' );

		ErrorTable::compose( DoubleRowError::class );
	}

	/**
	 * Tests that a class that is not an ErrorCode enum is refused as a catalog.
	 *
	 * @since 0.1.0
	 */
	public function test_a_class_that_is_not_a_catalog_is_refused(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( Money::class . ' is not an error catalog' );

		ErrorTable::compose( SupportError::class, Money::class );
	}

	/**
	 * Tests that a code whose catalog was not composed is not in the table.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_outside_the_table_is_refused(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'The error code currency.unknown is not in this error table' );

		ErrorTable::compose( FixtureError::class )->definitionFor( SupportError::UnknownCurrency );
	}

	/**
	 * Tests that a row must answer with a client or server error status.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_statuses_that_are_not_errors
	 *
	 * @param int $status An HTTP status outside 400 to 599.
	 */
	public function test_a_row_with_a_status_outside_4xx_and_5xx_is_refused( int $status ): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'answers with HTTP status ' . $status );

		new ErrorDefinition( FixtureError::NotFound, $status, static fn(): string => 'Nothing was found.' );
	}

	/**
	 * Provides statuses that are not client or server errors.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int}> Test cases.
	 */
	public static function data_statuses_that_are_not_errors(): array {
		return array(
			'success'                  => array( 200 ),
			'redirect'                 => array( 302 ),
			'just below client errors' => array( 399 ),
			'just above server errors' => array( 600 ),
		);
	}

	/**
	 * Tests that a code must be written module.reason.
	 *
	 * @since 0.1.0
	 */
	public function test_a_malformed_code_is_refused(): void {
		$cases = array_merge( MalformedCodeError::cases(), IntegerBackedError::cases() );

		foreach ( $cases as $code ) {
			try {
				new ErrorDefinition( $code, 400, static fn(): string => 'Malformed.' );
				$this->fail( 'A row was accepted for the code ' . $code->value . '.' );
			} catch ( ErrorTableException $refused ) {
				$this->assertStringContainsString( 'is not written module.reason', $refused->getMessage() );
			}
		}

		$this->assertCount( 6, $cases );
	}

	/**
	 * Tests that placeholder names are snake_case words, each named once.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_invalid_placeholders
	 *
	 * @param string[] $placeholders Placeholder names that must be refused.
	 */
	public function test_invalid_placeholder_names_are_refused( array $placeholders ): void {
		$this->expectException( ErrorTableException::class );

		new ErrorDefinition( FixtureError::Insufficient, 409, static fn(): string => '%1$s %2$s', $placeholders );
	}

	/**
	 * Provides invalid placeholder lists.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>}> Test cases.
	 */
	public static function data_invalid_placeholders(): array {
		return array(
			'upper case'    => array( array( 'Requested', 'available' ) ),
			'leading digit' => array( array( '1st', 'available' ) ),
			'a space'       => array( array( 'in stock', 'available' ) ),
			'empty'         => array( array( '', 'available' ) ),
			'named twice'   => array( array( 'available', 'available' ) ),
		);
	}

	/**
	 * Tests finding a code's row in its own catalog, and the two ways that can fail.
	 *
	 * @since 0.1.0
	 */
	public function test_of_finds_exactly_one_row_in_the_codes_catalog(): void {
		$this->assertSame( 404, ErrorDefinition::of( FixtureError::NotFound )->httpStatus() );

		foreach ( array( MissingRowError::Absent, DoubleRowError::Twice ) as $code ) {
			try {
				ErrorDefinition::of( $code );
				$this->fail( 'A row was found for ' . $code->value . '.' );
			} catch ( ErrorTableException $refused ) {
				$this->assertStringContainsString( 'it must have exactly one', $refused->getMessage() );
			}
		}
	}
}
