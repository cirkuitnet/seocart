<?php
/**
 * FixtureError: a valid error catalog for the error-model tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error\Fixtures;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * A catalog that follows every rule: two codes, one row each, one with placeholders.
 *
 * Its messages are plain strings rather than gettext calls, so no fixture text can reach the
 * plugin's translation template.
 *
 * @since 0.1.0
 */
enum FixtureError: string implements ErrorCode {

	/**
	 * A code without placeholders.
	 *
	 * @since 0.1.0
	 */
	case NotFound = 'fixture.not_found';

	/**
	 * A code with two placeholders.
	 *
	 * @since 0.1.0
	 */
	case Insufficient = 'fixture.insufficient';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::NotFound, 404, static fn(): string => 'Nothing was found.' ),
			new ErrorDefinition(
				self::Insufficient,
				409,
				static fn(): string => 'You asked for %1$s, but only %2$s are in stock (100%%).',
				array( 'requested', 'available' )
			),
		);
	}
}
