<?php
/**
 * CurlByName: a planted gateway that calls cURL through the names of its functions, held in strings
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Sends each request to the provider with cURL, its functions called by name: a variable function and call_user_func().
 *
 * No cURL function is written as a call, so a reading that looks only for calls by name passes it.
 * The plant of rule 12's reading of the source: the suite must name the callable by reading this
 * file. It is never run; its request would be neither recorded nor replayed.
 *
 * @since 0.2.0
 */
final class CurlByName extends ReferenceGateway implements SourcePlant {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the callable \'curl_exec\'';

	/**
	 * Sends a request with cURL, called by name.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode                 $mode   The mode of the payment the request is about.
	 * @param string               $method The method.
	 * @param string               $path   The path, with its query.
	 * @param array<string, mixed> $fields The form fields of a POST.
	 * @param string|null          $key    The idempotency key of a money call.
	 * @return array{status: int, body: array<mixed>} The answer.
	 */
	protected function send( Mode $mode, string $method, string $path, array $fields, ?string $key ): array {
		unset( $fields, $key );

		$open   = 'curl_init';
		$handle = $open( $this->url( $path, $mode ) );
		$body   = call_user_func( 'curl_exec', $handle );

		return array(
			'status' => is_string( $body ) ? 200 : 0,
			'body'   => $this->decoded( is_string( $body ) ? $body : '' ) + array( 'method' => $method ),
		);
	}
}
