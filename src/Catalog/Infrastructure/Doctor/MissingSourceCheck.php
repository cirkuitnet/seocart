<?php
/**
 * MissingSourceCheck: products whose source binding is invalid
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
 * Reports products whose source binding is invalid (doctor check 2): `source_post_id` NULL, no
 * matching `product_posts` row, or a post that is missing or is not a product post.
 *
 * Owns one fact: which products have no working source. Critical for a human — never repaired:
 * whether to rebind the product or write it off as a loss is left to a person, deliberately.
 *
 * @since 0.1.0
 */
final class MissingSourceCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'missing_source';

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
	 * @return string `catalog.missing_source`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists products with an invalid source binding.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every product has a working source binding.
	 */
	public function run(): CheckResult {
		$ids  = $this->products->invalidSourceBindings( 0, self::LIMIT + 1 );
		$more = count( $ids ) > self::LIMIT;
		$ids  = array_slice( $ids, 0, self::LIMIT );

		if ( array() === $ids ) {
			return CheckResult::pass( self::NAME, 'Every product has a working source binding.' );
		}

		$findings = array(
			sprintf(
				'Critical: product%1$s %2$s%3$s %4$s no working source binding (its `source_post_id` is empty, unbound, or points at a missing or foreign post). A person decides whether to rebind it or write it off; doctor never repairs this.',
				1 === count( $ids ) ? '' : 's',
				implode( ', ', $ids ),
				$more ? ' and more' : '',
				1 === count( $ids ) ? 'has' : 'have'
			),
		);

		return CheckResult::fail( self::NAME, sprintf( '%d product%s need a person\'s decision.', count( $ids ), 1 === count( $ids ) ? '' : 's' ), $findings );
	}
}
