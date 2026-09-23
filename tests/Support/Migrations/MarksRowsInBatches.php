<?php
/**
 * MarksRowsInBatches: a fixture data migration that marks the fixture rows a few at a time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Migrations;

use SEOCart\Platform\Database\DataMigration;
use SEOCart\Platform\Database\Database;

/**
 * Adds 1 to the `n` column of fixture rows 0 to total-1, batchSize rows per batch.
 *
 * Owns one fact: a data migration whose every row must end up marked exactly once, however
 * the run is interrupted. The cursor is the id of the next row. The optional $onBatch hook
 * runs after a batch has marked its rows, inside the batch's transaction, with the number of
 * the batch (counted over the fixture's life) and the connection: throwing there rolls the
 * batch back; registering an after-commit callback that throws simulates a crash between the
 * batch's COMMIT and anything that follows it.
 *
 * @since 0.1.0
 */
final class MarksRowsInBatches implements DataMigration {

	/**
	 * How many batches ran, rolled-back ones included.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $batches = 0;

	/**
	 * Runs after each batch marked its rows. Null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(int, Database): void)|null
	 */
	public ?\Closure $onBatch = null;

	/**
	 * The migration id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The unprefixed name of the table to mark.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * How many rows there are: ids 0 to total-1.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * How many rows one batch marks.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $batchSize;

	/**
	 * Creates the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id        The migration id.
	 * @param string $table     The unprefixed name of the table to mark.
	 * @param int    $total     How many rows there are.
	 * @param int    $batchSize How many rows one batch marks.
	 */
	public function __construct( string $id, string $table, int $total, int $batchSize ) {
		$this->id        = $id;
		$this->table     = $table;
		$this->total     = $total;
		$this->batchSize = $batchSize;
	}

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Tells whether the store may trade while this backfill is incomplete. It may.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Marks the next batch of rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $cursor The id of the first row of this batch, or null for 0.
	 * @param Database    $db     The connection, inside the batch's transaction.
	 * @return string|null The id of the first row of the next batch, or null when every row is marked.
	 */
	public function runBatch( ?string $cursor, Database $db ): ?string {
		$start = null === $cursor ? 0 : (int) $cursor;
		$end   = min( $start + $this->batchSize, $this->total );

		$db->execute( 'UPDATE %i SET n = n + 1 WHERE id >= %d AND id < %d', $db->table( $this->table ), $start, $end );

		++$this->batches;

		if ( null !== $this->onBatch ) {
			( $this->onBatch )( $this->batches, $db );
		}

		return $end >= $this->total ? null : (string) $end;
	}
}
