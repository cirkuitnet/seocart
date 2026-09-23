<?php
/**
 * ForeignRowError: a catalog that declares a row for another catalog's code
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
 * Declares a row for its own code and one for FixtureError::NotFound.
 *
 * @since 0.1.0
 */
enum ForeignRowError: string implements ErrorCode {

	/**
	 * The catalog's own code.
	 *
	 * @since 0.1.0
	 */
	case Own = 'foreign.own';

	/**
	 * Returns the catalog's rows, one of which is not its own.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> Two rows.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Own, 400, static fn(): string => 'Own.' ),
			new ErrorDefinition( FixtureError::NotFound, 404, static fn(): string => 'Not mine.' ),
		);
	}
}
