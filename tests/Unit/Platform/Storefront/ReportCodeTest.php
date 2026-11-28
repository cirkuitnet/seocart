<?php
/**
 * Tests that the codes the storefront logs under never collide with an error-table code or another module's report code
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Storefront;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Storefront\ReportCode;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Keeps the storefront's log vocabulary in one list, apart from the error table and other modules' reports.
 *
 * A code in ReportCode reaches the log, never a shopper. If one spelled a code of the error table
 * or of another module, a reader of the log could not tell them apart. The table is composed from
 * every error catalog under src/, and the other modules' codes from every enum named ReportCode
 * there. Every `storefront.` code the storefront's source spells must be a case of the enum, so
 * the enum stays the one list.
 *
 * Planted violations, shown red and removed: give ReportCode::AssetUnreadable the value
 * 'jobs.tick_failed'; spell `'storefront.planted'` in a string in StorefrontAssets.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class ReportCodeTest extends TestCase {

	/**
	 * Tests that no code is a code of the error table or of another module's reports.
	 *
	 * @since 0.2.0
	 */
	public function test_no_code_is_taken_elsewhere(): void {
		$catalogs = array();
		$reports  = array();

		foreach ( PhpSource::files( 'src' ) as $source ) {
			if ( ! str_contains( $source, 'enum ' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				if ( ! enum_exists( $class ) ) {
					continue;
				}

				if ( is_subclass_of( $class, ErrorCode::class ) ) {
					$catalogs[] = $class;
				} elseif ( str_ends_with( $class, '\\ReportCode' ) && ReportCode::class !== $class ) {
					$reports[] = $class;
				}
			}
		}

		$this->assertNotSame( array(), $catalogs, 'The search for error catalogs found none; it cannot be trusted.' );
		$this->assertNotSame( array(), $reports, 'The search for other modules\' report codes found none; it cannot be trusted.' );

		$taken = array();

		foreach ( $reports as $enum ) {
			foreach ( $enum::cases() as $case ) {
				$taken[] = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
			}
		}

		foreach ( ErrorTable::compose( ...$catalogs )->definitions() as $row ) {
			$taken[] = (string) $row->code()->value;
		}

		$ours = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );

		$this->assertSame( array(), array_values( array_intersect( $ours, $taken ) ), 'A code the storefront logs under is already a code of the error table or of another module.' );

		foreach ( $ours as $code ) {
			$this->assertMatchesRegularExpression( '/^storefront\.[a-z][a-z_]*$/', $code );
		}

		$this->assertNotContains( ErrorCode::class, ( new \ReflectionEnum( ReportCode::class ) )->getInterfaceNames(), 'These codes are never raised, so they are not a catalog.' );
	}

	/**
	 * Tests that the storefront spells no code outside the enum.
	 *
	 * @since 0.2.0
	 */
	public function test_the_storefront_spells_no_code_outside_the_enum(): void {
		$cases   = array_map( static fn( ReportCode $code ): string => $code->value, ReportCode::cases() );
		$spelled = array();

		foreach ( PhpSource::files( 'src/Platform/Storefront' ) as $path => $source ) {
			if ( str_ends_with( $path, 'ReportCode.php' ) ) {
				continue;
			}

			preg_match_all( '/[\'"](storefront\.[a-z][a-z0-9_]*)[\'"]/', $source, $matches );

			foreach ( $matches[1] as $code ) {
				$spelled[ $code ] = $path;
			}
		}

		foreach ( $spelled as $code => $path ) {
			$this->assertContains( $code, $cases, "{$path} spells the code {$code}, which ReportCode does not list." );
		}
	}
}
