<?php
/**
 * TableErrorTranslator: the smallest translator of coded errors, for the operation adapters' tests
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
 * The REST foundation provides the real translator, which also adds the details and the
 * correlation id. This one gives the adapters' tests exactly what they compare: the code and
 * the status.
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
}
