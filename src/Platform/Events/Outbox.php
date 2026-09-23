<?php
/**
 * Outbox: the rows of the `outbox` table, and the one form an event takes in them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These messages name a class and a payload field for the developer's test run, never HTML.

/**
 * Stores events, leases them to one drainer at a time, and records how each delivery ended.
 *
 * Owns one fact: the `outbox` table's rows and every statement that changes them. Each change
 * of state is one conditional UPDATE whose WHERE clause carries the invariant:
 *
 * - a row is claimable only while it is pending, due, and unleased or its lease has lapsed;
 * - a row's delivery is counted as started, and the row marked dispatched, retried or parked,
 *   only by the drainer whose token it carries, so a drainer whose lease lapsed changes nothing;
 * - pending rows are never deleted.
 *
 * `attempts` counts deliveries started, one per row as its delivery begins, never per claim:
 * a claim leases a whole batch, and a row the batch never reached was not attempted.
 *
 * Every time is the database's `UTC_TIMESTAMP(6)`, the one clock every web node shares, so a
 * node with a fast clock never writes rows into the others' future. Its microseconds make
 * every mark change the row, so a statement that matched a row always reports it.
 *
 * It also owns the form an event takes in the `payload_json` column,
 * `{"v":<payload version>,"at":"<instant, UTC, microseconds>","p":{<fields>}}`, and the rules
 * a payload must keep to be stored: encode() and decode() are the one writer and the one
 * reader of that form, and are pure functions.
 *
 * @since 0.1.0
 */
final class Outbox {

	/**
	 * State: waiting to be delivered.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * State: every listener was invoked.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DISPATCHED = 'dispatched';

	/**
	 * State: delivery gave up; the row is kept, counted and reported, never deleted by a drain.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The claim: lease up to a number of due, unleased rows, in id order, to one token.
	 *
	 * Its placeholders are the table, the token, the lease in seconds and the number of rows.
	 * The WHERE clause carries the invariant, so two drainers sending it at once partition the
	 * rows between them. It counts no attempt. It is public so that a concurrency test can race
	 * the very statement from two raw connections.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLAIM = "UPDATE %i SET claim_token = %s, claimed_until = UTC_TIMESTAMP(6) + INTERVAL %d SECOND WHERE state = 'pending' AND available_at <= UTC_TIMESTAMP(6) AND ( claimed_until IS NULL OR claimed_until < UTC_TIMESTAMP(6) ) ORDER BY id LIMIT %d";

	/**
	 * The format of the instant stored with an event.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

	/**
	 * How many characters of an error `last_error` keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ERROR_LENGTH = 191;

	/**
	 * An aggregate type: lowercase snake_case, at most 32 characters, the width of its column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const AGGREGATE_TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	/**
	 * A payload field name: lowercase snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * How many days a dispatched row is kept.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $dispatchedRetentionDays;

	/**
	 * How many days a failed row is kept after it was parked.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $failedRetentionDays;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a retention period is shorter than one day.
	 *
	 * @param Database $db                      The connection.
	 * @param int      $dispatchedRetentionDays Optional. How many days a dispatched row is kept. Default 7.
	 * @param int      $failedRetentionDays     Optional. How many days a failed row is kept. Default 90.
	 */
	public function __construct( Database $db, int $dispatchedRetentionDays = 7, int $failedRetentionDays = 90 ) {
		if ( $dispatchedRetentionDays < 1 || $failedRetentionDays < 1 ) {
			throw new \InvalidArgumentException( 'Outbox rows are kept for at least one day.' );
		}

		$this->db                      = $db;
		$this->dispatchedRetentionDays = $dispatchedRetentionDays;
		$this->failedRetentionDays     = $failedRetentionDays;
	}

