<?php
/**
 * Tests that the codes the Jobs module reports never collide with an error-table code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\ReportCode as DatabaseReportCode;
use SEOCart\Platform\Events\ReportCode as EventsReportCode;
use SEOCart\Platform\Jobs\ReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Keeps the Jobs module's reporter vocabulary in one list, apart from the error table.
 *
 * A code in ReportCode reaches the log and doctor, never a client. If one spelled a code of
 * the error table or of another module's reporter, a reader of the log could not tell them
 * apart. The table is composed from every error catalog under src/. Every `jobs.` code the
 * module's source spells must be a case of the enum, so the enum stays the one list.
 *
 * Planted violations: give ReportCode::TickFailed the value 'events.drain_failed'; spell
 * `'jobs.planted'` in a string in JobRunner.
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
	public function test_no_reported_code_is_taken_elsewhere(): void {
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

		$reported = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );

		$this->assertSame( array(), array_values( array_intersect( $reported, $taken ) ), 'A code the Jobs module reports is already a code of the error table or of another module.' );

		foreach ( $reported as $code ) {
			$this->assertMatchesRegularExpression( '/^jobs\.[a-z][a-z0-9_]*$/', $code );
		}

		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'Reported codes are never raised, so they are not a catalog.' );
	}

	/**
	 * Tests that the module spells no reported code outside the enum.
	 *
	 * @since 0.1.0
	 */
	public function test_the_module_spells_no_code_outside_the_enum(): void {
		$cases   = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );
		$spelled = array();

		foreach ( PhpSource::files( 'src/Platform/Jobs' ) as $path => $source ) {
			if ( str_ends_with( $path, 'ReportCode.php' ) ) {
				continue;
			}

			preg_match_all( '/[\'"](jobs\.[a-z][a-z0-9_]*)[\'"]/', $source, $matches );

			foreach ( $matches[1] as $code ) {
				$spelled[ $code ] = $path;
			}
		}

		foreach ( $spelled as $code => $path ) {
			$this->assertContains( $code, $cases, "{$path} spells the code {$code}, which ReportCode does not list." );
		}
	}
}
