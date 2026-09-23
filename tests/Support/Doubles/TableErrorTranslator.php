<?php
/**
 * TableErrorTranslator: the smallest translator of coded errors, for tests that never read an error
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use WP_Error;

/**
 * Turns a coded error into a WP_Error with the code, the rendered message and the status of its row.
 *
 * The plugin's translator, RestErrorTranslator, also adds the details and the correlation id, hides
 * internal rows and needs an error table; the surface tests wire that one through
 * OperationSurfaces. This double is for a test that builds an invoker only to register commands,
 * and never reads an error.
 *
 * @since 0.1.0
 */
final class TableErrorTranslator implements ErrorTranslator {

	/**
	 * Translates a coded failure.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $error The failure.
	 * @return WP_Error The error, with the code, the message and `status` in its data.
	 */
	public function translate( CodedException $error ): WP_Error {
		$row = ErrorDefinition::of( $error->errorCode() );

		return new WP_Error( (string) $error->errorCode()->value, $row->render( $error->context() ), array( 'status' => $row->httpStatus() ) );
	}

	/**
	 * Returns the generic internal error.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error The error, with `status` in its data.
	 */
	public function unexpected(): WP_Error {
		return new WP_Error( self::INTERNAL_ERROR, 'Internal error.', array( 'status' => self::INTERNAL_STATUS ) );
	}

	/**
	 * Returns an error unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 * @return WP_Error The same error.
	 */
	public function conform( WP_Error $error ): WP_Error {
		return $error;
	}
}
