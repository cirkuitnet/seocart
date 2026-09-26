<?php
/**
 * OrderNumberGenerator: allocates the number an order is shown with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The port that allocates human-readable order numbers.
 *
 * Owns one fact: that an order number is allocated atomically, inside the transaction that
 * places the order. Numbers are not gapless: a placement that rolls back returns its number, and
 * an order that failed after it was placed keeps its own. Two placements never receive the same
 * number, whatever they do at once.
 *
 * @since 0.1.0
 */
interface OrderNumberGenerator {

	/**
	 * The numbering scope every order is numbered in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_SCOPE = 'default';

	/**
	 * Allocates the next number of a scope. Runs only inside the caller's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $scope The numbering scope, for example DEFAULT_SCOPE.
	 * @return string The number, as it is shown.
	 */
	public function next( string $scope ): string;
}
