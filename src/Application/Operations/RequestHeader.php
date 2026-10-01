<?php
/**
 * RequestHeader: the request header a route reads an input field from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * Names the header an input field is read from, and whether a client must send it.
 *
 * Owns one fact: a header field's place on the wire. Its field stays an optional input either
 * way: WordPress validates only what the query or the body sends, so a header the request leaves
 * out is refused by the service with its own error code. `required` is what the API document
 * tells a client, as a path parameter's is.
 *
 * @since 0.1.0
 */
final readonly class RequestHeader {

	/**
	 * What a header name is: letters, digits and hyphens.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9-]*\z/';

	/**
	 * The header's name, such as `Idempotency-Key`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Whether a client must send it: the operation refuses a request without it.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $required;

	/**
	 * Names the header.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the name is not letters, digits and hyphens, starting with a letter.
	 *
	 * @param string $name     The header's name.
	 * @param bool   $required Optional. Whether the operation refuses a request without it. Default false.
	 */
	public function __construct( string $name, bool $required = false ) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			SchemaException::raise( 'The header "%1$s" must be named with letters, digits and hyphens, starting with a letter.', $name );
		}

		$this->name     = $name;
		$this->required = $required;
	}
}
