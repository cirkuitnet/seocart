<?php
/**
 * TransactionRetryable: a unit of work that lost a lock race and may win on a re-run
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
 * A deadlock (MySQL error 1213) or a lock-wait timeout (1205) ended the unit of work.
 *
 * Owns one fact: that running the whole unit of work again from its beginning is the correct
 * recovery. Only the outermost transaction may do that, under a RetryPolicy; a caller that
 * catches this inside a window must let it propagate, because the transaction it belonged to
 * is gone or no longer safe to continue.
 *
 * @since 0.1.0
 */
final class TransactionRetryable extends QueryFailed {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::TransactionRetryable;
}
