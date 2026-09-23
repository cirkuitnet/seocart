<?php
/**
 * Contribution: what one module adds to the data registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\DataRegistry;

use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * Everything one module owns in a site, handed to the data registry in one expression.
 *
 * Owns one fact: how a module's share of the site is written down, so that adding a module
 * to the production list is one line. It carries the module's tables and the migrations that
 * create them, side by side, because the registry lists the tables and the migrator runs the
 * migrations, and the coverage tests prove the two agree. It also carries the module's options
 * and its job groups.
 *
 * Tables and options name their own module. A job group is an Action Scheduler group name,
 * `seocart` optionally followed by more words, mapped to the module that owns it. A
 * contribution checks those names; the registry checks for duplicates across contributions.
 *
 * Pure data: constructing one does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class Contribution {

	/**
	 * A job group name: `seocart`, optionally followed by `-` or `_` separated lowercase words.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const JOB_GROUP_PATTERN = '/^seocart(?:[_-][a-z0-9]+)*$/D';

	/**
	 * The module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @var list<TableDefinition>
	 */
	private array $tables;

	/**
	 * The migrations that create and change the module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Migration>
	 */
	private array $migrations;

	/**
	 * The module's options.
	 *
	 * @since 0.1.0
	 *
	 * @var list<OptionDefinition>
	 */
	private array $options;

	/**
	 * Job group name => owning module.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $jobGroups;

	/**
	 * Writes down a module's share. Every argument is optional; name the ones the module has.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a job group is outside the plugin's namespace or has no owning module.
	 *
	 * @param TableDefinition[]     $tables     Optional. The tables, from the module's static factories. Default none.
	 * @param Migration[]           $migrations Optional. The migrations that create and change them. Default none.
	 * @param OptionDefinition[]    $options    Optional. The options. Default none.
	 * @param array<string, string> $jobGroups  Optional. Job group name => owning module. Default none.
	 */
	public function __construct( array $tables = array(), array $migrations = array(), array $options = array(), array $jobGroups = array() ) {
		foreach ( $jobGroups as $group => $module ) {
			if ( 1 !== preg_match( self::JOB_GROUP_PATTERN, (string) $group ) ) {
				throw new \InvalidArgumentException( sprintf( 'The job group "%s" is not in the plugin\'s namespace or not written in lowercase words. Map each name to its owning module.', $group ) );
			}

			if ( '' === trim( $module ) ) {
				throw new \InvalidArgumentException( sprintf( 'The job group %s needs an owning module.', $group ) );
			}
		}

		$this->tables     = array_values( $tables );
		$this->migrations = array_values( $migrations );
		$this->options    = array_values( $options );
		$this->jobGroups  = $jobGroups;
	}

	/**
	 * Returns the module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> In the order given.
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * Returns the migrations that create and change the module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Migration> In the order given.
	 */
	public function migrations(): array {
		return $this->migrations;
	}

	/**
	 * Returns the module's options.
	 *
	 * @since 0.1.0
	 *
	 * @return list<OptionDefinition> In the order given.
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Returns the module's job groups.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Group name => owning module.
	 */
	public function jobGroups(): array {
		return $this->jobGroups;
	}
}
