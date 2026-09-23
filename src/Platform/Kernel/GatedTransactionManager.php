<?php
/**
 * GatedTransactionManager: the transaction manager application services receive, closed while the schema gate is
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses to open a unit of work while the schema gate is closed, and otherwise is the database's transaction manager.
 *
 * Owns one fact: where degraded mode refuses a commerce write. Every commerce write runs in a
 * transaction, from whichever surface it came: the REST API, an ability, a command, the editor
 * or a job. So the refusal is made once, here, when the outermost unit of work would begin, and
 * no service can forget it. The error is KernelError::StoreUnavailable, which each surface
 * renders like any other coded error: 503 on REST, the same error from an ability, the code and
 * the message with a non-zero exit status on the command line.
 *
 * Only the outermost level asks the gate; a nested level belongs to a unit of work that was
 * already let through. Reads open no transaction and are never refused. The migrator, the
 * logger and the outbox keep the raw Database, which is what lets a site in degraded mode
 * repair itself.
 *
 * @since 0.1.0
 */
final class GatedTransactionManager implements TransactionManager {

	/**
	 * The transaction manager that does the work.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The schema gate.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaGate
	 */
	private SchemaGate $gate;

	/**
	 * Creates the manager.
	 *
	 * @since 0.1.0
	 *
	 * @param Database   $db   The transaction manager that does the work.
	 * @param SchemaGate $gate The schema gate.
	 */
	public function __construct( Database $db, SchemaGate $gate ) {
		$this->db   = $db;
		$this->gate = $gate;
	}

	/**
	 * Runs a unit of work inside a transaction, unless the schema gate is closed.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException KernelError::StoreUnavailable at the outermost level while the gate is
	 *                        closed; nothing is sent. Otherwise whatever Database::transaction() throws.
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param callable(): mixed $work  The unit of work.
	 * @param RetryPolicy|null  $retry Optional. Honoured at the outermost level only. Default null, which never retries.
	 * @return mixed What the callable returned, unchanged.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed {
		if ( 0 === $this->db->depth() && $this->gate->writesBlocked() ) {
			CodedException::raise( KernelError::StoreUnavailable, array( 'reason' => $this->gate->state()->value ) );
		}

		return $this->db->transaction( $work, $retry );
	}

	/**
	 * Returns how many transaction levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 outside any transaction.
	 */
	public function depth(): int {
		return $this->db->depth();
	}

	/**
	 * Registers work to run once the outermost level has committed.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after COMMIT.
	 */
	public function afterCommit( callable $callback ): void {
		$this->db->afterCommit( $callback );
	}

	/**
	 * Registers work to run once the current level has been rolled back.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after the rollback.
	 */
	public function afterRollback( callable $callback ): void {
		$this->db->afterRollback( $callback );
	}

	/**
	 * Records an object-cache key written inside the current level.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 */
	public function touchCacheKey( string $key, string $group ): void {
		$this->db->touchCacheKey( $key, $group );
	}
}
