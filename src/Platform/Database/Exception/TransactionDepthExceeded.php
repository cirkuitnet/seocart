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

defined( 'ABSPATH' ) || exit;

/**
 * A transaction was opened one level deeper than the configured ceiling.
 *
 * Owns one fact: that the nesting itself is a programming error. A deep nest means a service
 * calls another service that opens its own unit of work, which is the shape the two units of
 * work of order placement exist to prevent. It is thrown before any statement is sent.
 *
 * @since 0.1.0
 */
final class TransactionDepthExceeded extends DatabaseException {

	/**
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.transaction_depth';

	/**
	 * Describes the refused level.
	 *
	 * @since 0.1.0
	 *
	 * @param int $maxDepth The deepest level allowed.
	 */
	public function __construct( int $maxDepth ) {
		parent::__construct(
			sprintf( 'Transactions may nest %d levels deep; a level beyond that was refused.', $maxDepth ),
			array( 'max_depth' => $maxDepth )
		);
	}
}
