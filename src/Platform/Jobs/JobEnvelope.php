<?php
/**
 * JobEnvelope: a job as it is stored in the queue, with the metadata of one run
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These messages describe stored job data for the developer and the log, never HTML; this class may not call WordPress.

/**
 * A job, the correlation id of the request that queued it, which attempt this is, and its interval when it recurs.
 *
 * Owns one fact: the form a job takes in the queue's storage, and the rules it must keep to be
 * stored. The queue stores `[{"h":…,"k":…,"c":…,"n":…,"p":{…}}]`: one argument, a map of the
 * handler name, the unique key, the correlation id, the attempt number and the payload, with
 * absent parts left out; a recurring job stores `[{"h":…,"r":…}]`, the handler and its
 * interval in seconds. The runner's hook receives the map. The fields are always written in
 * that order, so the start of the stored text identifies a handler, a keyed job or a
 * recurring job: prefixFor(), keyPrefix() and recurringPrefix() build those starts, and the
 * queue finds jobs by them.
 *
 * The stored text must fit MAX_ENCODED_LENGTH, the width of Action Scheduler's indexed
 * `args` column. Longer arguments would be stored as a hash, which neither the index nor the
 * lookups by prefix could use, so they are refused when the envelope is built.
 *
 * encode() writes what the queue stores and fromStored() reads what the hook receives; both are
 * pure functions.
 *
 * @since 0.1.0
 */
