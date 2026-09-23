<?php
/**
 * OutboxCommand: `wp seocart outbox`, which drains, reports on and prunes the outbox from the command line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events\Cli;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\DrainReport;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the outbox drainer on demand, prints the outbox's state, and deletes rows past retention.
 *
 * Owns one fact: the command's contract with an operator. `drain` exits 0 when it delivered
 * what was due or nothing was due, and 2 when another drainer holds the lock or delivery is
 * paused, having changed nothing. `status` and `prune` exit 0. A database failure exits 1 with
 * its code and message.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin,
 * so it resolves to no operation definition by design.
 *
 * Output goes through a callable and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class. The kernel registers
 * the command.
 *
 * @since 0.1.0
 */
final class OutboxCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: a database failure, or the command was used wrongly.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * Exit code: another drainer holds the lock, or delivery is paused; nothing was changed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_BLOCKED = 2;

	/**
	 * How many rows of each state one prune statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRUNE_BATCH = 1000;

	/**
	 * The drainer.
	 *
	 * @since 0.1.0
	 *
	 * @var OutboxDrainer
	 */
	private OutboxDrainer $drainer;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.1.0
	 *
	 * @param OutboxDrainer          $drainer The drainer.
	 * @param Outbox                 $outbox  The outbox rows.
	 * @param callable(string): void $output  Prints one line, for example WP_CLI::log().
	 */
	public function __construct( OutboxDrainer $drainer, Outbox $outbox, callable $output ) {
		$this->drainer = $drainer;
		$this->outbox  = $outbox;
		$this->output  = $output;
	}

	/**
	 * Delivers stored events, reports the outbox's state, or deletes rows past retention.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do. `drain` delivers what is due, `status` prints the counts, `prune` deletes
	 * dispatched and failed rows past retention.
	 * ---
	 * options:
	 *   - drain
	 *   - status
	 *   - prune
	 * ---
	 *
	 * [--budget=<seconds>]
	 * : With drain, how long to keep delivering.
	 * ---
	 * default: 60
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done, 1 on a database failure, 2 when another drainer holds the lock or delivery
	 * is paused.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart outbox drain
	 *     wp seocart outbox drain --budget=300
	 *     wp seocart outbox status
	 *     wp seocart outbox prune
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action: drain, status or prune.
	 * @param array<string, string|bool> $assocArgs The options: budget.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		try {
			switch ( $args[0] ?? '' ) {
				case 'drain':
					return $this->drain( self::intOption( $assocArgs, 'budget', DrainOptions::COMMAND_BUDGET_SECONDS ) );

				case 'status':
					return $this->status();

				case 'prune':
					return $this->prune();

				default:
					$this->say( 'Usage: wp seocart outbox <drain|status|prune> [--budget=<seconds>]' );

					return self::EXIT_FAILED;
			}
		} catch ( DatabaseException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}
	}

	/**
	 * Drains the outbox.
	 *
	 * @since 0.1.0
	 *
	 * @param int $budgetSeconds How long to keep delivering.
	 * @return int One of the EXIT_* constants.
	 */
	private function drain( int $budgetSeconds ): int {
		$report = $this->drainer->drain( DrainOptions::command( max( 1, $budgetSeconds ) ) );

		if ( DrainReport::SKIPPED_LOCKED === $report->skipped ) {
			$this->say( 'Another drainer holds the outbox lock, so nothing was done. Run the command again later.' );

			return self::EXIT_BLOCKED;
		}

		if ( DrainReport::SKIPPED_PAUSED === $report->skipped ) {
			$this->say( 'Event delivery is paused, so nothing was done.' );

			return self::EXIT_BLOCKED;
		}

		$this->say( sprintf( 'Claimed %d, dispatched %d, retried %d, parked as failed %d, pruned %d.', $report->claimed, $report->dispatched, $report->retried, $report->failed, $report->pruned ) );

		if ( $report->budgetExhausted ) {
			$this->say( 'Stopped when the time budget ran out; run the command again to continue.' );
		}

		return self::EXIT_OK;
	}

	/**
	 * Prints the outbox's state.
	 *
	 * @since 0.1.0
	 *
	 * @return int EXIT_OK.
	 */
	private function status(): int {
		$report = $this->outbox->report();

		$this->say( sprintf( 'Pending: %d%s', $report->pending, null === $report->oldestPendingSeconds ? '' : sprintf( ' (oldest stored %d seconds ago)', $report->oldestPendingSeconds ) ) );
		$this->say( sprintf( 'In flight: %d', $report->inFlight ) );
		$this->say( sprintf( 'Failed: %d', $report->failed ) );
		$this->say( sprintf( 'Dispatched in the last 24 hours: %d', $report->dispatchedLastDay ) );

		return self::EXIT_OK;
	}

	/**
	 * Deletes every row past retention, in bounded batches.
	 *
	 * @since 0.1.0
	 *
	 * @return int EXIT_OK.
	 */
	private function prune(): int {
		$total = 0;

		do {
			$deleted = $this->outbox->prune( self::PRUNE_BATCH );
			$total  += $deleted;
		} while ( $deleted > 0 );

		$this->say( sprintf( 'Pruned %d %s past retention.', $total, 1 === $total ? 'row' : 'rows' ) );

		return self::EXIT_OK;
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}

	/**
	 * Reads a whole-number option.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|bool> $assocArgs The options.
	 * @param string                     $name      The option name.
	 * @param int                        $fallback  The value when the option is absent or not a whole number.
	 * @return int The value.
	 */
	private static function intOption( array $assocArgs, string $name, int $fallback ): int {
		$value = $assocArgs[ $name ] ?? null;

		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ? (int) $value : $fallback;
	}
}
