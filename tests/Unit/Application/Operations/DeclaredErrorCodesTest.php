<?php
/**
 * Tests that every error code an operation declares has its row in the one error table (DRY rule 8)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Error\ErrorTableException;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Unit\Support\PhpSource;
use SEOCart\Tools\Docs\ErrorCatalogs;

/**
 * The operations' half of the error table's totality test.
 *
 * ErrorTableTotalityTest proves that every code has exactly one row and that every row is used
 * under src/. This test adds the operations:
 *
 * - every code an operation declares has a row in the table composed from every catalog under
 *   src/ (plus the fixture's catalog, for the fixture), so a declared code with no row fails —
 *   the same table the error reference and the OpenAPI document are generated from;
 * - a code an operation declares counts as used: an operation's declaration names its codes as
 *   catalog cases in code under src/, which is exactly what the totality test counts as a use.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class DeclaredErrorCodesTest extends TestCase {

	/**
	 * Tests that every declared code of every operation has a row.
	 *
	 * @since 0.1.0
	 */
	public function test_every_declared_code_has_a_row(): void {
		$registry = Operations::registry();

		FixtureStockOperation::register( $registry );

		$this->assertSame( array(), self::codesWithoutRow( $registry, self::table( true ) ) );
	}

	/**
	 * Tests that a declared code whose catalog is not in the table is reported.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_code_without_a_row_is_reported(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$this->assertSame(
			array( 'fixture_stock.adjust_stock declares fixture_stock.insufficient, which has no row in the error table.' ),
			self::codesWithoutRow( $registry, self::table( false ) )
		);
	}

	/**
	 * Tests that the codes the production operations declare are references under src/, which the totality test counts as uses.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_code_counts_as_used(): void {
		$referenced = array();

		foreach ( PhpSource::files( 'src' ) as $source ) {
			foreach ( PhpSource::constantReferences( $source ) as $reference ) {
				$referenced[ $reference['class'] . '::' . $reference['constant'] ] = true;
			}
		}

		foreach ( Operations::registry()->all() as $definition ) {
			foreach ( $definition->errors() as $code ) {
				$this->assertArrayHasKey( get_class( $code ) . '::' . $code->name, $referenced, $definition->id() . ' declares ' . $code->value . ' outside src/.' );
			}
		}

		$fixture_references = array();

		foreach ( PhpSource::constantReferences( (string) file_get_contents( dirname( __DIR__, 3 ) . '/Fixtures/Operations/FixtureStockOperation.php' ) ) as $reference ) {
			$fixture_references[] = $reference['class'] . '::' . $reference['constant'];
		}

		$this->assertContains( FixtureStockError::class . '::Insufficient', $fixture_references, 'The reader does not see a code declared by a definition, so the check above would pass on nothing.' );
	}

	/**
	 * Lists the declared codes that have no row.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param ErrorTable        $table    The error table.
	 * @return list<string> One message per missing row.
	 */
	private static function codesWithoutRow( OperationRegistry $registry, ErrorTable $table ): array {
		$missing = array();

		foreach ( $registry->all() as $definition ) {
			foreach ( $definition->errors() as $code ) {
				try {
					$table->definitionFor( $code );
				} catch ( ErrorTableException $exception ) {
					$missing[] = self::missing( $definition, (string) $code->value );
				}
			}
		}

		return $missing;
	}

	/**
	 * Words one missing row.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param string              $code       The code.
	 * @return string The message.
	 */
	private static function missing( OperationDefinition $definition, string $code ): string {
		return $definition->id() . ' declares ' . $code . ', which has no row in the error table.';
	}

	/**
	 * Composes the error table from every catalog under src/, with or without the fixture's.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $with_fixture Whether to add the fixture's catalog.
	 * @return ErrorTable The table.
	 */
	private static function table( bool $with_fixture ): ErrorTable {
		$catalogs = ErrorCatalogs::find( PhpSource::root() . '/src' );

		if ( $with_fixture ) {
			$catalogs[] = FixtureStockError::class;
		}

		return ErrorTable::compose( ...$catalogs );
	}
}
