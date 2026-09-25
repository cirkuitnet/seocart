<?php
/**
 * IncompleteCountCheck: how many products are incomplete
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
 * Reports how many products are `incomplete`: informational, never a failure on its own (doctor check 10).
 *
 * Owns one fact: the count. Never repairs; a large or small count is not itself a defect — an
 * incomplete product is the ordinary, expected state of one still being set up.
 *
 * @since 0.1.0
 */
final class IncompleteCountCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'incomplete_count';

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
	 * @return string `catalog.incomplete_count`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the incomplete products.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Always passed: the count is informational.
	 */
	public function run(): CheckResult {
		$count = $this->products->incompleteCount();

		return CheckResult::pass( self::NAME, sprintf( '%d %s incomplete.', $count, 1 === $count ? 'product is' : 'products are' ) );
	}
}
