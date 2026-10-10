<?php
/**
 * WebhookReceiptRetention: deletes webhook receipts past their retention, as a recurring job
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Jobs;

use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Jobs\BoundedSweep;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The retention sweep of `webhook_receipts`, as a recurring job.
 *
 * Owns one fact: when a receipt leaves the table. Every hour it deletes receipts past their
 * retention, a batch of BATCH at a time in expiry order on its index, until a batch comes back
 * short or BUDGET_SECONDS is spent; a backlog is the next run's. A receipt expires the retention
 * period after it was received, and expiry is judged by the database clock in the statement that
 * deletes, so a receipt still inside the period is never touched. Thirty days outlast every
 * provider's own retries; an event delivered again after its receipt is gone is processed again,
 * and the ledger's key still applies its money once. A recurring job is never retried: the next
 * run is the retry.
 *
 * @since 0.2.0
 */
final class WebhookReceiptRetention implements JobHandler {

	/**
	 * How many receipts one statement deletes at most.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BATCH = 1000;

	/**
	 * How long one run may keep deleting, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The receipts' statements.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlWebhookReceipts
	 */
	private MysqlWebhookReceipts $receipts;

	/**
	 * The loop that repeats the delete, in batches and within the budget.
	 *
	 * @since 0.2.0
	 *
	 * @var BoundedSweep
	 */
	private BoundedSweep $batches;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param MysqlWebhookReceipts $receipts The receipts' statements.
	 * @param int                  $batch    Optional. Receipts one statement deletes at most. Default BATCH.
	 * @param callable|null        $clock    Optional. Returns a monotonic time in nanoseconds (int).
	 *                                       Default null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( MysqlWebhookReceipts $receipts, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->receipts = $receipts;
		$this->batches  = new BoundedSweep( $batch, self::BUDGET_SECONDS, $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `webhook_receipts.prune`.
	 */
	public static function name(): string {
		return 'webhook_receipts.prune';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.2.0
	 *
	 * @return int Every hour.
	 */
	public static function recurrence(): int {
		return 3600;
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.2.0
	 *
	 * @return int 1: the next run is the retry.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Deletes batches of receipts past their retention until a batch comes back short or the budget is spent.
	 *
	 * @since 0.2.0
	 *
	 * @throws DatabaseException When a statement fails.
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$this->batches->run( $this->receipts->deleteExpired( ... ) );

		return null;
	}
}
