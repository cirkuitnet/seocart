<?php
/**
 * CreatesTestTable: a fixture schema migration that creates one table and counts its runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

/**
 * Creates `test_{suffix}` and remembers how often up() ran.
 *
 * Owns one fact: an ordinary schema migration whose behaviour a test controls. The optional
 * $beforeUp hook runs at the start of up(); a test makes it throw to interrupt the chain
 * exactly there, and clears it to let a re-run through.
 *
 * @since 0.1.0
 */
final class CreatesTestTable implements SchemaMigration {

	/**
	 * How many times up() ran.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $runs = 0;

	/**
	 * Runs at the start of up(); may throw. Null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): void)|null
	 */
	public ?\Closure $beforeUp = null;

	/**
	 * The migration id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The table's name after `test_`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $suffix;

	/**
	 * The declared flag.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $canOperateHalfApplied;

	/**
	 * Creates the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id                    The migration id.
	 * @param string $suffix                The table's name after `test_`.
	 * @param bool   $canOperateHalfApplied Optional. The declared flag. Default true.
	 */
	public function __construct( string $id, string $suffix, bool $canOperateHalfApplied = true ) {
		$this->id                    = $id;
		$this->suffix                = $suffix;
		$this->canOperateHalfApplied = $canOperateHalfApplied;
	}

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the declared flag.
	 *
	 * @since 0.1.0
	 *
	 * @return bool The flag.
	 */
	public function canOperateHalfApplied(): bool {
		return $this->canOperateHalfApplied;
	}

	/**
	 * Declares the table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The one table.
	 */
	public function tables(): array {
		return array( TestTables::simple( $this->suffix ) );
	}

	/**
	 * Runs the hook, counts the run, and creates the table.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		if ( null !== $this->beforeUp ) {
			( $this->beforeUp )();
		}

		++$this->runs;

		$operations->createTables( $this->tables() );
	}
}
