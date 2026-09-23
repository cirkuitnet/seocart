<?php
/**
 * DuplicateCodeError: a catalog that declares a code another catalog already declares
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
 * Declares `fixture.not_found`, which FixtureError declares too: composing both must fail.
 *
 * @since 0.1.0
 */
enum DuplicateCodeError: string implements ErrorCode {

	/**
	 * The same code as FixtureError::NotFound.
	 *
	 * @since 0.1.0
	 */
	case Again = 'fixture.not_found';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Again, 404, static fn(): string => 'Nothing was found, again.' ),
		);
	}
}
