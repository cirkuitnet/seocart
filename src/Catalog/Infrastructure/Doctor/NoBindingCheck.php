<?php
/**
 * NoBindingCheck: products with no binding to any post at all
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports products with no `product_posts` row at all (doctor check 1).
 *
 * Owns one fact: which products present nothing. Never repaired here: "never ordered" cannot
 * be established before order history exists, so deleting one waits for that.
 *
 * @since 0.1.0
 */
final class NoBindingCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'no_binding';

	/**
	 * The most ids the check lists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products The products.
	 */
	public function __construct( ProductRepository $products ) {
		$this->products = $products;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.no_binding`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists products with no binding at all.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every product has at least one binding.
	 */
	public function run(): CheckResult {
		$ids  = $this->products->unboundProductIds( 0, self::LIMIT + 1 );
		$more = count( $ids ) > self::LIMIT;
		$ids  = array_slice( $ids, 0, self::LIMIT );

		if ( array() === $ids ) {
			return CheckResult::pass( self::NAME, 'Every product has at least one binding.' );
		}

		$findings   = array();
		$findings[] = sprintf(
			'Reported: product%1$s %2$s%3$s %4$s no `product_posts` row at all. Never repaired: whether one may be deleted waits for order history.',
			1 === count( $ids ) ? '' : 's',
			implode( ', ', $ids ),
			$more ? ' and more' : '',
			1 === count( $ids ) ? 'has' : 'have'
		);

		return CheckResult::fail( self::NAME, sprintf( '%d product%s with no binding.', count( $ids ), 1 === count( $ids ) ? '' : 's' ), $findings );
	}
}
