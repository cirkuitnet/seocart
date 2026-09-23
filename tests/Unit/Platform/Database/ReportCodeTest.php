<?php
/**
 * Tests that the codes the Database module only reports never collide with an error-table code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\ReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Support\ErrorCatalogs;

/**
 * Keeps the reporter vocabulary apart from the error table.
 *
 * A code in ReportCode reaches the log, the `migrations` table and doctor, never a client. If
 * one spelled the same string as a code in the error table, a reader of the log could not
 * tell a reported condition from a raised error. The table is composed here from every error
 * catalog under src/, so a catalog added later is checked too.
 *
 * Planted violation: give ReportCode::AfterRollbackFailed the value 'database.transaction_lost'.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class ReportCodeTest extends TestCase {

	/**
	 * Tests that no reported code is a code of the error table.
	 *
	 * @since 0.1.0
	 */
	public function test_no_reported_code_is_an_error_table_code(): void {
		$catalogs = array_keys( ErrorCatalogs::under( 'src' ) );

		$this->assertContains( DatabaseError::class, $catalogs, 'The search for catalogs did not find the Database module\'s own; it cannot be trusted to find the others.' );

		$table = array();

		foreach ( ErrorTable::compose( ...$catalogs )->definitions() as $row ) {
			$table[] = (string) $row->code()->value;
		}

		$reported = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );

		$this->assertSame( array(), array_values( array_intersect( $reported, $table ) ), 'A reported code spells a code of the error table.' );
	}

	/**
	 * Tests the shape of every reported code, and that ReportCode is not itself an error catalog.
	 *
	 * @since 0.1.0
	 */
	public function test_reported_codes_are_database_codes_outside_the_catalogs(): void {
		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'Reported codes are never raised, so they are not a catalog.' );

		foreach ( ReportCode::cases() as $code ) {
			$this->assertMatchesRegularExpression( '/^database\.[a-z][a-z0-9_]*$/', $code->value );
		}
	}
}
