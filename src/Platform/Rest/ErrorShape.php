<?php
/**
 * ErrorShape: the documented data every error SEOCart answers with carries
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Rest;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The shape of an error's data: `{ status, details, correlation_id }`.
 *
 * This class owns one fact: which members the data of an error carries and what each one means.
 * WordPress sends a WP_Error to a REST client as `{ code, message, data }`, and an ability returns
 * the WP_Error itself; the data is the part SEOCart defines, the same on both:
 *
 * - `status`: the HTTP status;
 * - `details`: the values the message was built from, keyed by name — always a JSON object,
 *   empty for an internal error;
 * - `correlation_id`: the identifier of the request, which the site's log records with the
 *   error, or null.
 *
 * The data has exactly these three members, never more. RestErrorTranslator builds the data of
 * every error it translates with data(), and conform() gives an error WordPress raised on a
 * plugin route the same three: its status stays, and every other member WordPress put in its data
 * moves into `details` under its WordPress name — a validation error's `params` and its own
 * `details` become `details.params` and `details.details`. Nothing is renamed or invented. The WP-CLI command
 * prints the correlation id it reads here, and the generated OpenAPI document and error reference
 * describe the members from members(), so the shape is written down once.
 *
 * Nothing here calls WordPress; conform() only reads and builds WP_Error objects.
 *
 * @since 0.1.0
 */
final class ErrorShape {

	/**
	 * The member holding the HTTP status.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS = 'status';

	/**
	 * The member holding the values the message was built from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DETAILS = 'details';

	/**
	 * The member holding the request's correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CORRELATION_ID = 'correlation_id';

	/**
	 * The status WordPress answers with when an error's data names none.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEFAULT_STATUS = 500;

	/**
	 * Cannot be called: the shape is used through its static functions.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Returns each member with its description, in the order the data carries them.
	 *
	 * The descriptions are English machine descriptions for the generated documentation, like a
	 * field's description; nothing shows them to a shopper.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The descriptions, keyed by member name.
	 */
	public static function members(): array {
		return array(
			self::STATUS         => 'The HTTP status of the error.',
			self::DETAILS        => 'The values the message was built from, keyed by name. For an error WordPress raised, such as its refusal of a request that does not match the input schema, every other member of its data under the name WordPress gave it, such as `params`. Always an object, and empty for an internal error.',
			self::CORRELATION_ID => 'The identifier of the request the error happened in, which the site\'s log records with the error; null when the request has none.',
		);
	}

	/**
	 * Builds the data of an error.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $status         The HTTP status.
	 * @param array<string, mixed> $details        The values the message was built from, keyed by name.
	 * @param string|null          $correlation_id The request's correlation id, or null.
	 * @return array{status: int, details: \stdClass, correlation_id: string|null} The data, members in order.
	 */
	public static function data( int $status, array $details, ?string $correlation_id ): array {
		return array(
			self::STATUS         => $status,
			self::DETAILS        => (object) $details,
			self::CORRELATION_ID => $correlation_id,
		);
	}

	/**
	 * Gives every code of an error exactly the three members.
	 *
	 * Data that already has exactly the three, with the details as an object, is kept as it is: an
	 * error the translator built, or one conformed before. Any other data is reshaped: its `status`
	 * stays (500, the status WordPress answers with, when it names none), every other member moves
	 * into `details` under its own name, and the correlation id is the request's. Data that is not
	 * an array has no member to keep.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error    $error          The error.
	 * @param string|null $correlation_id The request's correlation id, or null.
	 * @return WP_Error A new error with the same codes and messages, and the three members as data.
	 */
	public static function conform( WP_Error $error, ?string $correlation_id ): WP_Error {
		$conformed = new WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			foreach ( $error->get_error_messages( $code ) as $message ) {
				$conformed->add( $code, $message );
			}

			$data = $error->get_error_data( $code );

			$conformed->add_data( self::isShaped( $data ) ? $data : self::reshaped( $data, $correlation_id ), $code );
		}

		return $conformed;
	}

	/**
	 * Tells whether data has exactly the three members, with the details as an object.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data An error's data.
	 * @return bool True for data in the documented shape.
	 */
	private static function isShaped( $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}

		$keys    = array_keys( $data );
		$members = array_keys( self::members() );

		sort( $keys );
		sort( $members );

		return $keys === $members && $data[ self::DETAILS ] instanceof \stdClass;
	}

	/**
	 * Reshapes the data of an error WordPress raised: its status stays, everything else is details.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed       $data           The error's data.
	 * @param string|null $correlation_id The request's correlation id, or null.
	 * @return array{status: int, details: \stdClass, correlation_id: string|null} The data.
	 */
	private static function reshaped( $data, ?string $correlation_id ): array {
		$details = is_array( $data ) ? $data : array();
		$status  = isset( $details[ self::STATUS ] ) && is_numeric( $details[ self::STATUS ] ) ? (int) $details[ self::STATUS ] : self::DEFAULT_STATUS;

		unset( $details[ self::STATUS ] );

		return self::data( $status, $details, $correlation_id );
	}

	/**
	 * Reads the correlation id of an error.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 * @return string|null The correlation id its data carries, or null when it carries none.
	 */
	public static function correlationId( WP_Error $error ): ?string {
		$data = $error->get_error_data();
		$id   = is_array( $data ) ? ( $data[ self::CORRELATION_ID ] ?? null ) : null;

		return is_string( $id ) && '' !== $id ? $id : null;
	}
}
