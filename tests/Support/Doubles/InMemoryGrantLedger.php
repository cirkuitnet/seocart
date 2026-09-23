<?php
/**
 * InMemoryGrantLedger: a grant ledger that lives in one object, for tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Authorization\GrantLedger;

/**
 * Keeps the installer's record of one site in memory.
 *
 * It honours the GrantLedger contract: a pair recorded twice is kept once. It also counts the
 * calls to record(), so a test can tell a run that recorded nothing from one that recorded the
 * same pairs again.
 *
 * @since 0.1.0
 */
final class InMemoryGrantLedger implements GrantLedger {

	/**
	 * The recorded pairs: capabilities, keyed by role name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<string>>
	 */
	private array $granted;

	/**
	 * How many times record() has been called.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $recordCalls = 0;

	/**
	 * Creates a ledger, empty or holding an earlier record.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $granted Optional. Pairs recorded before. Default none.
	 */
	public function __construct( array $granted = array() ) {
		$this->granted = $granted;
	}

	/**
	 * Returns every recorded pair.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>> Capabilities, keyed by role name.
	 */
	public function granted(): array {
		return $this->granted;
	}

	/**
	 * Adds pairs to the record.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $grants Capabilities, keyed by role name.
	 */
	public function record( array $grants ): void {
		++$this->recordCalls;

		foreach ( $grants as $role => $capabilities ) {
			$this->granted[ $role ] = array_values( array_unique( array_merge( $this->granted[ $role ] ?? array(), $capabilities ) ) );
		}
	}

	/**
	 * Returns how many times record() has been called.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of calls.
	 */
	public function recordCalls(): int {
		return $this->recordCalls;
	}
}
