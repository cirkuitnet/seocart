<?php
/**
 * SchemaGate: decides, at no query, whether the current site's schema lets the store take writes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the schema head cached in the boot record with the code's migration chain.
 *
 * Owns one fact: which GateState the current site is in. The rule itself belongs to the
 * migrator, which decides from a head alone whether writes are blocked; the gate supplies the
 * cached head and names the state. It sends no query: the record is autoloaded, and the
 * migrator's decision reads no table. Nothing evaluates it while the plugin boots; the first
 * write, notice or check that asks pays for building the migrator, and the answer is remembered
 * for each head, so a head recorded later in the same request is answered afresh.
 *
 * @since 0.1.0
 */
final class SchemaGate {

	/**
	 * The boot record.
	 *
	 * @since 0.1.0
	 *
	 * @var BootOption
	 */
	private BootOption $bootOption;

	/**
	 * Returns the migrator, built on first use.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Migrator
	 */
	private \Closure $migrator;

	/**
	 * The state for each recorded head seen so far, keyed by the head ('' for none).
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, GateState>
	 */
	private array $states = array();

	/**
	 * Creates the gate. Reads nothing and builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param BootOption          $bootOption The boot record.
	 * @param callable():Migrator $migrator   Returns the migrator.
	 */
	public function __construct( BootOption $bootOption, callable $migrator ) {
		$this->bootOption = $bootOption;
		$this->migrator   = \Closure::fromCallable( $migrator );
	}

	/**
	 * Returns the state of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return GateState NotInstalled without a record; SchemaNewer when the recorded head sorts after
	 *                   the code's, or when a record of a newer shape holds a head this version cannot
	 *                   read; CodeNewer when the migrator blocks writes for any other reason; otherwise Ready.
	 */
	public function state(): GateState {
		$record = $this->bootOption->read();

		if ( $record->isAbsent() ) {
			return GateState::NotInstalled;
		}

		$head = $record->schemaHead();

		if ( null === $head && $record->isNewerShape() ) {
			// A newer version wrote a head this version cannot read: the schema is that version's.
			return GateState::SchemaNewer;
		}

		$key = (string) $head;

		if ( ! isset( $this->states[ $key ] ) ) {
			$migrator = ( $this->migrator )();

			if ( null !== $head && strcmp( $head, $migrator->codeHead() ) > 0 ) {
				$this->states[ $key ] = GateState::SchemaNewer;
			} elseif ( $migrator->writesBlocked( $head ) ) {
				$this->states[ $key ] = GateState::CodeNewer;
			} else {
				$this->states[ $key ] = GateState::Ready;
			}
		}

		return $this->states[ $key ];
	}

	/**
	 * Tells whether commerce writes must be refused on the current site. The transaction manager
	 * applications receive asks it before every outermost unit of work, and the degraded-mode
	 * notice before it says anything.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True in every state but Ready.
	 */
	public function writesBlocked(): bool {
		return GateState::Ready !== $this->state();
	}
}
