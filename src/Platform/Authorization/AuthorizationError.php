<?php
/**
 * AuthorizationError: the error catalog of the authorization module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors a client can cause through an authorization check.
 *
 * This enum owns one fact: how a refused capability check is reported. Asking for a capability
 * the plugin does not declare is a programming error, an InvalidArgumentException, and has no
 * row here.
 *
 * @since 0.1.0
 */
enum AuthorizationError: string implements ErrorCode {

	/**
	 * The actor does not hold the capability the use case requires, on the resource if there is one.
	 *
	 * @since 0.1.0
	 */
	case Denied = 'authorization.denied';

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
				self::Denied,
				403,
				static fn(): string =>
					/* translators: %1$s: The name of the capability the action requires, for example seocart_refund_orders. */
					__( 'Sorry, you are not allowed to do that. It requires the %1$s capability.', 'seocart' ),
				array( 'capability' )
			),
		);
	}
}
