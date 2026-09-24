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

/**
 * Reads tests/query-plan-allow-list.json and refuses it unless every entry is complete.
 *
 * Owns one fact: which queries may break the query-plan rule, and why. Each entry names a query
 * by its id and its shape, and gives the reason its plan is accepted. An entry is refused when
 * its reason is missing or blank, when its id is not the id of its shape, or when its id is
 * listed twice; so every accepted plan carries a reason a reviewer read, and an entry cannot
 * quietly come to mean another query. The query-plan run fails, too, on an entry that names no
 * query of the run: a query that changed shape or went away takes its entry with it.
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
	 * Reads a list and checks every entry.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the file cannot be read, is not a list of entries, or an entry is incomplete.
	 *
	 * @param string $file The list's path.
	 * @return array<string, array{statement: string, reason: string}> The entries, keyed by query id.
	 */
	public static function load( string $file ): array {
		$contents = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a committed test fixture.
		$decoded  = false === $contents ? null : json_decode( $contents, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			throw new \UnexpectedValueException( sprintf( '%s must be a JSON object whose "entries" is a list.', $file ) );
		}

		$entries = array();

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

			if ( isset( $entries[ $id ] ) ) {
				throw new \UnexpectedValueException( sprintf( 'Query %s is listed twice in %s.', $id, $file ) );
			}

			$entries[ $id ] = array(
				'statement' => $statement,
				'reason'    => $reason,
			);
		}

		return $entries;
	}
}
