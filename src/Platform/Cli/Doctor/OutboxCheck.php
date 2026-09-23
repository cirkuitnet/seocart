<?php
/**
 * OutboxCheck: no stored event failed delivery, and none has waited too long
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Events\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the outbox report and decides whether event delivery is healthy.
 *
 * Owns one fact: when doctor calls the outbox unhealthy. A failed row is an event that
 * delivery gave up on, which only a person can resolve. A pending row that has been due for
 * longer than STALLED_SECONDS means nothing has been delivering; the age is measured from the
 * row's `available_at`, so a row whose retry is scheduled for later is not counted until it is
 * due. The failure text and the payloads are never shown: only the counts and the age.
 *
 * @since 0.1.0
 */
final class OutboxCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'outbox';

	/**
	 * How long a pending event may be due before delivery counts as stalled: 15 minutes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALLED_SECONDS = 900;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Outbox $outbox The outbox rows.
	 */
	public function __construct( Outbox $outbox ) {
		$this->outbox = $outbox;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `outbox`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Reads the outbox's counts.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when nothing failed and nothing waits too long.
	 */
	public function run(): CheckResult {
		$report   = $this->outbox->report();
		$findings = array();

		if ( $report->failed > 0 ) {
			$findings[] = sprintf( '%d stored %s failed delivery and %s for a person to look at: `wp seocart outbox status`.', $report->failed, 1 === $report->failed ? 'event' : 'events', 1 === $report->failed ? 'waits' : 'wait' );
		}

		if ( null !== $report->oldestDueSeconds && $report->oldestDueSeconds > self::STALLED_SECONDS ) {
			$findings[] = sprintf( 'A pending event has been due for delivery for %d seconds, more than %d minutes: delivery has stalled. Run `wp seocart outbox drain`, and check that something runs it regularly.', $report->oldestDueSeconds, intdiv( self::STALLED_SECONDS, 60 ) );
		}

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, sprintf( '%d pending, none waiting more than %d minutes, and none failed.', $report->pending, intdiv( self::STALLED_SECONDS, 60 ) ) );
		}

		return CheckResult::fail( self::NAME, 'Event delivery needs attention.', $findings );
	}
}
