<?php
/**
 * Tests the query-plan allow-list's server-scoped entries
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\QueryPlan;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\Statement;

/**
 * Which entries hold on which server, in the committed list and as the loader reads `servers`.
 *
 * MariaDB estimates two cursor reads over their whole range, without the LIMIT that ends them,
 * and so breaks the query-plan rule where MySQL keeps it. Their entries hold on MariaDB only: an
 * entry for every server would be stale on MySQL, and the MySQL run would fail on it.
 *
 * Planted violations, each shown red and removed: in tests/query-plan-allow-list.json, scope a
 * third entry to MariaDB; in AllowList::load(), keep an entry scoped to other servers.
 *
 * @since 0.2.0
 */
final class AllowListTest extends TestCase {

	/**
	 * Tests that the committed list scopes exactly the two reads MariaDB misjudges to MariaDB, each with its reason, and nothing to MySQL alone.
	 *
	 * @since 0.2.0
	 */
	public function test_the_committed_list_scopes_exactly_two_reads_to_mariadb(): void {
		$file    = dirname( __DIR__, 4 ) . '/' . AllowList::FILE;
		$mariadb = AllowList::load( $file, ServerKind::MariaDB );
		$mysql   = AllowList::load( $file, ServerKind::MySQL );
		$only    = array_map( 'strval', array_keys( array_diff_key( $mariadb, $mysql ) ) );

		sort( $only );

		$this->assertSame( array( '471508706270', 'e17d9305aac4' ), $only, 'The MariaDB-only entries are not exactly the hold sweep\'s page and doctor\'s variant ids.' );
		$this->assertSame( array(), array_keys( array_diff_key( $mysql, $mariadb ) ), 'An entry holds on MySQL alone.' );

		foreach ( $only as $id ) {
			$this->assertSame( array( 'mariadb' ), $mariadb[ $id ]['servers'] );
			$this->assertStringStartsWith( 'MariaDB only. ', $mariadb[ $id ]['reason'] );
		}
	}

	/**
	 * Tests that an entry scoped to one server is left out of a run on another, and that an unscoped entry is in every run.
	 *
	 * @since 0.2.0
	 */
	public function test_a_scoped_entry_is_loaded_only_on_its_servers(): void {
		$scoped = self::entry( 'SELECT id FROM {prefix}seocart_test_rows WHERE value = ?' ) + array( 'servers' => array( 'mariadb' ) );
		$every  = self::entry( 'SELECT id FROM {prefix}seocart_test_rows WHERE id > ?' );

		$this->assertSame( array( $every['id'] ), array_map( 'strval', array_keys( self::loaded( array( $scoped, $every ), ServerKind::MySQL ) ) ) );
		$this->assertSame( array( $scoped['id'], $every['id'] ), array_map( 'strval', array_keys( self::loaded( array( $scoped, $every ), ServerKind::MariaDB ) ) ) );
		$this->assertSame( array(), self::loaded( array( $every ), ServerKind::MariaDB )[ $every['id'] ]['servers'] );
	}

	/**
	 * Lists values of `servers` the loader refuses.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{mixed}> The value.
	 */
	public static function refusedServers(): array {
		return array(
			'an unknown server'  => array( array( 'oracle' ) ),
			'an empty list'      => array( array() ),
			'a name twice'       => array( array( 'mariadb', 'mariadb' ) ),
			'a name, not a list' => array( 'mariadb' ),
			'a map'              => array( array( 'server' => 'mariadb' ) ),
			'not a name'         => array( array( 1 ) ),
		);
	}

	/**
	 * Tests that `servers` is refused unless it is a non-empty list of distinct server names.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusedServers
	 *
	 * @param mixed $servers The value.
	 */
	public function test_servers_must_be_a_non_empty_list_of_distinct_server_names( mixed $servers ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( '"servers" must be a non-empty list of distinct server names out of mysql, mariadb' );

		self::loaded( array( self::entry( 'SELECT id FROM {prefix}seocart_test_rows WHERE value = ?' ) + array( 'servers' => $servers ) ), ServerKind::MariaDB );
	}

	/**
	 * Builds a complete entry for a statement.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement The statement, as the run's report prints it.
	 * @return array{id: string, statement: string, reason: string} The entry.
	 */
	private static function entry( string $statement ): array {
		return array(
			'id'        => Statement::idOf( $statement ),
			'statement' => $statement,
			'reason'    => 'A reason.',
		);
	}

	/**
	 * Writes a list to a temporary file and loads it.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $entries The entries.
	 * @param ServerKind                 $server  The server of the run.
	 * @return array<string, array{statement: string, reason: string, servers: list<string>}> What the loader returned.
	 */
	private static function loaded( array $entries, ServerKind $server ): array {
		$file = (string) tempnam( sys_get_temp_dir(), 'seocart-allow-list-' );

		file_put_contents( $file, (string) json_encode( array( 'entries' => $entries ) ) );

		try {
			return AllowList::load( $file, $server );
		} finally {
			unlink( $file );
		}
	}
}
