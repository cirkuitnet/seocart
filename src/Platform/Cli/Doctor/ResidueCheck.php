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
 * no `seocart_` scheduled event, and no background job in the plugin's job group or any other
 * `seocart` group of Action Scheduler, whatever its status. Each thing found is one line,
 * marked `declared` when the data registry names it and `undeclared` when nothing does, which
 * is worse: nothing would ever have removed it. Jobs are counted by status, one line per
 * group. Only names and counts are shown, never values.
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
	 * How the names of the plugin's job groups start, beside the groups the registry declares.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const JOB_GROUP_PREFIX = 'seocart';

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
		$findings = array_merge( $this->tables(), $this->options(), $this->roles(), $this->scheduledEvents(), $this->jobs() );

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Nothing the plugin owned remains on this site: no table, option, role, capability, scheduled event or background job.' );
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

	/**
	 * Lists the background jobs that remain in the plugin's job groups, by group and status.
	 *
	 * The jobs are read from Action Scheduler's tables. A site without them has none to list.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> One line per group, with its jobs counted by status.
	 */
	private function jobs(): array {
		$actions = $this->db->prefix() . 'actionscheduler_actions';
		$groups  = $this->db->prefix() . 'actionscheduler_groups';

		if ( 2 !== (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ( %s, %s )', $actions, $groups ) ) {
			return array();
		}

		$declared = array_map( 'strval', array_keys( $this->registry->jobGroups() ) );
		$rows     = $this->db->fetchAll(
			'SELECT g.slug AS job_group, a.status, COUNT(*) AS total FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug LIKE %s' . str_repeat( ' OR g.slug = %s', count( $declared ) ) . ' GROUP BY g.slug, a.status ORDER BY g.slug, a.status',
			$actions,
			$groups,
			addcslashes( self::JOB_GROUP_PREFIX, '\\_%' ) . '%',
			...$declared
		);
		$counts   = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row['job_group'] ][] = sprintf( '%1$d %2$s', (int) $row['total'], CheckResult::identifier( (string) $row['status'] ) );
		}

		$findings = array();

		foreach ( $counts as $group => $statuses ) {
			$findings[] = sprintf( 'job group %1$s (%2$s): %3$s', CheckResult::identifier( (string) $group ), in_array( (string) $group, $declared, true ) ? 'declared' : 'undeclared', implode( ', ', $statuses ) );
		}

		return $findings;
	}
}
