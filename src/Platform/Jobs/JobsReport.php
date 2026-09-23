<?php
/**
 * JobsReport: the state of the plugin's jobs and of the runner, at one moment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Counts of the plugin's jobs, the runner's last check-in, the handlers that fail, and which queue runtime is in control.
 *
 * Owns one fact: how the health of background work is told to `wp seocart jobs status`,
 * doctor and Site Health, so the three show the same numbers.
 *
 * The runner checks in whenever it starts one of the plugin's jobs. A recurring job runs every
 * few minutes, so a runner that works leaves a recent check-in even on a site with nothing
 * else to do; runnerStale() says when the last one is too old. What that means for the store,
 * mail and stock releases that are not running, is for the screen that shows it to say.
 *
 * The runtime is the queue library that actually runs the plugin's jobs: on a site where
 * another plugin bundles a newer copy of Action Scheduler, that copy is in control, not the
 * one SEOCart ships. runtimeSupported is false below MINIMUM_VERSION.
 *
 * The counts and the check-in are read from the library's tables. On a site where another
 * plugin has given the library a store of its own, customStore names it: the jobs are not in
 * those tables, so the counts and the check-in say nothing about them, and the plugin's own
 * triggers (`wp seocart jobs run`, the admin tick) run no job. WP-Cron still runs the plugin's
 * jobs through the library.
 *
 * @since 0.1.0
 */
final readonly class JobsReport {

	/**
	 * The oldest Action Scheduler version SEOCart's jobs are known to work with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MINIMUM_VERSION = '3.6.0';

	/**
	 * After how long without a check-in the runner counts as stale, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALE_AFTER_SECONDS = 3600;

	/**
	 * Jobs waiting whose time has come.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $due;

	/**
	 * Jobs waiting, due or not.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $pending;

	/**
	 * Jobs a runner is running now.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $inProgress;

	/**
	 * Jobs that failed for the last time and are still kept.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $failed;

	/**
	 * How long the oldest due job has been waiting, in seconds, or null when none is due.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $oldestDueSeconds;

	/**
	 * How long ago a runner last started one of the plugin's jobs, in seconds, or null when none ever did.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $secondsSinceCheckIn;

	/**
	 * The handlers with the most failed jobs, most first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{handler: string, failures: int, last_error: string}>
	 */
	public array $failingHandlers;

	/**
	 * The version of the queue runtime in control, or an empty string when none is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $runtimeVersion;

	/**
	 * Where the runtime in control comes from, for example `SEOCart` or `plugin woocommerce`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $runtimeSource;

	/**
	 * Every version that registered itself for the runtime's version negotiation, highest first.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $registeredVersions;

	/**
	 * Whether the runtime in control is MINIMUM_VERSION or newer.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $runtimeSupported;

	/**
	 * The class of the store Action Scheduler uses when another plugin chose it, or null for the library's own.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $customStore;

	/**
	 * Records the state.
	 *
	 * @since 0.1.0
	 *
	 * @param int                                                             $due                 Jobs due.
	 * @param int                                                             $pending             Jobs waiting.
	 * @param int                                                             $inProgress          Jobs running.
	 * @param int                                                             $failed              Jobs failed.
	 * @param int|null                                                        $oldestDueSeconds    Age of the oldest due job.
	 * @param int|null                                                        $secondsSinceCheckIn Age of the last check-in.
	 * @param list<array{handler: string, failures: int, last_error: string}> $failingHandlers     The handlers that fail most.
	 * @param string                                                          $runtimeVersion      The runtime version in control.
	 * @param string                                                          $runtimeSource       Where it comes from.
	 * @param string[]                                                        $registeredVersions  Every version registered.
	 * @param string|null                                                     $customStore         Optional. The class of a store another
	 *                                                                                             plugin chose. Default null, the
	 *                                                                                             library's own.
	 */
	public function __construct(
		int $due,
		int $pending,
		int $inProgress,
		int $failed,
		?int $oldestDueSeconds,
		?int $secondsSinceCheckIn,
		array $failingHandlers,
		string $runtimeVersion,
		string $runtimeSource,
		array $registeredVersions,
		?string $customStore = null
	) {
		$this->due                 = $due;
		$this->pending             = $pending;
		$this->inProgress          = $inProgress;
		$this->failed              = $failed;
		$this->oldestDueSeconds    = $oldestDueSeconds;
		$this->secondsSinceCheckIn = $secondsSinceCheckIn;
		$this->failingHandlers     = $failingHandlers;
		$this->runtimeVersion      = $runtimeVersion;
		$this->runtimeSource       = $runtimeSource;
		$this->registeredVersions  = $registeredVersions;
		$this->runtimeSupported    = '' !== $runtimeVersion && version_compare( $runtimeVersion, self::MINIMUM_VERSION, '>=' );
		$this->customStore         = $customStore;
	}

	/**
	 * Tells whether no runner has checked in for too long.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterSeconds Optional. How long without a check-in counts as stale. Default STALE_AFTER_SECONDS.
	 * @return bool True when no runner ever checked in, or the last check-in is older than that.
	 */
	public function runnerStale( int $afterSeconds = self::STALE_AFTER_SECONDS ): bool {
		return null === $this->secondsSinceCheckIn || $this->secondsSinceCheckIn > $afterSeconds;
	}
}
