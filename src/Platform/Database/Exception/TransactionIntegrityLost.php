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

use SEOCart\Platform\Database\DatabaseError;

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
 * Its row answers 503: the request may succeed if it is sent again.
 *
 * @since 0.1.0
 */
final class TransactionIntegrityLost extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::TransactionLost;

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
	 * Builds the exception without throwing it.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $reason    One of the reason constants.
	 * @param string          $statement The statement at which the loss was noticed. Cut to QueryFailed::STATEMENT_LENGTH.
	 * @param \Throwable|null $previous  Optional. The failure that revealed it. Default null.
	 * @return self The exception.
	 */
	public static function lost( string $reason, string $statement, ?\Throwable $previous = null ): self {
		return self::because( self::CODE, self::facts( $reason, $statement ), $previous );
	}

	/**
	 * Raises the exception.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionIntegrityLost Always.
	 *
	 * @param string $reason    One of the reason constants.
	 * @param string $statement The statement at which the loss was noticed.
	 * @return never
	 */
	public static function raiseLost( string $reason, string $statement ): never {
		self::raise( self::CODE, self::facts( $reason, $statement ) );
	}

	/**
	 * Returns why the transaction was refused.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of the reason constants.
	 */
	public function reason(): string {
		return (string) $this->context()['reason'];
	}

	/**
	 * Returns the statement at which the loss was noticed.
	 *
	 * @since 0.1.0
	 *
	 * @return string At most QueryFailed::STATEMENT_LENGTH characters.
	 */
	public function statement(): string {
		return (string) $this->context()['statement'];
	}

	/**
	 * Builds the context.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason    One of the reason constants.
	 * @param string $statement The statement.
	 * @return array{reason: string, statement: string} The context.
	 */
	private static function facts( string $reason, string $statement ): array {
		return array(
			'reason'    => $reason,
			'statement' => QueryFailed::shorten( $statement ),
		);
	}
}
