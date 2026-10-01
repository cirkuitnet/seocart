<?php
/**
 * Isolation: the isolation level a unit of work asks for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The isolation levels an outermost unit of work may run at: the server's own, or READ COMMITTED.
 *
 * Owns one fact: which levels the plugin asks for, and how it asks. A unit that writes stock asks
 * for READ COMMITTED, so its locking statements lock only the rows they match and never the gaps
 * between them: a hold, a release or a reclaim of one item then never waits on another item's.
 * Every other unit keeps the server's level, which is what hosts expect.
 *
 * The level is asked for with `SET TRANSACTION ISOLATION LEVEL`, which MySQL and MariaDB apply to
 * the next transaction of the connection only: the session's own level is left as it was, so a
 * unit that did not ask runs at the server's level whatever ran before it.
 *
 * At READ COMMITTED, InnoDB refuses to write while the binary log records statements
 * (`binlog_format = STATEMENT`), which is why activation refuses such a server.
 *
 * @since 0.1.0
 */
enum Isolation {

	/**
	 * The server's level, usually REPEATABLE READ: nothing is asked for.
	 *
	 * @since 0.1.0
	 */
	case Default;

	/**
	 * READ COMMITTED: every read sees the latest committed rows, and locking statements take no gap locks.
	 *
	 * @since 0.1.0
	 */
	case ReadCommitted;

	/**
	 * Returns the statement that asks for this level for the next transaction.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The statement, or null for the server's level, which needs none.
	 */
	public function statement(): ?string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Default       => null,
			self::ReadCommitted => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED',
		};
	}
}
