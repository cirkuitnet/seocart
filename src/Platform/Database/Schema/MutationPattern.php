<?php
/**
 * MutationPattern: the only ways a table's rows may change
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * How a table's rows are written, which decides the operations allowed on it.
 *
 * Owns one fact: the five mutation patterns of the storage conventions. Every table declares
 * exactly one.
 *
 * @since 0.1.0
 */
enum MutationPattern: string {

	/**
	 * Merchant-authored, settings-shaped rows: insert, update and delete; low volume.
	 *
	 * @since 0.1.0
	 */
	case Config = 'config';

	/**
	 * Takes part in a money or stock transaction: the contended case is one conditional UPDATE.
	 *
	 * @since 0.1.0
	 */
	case MutableTransactional = 'mutable-transactional';

	/**
	 * A ledger or audit trail: INSERT only; retention deletes by age in batches.
	 *
	 * @since 0.1.0
	 */
	case AppendOnly = 'append-only';

	/**
	 * Work claimed by a worker: insert, conditional claim, terminal update, retention delete.
	 *
	 * @since 0.1.0
	 */
	case Queue = 'queue';

	/**
	 * Rebuildable from other tables; never the source of truth for a decision.
	 *
	 * @since 0.1.0
	 */
	case Derived = 'derived';
}
