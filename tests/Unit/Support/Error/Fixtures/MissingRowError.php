<?php
/**
 * MissingRowError: a catalog with a code that has no row
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
 * Declares two codes and a row for only one of them.
 *
 * @since 0.1.0
 */
enum MissingRowError: string implements ErrorCode {

	/**
	 * A code with a row.
	 *
	 * @since 0.1.0
	 */
	case Present = 'missing.present';

	/**
	 * A code without a row.
	 *
	 * @since 0.1.0
	 */
	case Absent = 'missing.absent';

	/**
	 * Returns the catalog's rows: one short.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> A row for Present only.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Present, 400, static fn(): string => 'Present.' ),
		);
	}
}
