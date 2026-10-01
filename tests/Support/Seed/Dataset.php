<?php
/**
 * Dataset: the sizes of the reference dataset
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Seed;

/**
 * The three reference datasets, by how many products each holds.
 *
 * Owns one fact: the size of each dataset. `small` is the size any test can afford, `medium` is
 * the reference dataset the query plans are judged on, and `large` is for scale questions only.
 * Every other count but the carts' follows from the products: today each product has one variant, one price
 * in the base currency, one stock item and one source post, and every second product a second
 * post in another locale.
 *
 * @since 0.1.0
 */
enum Dataset: string {

	case Small  = 'small';
	case Medium = 'medium';
	case Large  = 'large';

	/**
	 * Returns how many products the dataset holds.
	 *
	 * @since 0.1.0
	 *
	 * @return int The products.
	 */
	public function products(): int {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Small  => 200,
			self::Medium => 10000,
			self::Large  => 100000,
		};
	}

	/**
	 * Returns how many carts the dataset holds, live and expired.
	 *
	 * @since 0.1.0
	 *
	 * @return int The carts.
	 */
	public function carts(): int {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Small  => 200,
			self::Medium => 5000,
			self::Large  => 50000,
		};
	}
}
