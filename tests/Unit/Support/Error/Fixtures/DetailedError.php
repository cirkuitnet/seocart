<?php
/**
 * DetailedError: a catalog whose row declares structured details beyond its placeholders
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
 * One code with a placeholder and one detail key, as a refused write that sends back the state it met.
 *
 * Its message is a plain string rather than a gettext call, so no fixture text can reach the
 * plugin's translation template.
 *
 * @since 0.1.0
 */
enum DetailedError: string implements ErrorCode {

	/**
	 * A code with the placeholder `current` and the detail key `state`.
	 *
	 * @since 0.1.0
	 */
	case Stale = 'detailed.stale';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition( self::Stale, 409, static fn(): string => 'It is at version %1$s now.', array( 'current' ), details: array( 'state' ) ),
		);
	}
}
