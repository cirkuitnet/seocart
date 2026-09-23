<?php
/**
 * RecordingDatabaseState: a DatabaseState that remembers every head it is told
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\LockMode;

/**
 * A DatabaseState that records what the migrator writes to it, and reads nothing.
 *
 * Owns one fact: what a migration run recorded as the schema head. A test asserts on the
 * list, which a state that answers from the table could not show, because its answer would
 * come from the table whatever the migrator recorded.
 *
 * @since 0.1.0
 */
final class RecordingDatabaseState implements DatabaseState {

	/**
	 * Every head recorded, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $heads = array();

	/**
	 * The lock mode recorded last, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var LockMode|null
	 */
	private ?LockMode $lockMode = null;

	/**
	 * Returns the head recorded last.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The head, or null when none was recorded.
	 */
	public function schemaHead(): ?string {
		return array() === $this->heads ? null : $this->heads[ count( $this->heads ) - 1 ];
	}

	/**
	 * Records a head.
	 *
	 * @since 0.1.0
	 *
	 * @param string $migrationId The id of the newest applied migration.
	 */
	public function recordSchemaHead( string $migrationId ): void {
		$this->heads[] = $migrationId;
	}

	/**
	 * Returns the lock mode recorded last.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode|null The mode, or null.
	 */
	public function lockMode(): ?LockMode {
		return $this->lockMode;
	}

	/**
	 * Records a lock mode.
	 *
	 * @since 0.1.0
	 *
	 * @param LockMode $mode The mode.
	 */
	public function recordLockMode( LockMode $mode ): void {
		$this->lockMode = $mode;
	}
}
