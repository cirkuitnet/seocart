<?php
/**
 * RepairResult: what one doctor repair changed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one Repairable::repair() call: the check's name and what it changed.
 *
 * Owns one fact: what a repair reports. A repair names ids, never a stored value: "deleted
 * product_posts row for post 4032", never a price or a title. An empty list means the repair
 * looked but found nothing left to do (a concurrent save had already fixed it, or every finding
 * skipped because its lock was busy).
 *
 * @since 0.1.0
 */
final readonly class RepairResult {

	/**
	 * The name of the check the repair belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $check;

	/**
	 * One line per change the repair made, or per finding it skipped and why.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $changes;

	/**
	 * Records a repair's outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $check   The name of the check.
	 * @param string[] $changes One line per change or skip.
	 *
	 * @phpstan-param list<string> $changes
	 */
	public function __construct( string $check, array $changes = array() ) {
		$this->check   = $check;
		$this->changes = $changes;
	}
}
