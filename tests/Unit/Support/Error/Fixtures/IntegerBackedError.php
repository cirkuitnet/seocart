<?php
/**
 * IntegerBackedError: a catalog whose codes are integers
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
 * An integer-backed enum can implement ErrorCode, but a code is a string: its row is refused.
 *
 * @since 0.1.0
 */
enum IntegerBackedError: int implements ErrorCode {

	/**
	 * A numeric code.
	 *
	 * @since 0.1.0
	 */
	case Numeric = 404;

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row, which cannot be built.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Numeric, 404, static fn(): string => 'Numeric.' ),
		);
	}
}
