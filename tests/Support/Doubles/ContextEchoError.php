<?php
/**
 * ContextEchoError: an error whose message repeats input values, for the error-privacy tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * One code whose placeholders are named like input fields — a personal-data note, a secret
 * currency and a public delta — so a test can see which of them reach the client.
 *
 * Its message is a plain string rather than a gettext call, so no test text can reach the
 * plugin's translation template.
 *
 * @since 0.1.0
 */
enum ContextEchoError: string implements ErrorCode {

	/**
	 * The adjustment was refused, and the message says with what.
	 *
	 * @since 0.1.0
	 */
	case Echoed = 'fixture_context.echoed';

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
				self::Echoed,
				422,
				static fn(): string => 'The note %1$s in %2$s cannot be applied to a change of %3$s.',
				array( 'note', 'currency', 'delta' )
			),
		);
	}
}
