<?php
/**
 * Tests CodedException, the typed exception base
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorTableException;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureError;
use SEOCart\Tests\Unit\Support\Error\Fixtures\FixtureException;
use SEOCart\Tests\Unit\Support\Error\Fixtures\MissingRowError;

/**
 * Proves that a coded exception carries its code and exactly the context its row declares.
 *
 * @since 0.1.0
 */
final class CodedExceptionTest extends TestCase {

	/**
	 * Tests what the exception carries.
	 *
	 * @since 0.1.0
	 */
	public function test_it_carries_the_code_and_the_context(): void {
		$cause     = new \RuntimeException( 'The cause.' );
		$exception = CodedException::because(
			FixtureError::Insufficient,
			array(
				'available' => 1,
				'requested' => 3,
			),
			$cause
		);

		$this->assertInstanceOf( \RuntimeException::class, $exception );
		$this->assertSame( FixtureError::Insufficient, $exception->errorCode() );
		$this->assertSame(
			array(
				'available' => 1,
				'requested' => 3,
			),
			$exception->context(),
			'The keys may come in any order; the context keeps the order it was given.'
		);
		$this->assertSame( 'fixture.insufficient', $exception->getMessage(), 'The message is the code: never translated, safe to log.' );
		$this->assertSame( 0, $exception->getCode() );
		$this->assertSame( $cause, $exception->getPrevious() );
	}

	/**
	 * Tests that because() creates the class it is called on, so a module can catch its own type.
	 *
	 * @since 0.1.0
	 */
	public function test_a_subclass_creates_itself(): void {
		$exception = FixtureException::because( FixtureError::NotFound );

		$this->assertInstanceOf( FixtureException::class, $exception );
		$this->assertInstanceOf( CodedException::class, $exception );
		$this->assertSame( array(), $exception->context() );
	}

	/**
	 * Tests that the context must carry exactly the placeholders the row declares.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_contexts_with_the_wrong_keys
	 *
	 * @param array<string, int> $context A context whose keys do not match the row.
	 */
	public function test_the_context_must_carry_exactly_the_declared_placeholders( array $context ): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'must carry exactly the placeholders its row declares: [available, requested]' );

		CodedException::because( FixtureError::Insufficient, $context );
	}

	/**
	 * Provides contexts with missing or extra keys.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, int>}> Test cases.
	 */
	public static function data_contexts_with_the_wrong_keys(): array {
		return array(
			'none'          => array( array() ),
			'one missing'   => array( array( 'requested' => 3 ) ),
			'one extra'     => array(
				array(
					'requested' => 3,
					'available' => 1,
					'sku'       => 7,
				),
			),
			'a misspelling' => array(
				array(
					'requested' => 3,
					'availble'  => 1,
				),
			),
		);
	}

	/**
	 * Tests that a context value must be an int, a string or a bool.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_values_that_are_not_scalars
	 *
	 * @param mixed $value A value that must be refused.
	 */
	public function test_a_context_value_must_be_an_int_a_string_or_a_bool( $value ): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'must be an int, a string or a bool' );

		CodedException::because(
			FixtureError::Insufficient,
			array(
				'requested' => $value,
				'available' => 1,
			)
		);
	}

	/**
	 * Provides values an error context must not hold.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{mixed}> Test cases.
	 */
	public static function data_values_that_are_not_scalars(): array {
		return array(
			'an object' => array( new \stdClass() ),
			'an array'  => array( array( 3 ) ),
			'null'      => array( null ),
			'a float'   => array( 3.5 ),
		);
	}

	/**
	 * Tests that booleans and strings are accepted as context values.
	 *
	 * @since 0.1.0
	 */
	public function test_strings_and_booleans_are_accepted(): void {
		$exception = CodedException::because(
			FixtureError::Insufficient,
			array(
				'requested' => 'three',
				'available' => false,
			)
		);

		$this->assertSame( 'three', $exception->context()['requested'] );
		$this->assertFalse( $exception->context()['available'] );
	}

	/**
	 * Tests that a code without a row cannot be raised.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_without_a_row_cannot_be_raised(): void {
		$this->expectException( ErrorTableException::class );
		$this->expectExceptionMessage( 'The error code missing.absent has 0 rows in its catalog' );

		CodedException::because( MissingRowError::Absent );
	}
}
