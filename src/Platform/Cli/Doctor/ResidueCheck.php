<?php
/**
 * ResidueCheck: nothing the plugin owned remains on the site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\DataRegistry\DataRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for everything the plugin owns, on a site from which it should all be gone.
 *
 * Owns one fact: what counts as residue. After the store's data has been deleted, nothing in
 * the plugin's namespaces may remain on the current site: no `{prefix}seocart_` table, no
 * `seocart_` option or transient, no role the plugin ships, no plugin capability on any role,
 * and no `seocart_` scheduled event. Each thing found is one line, marked `declared` when the
 * data registry names it and `undeclared` when nothing does, which is worse: nothing would
 * ever have removed it. Only names are shown, never values.
 *
 * Doctor runs this check only when asked with `--residue`, because on a working store every
 * declared thing is meant to exist.
 *
 * @since 0.1.0
 */
final class ResidueCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'residue';

	/**
	 * The option-name prefixes the plugin's options and transients have.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const OPTION_PREFIXES = array( 'seocart_', '_transient_seocart_', '_transient_timeout_seocart_', '_site_transient_seocart_', '_site_transient_timeout_seocart_' );

	/**
	 * The prefix of the plugin's hooks, and so of its scheduled events.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HOOK_PREFIX = 'seocart_';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * What the plugin owns.
	 *
	 * @since 0.1.0
	 *
	 * @var DataRegistry
	 */
	private DataRegistry $registry;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database     $db       The connection.
	 * @param DataRegistry $registry What the plugin owns.
	 */
	public function __construct( Database $db, DataRegistry $registry ) {
		$this->db       = $db;
		$this->registry = $registry;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `residue`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists what remains.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when nothing remains.
	 */
	public function run(): CheckResult {
		$findings = array_merge( $this->tables(), $this->options(), $this->roles(), $this->scheduledEvents() );

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Nothing the plugin owned remains on this site: no table, option, role, capability or scheduled event.' );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d %s the plugin owned %s on this site.', count( $findings ), 1 === count( $findings ) ? 'thing' : 'things', 1 === count( $findings ) ? 'remains' : 'remain' ), $findings );
	}

	/**
	 * Lists the plugin tables that remain.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per table.
	 */
	private function tables(): array {
		$declared = array_map( fn( string $name ): string => $this->db->table( $name ), $this->registry->tableNames() );

		return array_map(
			static fn( string $table ): string => sprintf( 'table %s (%s)', CheckResult::identifier( $table ), in_array( $table, $declared, true ) ? 'declared' : 'undeclared' ),
			SchemaCheck::pluginTables( $this->db )
		);
	}

	/**
	 * Lists the plugin options and transients that remain.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per option.
	 */
	private function options(): array {
		$patterns = array_map( static fn( string $prefix ): string => addcslashes( $prefix, '\\_%' ) . '%', self::OPTION_PREFIXES );
		$rows     = $this->db->fetchAll(
			'SELECT option_name AS name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
			$this->db->prefix() . 'options',
			...$patterns
		);
		$declared = $this->registry->optionNames();

		return array_map(
			static fn( array $row ): string => sprintf( 'option %s (%s)', CheckResult::identifier( (string) $row['name'] ), in_array( (string) $row['name'], $declared, true ) ? 'declared' : 'undeclared' ),
			$rows
		);
	}

	/**
	 * Lists the shipped roles that remain, and every role that still has plugin capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per role.
	 */
	private function roles(): array {
		$declaration = $this->registry->capabilities();
		$findings    = array();

		foreach ( wp_roles()->roles as $role => $details ) {
			$role = (string) $role;

			if ( $declaration->isShippedRole( $role ) ) {
				$findings[] = sprintf( 'role %s (declared)', CheckResult::identifier( $role ) );

				continue;
			}

			$capabilities = array_filter( array_keys( (array) ( $details['capabilities'] ?? array() ) ), static fn( $capability ): bool => $declaration->isPluginCapability( (string) $capability ) );

			if ( array() !== $capabilities ) {
				$findings[] = sprintf( 'role %1$s still has %2$d plugin %3$s', CheckResult::identifier( $role ), count( $capabilities ), 1 === count( $capabilities ) ? 'capability' : 'capabilities' );
			}
		}

		return $findings;
	}

	/**
	 * Lists the plugin's scheduled events that remain.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per hook.
	 */
	private function scheduledEvents(): array {
		$hooks = array();

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, self::HOOK_PREFIX ) ) {
					$hooks[ (string) $hook ] = true;
				}
			}
		}

		ksort( $hooks );

		return array_map( static fn( string $hook ): string => sprintf( 'scheduled event %s', CheckResult::identifier( $hook ) ), array_keys( $hooks ) );
	}
}
