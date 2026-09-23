<?php
/**
 * FixtureStoreError: a test catalog with a code any changing operation may raise
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
 * One public code declared with `any_write: true`, standing for the store refusing every write
 * while its schema and its code disagree.
 *
 * Its message is a plain string rather than a gettext call, so no fixture text can reach the
 * plugin's translation template.
 *
 * @since 0.1.0
 */
enum FixtureStoreError: string implements ErrorCode {

	/**
	 * The store refuses writes for now.
	 *
	 * @since 0.1.0
	 */
	case Unavailable = 'fixture_store.unavailable';

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
				self::Unavailable,
				503,
				static fn(): string => 'The store is being updated. Try again in a minute.',
				any_write: true
			),
		);
	}
}
