<?php
/**
 * Tests the privacy handling a column declares: how the eraser and the exporter treat a pii column
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;

/**
 * A `pii` column says how the privacy tools treat it, on the column itself; no other column may.
 *
 * A `pii` column cannot be built without it. Each refused declaration names its planted
 * violation in ColumnSpec::checkPrivacyHandling().
 *
 * @since 0.1.0
 */
final class ColumnPrivacyHandlingTest extends TestCase {

	/**
	 * Tests that a pii column declares its erasure and is exported unless it says why not.
	 *
	 * @since 0.1.0
	 */
	public function test_a_pii_column_is_erased_as_declared_and_exported_by_default(): void {
		foreach ( array( ColumnSpec::ERASE_DESTROY, ColumnSpec::ERASE_ANONYMIZE ) as $erasure ) {
			$column = new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Where order mail goes.', erasure: $erasure );

			$this->assertSame( $erasure, $column->erasure() );
			$this->assertNull( $column->retainedBecause() );
			$this->assertTrue( $column->isExported(), 'A pii column is exported unless it says why not.' );
			$this->assertNull( $column->notExportedBecause() );
		}
	}

	/**
	 * Tests that a retained pii column carries the reason the eraser reports with it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_retained_pii_column_says_why(): void {
		$column = new ColumnSpec( 'presented_tax_id', 'varchar(64)', Classification::Pii, 'The tax id the buyer gave.', erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: 'Legal obligation: a tax record.' );

		$this->assertSame( ColumnSpec::ERASE_RETAIN, $column->erasure() );
		$this->assertSame( 'Legal obligation: a tax record.', $column->retainedBecause() );
		$this->assertTrue( $column->isExported() );
	}

	/**
	 * Tests that a pii column the exporter leaves out says why.
	 *
	 * @since 0.1.0
	 */
	public function test_a_pii_column_left_out_of_the_export_says_why(): void {
		$column = new ColumnSpec( 'key_hash', 'char(64)', Classification::Pii, 'An HMAC of the client identity.', erasure: ColumnSpec::ERASE_DESTROY, notExportedBecause: 'A keyed hash tells its subject nothing.' );

		$this->assertSame( ColumnSpec::ERASE_DESTROY, $column->erasure() );
		$this->assertFalse( $column->isExported() );
		$this->assertSame( 'A keyed hash tells its subject nothing.', $column->notExportedBecause() );
	}

	/**
	 * Tests that a column of every other class carries no privacy handling and is never exported.
	 *
	 * @since 0.1.0
	 */
	public function test_other_columns_carry_no_privacy_handling(): void {
		foreach ( array( Classification::Public, Classification::Secret, Classification::Financial ) as $classification ) {
			$column = new ColumnSpec( 'value', 'varchar(20)', $classification, 'A value.' );

			$this->assertNull( $column->erasure(), $classification->value );
			$this->assertNull( $column->retainedBecause(), $classification->value );
			$this->assertNull( $column->notExportedBecause(), $classification->value );
			$this->assertFalse( $column->isExported(), $classification->value . ' is never exported: the exporter carries personal data only.' );
		}
	}

	/**
	 * Lists privacy handling that must be refused, each with a fragment of the message.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{\Closure(): mixed, string}> The attempts.
	 */
	public static function refusedHandling(): array {
		return array(
			// Planted violation: the class check removed.
			'erasure on a public column'                => array(
				static fn() => new ColumnSpec( 'sku', 'varchar(64)', Classification::Public, 'The SKU.', erasure: ColumnSpec::ERASE_DESTROY ),
				'Column sku is public, not pii',
			),
			'an export reason on a financial column'    => array(
				static fn() => new ColumnSpec( 'total_minor', 'bigint', Classification::Financial, 'The total.', notExportedBecause: 'Not personal.' ),
				'Column total_minor is financial, not pii',
			),
			'a retain reason on a secret column'        => array(
				static fn() => new ColumnSpec( 'token_hash', 'char(64)', Classification::Secret, 'A token hash.', retainedBecause: 'Kept.' ),
				'Column token_hash is secret, not pii',
			),
			// Planted violation: the missing-erasure check removed.
			'a pii column without handling'             => array(
				static fn() => new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Email.' ),
				'Column email is pii and does not say how the privacy tools treat it',
			),
			'a pii column with a reason but no erasure' => array(
				static fn() => new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Email.', notExportedBecause: 'Not needed.' ),
				'Column email is pii and does not say how the privacy tools treat it',
			),
			// Planted violation: the erasure vocabulary check removed.
			'an erasure that is not one of three'       => array(
				static fn() => new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Email.', erasure: 'delete' ),
				'erasure "delete" is not one of destroy, anonymize, retain',
			),
			// Planted violation: the retain-and-reason check removed.
			'retain without a reason'                   => array(
				static fn() => new ColumnSpec( 'tax_id', 'varchar(64)', Classification::Pii, 'Tax id.', erasure: ColumnSpec::ERASE_RETAIN ),
				'a reason for retaining goes with ERASE_RETAIN, and only with it',
			),
			'a retain reason on a destroyed column'     => array(
				static fn() => new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Email.', erasure: ColumnSpec::ERASE_DESTROY, retainedBecause: 'Kept.' ),
				'a reason for retaining goes with ERASE_RETAIN, and only with it',
			),
			// Planted violation: the blank-reason check removed.
			'retain with a blank reason'                => array(
				static fn() => new ColumnSpec( 'tax_id', 'varchar(64)', Classification::Pii, 'Tax id.', erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: ' ' ),
				'a privacy reason cannot be blank',
			),
			'a blank reason for not exporting'          => array(
				static fn() => new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Email.', erasure: ColumnSpec::ERASE_DESTROY, notExportedBecause: '' ),
				'a privacy reason cannot be blank',
			),
		);
	}

	/**
	 * Tests that incomplete or misplaced privacy handling cannot be declared.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedHandling
	 *
	 * @param \Closure $build   Declares the column.
	 * @param string   $message A fragment of the message that must explain the refusal.
	 */
	public function test_misplaced_or_incomplete_handling_is_refused( \Closure $build, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$build();
	}
}
