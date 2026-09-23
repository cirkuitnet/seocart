<?php
/**
 * ReportCode: the codes the Events module logs or records without throwing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the reporter, the log, the `outbox.last_error` column and doctor, never a client.
 *
 * This enum owns one fact: the vocabulary of what event delivery reports. No failure of
 * delivery is a client's: the operation that recorded the event has already committed. So
 * none of these is ever thrown, none has a row in the error table, and none may spell a code
 * the error table uses; a test composes the table and checks it.
 *
 * @since 0.1.0
 */
enum ReportCode: string {

	/**
	 * A listener threw. The other listeners still ran, and the event is not delivered again for it.
	 *
	 * @since 0.1.0
	 */
	case ListenerFailed = 'events.listener_failed';

	/**
	 * A listener took longer than the slow threshold. A report, not a failure.
	 *
	 * @since 0.1.0
	 */
	case ListenerSlow = 'events.listener_slow';

	/**
	 * The process died of a fatal error while a listener ran; the event is delivered again later.
	 *
	 * @since 0.1.0
	 */
	case ListenerFatal = 'events.listener_fatal';

	/**
	 * A stored event was claimed more often than delivery allows, and a claim ended with no outcome:
	 * the process died or exited while its listeners ran. It is parked without running them again,
	 * so it cannot head every later batch.
	 *
	 * @since 0.1.0
	 */
	case ListenerAbandoned = 'events.listener_abandoned';

	/**
	 * The drainer's lease on a row, or its lock, lapsed and another drainer took over; this one stopped.
	 *
	 * @since 0.1.0
	 */
	case LeaseLost = 'events.lease_lost';

	/**
	 * A stored event names no class in the event catalog. It is parked as failed; no retry can help.
	 *
	 * @since 0.1.0
	 */
	case UnknownEvent = 'events.unknown_event';

	/**
	 * A stored event was written with a payload version newer than its class knows. It is parked as failed.
	 *
	 * @since 0.1.0
	 */
	case PayloadVersion = 'events.payload_version';

	/**
	 * Delivering a stored event failed outside any listener, for example when rebuilding it; it is retried later or parked.
	 *
	 * @since 0.1.0
	 */
	case DispatchFailed = 'events.dispatch_failed';

	/**
	 * A drain at the end of a request failed as a whole, for example because the database was unreachable.
	 *
	 * @since 0.1.0
	 */
	case DrainFailed = 'events.drain_failed';
}
