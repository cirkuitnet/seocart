<?php
/**
 * ClosedGateTransactions: the transaction manager of a site whose schema gate is closed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Support\Error\CodedException;

/**
 * Refuses every outermost unit of work as GatedTransactionManager does while the schema gate is in a given state.
 *
 * Owns one fact: how a test plays a closed schema gate without storing a boot record, which the
 * whole test process would read. At the outermost level it raises `store.unavailable` with the
 * gate state as its reason, the refusal GatedTransactionManager raises, and sends nothing; a
 * nested level, and everything else, goes to the manager it wraps.
 *
 * @since 0.1.0
 */
final class ClosedGateTransactions implements TransactionManager {

	/**
	 * The manager that does the work of a nested level.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $inner;

	/**
	 * The state the gate is closed in.
	 *
	 * @since 0.1.0
	 *
	 * @var GateState
	 */
	private GateState $state;

	/**
	 * Creates the manager.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $inner The manager that does the work of a nested level.
	 * @param GateState          $state The state the gate is closed in: any but Ready.
	 */
	public function __construct( TransactionManager $inner, GateState $state ) {
		$this->inner = $inner;
		$this->state = $state;
	}

	/**
	 * Refuses an outermost unit of work, and runs a nested one.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `store.unavailable` at the outermost level.
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param callable(): mixed $work  The unit of work.
	 * @param RetryPolicy|null  $retry Optional. Its retry policy. Default null.
	 * @return mixed What a nested unit of work returned.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed {
		if ( 0 === $this->inner->depth() ) {
			CodedException::raise( KernelError::StoreUnavailable, array( 'reason' => $this->state->value ) );
		}

		return $this->inner->transaction( $work, $retry );
	}

	/**
	 * Returns how many levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int The depth.
	 */
	public function depth(): int {
		return $this->inner->depth();
	}

	/**
	 * Runs work after the outermost level commits.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work.
	 */
	public function afterCommit( callable $callback ): void {
		$this->inner->afterCommit( $callback );
	}

	/**
	 * Runs work after the current level rolls back.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work.
	 */
	public function afterRollback( callable $callback ): void {
		$this->inner->afterRollback( $callback );
	}

	/**
	 * Records a cache key the current level touched.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 */
	public function touchCacheKey( string $key, string $group ): void {
		$this->inner->touchCacheKey( $key, $group );
	}
}
