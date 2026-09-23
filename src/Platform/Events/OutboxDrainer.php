<?php
/**
 * OutboxDrainer: delivers stored events to their listeners, at least once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\LockLost;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Lease;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Logging\CorrelationId;

defined( 'ABSPATH' ) || exit;

/**
 * Claims pending rows of the outbox, fires each event's action, and records how each delivery ended.
 *
 * Owns one fact: the delivery loop. One drainer at a time holds the `outbox_drain` lock; it
 * never waits for it, because at the end of a request nobody is there to wait. Holding it, the
 * drainer repeats until its time budget is spent or nothing is due: renew the lock, claim a
 * batch under a new token, and for each row rebuild the event, fire its action under the
 * correlation id stored with it, and mark the row. A row whose listeners all ran is marked
 * dispatched, whatever the listeners did: their failures are theirs, and are reported, not
 * retried. Only a failure outside the listeners (the row cannot be read or rebuilt, or the
 * bridge itself failed) puts the row back, after the Backoff delay, until the attempts run out
 * and it is parked as failed. A row whose event is unknown, or was stored by a newer payload
 * version, is parked at once. Every mark carries the claim's token, so a drainer whose lease
 * lapsed changes nothing and stops. Rows a batch did not reach are handed back unattempted.
 *
 * Delivery order: within one drainer, rows are dispatched in id order; across drainers, and
 * after a retry, there is no order, per aggregate or otherwise. Within one action, listeners
 * run in priority order, then in the order they were added. A listener reads current state
 * and keys its side effect on the envelope's outbox id; it never replays events as a log.
 *
 * Who drains: the request that published, at its end. Publisher's wake only notes the site
 * and, once per process, registers its end-of-request work: no query and no I/O at commit
 * time. At the end of the request, drainAtEndOfRequest() drains every site that published,
 * within the shutdown bounds. The listeners run after the response has been built, but the
 * client's connection stays open until the shutdown work is done unless the wake ends the
 * response first. scheduleAtShutdown() is the wake that does not: registering
 * drainAtShutdown(), it adds up to the drain's budget (2 seconds) of latency to the request
 * that published. The wake the plugin binds ends the response first where the server can,
 * and elsewhere hands the drain to a background job. `wp seocart outbox drain` and the job
 * runner call drain() with bounds of their own.
 *
 * A fatal error in a listener cannot be caught. When the drain runs from a request's main
 * code (the command, a job), a shutdown handler registered at the first drain puts the row
 * back at once and names the culprit (`events.listener_fatal`). When the drain itself runs at
 * shutdown, PHP runs no further shutdown function after a fatal one, and the row's lease is
 * what recovers it: after it lapses, the next drain claims the row again. Either way the row
 * is delivered again, never lost. A row whose deliveries keep ending that way, or with a
 * listener calling exit, is parked, without running its listeners, once as many of its
 * deliveries have started as the attempts allow (`events.listener_abandoned`), so it cannot
 * head every later batch and starve the rows behind it. An attempt is counted as a row's
 * delivery starts, one token-guarded UPDATE per row, never at the claim: rows claimed in the
 * same batch as a row that killed its process are not charged for it. That is one statement
 * per delivered row; a request that publishes nothing never drains and pays nothing.
 *
 * @since 0.1.0
 */
final class OutboxDrainer {

	/**
	 * The name of the lock one drainer holds at a time.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOCK_NAME = 'outbox_drain';

	/**
	 * How long the lock lasts without renewal, in seconds; it is renewed before every claim.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 120;

	/**
	 * How many rows of each state an opportunistic prune deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRUNE_LIMIT = 200;

	/**
	 * The error types that end the process: the ones WordPress's own fatal handler handles, and E_CORE_ERROR.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * Outcome of one row: dispatched.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DISPATCHED = 'dispatched';

	/**
	 * Outcome of one row: put back for a later attempt.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RETRIED = 'retried';

	/**
	 * Outcome of one row: parked as failed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FAILED = 'failed';

	/**
	 * Outcome of one row: the lease lapsed and another drainer holds the row.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LOST = 'lost';

	/**
	 * The connection, asked whether a transaction is open.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * Fires the actions.
	 *
	 * @since 0.1.0
	 *
	 * @var HookBridge
	 */
	private HookBridge $bridge;

