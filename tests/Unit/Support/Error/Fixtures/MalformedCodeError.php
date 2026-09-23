<?php
/**
 * MalformedCodeError: codes that are not written module.reason
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
 * Cases whose values break the code format; a row declared for any of them must be refused.
 *
 * @since 0.1.0
 */
enum MalformedCodeError: string implements ErrorCode {

	/**
	 * No dot.
	 *
	 * @since 0.1.0
	 */
	case NoDot = 'nodot';

	/**
	 * Upper case.
	 *
	 * @since 0.1.0
	 */
	case UpperCase = 'Stock.insufficient';

	/**
	 * Three segments.
	 *
	 * @since 0.1.0
	 */
	case ThreeSegments = 'payment.gateway.declined';

	/**
	 * A hyphen instead of an underscore.
	 *
	 * @since 0.1.0
	 */
	case Hyphen = 'cart.version-stale';

	/**
	 * A reason starting with a digit.
	 *
	 * @since 0.1.0
	 */
	case LeadingDigit = 'cart.1st';

	/**
	 * Returns no rows: the tests declare each row themselves and expect it refused.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> None.
	 */
	public static function definitions(): array {
		return array();
	}
}
