<?php
/**
 * Tests that the codes event delivery only reports never collide with an error-table code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\ReportCode as DatabaseReportCode;
use SEOCart\Platform\Events\ReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Support\ErrorCatalogs;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Keeps the Events module's reporter vocabulary in one list, apart from the error table.
 *
 * A code in ReportCode reaches the log, `outbox.last_error` and doctor, never a client. If one
 * spelled a code of the error table, a reader of the log could not tell a reported condition
 * from a raised error. The table is composed from every error catalog under src/, so a
 * catalog added later is checked too. Every `events.` code the module's source spells must be
 * a case of the enum, so the enum stays the one list.
 *
 * Planted violation: give ReportCode::DrainFailed the value 'database.transaction_lost'.
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
		$catalogs = array_keys( ErrorCatalogs::under( 'src' ) );

		$this->assertNotSame( array(), $catalogs, 'The search for error catalogs found none; it cannot be trusted.' );

		$taken = array_map( static fn( DatabaseReportCode $code ): string => $code->value, DatabaseReportCode::cases() );

		foreach ( ErrorTable::compose( ...$catalogs )->definitions() as $row ) {
			$taken[] = (string) $row->code()->value;
		}

		$reported = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );

		$this->assertSame( array(), array_values( array_intersect( $reported, $taken ) ), 'A reported code of event delivery spells a code of the error table or of another module.' );
	}

	/**
	 * Tests the shape of every reported code, and that ReportCode is not itself an error catalog.
	 *
	 * @since 0.1.0
	 */
	public function test_reported_codes_are_events_codes_outside_the_catalogs(): void {
		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'Reported codes are never raised, so they are not a catalog.' );

		foreach ( ReportCode::cases() as $code ) {
			$this->assertMatchesRegularExpression( '/^events\.[a-z][a-z0-9_]*$/', $code->value );
		}
	}

	/**
	 * Tests that the module spells no reported code outside the enum.
	 *
	 * @since 0.1.0
	 */
	public function test_every_events_code_in_the_module_is_a_case(): void {
		$cases   = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );
		$spelled = array();

		foreach ( PhpSource::files( 'src/Platform/Events' ) as $source ) {
			preg_match_all( '/[\'"](events\.[a-z0-9_]+)[\'"]/', $source, $matches );

			$spelled = array_merge( $spelled, $matches[1] );
		}

		$this->assertSame( array(), array_values( array_diff( array_unique( $spelled ), $cases ) ), 'A reported code is spelled outside ReportCode.' );
		$this->assertNotSame( array(), $spelled, 'The enum itself spells every code, so the search must find them.' );
	}
}
