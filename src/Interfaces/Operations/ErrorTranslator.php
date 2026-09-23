<?php
/**
 * ErrorTranslator: the seam through which every surface turns a coded failure into a WP_Error
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Support\Error\CodedException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a failure a client caused into the error a surface answers with.
 *
 * This interface owns one fact: where the error table meets the surfaces. OperationInvoker hands a
 * CodedException here, with the personal-data and secret values of its context already redacted:
 * the REST route and the Ability return the WP_Error, and the WP-CLI command prints its code and
 * message. Nothing else reaches a translator: any other Throwable is a failure no client caused,
 * which the invoker reports and answers with a generic internal error that carries no message of
 * the exception. The REST foundation provides the implementation,
 * which takes the status and the message from the one error table and adds the details and the
 * request's correlation id; the adapters never build an error response themselves.
 *
 * @since 0.1.0
 */
interface ErrorTranslator {

	/**
	 * Translates a coded failure.
	 *
	 * Called while a request is served, after `init`, so the message may be translated here.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $error The failure the service raised.
	 * @return WP_Error The error, with the code as its code and the HTTP status in its data.
	 */
	public function translate( CodedException $error ): WP_Error;
}