	/**
	 * Stores an event as a pending row, due at once. One INSERT, inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the database refuses the row.
	 *
	 * @param DomainEvent $event         The event.
	 * @param string      $payloadJson   What encode() returned for the event.
	 * @param string      $correlationId The correlation id of the request publishing it.
	 * @return int The row's id: the event id listeners deduplicate on.
	 */
	public function insert( DomainEvent $event, string $payloadJson, string $correlationId ): int {
		$this->db->execute(
			"INSERT INTO %i ( event_name, aggregate_type, aggregate_id, payload_json, correlation_id, state, available_at, attempts, created_at ) VALUES ( %s, %s, %d, %s, %s, 'pending', UTC_TIMESTAMP(6), 0, UTC_TIMESTAMP(6) )",
			$this->table(),
			$event::eventName(),
			$event->aggregateType(),
			$event->aggregateId(),
			$payloadJson,
			$correlationId
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Leases up to a number of due rows to a token and returns them.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When a statement fails.
	 *
	 * @param string $token        The claim's token: 64 hexadecimal digits, new for every claim.
	 * @param int    $leaseSeconds How long the lease lasts.
	 * @param int    $limit        How many rows to claim at most.
	 * @return list<array<string, mixed>> The claimed rows in id order, each with every column and
	 *                                    `lease_left_us`: the microseconds left on the lease, as the
	 *                                    database measures it.
	 */
	public function claim( string $token, int $leaseSeconds, int $limit ): array {
		if ( 0 === $this->db->execute( self::CLAIM, $this->table(), $token, $leaseSeconds, $limit ) ) {
			return array();
		}

		return $this->db->fetchAll(
			'SELECT *, TIMESTAMPDIFF( MICROSECOND, UTC_TIMESTAMP(6), claimed_until ) AS lease_left_us FROM %i WHERE claim_token = %s ORDER BY id',
			$this->table(),
			$token
		);
	}

	/**
	 * Counts one more delivery of a claimed row as started, if the token still holds a live lease.
	 *
	 * Sent just before the row's event is rebuilt and its listeners run: whatever happens next,
	 * even a process that dies, the start is on record. The lease must not have lapsed, on the
	 * database's clock: a drainer that stalled past it must not start a delivery that another
	 * drainer may already be entitled to claim, or charge the row an attempt for it.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails.
	 *
	 * @param int    $id    The row's id.
	 * @param string $token The token of the claim that leased it.
	 * @return bool False when the row no longer carries the token, is no longer pending or its
	 *              lease has lapsed: nothing may be started.
	 */
	public function startDelivery( int $id, string $token ): bool {
		return 1 === $this->db->execute(
			"UPDATE %i SET attempts = attempts + 1 WHERE id = %d AND claim_token = %s AND state = 'pending' AND claimed_until > UTC_TIMESTAMP(6)",
			$this->table(),
			$id,
			$token
		);
	}

	/**
	 * Marks a claimed row dispatched, if the token still holds its lease.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails.
	 *
	 * @param int    $id    The row's id.
	 * @param string $token The token of the claim that leased it.
	 * @return bool False when the row no longer carries the token: the lease lapsed and another
	 *              drainer holds the row, which it marks itself.
	 */
	public function markDispatched( int $id, string $token ): bool {
		return 1 === $this->db->execute(
			"UPDATE %i SET state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6), claim_token = NULL, claimed_until = NULL WHERE id = %d AND claim_token = %s",
			$this->table(),
			$id,
			$token
		);
	}

	/**
	 * Puts a claimed row back to pending, due after a delay, if the token still holds its lease.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails.
	 *
	 * @param int    $id           The row's id.
	 * @param string $token        The token of the claim that leased it.
	 * @param int    $delaySeconds How long until it may be claimed again.
	 * @param string $error        Why: a report code and the first words of the failure. Never a payload.
	 * @return bool False when the row no longer carries the token.
	 */
	public function retry( int $id, string $token, int $delaySeconds, string $error ): bool {
		return $this->release( $id, $token, self::PENDING, $delaySeconds, $error );
	}

	/**
	 * Parks a claimed row as failed, if the token still holds its lease. Its `available_at` records when.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails.
	 *
	 * @param int    $id    The row's id.
	 * @param string $token The token of the claim that leased it.
	 * @param string $error Why: a report code and the first words of the failure. Never a payload.
	 * @return bool False when the row no longer carries the token.
	 */
	public function park( int $id, string $token, string $error ): bool {
		return $this->release( $id, $token, self::FAILED, 0, $error );
	}

	/**
	 * Hands back the rows of a claim that were not attempted, so they may be claimed again at once.
	 *
	 * Their attempt counts are untouched: no delivery of theirs was started.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails.
	 *
	 * @param string $token The token of the claim.
	 * @return int How many rows were handed back.
	 */
	public function handBack( string $token ): int {
		return $this->db->execute(
			"UPDATE %i SET claim_token = NULL, claimed_until = NULL WHERE claim_token = %s AND state = 'pending'",
			$this->table(),
			$token
		);
	}

	/**
	 * Deletes dispatched and failed rows past their retention, at most a number of each.
	 *
	 * Two bounded DELETEs, each on its own index. Pending rows are never deleted.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When a statement fails.
	 *
	 * @param int $limit How many rows of each state to delete at most.
	 * @return int How many rows were deleted.
	 */
	public function prune( int $limit ): int {
		$dispatched = $this->db->execute(
			"DELETE FROM %i WHERE state = 'dispatched' AND dispatched_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY ORDER BY dispatched_at LIMIT %d",
			$this->table(),
			$this->dispatchedRetentionDays,
			$limit
		);

		// A parked row's available_at is the moment it was parked.
		$failed = $this->db->execute(
			"DELETE FROM %i WHERE state = 'failed' AND available_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY ORDER BY available_at LIMIT %d",
			$this->table(),
			$this->failedRetentionDays,
			$limit
		);

		return $dispatched + $failed;
	}

	/**
	 * Counts the rows by state, for doctor and Site Health. One query.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the query fails.
	 *
	 * @return OutboxReport The counts.
	 */
	public function report(): OutboxReport {
		$rows = $this->db->fetchAll(
			'SELECT state, COUNT(*) AS total, TIMESTAMPDIFF( SECOND, MIN( created_at ), UTC_TIMESTAMP(6) ) AS oldest_seconds, SUM( claimed_until > UTC_TIMESTAMP(6) ) AS leased, SUM( dispatched_at > UTC_TIMESTAMP(6) - INTERVAL 1 DAY ) AS recent FROM %i GROUP BY state',
			$this->table()
		);

		$byState = array();

		foreach ( $rows as $row ) {
			$byState[ (string) $row['state'] ] = $row;
		}

		$pending    = $byState[ self::PENDING ] ?? null;
		$failed     = $byState[ self::FAILED ] ?? null;
		$dispatched = $byState[ self::DISPATCHED ] ?? null;

		return new OutboxReport(
			null === $pending ? 0 : (int) $pending['total'],
			null === $pending ? null : (int) $pending['oldest_seconds'],
			null === $pending ? 0 : (int) $pending['leased'],
			null === $failed ? 0 : (int) $failed['total'],
			null === $dispatched ? 0 : (int) $dispatched['recent']
		);
	}

	/**
	 * Writes an event in the form the `payload_json` column stores, after checking its payload.
	 *
	 * The rules keep payloads to ids and summaries: every key is a lowercase snake_case string,
	 * every value an int, a string, a bool, null, or a list of those (two levels at most), and
	 * the encoded form at most the cap. An object is refused outright, which is what keeps Money,
	 * aggregates and posts out; so is a float, because money is never a float. The aggregate
	 * type must fit its column and the aggregate id must not be negative. Whether a field holds
	 * personal data is a review question, not a mechanical one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the event breaks a rule; the message names the event and the field.
	 *
	 * @param DomainEvent $event    The event.
	 * @param int         $capBytes The longest encoded form allowed, in bytes.
	 * @return string `{"v":…,"at":…,"p":{…}}`.
	 */
	public static function encode( DomainEvent $event, int $capBytes ): string {
		$class = get_class( $event );

		if ( 1 !== preg_match( self::AGGREGATE_TYPE_PATTERN, $event->aggregateType() ) ) {
			throw new \LogicException( sprintf( 'Event %s: the aggregate type "%s" must be lowercase snake_case of at most 32 characters.', $class, $event->aggregateType() ) );
		}

		if ( $event->aggregateId() < 0 ) {
			throw new \LogicException( sprintf( 'Event %s: the aggregate id must not be negative.', $class ) );
		}

		if ( $event::payloadVersion() < 1 ) {
			throw new \LogicException( sprintf( 'Event %s: the payload version starts at 1.', $class ) );
		}

		$payload = $event->toPayload();

		foreach ( $payload as $key => $value ) {
			self::checkField( $class, $key, $value );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A pure function that must run without WordPress, and must throw on invalid text; wp_json_encode() returns false.
			$json = json_encode(
				array(
					'v'  => $event::payloadVersion(),
					'at' => $event->occurredAt()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::INSTANT_FORMAT ),
					'p'  => (object) $payload,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			);
		} catch ( \JsonException $invalid ) {
			throw new \LogicException( sprintf( 'Event %s: the payload cannot be encoded as JSON (%s).', $class, $invalid->getMessage() ), 0, $invalid );
		}

		if ( strlen( $json ) > $capBytes ) {
			throw new \LogicException( sprintf( 'Event %s: the payload encodes to %d bytes, more than the %d allowed; the largest field is "%s". Store ids and summaries, not documents.', $class, strlen( $json ), $capBytes, self::largestField( $payload ) ) );
		}

		return $json;
	}

	/**
	 * Reads what encode() wrote.
	 *
	 * A value that is not JSON at all fails with the \JsonException json_decode() throws.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When the JSON is not in the stored form.
	 *
	 * @param string $payloadJson The column's value.
	 * @return array{v: int, at: \DateTimeImmutable, p: array<string, mixed>} The payload version, the
	 *         instant the event happened (UTC) and its fields.
	 */
	public static function decode( string $payloadJson ): array {
		$stored = json_decode( $payloadJson, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $stored ) || ! isset( $stored['v'], $stored['at'], $stored['p'] ) || ! is_int( $stored['v'] ) || ! is_string( $stored['at'] ) || ! is_array( $stored['p'] ) ) {
			throw new \UnexpectedValueException( 'The stored event is not in the form {"v":int,"at":string,"p":object}.' );
		}

		$at = \DateTimeImmutable::createFromFormat( self::INSTANT_FORMAT, $stored['at'] );

		if ( false === $at ) {
			throw new \UnexpectedValueException( 'The stored event\'s instant is not in the stored form.' );
		}

		return array(
			'v'  => $stored['v'],
			'at' => $at,
			'p'  => $stored['p'],
		);
	}

	/**
	 * Keeps the first characters of an error message for `last_error`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $error The message.
	 * @return string At most 191 characters.
	 */
	public static function shortenError( string $error ): string {
		return mb_substr( $error, 0, self::ERROR_LENGTH );
	}

	/**
	 * Returns the full table name on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wp_seocart_outbox`.
	 */
	private function table(): string {
		return $this->db->table( OutboxTable::NAME );
	}

	/**
	 * Sets a leased row's state, due time and error, if the token still holds its lease.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id           The row's id.
	 * @param string $token        The token of the claim that leased it.
	 * @param string $state        PENDING or FAILED.
	 * @param int    $delaySeconds How long from now the row becomes due; 0 for a parked row.
	 * @param string $error        Why.
	 * @return bool False when the row no longer carries the token.
	 */
	private function release( int $id, string $token, string $state, int $delaySeconds, string $error ): bool {
		return 1 === $this->db->execute(
			'UPDATE %i SET state = %s, claim_token = NULL, claimed_until = NULL, available_at = UTC_TIMESTAMP(6) + INTERVAL %d SECOND, last_error = %s WHERE id = %d AND claim_token = %s',
			$this->table(),
			$state,
			$delaySeconds,
			self::shortenError( $error ),
			$id,
			$token
		);
	}

	/**
	 * Refuses a payload field that is not an id or a plain value.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the field breaks a rule.
	 *
	 * @param string     $eventClass The event class.
	 * @param int|string $key        The field name.
	 * @param mixed      $value      The value.
	 */
	private static function checkField( string $eventClass, int|string $key, mixed $value ): void {
		if ( ! is_string( $key ) || 1 !== preg_match( self::FIELD_PATTERN, $key ) ) {
			throw new \LogicException( sprintf( 'Event %s: payload key "%s" must be a lowercase snake_case string.', $eventClass, (string) $key ) );
		}

		if ( self::isPlain( $value ) ) {
			return;
		}

		if ( ! is_array( $value ) ) {
			throw new \LogicException( sprintf( 'Event %s: payload field "%s" holds %s; a field holds an int, a string, a bool, null, or a list of those.', $eventClass, $key, get_debug_type( $value ) ) );
		}

		if ( ! array_is_list( $value ) ) {
			throw new \LogicException( sprintf( 'Event %s: payload field "%s" holds a map; a field may hold a list, never keys of its own.', $eventClass, $key ) );
		}

		foreach ( $value as $item ) {
			if ( ! self::isPlain( $item ) ) {
				throw new \LogicException( sprintf( 'Event %s: payload field "%s" holds a list containing %s; a list holds ints, strings, bools or null, two levels deep at most.', $eventClass, $key, get_debug_type( $item ) ) );
			}
		}
	}

	/**
	 * Tells whether a value is an int, a string, a bool or null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @return bool True for a plain value.
	 */
	private static function isPlain( mixed $value ): bool {
		return null === $value || is_int( $value ) || is_string( $value ) || is_bool( $value );
	}

	/**
	 * Names the field whose encoded value is longest.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload The checked payload.
	 * @return string The field name.
	 */
	private static function largestField( array $payload ): string {
		$largest = '';
		$bytes   = -1;

		foreach ( $payload as $key => $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See encode().
			$length = strlen( (string) json_encode( $value, JSON_UNESCAPED_SLASHES ) );

			if ( $length > $bytes ) {
				$largest = (string) $key;
				$bytes   = $length;
			}
		}

		return $largest;
	}
}
