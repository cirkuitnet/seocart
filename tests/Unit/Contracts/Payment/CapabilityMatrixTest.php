<?php
/**
 * Tests the capability matrix: which operations a gateway supports, by currency and account country
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A matrix allows an operation only in a cell that declares it: the row of the currency and the account's own country, or else the currency's `*` row; and its rows are well formed.
 *
 * Planted violation, shown red and removed: in MatrixRow::covers(), let a `*` row cover every
 * currency: the matrix then allows a currency it has no row for.
 *
 * @since 0.2.0
 */
final class CapabilityMatrixTest extends TestCase {

	/**
	 * Tests that the account's own row wins over the currency's `*` row, which answers for every other country and for none.
	 *
	 * @since 0.2.0
	 */
	public function test_the_cell_is_the_country_row_or_else_the_any_country_row(): void {
		$matrix = new CapabilityMatrix(
			array(
				new MatrixRow( Currency::of( 'USD' ), 'US', array_merge( Operations::REQUIRED, array( Operations::PARTIAL_REFUND, Operations::SCA ) ) ),
				new MatrixRow( Currency::of( 'USD' ), MatrixRow::ANY_COUNTRY, Operations::REQUIRED ),
				new MatrixRow( Currency::of( 'EUR' ), 'DE', Operations::REQUIRED ),
			)
		);
		$usd    = Currency::of( 'USD' );

		$this->assertTrue( $matrix->allows( Operations::PARTIAL_REFUND, $usd, 'US' ), 'The account\'s own row.' );
		$this->assertFalse( $matrix->allows( Operations::PARTIAL_REFUND, $usd, 'GB' ), 'Another country takes the * row, which does not declare it.' );
		$this->assertTrue( $matrix->allows( Operations::CAPTURE, $usd, 'GB' ) );
		$this->assertTrue( $matrix->allows( Operations::CAPTURE, $usd, null ), 'An account with no country set takes the * row.' );
		$this->assertTrue( $matrix->allows( Operations::REFUND, Currency::of( 'EUR' ), 'DE' ) );
		$this->assertFalse( $matrix->allows( Operations::REFUND, Currency::of( 'EUR' ), 'FR' ), 'No row for that country and no * row.' );
		$this->assertFalse( $matrix->allows( Operations::REFUND, Currency::of( 'EUR' ), null ) );
		$this->assertFalse( $matrix->allows( Operations::AUTHORIZE, Currency::of( 'GBP' ), 'US' ), 'No row for the currency at all.' );
		$this->assertSame( array( 'USD', 'EUR' ), $matrix->currencies() );
	}

	/**
	 * Tests that a payment is available by the matrix only where its cell declares `authorize`, read with the account's country.
	 *
	 * @since 0.2.0
	 */
	public function test_availability_is_the_cell_of_the_currency_and_the_account_country(): void {
		$matrix  = new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), 'US', Operations::REQUIRED ) ) );
		$context = static fn( string $currency, ?string $country ): AvailabilityContext => new AvailabilityContext( Currency::of( $currency ), Money::of( 1000, Currency::of( $currency ) ), 'GB', 'storefront', Mode::Live, array( GatewayDescriptor::ACCOUNT_COUNTRY => $country ) );

		$this->assertTrue( $matrix->available( $context( 'USD', 'US' ) ) );
		$this->assertFalse( $matrix->available( $context( 'USD', 'GB' ) ), 'The billing country is not the account\'s.' );
		$this->assertFalse( $matrix->available( $context( 'USD', null ) ) );
		$this->assertFalse( $matrix->available( $context( 'EUR', 'US' ) ) );
	}

	/**
	 * Tests that a row refuses a country that is not one, operations that are not names of the vocabulary or repeat, and a row without every required operation.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider malformedRows
	 *
	 * @param string $country    The account country.
	 * @param array  $operations The operations.
	 *
	 * @phpstan-param list<string> $operations
	 */
	public function test_a_malformed_row_is_refused( string $country, array $operations ): void {
		$this->expectException( \InvalidArgumentException::class );

		new MatrixRow( Currency::of( 'USD' ), $country, $operations );
	}

	/**
	 * Rows a gateway may not declare.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: list<string>}> The country and the operations.
	 */
	public static function malformedRows(): array {
		return array(
			'a lower-case country'         => array( 'us', Operations::REQUIRED ),
			'a three-letter country'       => array( 'USA', Operations::REQUIRED ),
			'an operation not in the list' => array( 'US', array_merge( Operations::REQUIRED, array( 'void_after_capture' ) ) ),
			'an operation twice'           => array( 'US', array_merge( Operations::REQUIRED, array( Operations::CAPTURE ) ) ),
			'no query'                     => array( 'US', array_values( array_diff( Operations::REQUIRED, array( Operations::QUERY ) ) ) ),
		);
	}

	/**
	 * Tests that a matrix has at least one row, and no cell twice.
	 *
	 * @since 0.2.0
	 */
	public function test_a_matrix_has_rows_and_each_cell_once(): void {
		try {
			new CapabilityMatrix( array() );
			$this->fail( 'A matrix without rows was accepted.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertStringContainsString( 'at least one row', $refused->getMessage() );
		}

		$this->expectException( \InvalidArgumentException::class );

		new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), 'US', Operations::REQUIRED ), new MatrixRow( Currency::of( 'USD' ), 'US', Operations::REQUIRED ) ) );
	}

	/**
	 * Tests that the required operations are names of the vocabulary, and the vocabulary has no void after a capture.
	 *
	 * @since 0.2.0
	 */
	public function test_the_required_operations_are_in_the_vocabulary(): void {
		$this->assertSame( array(), array_diff( Operations::REQUIRED, Operations::ALL ) );
		$this->assertSame( Operations::ALL, array_values( array_unique( Operations::ALL ) ) );
		$this->assertNotContains( 'void_after_capture', Operations::ALL );
	}
}
