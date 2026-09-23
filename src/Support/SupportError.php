<?php
/**
 * SupportError: the error catalog of the shared kernel
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors a client can cause through the shared kernel.
 *
 * This enum owns one fact: which failures of the Support value objects a client can reach
 * with a request, and how each is reported. Everything else Support refuses — a malformed
 * decimal, a mixed-currency sum, an overflow — is refused before a request gets that far (by
 * the field declarations of the operation) or is a programming error, and has no row here.
 *
 * @since 0.1.0
 */
enum SupportError: string implements ErrorCode {

	/**
	 * A currency code that is not an active ISO 4217 code with a numeric minor unit.
	 *
	 * @since 0.1.0
	 */
	case UnknownCurrency = 'currency.unknown';

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
				self::UnknownCurrency,
				400,
				static fn(): string =>
					/* translators: %1$s: The currency code that was asked for, for example XYZ. */
					__( '%1$s is not a currency code that SEOCart supports.', 'seocart' ),
				array( 'currency' )
			),
		);
	}
}
