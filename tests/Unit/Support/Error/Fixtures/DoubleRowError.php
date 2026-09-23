<?php
/**
 * DoubleRowError: a catalog with two rows for one code
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
 * Declares one code and two rows for it.
 *
 * @since 0.1.0
 */
enum DoubleRowError: string implements ErrorCode {

	/**
	 * The code with two rows.
	 *
	 * @since 0.1.0
	 */
	case Twice = 'double.twice';

	/**
	 * Returns the catalog's rows: one too many.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> Two rows for one code.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Twice, 400, static fn(): string => 'Once.' ),
			new ErrorDefinition( self::Twice, 409, static fn(): string => 'Twice.' ),
		);
	}
}
