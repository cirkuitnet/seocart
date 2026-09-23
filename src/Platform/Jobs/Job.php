<?php
/**
 * Job: one piece of background work, as it is queued
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A job declaration error is a message for the developer, never HTML; this class may not call WordPress.

/**
 * A handler name, the ids it needs, and an optional unique key.
 *
 * Owns one fact: what a job may carry. A job carries ids and short strings, never a document:
 * the handler reads current state when it runs, because the job may run minutes or hours
 * later. So the payload is a flat map of lowercase snake_case names to integers, strings,
 * booleans or null. A float is refused (money is never a float), and so is an array or an
 * object. Its stored form must also fit JobEnvelope::MAX_ENCODED_LENGTH, which the queue
 * checks when the job is queued.
 *
 * The unique key names the one job a caller means, for example `outbox:{id}:{handler}` for
 * the job a listener queues for one stored event. Queueing a job whose handler and key match
 * a job that is waiting, running or completed changes nothing (JobQueue).
 *
 * Pure data: constructing one does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final readonly class Job {

	/**
	 * A handler name: two or more lowercase snake_case words joined by dots, for example `outbox.catch_up`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HANDLER_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D';

	/**
	 * The longest handler name.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const HANDLER_MAX_LENGTH = 48;

	/**
	 * A unique key: lowercase letters, digits, and `_ . : -` as separators, for example `outbox:42:notification.send`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_.:-]*$/D';

	/**
	 * The longest unique key.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const KEY_MAX_LENGTH = 64;

	/**
	 * A payload field name: lowercase snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * The handler that runs the job.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $handler;

	/**
	 * What the handler needs: field name => integer, string, boolean or null.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string|bool|null>
	 */
	public array $payload;

	/**
	 * The key that makes the job unique for its handler, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $uniqueKey;

	/**
	 * Describes a job, after checking its shape.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the handler name, the key or the payload breaks the rules above.
	 *
	 * @param string       $handler   The handler name.
	 * @param array<mixed> $payload   Optional. The payload: field name => integer, string, boolean
	 *                                or null. Default empty.
	 * @param string|null  $uniqueKey Optional. The unique key. Default null.
	 */
	public function __construct( string $handler, array $payload = array(), ?string $uniqueKey = null ) {
		if ( strlen( $handler ) > self::HANDLER_MAX_LENGTH || 1 !== preg_match( self::HANDLER_PATTERN, $handler ) ) {
			throw new \InvalidArgumentException( sprintf( 'The job handler name "%s" is not two or more lowercase words joined by dots, of at most %d characters.', $handler, self::HANDLER_MAX_LENGTH ) );
		}

		if ( null !== $uniqueKey && ( strlen( $uniqueKey ) > self::KEY_MAX_LENGTH || 1 !== preg_match( self::KEY_PATTERN, $uniqueKey ) ) ) {
			throw new \InvalidArgumentException( sprintf( 'The unique key "%s" of a %s job may hold lowercase letters, digits and the separators _ . : - only, and at most %d characters.', $uniqueKey, $handler, self::KEY_MAX_LENGTH ) );
		}

		$checked = array();

		foreach ( $payload as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( self::FIELD_PATTERN, $name ) ) {
				throw new \InvalidArgumentException( sprintf( 'The payload field "%s" of a %s job is not a lowercase snake_case name of at most 32 characters.', $name, $handler ) );
			}

			if ( ! ( is_int( $value ) || is_string( $value ) || is_bool( $value ) || null === $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'The payload field %s of a %s job holds a value of type %s; a job carries integers, strings, booleans and null only.', $name, $handler, get_debug_type( $value ) ) );
			}

			$checked[ $name ] = $value;
		}

		$this->handler   = $handler;
		$this->payload   = $checked;
		$this->uniqueKey = $uniqueKey;
	}
}
