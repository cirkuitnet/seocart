<?php
/**
 * Tests that the codes the logging module writes lines under collide with nothing and are spelled nowhere else
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Logging;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\ReportCode as DatabaseReportCode;
use SEOCart\Platform\Events\ReportCode as EventsReportCode;
use SEOCart\Platform\Logging\ReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Keeps the logging module's codes in one list, apart from the error table and the other modules' reports.
 *
 * A reader of the log must be able to tell a line the logging module wrote from an error a
 * client saw, and from a report of another module. The error table is composed from every
 * catalog under src/, so one added later is checked too; the other modules' report codes are
 * their enums. Every `operations.` or `logging.` code spelled in the module's source must be a
 * case of the enum.
 *
 * Planted violation: give ReportCode::InvalidCode the value 'events.listener_failed'.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class ReportCodeTest extends TestCase {

	/**
	 * Tests that no code of the module is a code of the error table or of another module's reports.
	 *
	 * @since 0.1.0
	 */
	public function test_no_code_collides_with_the_error_table_or_another_report(): void {
		$catalogs = array();

		foreach ( PhpSource::files( 'src' ) as $source ) {
			if ( ! str_contains( $source, 'enum ' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				if ( enum_exists( $class ) && is_subclass_of( $class, ErrorCode::class ) ) {
					$catalogs[] = $class;
				}
			}
		}

		$this->assertNotSame( array(), $catalogs, 'The search for error catalogs found none; it cannot be trusted.' );

		$taken = array_merge(
			array_map( static fn( DatabaseReportCode $code ): string => $code->value, DatabaseReportCode::cases() ),
			array_map( static fn( EventsReportCode $code ): string => $code->value, EventsReportCode::cases() )
		);

		foreach ( ErrorTable::compose( ...$catalogs )->definitions() as $row ) {
			$taken[] = (string) $row->code()->value;
		}

		$ours = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );

		$this->assertSame( array(), array_values( array_intersect( $ours, $taken ) ), 'A code of the logging module spells a code of the error table or of another module.' );
		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'These codes are never raised, so they are not a catalog.' );
	}

	/**
	 * Tests that the module spells none of its codes outside the enum.
	 *
	 * @since 0.1.0
	 */
	public function test_every_code_in_the_module_is_a_case(): void {
		$cases   = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );
		$spelled = array();

		foreach ( PhpSource::files( 'src/Platform/Logging' ) as $source ) {
			preg_match_all( '/[\'"]((?:operations|logging)\.[a-z0-9_]+)[\'"]/', $source, $matches );

			$spelled = array_merge( $spelled, $matches[1] );
		}

		$this->assertNotSame( array(), $spelled, 'The enum itself spells every code, so the search must find them.' );
		$this->assertSame( array(), array_values( array_diff( array_unique( $spelled ), $cases ) ), 'A code is spelled outside ReportCode.' );
	}
}
