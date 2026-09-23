<?php
/**
 * DataMigration: a migration that rewrites rows in resumable batches
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A batched, resumable, idempotent rewrite of existing rows.
 *
 * Owns one fact: how a data migration advances. The migrator runs each batch in its own
 * transaction together with the update of the migration's cursor, so a batch and the record
 * that it happened commit together; a crash can neither skip a batch nor replay one that
 * committed. A batch must still be idempotent, because the whole unit can be retried.
 *
 * @since 0.1.0
 */
interface DataMigration extends Migration {

	/**
	 * Processes one batch.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $cursor Where to continue: null for the first batch, otherwise the value
	 *                            the previous batch returned.
	 * @param Database    $db     The connection. The batch runs inside an open transaction.
	 * @return string|null The cursor of the next batch, or null when the migration is complete.
	 */
	public function runBatch( ?string $cursor, Database $db ): ?string;
}
