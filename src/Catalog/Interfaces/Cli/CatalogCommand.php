<?php
/**
 * CatalogCommand: `wp seocart catalog`, the catalog's maintenance from the command line
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Cli;

use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Summarizes the stock of every complete product, a page at a time, resumably.
 *
 * Owns one fact: the command's contract with an operator. `summarize-stock` walks the complete
 * products by id, PAGE at a time, recomputes each page's summaries as the stock events do, and
 * prints after each page the id it reached, which `--after` resumes from. A summary already right
 * is not written, so a second run writes nothing. It is how a store whose products were saved
 * before their summaries existed catches up; every save and every stock movement but a checkout's
 * hold and its release keeps them right after that, and a run also puts right a summary that a
 * hold given back left out of stock (StockSummaryProjector says when). It exits 0 when done, and
 * 1 on a database failure, with its code and message.
 *
 * It serialises with the drain that delivers the stock events: each page is recomputed and written
 * while the command holds the outbox drainer's lock, OutboxDrainer::LOCK_NAME, so a page and a drain
 * never recompute summaries at once, and a page never writes over a fresher summary that a drain
 * wrote from stock moved after the page read it. A drain that finds the lock held leaves its events
 * pending for the next drain, at the latest the catch-up job's, which reads the stock after the page
 * is written. A page waits up to LOCK_WAIT_SECONDS for a drain at work; a run that cannot take the
 * lock in that time stops with exit code 1 and the lock's message, and `--after` resumes it.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin,
 * so it resolves to no operation definition by design. Output goes through a callable and run()
 * returns the exit code, so tests call run() without WP-CLI.
 *
 * @since 0.2.0
 */
final class CatalogCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: a database failure, or the command was used wrongly.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * How many products one page summarizes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PAGE = 200;

	/**
	 * How long a page waits for a drain at work to give the drainer's lock back, in seconds: twice as
	 * long as `wp seocart outbox drain` delivers for.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const LOCK_WAIT_SECONDS = 2 * DrainOptions::COMMAND_BUDGET_SECONDS;

	/**
	 * How long a table-mode lease on the lock lasts: far longer than a page takes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 60;

	/**
	 * The summaries.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaries
	 */
	private StockSummaries $summaries;

	/**
	 * Recomputes summaries as the stock events do.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaryProjector
	 */
	private StockSummaryProjector $projector;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.2.0
	 *
	 * @param StockSummaries         $summaries The summaries.
	 * @param StockSummaryProjector  $projector Recomputes summaries as the stock events do.
	 * @param callable               $withLock  Takes a named lock, runs work while holding it and releases it.
	 * @param callable(string): void $output    Prints one line, for example WP_CLI::log().
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( StockSummaries $summaries, StockSummaryProjector $projector, callable $withLock, callable $output ) {
		$this->summaries = $summaries;
		$this->projector = $projector;
		$this->withLock  = $withLock;
		$this->output    = $output;
	}

	/**
	 * Summarizes the stock of every complete product for the listings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do. `summarize-stock` recomputes every complete product's stock summary.
	 * ---
	 * options:
	 *   - summarize-stock
	 * ---
	 *
	 * [--after=<id>]
	 * : Start after this product id: the id the last page of an interrupted run printed.
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done, 1 on a database failure, such as a drain of the outbox holding its lock for longer than a page waits.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart catalog summarize-stock
	 *     wp seocart catalog summarize-stock --after=4200
	 *
	 * @since 0.2.0
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
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The action: summarize-stock.
	 * @param array<string, string|bool> $assocArgs The options: after.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		if ( 'summarize-stock' !== ( $args[0] ?? '' ) ) {
			$this->say( 'Usage: wp seocart catalog summarize-stock [--after=<id>]' );

			return self::EXIT_FAILED;
		}

		try {
			return $this->summarizeStock( max( 0, (int) ( $assocArgs['after'] ?? 0 ) ) );
		} catch ( DatabaseException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}
	}

	/**
	 * Summarizes the complete products after a cursor, a page at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param int $after The last product id already done, or 0.
	 * @return int EXIT_OK.
	 */
	private function summarizeStock( int $after ): int {
		$products = 0;
		$written  = 0;

		do {
			$page = $this->summaries->completeProductIds( $after, self::PAGE );
			$full = self::PAGE === count( $page );

			if ( array() === $page ) {
				break;
			}

			$changed   = $this->summarizePage( $page );
			$after     = (int) end( $page );
			$products += count( $page );
			$written  += $changed;

			$this->say( sprintf( 'Summarized %1$d products up to id %2$d, %3$d written. To resume: --after=%2$d', count( $page ), $after, $changed ) );
		} while ( $full );

		$this->say( sprintf( 'Done: %1$d complete products summarized, %2$d written.', $products, $written ) );

		return self::EXIT_OK;
	}

	/**
	 * Recomputes one page's summaries while holding the drainer's lock, so no drain recomputes them meanwhile.
	 *
	 * @since 0.2.0
	 *
	 * @throws LockNotAcquired When a drain held the lock for the whole wait; nothing was written.
	 *
	 * @param int[] $productIds The page's products.
	 * @return int How many summaries were written.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	private function summarizePage( array $productIds ): int {
		$written = 0;

		( $this->withLock )(
			OutboxDrainer::LOCK_NAME,
			self::LOCK_TTL_SECONDS,
			self::LOCK_WAIT_SECONDS,
			function () use ( $productIds, &$written ): void {
				$written = $this->projector->project( $productIds );
			}
		);

		return $written;
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}
}
