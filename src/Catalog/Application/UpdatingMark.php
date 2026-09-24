<?php
/**
 * UpdatingMark: what marking a product `updating` replaced, and when
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application;

use SEOCart\Catalog\Domain\GenerationState;

defined( 'ABSPATH' ) || exit;

/**
 * The generation marker a product had just before a write marked it `updating`, and the instant of that mark.
 *
 * Owns one fact: what a write needs to take its own mark back. Both are read under the row lock
 * the mark takes, in the transaction that commits it, so `before` is the product's state at the
 * moment of the mark and `markedAt` names this mark and no later one. A write whose window is
 * rolled back hands both to ProductRepository::restoreMark().
 *
 * @since 0.1.0
 */
final readonly class UpdatingMark {

	/**
	 * Holds the mark.
	 *
	 * @since 0.1.0
	 *
	 * @param GenerationState $before   The marker the product had just before the mark.
	 * @param string          $markedAt The `updated_at` the mark wrote, to the microsecond, such as `2026-09-25 10:00:00.123456`.
	 */
	public function __construct(
		public GenerationState $before,
		public string $markedAt
	) {
	}
}