final readonly class JobEnvelope {

	/**
	 * The longest stored form, in bytes: Action Scheduler's indexed `args` column.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_ENCODED_LENGTH = 191;

	/**
	 * The shortest interval of a recurring job, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MIN_INTERVAL_SECONDS = 60;

	/**
	 * The longest correlation id: a UUID in its canonical form.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CORRELATION_MAX_LENGTH = 36;

	/**
	 * The job.
	 *
	 * @since 0.1.0
	 *
	 * @var Job
	 */
	public Job $job;

	/**
	 * The correlation id of the request that queued the job, or null for a recurring run.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $correlationId;

	/**
	 * Which attempt this run is, from 1.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $attempt;

	/**
	 * The interval of a recurring job, in seconds, or null for a job that runs once.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $every;

	/**
	 * Describes a stored job, after checking that it can be stored.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the attempt is below 1, the interval is below
	 *                                   MIN_INTERVAL_SECONDS, a recurring job carries a key, a
	 *                                   payload, a correlation id or an attempt above 1, or the
	 *                                   stored form would be longer than MAX_ENCODED_LENGTH.
	 *
	 * @param Job         $job           The job.
	 * @param string|null $correlationId Optional. The correlation id of the request that queued it. Default null.
	 * @param int         $attempt       Optional. Which attempt this is, from 1. Default 1.
	 * @param int|null    $every         Optional. The interval of a recurring job, in seconds. Default null.
	 */
	public function __construct( Job $job, ?string $correlationId = null, int $attempt = 1, ?int $every = null ) {
		if ( $attempt < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A %s job cannot be on attempt %d; attempts start at 1.', $job->handler, $attempt ) );
		}

		if ( null !== $correlationId && ( '' === $correlationId || strlen( $correlationId ) > self::CORRELATION_MAX_LENGTH ) ) {
			throw new \InvalidArgumentException( sprintf( 'The correlation id of a %s job must be a UUID of %d characters at most.', $job->handler, self::CORRELATION_MAX_LENGTH ) );
		}

		if ( null !== $every ) {
			if ( $every < self::MIN_INTERVAL_SECONDS ) {
				throw new \InvalidArgumentException( sprintf( 'A recurring %s job runs every %d seconds at most often, not every %d.', $job->handler, self::MIN_INTERVAL_SECONDS, $every ) );
			}

			if ( null !== $job->uniqueKey || array() !== $job->payload || null !== $correlationId || 1 !== $attempt ) {
				throw new \InvalidArgumentException( sprintf( 'A recurring %s job carries its handler and interval only: no key, payload, correlation id or retry.', $job->handler ) );
			}
		}

		$this->job           = $job;
		$this->correlationId = $correlationId;
		$this->attempt       = $attempt;
		$this->every         = $every;

		$length = strlen( $this->encode() );

		if ( $length > self::MAX_ENCODED_LENGTH ) {
			throw new \InvalidArgumentException( sprintf( 'A %s job is %d bytes when stored, and at most %d fit the queue\'s indexed column: carry ids, not documents.', $job->handler, $length, self::MAX_ENCODED_LENGTH ) );
		}
	}

	/**
	 * Returns the arguments the queue stores: one map, in a list.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: array<string, mixed>} The arguments.
	 */
	public function toArguments(): array {
		$fields = array( 'h' => $this->job->handler );

		if ( null !== $this->every ) {
			$fields['r'] = $this->every;

			return array( $fields );
		}

		if ( null !== $this->job->uniqueKey ) {
			$fields['k'] = $this->job->uniqueKey;
		}

		if ( null !== $this->correlationId ) {
			$fields['c'] = $this->correlationId;
		}

		$fields['n'] = $this->attempt;

		if ( array() !== $this->job->payload ) {
			$fields['p'] = $this->job->payload;
		}

		return array( $fields );
	}

	/**
	 * Returns the text the queue stores, exactly as it encodes the arguments.
	 *
	 * @since 0.1.0
	 *
	 * @return string The JSON text.
	 */
	public function encode(): string {
		return self::json( $this->toArguments() );
	}

	/**
	 * Returns the next attempt of the same job, for a retry.
	 *
	 * @since 0.1.0
	 *
	 * @return self The envelope with the attempt one higher.
	 */
	public function nextAttempt(): self {
		return new self( $this->job, $this->correlationId, $this->attempt + 1, $this->every );
	}

	/**
	 * Reads the map the runner's hook receives.
	 *
	 * Fields a later version of the plugin may add are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When it is not a map, or a field is missing or malformed.
	 *
	 * @param mixed $fields What the hook received.
	 * @return self The envelope.
	 */
	public static function fromStored( mixed $fields ): self {
		if ( ! is_array( $fields ) || ! isset( $fields['h'] ) || ! is_string( $fields['h'] ) ) {
			throw new \UnexpectedValueException( 'The stored job has no handler name.' );
		}

		$key         = $fields['k'] ?? null;
		$correlation = $fields['c'] ?? null;
		$attempt     = $fields['n'] ?? 1;
		$every       = $fields['r'] ?? null;
		$payload     = $fields['p'] ?? array();

		if ( ( null !== $key && ! is_string( $key ) ) || ( null !== $correlation && ! is_string( $correlation ) ) || ! is_int( $attempt ) || ( null !== $every && ! is_int( $every ) ) || ! is_array( $payload ) ) {
			throw new \UnexpectedValueException( sprintf( 'The stored %s job has a field of the wrong type.', $fields['h'] ) );
		}

		try {
			return new self( new Job( $fields['h'], $payload, $key ), $correlation, $attempt, $every );
		} catch ( \InvalidArgumentException $invalid ) {
			throw new \UnexpectedValueException( $invalid->getMessage(), 0, $invalid );
		}
	}

	/**
	 * Returns how every stored job of a handler starts.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler The handler name.
	 * @return string The start of the stored text, up to and including the comma after the name.
	 */
	public static function prefixFor( string $handler ): string {
		return self::opening( array( 'h' => $handler ) ) . ',';
	}

	/**
	 * Returns how every stored run of a keyed job starts.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler   The handler name.
	 * @param string $uniqueKey The unique key.
	 * @return string The start of the stored text, up to and including the comma after the key.
	 */
	public static function keyPrefix( string $handler, string $uniqueKey ): string {
		return self::opening(
			array(
				'h' => $handler,
				'k' => $uniqueKey,
			)
		) . ',';
	}

	/**
	 * Returns how every stored recurring run of a handler starts, whatever its interval.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler The handler name.
	 * @return string The start of the stored text, up to and including `"r":`.
	 */
	public static function recurringPrefix( string $handler ): string {
		return self::opening( array( 'h' => $handler ) ) . ',"r":';
	}

	/**
	 * Encodes the start of a stored map: the fields given, without the closing brace and bracket.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $fields The leading fields, in stored order.
	 * @return string The text.
	 */
	private static function opening( array $fields ): string {
		return substr( self::json( array( $fields ) ), 0, -2 );
	}

	/**
	 * Encodes as the queue does: plain json_encode(), which is what wp_json_encode() produces for valid text.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $value The value.
	 * @return string The JSON text.
	 */
	private static function json( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This class may not call WordPress; for valid UTF-8 the output is wp_json_encode()'s, which the queue uses.
		return (string) json_encode( $value, JSON_THROW_ON_ERROR );
	}
}
