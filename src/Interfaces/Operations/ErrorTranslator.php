<?php
/**
 * ErrorTranslator: the seam through which every surface turns a failure into a WP_Error
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
 * Turns a failure into the error a surface answers with.
 *
 * This interface owns one fact: where the error table meets the surfaces. The adapters never
 * build an error response themselves; every error an operation answers with comes from here:
 *
 * - translate(): OperationInvoker hands a CodedException here, with the personal-data and secret
 *   values of its context already redacted. The REST route and the Ability return the WP_Error,
 *   and the WP-CLI command prints its code, its message and its correlation id.
 * - unexpected(): any other Throwable is a failure no client caused. The invoker reports it and
 *   answers with this generic internal error, which carries no message of the exception.
 * - conform(): an error WordPress itself raised on a plugin route — a request that does not match
 *   the input schema, or a user the permission check refused — is given the same data members,
 *   so every error on the route has one shape.
 *
 * The REST foundation provides the implementation, RestErrorTranslator, which takes the status
 * and the message from the one error table and adds the details — the values of the message's
 * placeholders, and the structured details the row declares, such as a cart's totals — and the
 * request's correlation id.
 *
 * @since 0.1.0
 */
interface ErrorTranslator {

	/**
	 * The code of the generic internal error, which a failure no client caused is answered with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTERNAL_ERROR = 'seocart_internal_error';

	/**
	 * The HTTP status of the generic internal error.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const INTERNAL_STATUS = 500;

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

	/**
	 * Returns the generic internal error, for a failure no client caused.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error The error INTERNAL_ERROR, with the status INTERNAL_STATUS and a message that
	 *                  says nothing about the failure.
	 */
	public function unexpected(): WP_Error;

	/**
	 * Gives an error WordPress raised on a plugin route the data members every error carries.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error: a validation or permission failure, or one this
	 *                        translator built, which is returned with the same members.
	 * @return WP_Error The error, with every member of the documented shape in its data.
	 */
	public function conform( WP_Error $error ): WP_Error;
}
