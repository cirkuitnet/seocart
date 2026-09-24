<?php
/**
 * StepLog: the order in which a product save's collaborators were called
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

/**
 * Records one line per call, in the order the calls happened, for the unit tests of the product write.
 *
 * Owns one fact: the one timeline every double of a save writes to, so that a test reads the
 * order of transactions, repository statements, post writes and events in one list.
 *
 * @since 0.1.0
 */
final class StepLog {

	/**
	 * The lines, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $steps = array();

	/**
	 * Records a step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step What happened, such as `repository.relock`.
	 */
	public function record( string $step ): void {
		$this->steps[] = $step;
	}

	/**
	 * Returns the steps, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The steps.
	 */
	public function steps(): array {
		return $this->steps;
	}
}
