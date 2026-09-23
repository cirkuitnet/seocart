<?php
/**
 * TransactionIntegrityLost: the transaction the wrapper opened is no longer the one it would commit
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * The unit of work cannot be committed, because what it would commit is not what it wrote.
 *
 * Owns one fact: why the wrapper refused to go on with a transaction. The reason is one of
 * four constants:
 *
 * - `connection_changed`: wpdb reconnected, so the server already rolled the transaction back,
 *   and any statement wpdb re-ran on the new connection was committed on its own;
 * - `connection_lost`: the server went away inside the window and wpdb did not reconnect;
 * - `ended_externally`: something committed or rolled back the transaction behind the wrapper's
 *   back, directly through mysqli, with DDL, or with a second START TRANSACTION;
 * - `aborted`: a deadlock or lock-wait timeout already ended the unit of work, and a statement
 *   was still issued inside it.
 *
 * The REST layer answers 503 for this code: the request may succeed if it is sent again.
 *
 * @since 0.1.0
 */
final class TransactionIntegrityLost extends DatabaseException {

	/**
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.transaction_lost';

	/**
	 * Reason: wpdb is on a different connection than the one the transaction began on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONNECTION_CHANGED = 'connection_changed';

	/**
	 * Reason: the connection went away inside the window.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONNECTION_LOST = 'connection_lost';

	/**
	 * Reason: the transaction was ended by a statement the wrapper did not issue.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ENDED_EXTERNALLY = 'ended_externally';

	/**
	 * Reason: a statement was issued after a deadlock or lock-wait timeout ended the unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ABORTED = 'aborted';

	/**
	 * Why the transaction was refused: one of the reason constants.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Describes a refused transaction. Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $reason   One of the reason constants.
	 * @param string               $message  What happened, for a person reading a log.
	 * @param array<string, mixed> $context  Structured facts.
	 * @param \Throwable|null      $previous Optional. The failure that revealed it. Default null.
	 */
	private function __construct( string $reason, string $message, array $context, ?\Throwable $previous = null ) {
		$this->reason = $reason;

		parent::__construct( $message, array( 'reason' => $reason ) + $context, $previous );
	}

	/**
	 * Describes a reconnect: the connection now in use is not the one the transaction began on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement after which the change was noticed.
	 * @param int    $openedOn  The connection's thread id when the transaction began.
	 * @param int    $current   The thread id of the connection wpdb holds now.
	 * @return self The exception.
	 */
	public static function connectionChanged( string $statement, int $openedOn, int $current ): self {
		$statement = QueryFailed::shorten( $statement );

		return new self(
			self::CONNECTION_CHANGED,
			sprintf(
				'wpdb reconnected inside a transaction (thread %d became %d), so the server rolled the transaction back. Noticed after: %s. A statement wpdb re-ran on the new connection was committed on its own.',
				$openedOn,
				$current,
				$statement
			),
			array(
				'statement'  => $statement,
				'opened_on'  => $openedOn,
				'current_on' => $current,
			)
		);
	}

	/**
	 * Describes a connection that went away inside the window without wpdb reconnecting.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $errno     The error number, 2006 or 2013.
	 * @param string $statement The statement that found the connection gone.
	 * @return self The exception.
	 */
	public static function connectionLost( int $errno, string $statement ): self {
		$statement = QueryFailed::shorten( $statement );

		return new self(
			self::CONNECTION_LOST,
			sprintf( 'The database connection was lost inside a transaction (error %d) at: %s', $errno, $statement ),
			array(
				'errno'     => $errno,
				'statement' => $statement,
			)
		);
	}

	/**
	 * Describes a missing probe savepoint: something ended the transaction behind the wrapper's back.
	 *
	 * @since 0.1.0
	 *
	 * @param QueryFailed $probe The failure of the probe, error 1305.
	 * @return self The exception.
	 */
	public static function endedExternally( QueryFailed $probe ): self {
		return new self(
			self::ENDED_EXTERNALLY,
			'The transaction was ended by a statement the transaction wrapper did not issue (a COMMIT, ROLLBACK, START TRANSACTION or DDL), so COMMIT was not sent.',
			array( 'errno' => $probe->errno() ),
			$probe
		);
	}

	/**
	 * Describes a statement issued inside a unit of work that a deadlock or lock-wait timeout had already ended.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement that was refused.
	 * @return self The exception.
	 */
	public static function aborted( string $statement ): self {
		$statement = QueryFailed::shorten( $statement );

		return new self(
			self::ABORTED,
			sprintf( 'The unit of work was already ended by a deadlock or lock-wait timeout; refused: %s', $statement ),
			array( 'statement' => $statement )
		);
	}

	/**
	 * Returns why the transaction was refused.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of the reason constants.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
