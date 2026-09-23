<?php
/**
 * FixtureStockError: the error catalog of the test-fixture operation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * The one code the fixture operation declares.
 *
 * Its message is a plain string rather than a gettext call, so no fixture text can reach the
 * plugin's translation template.
 *
 * @since 0.1.0
 */
enum FixtureStockError: string implements ErrorCode {

	/**
	 * An adjustment would take the stock level below zero.
	 *
	 * @since 0.1.0
	 */
	case Insufficient = 'fixture_stock.insufficient';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::Insufficient,
				409,
				static fn(): string => 'You asked to remove %1$s, but only %2$s are in stock.',
				array( 'requested', 'available' )
			),
		);
	}
}