	/**
	 * Maps stored event names to their classes.
	 *
	 * @since 0.1.0
	 *
	 * @var EventCatalog
	 */
	private EventCatalog $catalog;

	/**
	 * Takes the drain lock.
	 *
	 * @since 0.1.0
	 *
	 * @var LockService
	 */
	private LockService $locks;

	/**
	 * The correlation id each delivery runs under.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Tells whether delivery is paused.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): bool
	 */
	private $isPaused;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * The sites whose requests published events since the last drain at shutdown, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, true>
	 */
	private array $sites = array();

	/**
	 * Whether drainAtShutdown() is registered.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $wakeRegistered = false;

	/**
	 * The rows whose listeners are running right now in this process, for the fatal-error handler.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{drainer: self, id: int, token: string, hook: string, attempts: int, maxAttempts: int, correlation: string|null}>
	 */
	private static array $inFlight = array();

	/**
	 * Whether the fatal-error handler is registered in this process.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private static bool $fatalHandlerRegistered = false;

	/**
	 * Creates the drainer. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database      $db          The connection.
	 * @param Outbox        $outbox      The outbox rows.
	 * @param HookBridge    $bridge      Fires the actions.
	 * @param EventCatalog  $catalog     Maps stored event names to classes.
	 * @param LockService   $locks       Takes the drain lock.
	 * @param CorrelationId $correlation The correlation id each delivery runs under.
	 * @param callable      $report      Receives a report code (string) and its context (array).
	 * @param callable|null $isPaused    Optional. Returns true while delivery is paused. Default null,
	 *                                   never paused.
	 * @param callable|null $clock       Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                                   null, which uses hrtime().
	 */
	public function __construct(
		Database $db,
		Outbox $outbox,
		HookBridge $bridge,
		EventCatalog $catalog,
		LockService $locks,
		CorrelationId $correlation,
		callable $report,
		?callable $isPaused = null,
		?callable $clock = null
	) {
		$this->db          = $db;
		$this->outbox      = $outbox;
		$this->bridge      = $bridge;
		$this->catalog     = $catalog;
		$this->locks       = $locks;
		$this->correlation = $correlation;
		$this->report      = $report;
		$this->isPaused    = $isPaused ?? static fn(): bool => false;
		$this->clock       = $clock ?? static fn(): int => (int) hrtime( true );
	}

	/**
	 * Delivers what is due, within the bounds given.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open: listeners never run inside one.
	 *
	 * @param DrainOptions $options The bounds.
	 * @return DrainReport What the drain did; `skipped` says why it did nothing. A database
	 *                     failure outside the listeners is thrown, and the claimed rows it
	 *                     stranded are delivered after their lease lapses.
	 */
	public function drain( DrainOptions $options ): DrainReport {
		return $this->drainUntil( $options, ( $this->clock )() + $options->timeBudgetSeconds * self::NANOSECONDS );
	}

	/**
	 * Wakes the drainer for the end of this request. Publisher calls it after a commit that stored events.
	 *
	 * Notes the current site and, the first time in the process, registers drainAtShutdown().
	 * No query, no hook, no I/O: nothing can be lost between the commit and the drain, because
	 * the rows are already committed and any later drain delivers them.
	 *
	 * @since 0.1.0
	 */
	public function scheduleAtShutdown(): void {
		$this->sites[ get_current_blog_id() ] = true;

		if ( $this->wakeRegistered ) {
			return;
		}

		$this->wakeRegistered = true;

		register_shutdown_function( array( $this, 'drainAtShutdown' ) );
	}

	/**
	 * Drains, at the end of the request, the outbox of every site that published. Registered by scheduleAtShutdown().
	 *
	 * After a fatal error in the request nothing is drained: the rows wait for the next wake, and
	 * a row left leased by the fatal error is claimed again once its lease lapses. Otherwise the
	 * sites are drained by drainAtEndOfRequest(). Nothing is ever thrown, because it is shutdown.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $error Optional. The last error, as error_get_last() describes it.
	 *                                         Default null, which reads error_get_last().
	 */
	public function drainAtShutdown( ?array $error = null ): void {
		$sites       = array_keys( $this->sites );
		$this->sites = array();

		if ( array() === $sites || self::isFatal( $error ?? error_get_last() ) ) {
			return;
		}

		$this->drainAtEndOfRequest( $sites );
	}

