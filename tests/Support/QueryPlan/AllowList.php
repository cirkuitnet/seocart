<?php
/**
 * AllowList: the reviewed list of plugin SELECTs whose plans may break the query-plan rule, each with its reason
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

use SEOCart\Platform\Database\ServerKind;

/**
 * Reads tests/query-plan-allow-list.json and refuses it unless every entry is complete.
 *
 * Owns one fact: which queries may break the query-plan rule, on which servers, and why. Each
 * entry names a query by its id and its shape, and gives the reason its plan is accepted. An
 * entry may also name the servers it holds on (`servers`, ServerKind values), for a plan that
 * only one server's optimizer makes; it is then loaded, judged and judged stale only on a run of
 * those servers. An entry is refused when its reason is missing or blank, when its id is not the
 * id of its shape, when its id is listed twice, or when `servers` is not a non-empty list of
 * distinct server names; so every accepted plan carries a reason a reviewer read, and an entry
 * cannot quietly come to mean another query. The query-plan run fails, too, on an entry that
 * names no query of the run: a query that changed shape or went away takes its entry with it.
 *
 * @since 0.1.0
 */
final class AllowList {

	/**
	 * The committed list, relative to the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FILE = 'tests/query-plan-allow-list.json';

	/**
	 * Reads a list, checks every entry, and returns the entries that hold on a server.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The server, and an entry's `servers`.
	 *
	 * @throws \UnexpectedValueException When the file cannot be read, is not a list of entries, or an entry is incomplete.
	 *
	 * @param string     $file   The list's path.
	 * @param ServerKind $server The server of the run: an entry scoped to other servers is left out.
	 * @return array<string, array{statement: string, reason: string, servers: list<string>}> The entries, keyed by
	 *         query id (PHP makes an id of digits only an integer key: compare keys as strings); `servers` is empty
	 *         for an entry that holds on every server.
	 */
	public static function load( string $file, ServerKind $server ): array {
		$contents = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a committed test fixture.
		$decoded  = false === $contents ? null : json_decode( $contents, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			throw new \UnexpectedValueException( sprintf( '%s must be a JSON object whose "entries" is a list.', $file ) );
		}

		$entries = array();
		$listed  = array();

		foreach ( $decoded['entries'] as $position => $entry ) {
			$id        = is_array( $entry ) && is_string( $entry['id'] ?? null ) ? $entry['id'] : '';
			$statement = is_array( $entry ) && is_string( $entry['statement'] ?? null ) ? $entry['statement'] : '';
			$reason    = is_array( $entry ) && is_string( $entry['reason'] ?? null ) ? trim( $entry['reason'] ) : '';

			if ( '' === $reason ) {
				throw new \UnexpectedValueException( sprintf( 'Entry %d of %s (query %s) gives no reason. Every allowed plan needs the reason it is accepted.', (int) $position + 1, $file, '' === $id ? 'without an id' : $id ) );
			}

			if ( '' === $statement || Statement::idOf( $statement ) !== $id ) {
				throw new \UnexpectedValueException( sprintf( 'Entry %d of %s: the id "%s" is not the id of its statement, %s. Copy both from the query-plan report.', (int) $position + 1, $file, $id, '' === $statement ? '(none)' : Statement::idOf( $statement ) ) );
			}

			if ( isset( $listed[ $id ] ) ) {
				throw new \UnexpectedValueException( sprintf( 'Query %s is listed twice in %s.', $id, $file ) );
			}

			$listed[ $id ] = true;
			$servers       = self::servers( $entry, (int) $position, $file, $id );

			if ( array() !== $servers && ! in_array( $server->value, $servers, true ) ) {
				continue;
			}

			$entries[ $id ] = array(
				'statement' => $statement,
				'reason'    => $reason,
				'servers'   => $servers,
			);
		}

		return $entries;
	}

	/**
	 * Reads an entry's `servers`: absent for an entry that holds on every server, else a non-empty list of distinct ServerKind values.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When `servers` is there and is anything else.
	 *
	 * @param mixed  $entry    The entry.
	 * @param int    $position Its position in the list, from 0.
	 * @param string $file     The list's path.
	 * @param string $id       The query id it names.
	 * @return list<string> The servers it holds on; empty for every server.
	 */
	private static function servers( mixed $entry, int $position, string $file, string $id ): array {
		if ( ! is_array( $entry ) || ! array_key_exists( 'servers', $entry ) ) {
			return array();
		}

		$known   = array_map( static fn( ServerKind $kind ): string => $kind->value, ServerKind::cases() );
		$refused = new \UnexpectedValueException( sprintf( 'Entry %1$d of %2$s (query %3$s): "servers" must be a non-empty list of distinct server names out of %4$s.', $position + 1, $file, $id, implode( ', ', $known ) ) );
		$names   = array();

		foreach ( is_array( $entry['servers'] ) && array_is_list( $entry['servers'] ) ? $entry['servers'] : array( null ) as $name ) {
			$kind = is_string( $name ) ? ServerKind::tryFrom( $name ) : null;

			if ( null === $kind || in_array( $kind->value, $names, true ) ) {
				throw $refused;
			}

			$names[] = $kind->value;
		}

		if ( array() === $names ) {
			throw $refused;
		}

		return $names;
	}
}
