<?php
/**
 * TransactionDepthExceeded: a unit of work nested deeper than the ceiling allows
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
 * A transaction was opened one level deeper than the configured ceiling.
 *
 * Owns one fact: that the nesting itself is a programming error. A deep nest means a service
 * calls another service that opens its own unit of work, which is the shape the two units of
 * work of order placement exist to prevent. It is raised before any statement is sent, with
 * the ceiling as `max_depth`.
 *
 * @since 0.1.0
 */
final class TransactionDepthExceeded extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::TransactionDepth;
}