	/**
	 * Drains the outbox of each site given, with the shutdown bounds. The end-of-request drain of every wake.
	 *
	 * Each site is switched to when it is not the current one. The sites share one time budget:
	 * each drain stops at the deadline the first one started with, so a request that published
	 * on several sites still spends at most the budget. The caller has checked that the request
	 * did not end in a fatal error (isFatal()). Nothing is ever thrown, because it runs at
	 * shutdown; failures are reported.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $sites The ids of the sites that published.
	 */
	public function drainAtEndOfRequest( array $sites ): void {
		$options  = DrainOptions::shutdown();
		$deadline = ( $this->clock )() + $options->timeBudgetSeconds * self::NANOSECONDS;

		foreach ( $sites as $site ) {
			if ( ( $this->clock )() >= $deadline ) {
				return;
			}

			$switched = is_multisite() && get_current_blog_id() !== $site;

			if ( $switched ) {
				switch_to_blog( $site );
			}

			try {
				$this->drainUntil( $options, $deadline );
			} catch ( \Throwable $failure ) {
				$this->reportSafely(
					ReportCode::DrainFailed,
					array(
						'site'      => $site,
						'exception' => get_class( $failure ),
						'message'   => $failure->getMessage(),
					)
				);
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Puts back the rows whose listeners were running when the process died of a fatal error.
	 *
	 * Registered as a shutdown function at the first drain in the process. Each such row is
	 * retried after the Backoff delay, or parked once its attempts are spent, with `last_error`
	 * naming the action and the file and line of the error. A row whose listener died inside a
	 * transaction is left to its lease: no statement on that connection could be made durable
	 * without committing the listener's half-done work. Nothing is thrown.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $error Optional. The error, as error_get_last() describes it.
	 *                                         Default null, which reads error_get_last().
	 */
	public static function handleShutdown( ?array $error = null ): void {
		$error ??= error_get_last();

		if ( ! self::isFatal( $error ) ) {
			return;
		}

		foreach ( array_reverse( self::$inFlight ) as $key => $flight ) {
			unset( self::$inFlight[ $key ] );

			$flight['drainer']->releaseAfterFatal( $flight, (array) $error );
		}
	}

	/**
	 * Lists the callbacks registered on every catalogued event's action, for doctor.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{hook: string, callback: string, priority: int, plugin: string}> One entry per callback.
	 */
	public function listeners(): array {
		$listeners = array();

		foreach ( $this->catalog->names() as $name ) {
			$listeners = array_merge( $listeners, $this->bridge->listeners( EventEnvelope::hookFor( $name ) ) );
		}

		return $listeners;
	}

	/**
	 * Delivers what is due until a deadline.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open.
	 *
	 * @param DrainOptions $options  The bounds; their time budget is already in the deadline.
	 * @param int          $deadline When to stop, on the drainer's clock, in nanoseconds.
	 * @return DrainReport What the drain did.
	 */
	private function drainUntil( DrainOptions $options, int $deadline ): DrainReport {
		if ( 0 !== $this->db->depth() ) {
			throw new \LogicException( 'The outbox is drained outside any transaction: a listener must never run inside one.' );
		}

		if ( ( $this->isPaused )() ) {
			return DrainReport::skipped( DrainReport::SKIPPED_PAUSED );
		}

		self::registerFatalHandler();

		try {
			return $this->locks->withLock( self::LOCK_NAME, self::LOCK_TTL_SECONDS, 0, fn( Lease $lease ): DrainReport => $this->drainHolding( $lease, $options, $deadline ) );
		} catch ( LockNotAcquired $held ) {
			// Another drainer is at work; it takes what is due, and the next wake takes the rest.
			return DrainReport::skipped( DrainReport::SKIPPED_LOCKED );
		}
	}

	/**
	 * Runs the delivery loop while holding the drain lock.
	 *
	 * @since 0.1.0
	 *
	 * @param Lease        $lease    The drain lock.
	 * @param DrainOptions $options  The bounds.
	 * @param int          $deadline When to stop, on the drainer's clock, in nanoseconds.
	 * @return DrainReport What the drain did.
	 */
	private function drainHolding( Lease $lease, DrainOptions $options, int $deadline ): DrainReport {
		$counts    = array(
			self::DISPATCHED => 0,
			self::RETRIED    => 0,
			self::FAILED     => 0,
		);
		$claimed   = 0;
		$exhausted = false;
		$reserve   = DrainOptions::LEASE_RESERVE_SECONDS * self::NANOSECONDS;

		while ( ! $exhausted ) {
			if ( ( $this->clock )() >= $deadline ) {
				$exhausted = true;

				break;
			}

			try {
				$lease->renew();
			} catch ( LockLost $lost ) {
				// The lock lapsed and another drainer may be at work; from here the row leases decide.
				$this->reportSafely(
					ReportCode::LeaseLost,
					array(
						'lease'  => 'lock',
						'lock'   => self::LOCK_NAME,
						'reason' => (string) ( $lost->context()['reason'] ?? '' ),
					)
				);

				break;
			}

			$token = bin2hex( random_bytes( 32 ) );
			$rows  = $this->outbox->claim( $token, $options->leaseSeconds, $options->batchSize );

			if ( array() === $rows ) {
				break;
			}

			$claimed  += count( $rows );
			$leaseEnds = ( $this->clock )() + (int) $rows[0]['lease_left_us'] * 1000;
			$started   = 0;

			foreach ( $rows as $row ) {
				$now = ( $this->clock )();

				if ( $now >= $deadline ) {
					$exhausted = true;

					break;
				}

				// Never start a listener the lease cannot cover.
				if ( $leaseEnds - $now < $reserve ) {
					break;
				}

				++$started;

				$outcome = $this->dispatchRow( $row, $token, $options );

				if ( self::LOST === $outcome ) {
					break;
				}

				++$counts[ $outcome ];
			}

			if ( $started < count( $rows ) ) {
				$this->outbox->handBack( $token );
			}
		}

		$pruned = $options->prune && $counts[ self::DISPATCHED ] > 0 ? $this->outbox->prune( self::PRUNE_LIMIT ) : 0;

		return new DrainReport( $claimed, $counts[ self::DISPATCHED ], $counts[ self::RETRIED ], $counts[ self::FAILED ], null, $exhausted, $pruned );
	}

	/**
	 * Delivers one claimed row and records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row     The claimed row.
	 * @param string               $token   The claim's token.
	 * @param DrainOptions         $options The bounds.
	 * @return string DISPATCHED, RETRIED, FAILED or LOST.
	 */
	private function dispatchRow( array $row, string $token, DrainOptions $options ): string {
		$id            = (int) $row['id'];
		$attempts      = (int) $row['attempts'];
		$name          = (string) $row['event_name'];
		$correlationId = isset( $row['correlation_id'] ) ? (string) $row['correlation_id'] : null;

		/*
		 * `attempts` counts deliveries of this row that were started, and a started delivery
		 * that ends records an outcome: dispatched, or retried or parked once the attempts are
		 * spent. So a pending row whose started deliveries already reached the limit had one
		 * that ended with none: a process died or exited while its listeners ran, in a place no
		 * handler could record it. Delivering it again would do the same, and the row would head
		 * every later batch; it is parked before anything runs. A row claimed in the same batch
		 * but never started carries no attempt for it.
		 */
		if ( $attempts >= $options->maxAttempts ) {
			return $this->parkUndeliverable(
				$id,
				$token,
				ReportCode::ListenerAbandoned,
				$name,
				sprintf( 'the process died or exited during delivery; parked after %d started deliveries without running the listeners again', $attempts ),
				array( 'attempts' => $attempts ),
				$correlationId
			);
		}

		$class = $this->catalog->classFor( $name );

		if ( null === $class ) {
			// Removed by a later release, or written by a newer one: no retry can help.
			return $this->parkUndeliverable( $id, $token, ReportCode::UnknownEvent, $name, 'no registered event has this name', array(), $correlationId );
		}

		if ( ! $this->outbox->startDelivery( $id, $token ) ) {
			$this->reportLeaseLost( $id, $name, $correlationId );

			return self::LOST;
		}

		// This delivery's number, which the envelope hands to listeners.
		++$attempts;

		$row['attempts'] = $attempts;

		try {
			$stored = Outbox::decode( (string) $row['payload_json'] );
		} catch ( \Throwable $unreadable ) {
			return $this->retryOrPark( $id, $token, $name, $attempts, $options->maxAttempts, $unreadable, $correlationId );
		}

		if ( $stored['v'] > $class::payloadVersion() ) {
			return $this->parkUndeliverable(
				$id,
				$token,
				ReportCode::PayloadVersion,
				$name,
				sprintf( 'stored with payload version %d; the class reads up to %d', $stored['v'], $class::payloadVersion() ),
				array(
					'stored_version' => $stored['v'],
					'known_version'  => $class::payloadVersion(),
				),
				$correlationId
			);
		}

		$flight = spl_object_id( $this ) . ':' . $id . ':' . $token;

		try {
			$envelope = EventEnvelope::fromRow( $row, $class::fromPayload( $stored['p'], $stored['at'], $stored['v'] ) );

			self::$inFlight[ $flight ] = array(
				'drainer'     => $this,
				'id'          => $id,
				'token'       => $token,
				'hook'        => $envelope->hook,
				'attempts'    => $attempts,
				'maxAttempts' => $options->maxAttempts,
				'correlation' => $correlationId,
			);

			$this->correlation->scoped( $correlationId, fn() => $this->bridge->dispatch( $envelope ) );
		} catch ( \Throwable $failure ) {
			return $this->retryOrPark( $id, $token, $name, $attempts, $options->maxAttempts, $failure, $correlationId );
		} finally {
			unset( self::$inFlight[ $flight ] );
		}

		if ( $this->outbox->markDispatched( $id, $token ) ) {
			return self::DISPATCHED;
		}

		$this->reportLeaseLost( $id, $name, $correlationId );

		return self::LOST;
	}

	/**
	 * Puts a row back for a later attempt, or parks it once its attempts are spent.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $id            The row's id.
	 * @param string      $token         The claim's token.
	 * @param string      $name          The event's name.
	 * @param int         $attempts      How many attempts the row has had, this one included.
	 * @param int         $maxAttempts   After how many attempts a row is parked.
	 * @param \Throwable  $failure       What failed.
	 * @param string|null $correlationId The correlation id stored with the row, which the report carries.
	 * @return string RETRIED, FAILED or LOST.
	 */
	private function retryOrPark( int $id, string $token, string $name, int $attempts, int $maxAttempts, \Throwable $failure, ?string $correlationId ): string {
		$park  = $attempts >= $maxAttempts;
		$delay = $park ? 0 : Backoff::seconds( max( 1, $attempts ) );
		$error = ReportCode::DispatchFailed->value . ' ' . get_class( $failure ) . ': ' . $failure->getMessage();

		$released = $park ? $this->outbox->park( $id, $token, $error ) : $this->outbox->retry( $id, $token, $delay, $error );

		$this->correlation->scoped(
			$correlationId,
			fn() => $this->reportSafely(
				ReportCode::DispatchFailed,
				array(
					'outbox_id' => $id,
					'event'     => $name,
					'attempt'   => $attempts,
					'exception' => get_class( $failure ),
					'message'   => $failure->getMessage(),
					'parked'    => $park,
					'retry_in'  => $delay,
				)
			)
		);

		if ( ! $released ) {
			$this->reportLeaseLost( $id, $name, $correlationId );

			return self::LOST;
		}

		return $park ? self::FAILED : self::RETRIED;
	}

	/**
	 * Parks a row no retry can help, without running a listener, and reports why.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id            The row's id.
	 * @param string               $token         The claim's token.
	 * @param ReportCode           $code          ListenerAbandoned, UnknownEvent or PayloadVersion.
	 * @param string               $name          The event's name.
	 * @param string               $why           Why, for `last_error` after the code and the name.
	 * @param array<string, mixed> $context       What was found, for the report.
	 * @param string|null          $correlationId The correlation id stored with the row, which the report carries.
	 * @return string FAILED or LOST.
	 */
	private function parkUndeliverable( int $id, string $token, ReportCode $code, string $name, string $why, array $context, ?string $correlationId ): string {
		$parked = $this->outbox->park( $id, $token, $code->value . ' ' . $name . ': ' . $why );

		$this->correlation->scoped(
			$correlationId,
			fn() => $this->reportSafely(
				$code,
				array(
					'outbox_id' => $id,
					'event'     => $name,
				) + $context
			)
		);

		if ( ! $parked ) {
			$this->reportLeaseLost( $id, $name, $correlationId );

			return self::LOST;
		}

		return self::FAILED;
	}

	/**
	 * Puts back, after a fatal error, a row whose listeners were running.
	 *
	 * @since 0.1.0
	 *
	 * The report is written under the correlation id stored with the row, whatever id the
	 * listener that died left in force.
	 *
	 * @param array $flight The row: its drainer, id, token, action, attempts, the attempts allowed and its correlation id.
	 * @param array $error  The fatal error, as error_get_last() describes it.
	 *
	 * @phpstan-param array{drainer: self, id: int, token: string, hook: string, attempts: int, maxAttempts: int, correlation: string|null} $flight
	 * @phpstan-param array<mixed> $error
	 */
	private function releaseAfterFatal( array $flight, array $error ): void {
		$where   = sprintf( '%s:%d', (string) ( $error['file'] ?? '' ), (int) ( $error['line'] ?? 0 ) );
		$park    = $flight['attempts'] >= $flight['maxAttempts'];
		$context = array(
			'hook'      => $flight['hook'],
			'outbox_id' => $flight['id'],
			'attempt'   => $flight['attempts'],
			'where'     => $where,
			'message'   => (string) ( $error['message'] ?? '' ),
			'parked'    => $park,
		);

		$report = function ( bool $released ) use ( $flight, $context ): void {
			try {
				$this->correlation->scoped( $flight['correlation'], fn() => $this->reportSafely( ReportCode::ListenerFatal, $context + array( 'released' => $released ) ) );
			} catch ( \Throwable $unscoped ) {
				// Only restoring the id could throw here, at shutdown, where nothing may.
				unset( $unscoped );
			}
		};

		if ( 0 !== $this->db->depth() ) {
			$report( false );

			return;
		}

		try {
			$message  = ReportCode::ListenerFatal->value . ' ' . $flight['hook'] . ' ' . $where;
			$released = $park
				? $this->outbox->park( $flight['id'], $flight['token'], $message )
				: $this->outbox->retry( $flight['id'], $flight['token'], Backoff::seconds( max( 1, $flight['attempts'] ) ), $message );

			$report( $released );
		} catch ( \Throwable $failure ) {
			$report( false );
		}
	}

	/**
	 * Reports that this drainer's lease on a row lapsed and another drainer holds it.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $id            The row's id.
	 * @param string      $name          The event's name.
	 * @param string|null $correlationId The correlation id stored with the row, which the report carries.
	 */
	private function reportLeaseLost( int $id, string $name, ?string $correlationId ): void {
		$this->correlation->scoped(
			$correlationId,
			fn() => $this->reportSafely(
				ReportCode::LeaseLost,
				array(
					'lease'     => 'row',
					'outbox_id' => $id,
					'event'     => $name,
				)
			)
		);
	}

	/**
	 * Reports. A reporter that throws loses its report, never the delivery of the rows after it.
	 *
	 * @since 0.1.0
	 *
	 * @param ReportCode           $code    The code.
	 * @param array<string, mixed> $context What happened.
	 */
	private function reportSafely( ReportCode $code, array $context ): void {
		try {
			( $this->report )( $code->value, $context );
		} catch ( \Throwable $reporterFailed ) {
			unset( $reporterFailed );
		}
	}

	/**
	 * Registers the fatal-error handler, once per process.
	 *
	 * @since 0.1.0
	 */
	private static function registerFatalHandler(): void {
		if ( self::$fatalHandlerRegistered ) {
			return;
		}

		self::$fatalHandlerRegistered = true;

		register_shutdown_function( array( self::class, 'handleShutdown' ) );
	}

	/**
	 * Tells whether an error, as error_get_last() describes it, ended the process.
	 *
	 * Public for the work that runs at shutdown elsewhere, which checks it before it acts.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $error The description, or null.
	 * @return bool True for a fatal error.
	 */
	public static function isFatal( mixed $error ): bool {
		return is_array( $error ) && 0 !== ( (int) ( $error['type'] ?? 0 ) & self::FATAL_ERRORS );
	}
}
