<?php
/**
 * MigrationReport: what one migration run did
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a Migrator::migrate() call that did not fail.
 *
 * Owns one fact: how a run ended, as one of four outcomes, with the migrations it applied and
 * those another runner applied while this one waited. A failed run throws MigrationFailed
 * instead of returning a report.
 *
 * @since 0.1.0
 */
final class MigrationReport {

	/**
	 * Outcome: at least one migration was applied, and nothing is left pending.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLIED = 'applied';

	/**
	 * Outcome: nothing was pending.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UP_TO_DATE = 'up_to_date';

	/**
	 * Outcome: another runner held the schema lock for the whole wait; nothing was changed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BLOCKED = 'blocked';

	/**
	 * Outcome: the time budget ran out inside a data migration, which stays `running` and resumes on the next run.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INCOMPLETE = 'incomplete';

	/**
	 * One of the outcome constants.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $outcome;

	/**
	 * The migrations this run applied, in order, each with its duration.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{id: string, duration_ms: int}>
	 */
	private array $applied;

	/**
	 * The migrations that were pending before this run took the lock and applied when it re-read them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $appliedElsewhere;

	/**
	 * The data migration left running when the budget ran out, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $incomplete;

	/**
	 * Describes a run.
	 *
	 * @since 0.1.0
	 *
	 * @param string                                    $outcome          One of the outcome constants.
	 * @param list<array{id: string, duration_ms: int}> $applied          The migrations this run applied.
	 * @param string[]                                  $appliedElsewhere The migrations another runner applied meanwhile.
	 * @param string|null                               $incomplete       Optional. The data migration left running. Default null.
	 */
	public function __construct( string $outcome, array $applied, array $appliedElsewhere, ?string $incomplete = null ) {
		$this->outcome          = $outcome;
		$this->applied          = $applied;
		$this->appliedElsewhere = $appliedElsewhere;
		$this->incomplete       = $incomplete;
	}

	/**
	 * Describes a run that found another runner holding the lock.
	 *
	 * @since 0.1.0
	 *
	 * @return self The report.
	 */
	public static function blocked(): self {
		return new self( self::BLOCKED, array(), array() );
	}

	/**
	 * Returns how the run ended.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of the outcome constants.
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * Returns the migrations this run applied.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{id: string, duration_ms: int}> In the order they were applied.
	 */
	public function applied(): array {
		return $this->applied;
	}

	/**
	 * Returns the migrations another runner applied while this one waited for the lock.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Migration ids.
	 */
	public function appliedElsewhere(): array {
		return $this->appliedElsewhere;
	}

	/**
	 * Returns the data migration the budget interrupted.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Its id, or null.
	 */
	public function incompleteMigration(): ?string {
		return $this->incomplete;
	}
}
