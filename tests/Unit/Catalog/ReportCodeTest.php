<?php
/**
 * Tests that the codes the catalog only reports never collide with an error-table code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Platform\Database\ReportCode as DatabaseReportCode;
use SEOCart\Platform\Events\ReportCode as EventsReportCode;
use SEOCart\Platform\Jobs\ReportCode as JobsReportCode;
use SEOCart\Platform\Logging\ReportCode as LoggingReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Support\ErrorCatalogs;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Keeps the catalog's reporter vocabulary in one list, apart from the error table.
 *
 * A code in ReportCode reaches the log, never a client. If one spelled a code of the error
 * table, a reader of the log could not tell a reported condition from a raised error. Every
 * `catalog.` code the module's source spells is either a row of the error table or a case of
 * the enum, so the enum stays the one list of the rest.
 *
 * Planted violations, each confirmed to fail a test here:
 * - Give ReportCode::RestoreFailed the value 'catalog.write_conflict': the first test fails.
 * - In SaveProduct::takeBackMark(), report `'catalog.restore_lost'` instead of the enum case:
 *   the last test fails.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class ReportCodeTest extends TestCase {

	/**
	 * Tests that no reported code is a code of the error table or of another module's reporter.
	 *
	 * @since 0.1.0
	 */
	public function test_no_reported_code_is_an_error_table_code(): void {
		$taken = self::errorTableCodes();

		foreach ( array( DatabaseReportCode::class, EventsReportCode::class, JobsReportCode::class, LoggingReportCode::class ) as $enum ) {
			foreach ( $enum::cases() as $code ) {
				$taken[] = $code->value;
			}
		}

		$this->assertSame( array(), array_values( array_intersect( self::reported(), $taken ) ), 'A reported code of the catalog spells a code of the error table or of another module.' );
	}

	/**
	 * Tests the shape of every reported code, and that ReportCode is not itself an error catalog.
	 *
	 * @since 0.1.0
	 */
	public function test_reported_codes_are_catalog_codes_outside_the_catalogs(): void {
		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'Reported codes are never raised, so they are not a catalog.' );

		foreach ( ReportCode::cases() as $code ) {
			$this->assertMatchesRegularExpression( '/^catalog\.[a-z][a-z0-9_]*$/', $code->value );
		}
	}

	/**
	 * Tests that the module spells no `catalog.` code that is neither an error-table row nor a case of the enum.
	 *
	 * @since 0.1.0
	 */
	public function test_every_catalog_code_in_the_module_is_declared(): void {
		$declared = array_merge( self::errorTableCodes(), self::reported() );
		$spelled  = array();

		foreach ( PhpSource::files( 'src/Catalog' ) as $source ) {
			preg_match_all( '/[\'"](catalog\.[a-z0-9_]+)[\'"]/', $source, $matches );

			$spelled = array_merge( $spelled, $matches[1] );
		}

		$this->assertNotSame( array(), $spelled, 'The catalogs spell their codes, so the search must find them.' );
		$this->assertSame( array(), array_values( array_diff( array_unique( $spelled ), $declared ) ), 'A catalog code is spelled outside the error table and ReportCode.' );
	}

	/**
	 * Returns the catalog's reported codes.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The codes.
	 */
	private static function reported(): array {
		return array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );
	}

	/**
	 * Returns every code of the error table composed from the catalogs under src/.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The codes.
	 */
	private static function errorTableCodes(): array {
		$catalogs = array_keys( ErrorCatalogs::under( 'src' ) );
		$codes    = array();

		foreach ( ErrorTable::compose( ...$catalogs )->definitions() as $row ) {
			$codes[] = (string) $row->code()->value;
		}

		return $codes;
	}
}
