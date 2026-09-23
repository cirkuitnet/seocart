<?php
/**
 * DataRegistry: everything the plugin owns in a site, collected once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\DataRegistry;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A registration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * The one collection of what the plugin owns in a site, and the column classification it carries.
 *
 * Owns one fact: the set of things that are the plugin's in a site: its tables and the
 * migrations that create them, its options, capabilities and roles, and its job groups. It
 * restates none of them. Tables come from their modules' TableDefinition factories, whose
 * columns carry their classification and privacy handling; capabilities and roles are the
 * CapabilityDeclaration's; retention periods are the RetentionCatalog's.
 *
 * Everything that must know what exists reads it from here: the per-site install (the
 * migration chain), uninstall and "Delete all store data" (what to remove), `doctor --residue`
 * (what may remain), and the privacy exporter, eraser and log redaction (the columns of each
 * class). OwnedData builds the production instance.
 *
 * Construction refuses a registration that could not be right: a table, option, migration or
 * job group registered twice; a table whose retention policy the catalog does not declare; a
 * table with a `created_at` column kept permanently without its purpose saying why; and more
 * than one option that autoloads. A `pii` column without its privacy handling never gets this
 * far: ColumnSpec refuses to be built without it.
 *
 * Immutable and pure data: building and reading it does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class DataRegistry {

	/**
	 * What a permanent table with a `created_at` column must say in its purpose: that it is kept
	 * permanently, then `because`, then at least one word.
	 *
	 * A phrasing gate: it proves a reason was written down where the generated reference and a
	 * reviewer will read it, not that the reason is a good one. That is the review's to judge.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PERMANENCE_REASON = '/\bpermanent(?:ly)?\b.*\bbecause\s+\w/isu';

	/**
	 * The capability vocabulary and the role bundles.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $capabilities;

	/**
	 * The retention policies a table may name.
	 *
	 * @since 0.1.0
	 *
	 * @var RetentionCatalog
	 */
	private RetentionCatalog $retention;

	/**
	 * Unprefixed table name => declaration, in registration order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, TableDefinition>
	 */
	private array $tables = array();

	/**
	 * Every module's migrations, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Migration>
	 */
	private array $migrations;

	/**
	 * Option name => declaration, in registration order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, OptionDefinition>
	 */
	private array $options = array();

	/**
	 * Job group name => owning module.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $jobGroups = array();

	/**
	 * Collects the modules' contributions.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a registration could not be right; see the class description.
	 *
	 * @param CapabilityDeclaration $capabilities  The capability vocabulary and role bundles.
	 * @param Contribution          ...$contributions What each module owns.
	 */
	public function __construct( CapabilityDeclaration $capabilities, Contribution ...$contributions ) {
		$this->capabilities = $capabilities;
		$this->retention    = new RetentionCatalog();

		$migrations = array();

		foreach ( $contributions as $contribution ) {
			foreach ( $contribution->tables() as $table ) {
				$this->registerTable( $table );
			}

			foreach ( $contribution->migrations() as $migration ) {
				if ( isset( $migrations[ $migration->id() ] ) ) {
					throw new \LogicException( sprintf( 'Migration %s is registered twice.', $migration->id() ) );
				}

				$migrations[ $migration->id() ] = $migration;
			}

			foreach ( $contribution->options() as $option ) {
				if ( isset( $this->options[ $option->name() ] ) ) {
					throw new \LogicException( sprintf( 'Option %s is registered twice.', $option->name() ) );
				}

				$this->options[ $option->name() ] = $option;
			}

			foreach ( $contribution->jobGroups() as $group => $module ) {
				if ( isset( $this->jobGroups[ $group ] ) ) {
					throw new \LogicException( sprintf( 'Job group %s is registered twice.', $group ) );
				}

				$this->jobGroups[ $group ] = $module;
			}
		}

		ksort( $migrations, SORT_STRING );
		$this->migrations = array_values( $migrations );

		$autoloaded = array_keys( array_filter( $this->options, static fn( OptionDefinition $option ): bool => $option->autoloads() ) );

		if ( count( $autoloaded ) > 1 ) {
			throw new \LogicException( sprintf( 'Only one plugin option may autoload, and %s all do.', implode( ', ', $autoloaded ) ) );
		}
	}

	/**
	 * Returns every registered table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> In registration order.
	 */
	public function tables(): array {
		return array_values( $this->tables );
	}

	/**
	 * Returns the name of every registered table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Unprefixed, in registration order.
	 */
	public function tableNames(): array {
		return array_keys( $this->tables );
	}

	/**
	 * Returns a registered table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The unprefixed name, for example `migrations`.
	 * @return TableDefinition|null The declaration, or null when no module registers the table.
	 */
	public function tableNamed( string $name ): ?TableDefinition {
		return $this->tables[ $name ] ?? null;
	}

	/**
	 * Returns every column of a class, by table.
	 *
	 * @since 0.1.0
	 *
	 * @param Classification $classification The class.
	 * @return array<string, list<ColumnSpec>> Unprefixed table name => its columns of that class, in table order.
	 *                                          A table with none is left out.
	 */
	public function columnsClassified( Classification $classification ): array {
		$columns = array();

		foreach ( $this->tables as $name => $table ) {
			$matching = array_values( array_filter( $table->columns(), static fn( ColumnSpec $column ): bool => $classification === $column->classification() ) );

			if ( array() !== $matching ) {
				$columns[ $name ] = $matching;
			}
		}

		return $columns;
	}

	/**
	 * Returns the migration chain: every module's migrations, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Migration> What the migrator runs on each site to create and change the registered tables.
	 */
	public function migrations(): array {
		return $this->migrations;
	}

	/**
	 * Returns every registered option.
	 *
	 * @since 0.1.0
	 *
	 * @return list<OptionDefinition> In registration order.
	 */
	public function options(): array {
		return array_values( $this->options );
	}

	/**
	 * Returns the name of every registered option.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> In registration order.
	 */
	public function optionNames(): array {
		return array_keys( $this->options );
	}

	/**
	 * Returns every registered job group.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Action Scheduler group name => owning module.
	 */
	public function jobGroups(): array {
		return $this->jobGroups;
	}

	/**
	 * Returns the capability declaration: the plugin's capabilities and the roles it grants them to.
	 *
	 * @since 0.1.0
	 *
	 * @return CapabilityDeclaration The declaration the registry was built with.
	 */
	public function capabilities(): CapabilityDeclaration {
		return $this->capabilities;
	}

	/**
	 * Returns the retention policies, with their default periods.
	 *
	 * @since 0.1.0
	 *
	 * @return RetentionCatalog The catalog every registered table's policy is in.
	 */
	public function retention(): RetentionCatalog {
		return $this->retention;
	}

	/**
	 * Registers one table after checking what its declaration alone cannot.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the table is already registered, names an unknown retention policy, or is
	 *                         kept permanently without saying why.
	 *
	 * @param TableDefinition $table The declaration.
	 */
	private function registerTable( TableDefinition $table ): void {
		$name = $table->name();

		if ( isset( $this->tables[ $name ] ) ) {
			throw new \LogicException( sprintf( 'Table %s is registered twice.', $name ) );
		}

		if ( ! $this->retention->has( $table->retention() ) ) {
			throw new \LogicException( sprintf( 'Table %s names the retention policy "%s", which the catalog does not declare. The catalog declares: %s.', $name, $table->retention(), implode( ', ', $this->retention->ids() ) ) );
		}

		$columns = array_map( static fn( ColumnSpec $column ): string => $column->name(), $table->columns() );

		if ( RetentionCatalog::PERMANENT === $table->retention() && in_array( 'created_at', $columns, true ) && 1 !== preg_match( self::PERMANENCE_REASON, $table->purpose() ) ) {
			throw new \LogicException( sprintf( 'Table %s has a created_at column and keeps its rows permanently, so its purpose must say why: "... kept permanently because ...".', $name ) );
		}

		$this->tables[ $name ] = $table;
	}
}
