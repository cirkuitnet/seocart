<?php
/**
 * ReadsByPost: the reference gateway of a provider that reads by POST, at a single endpoint that names each request in its body
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Sends each of its reads as a POST of a JSON body to the provider's single endpoint, the request named by the body's one key, and reads the provider's intent before it refunds.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: a read is told from a
 * payment by its body's key alone, so the suite passes it when its test declares the three reads
 * in readRequests() (reads()), and fails it when the test does not: each read is then a request
 * that moves money without a key, and the answer rule 2 loses is the read's before the refund.
 *
 * @since 0.2.0
 */
class ReadsByPost extends ReferenceGateway {

	/**
	 * The provider's single endpoint.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PATH = '/v1/requests';

	/**
	 * The key of the request that retrieves an intent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RETRIEVE = 'retrieveIntent';

	/**
	 * The key of the request that searches intents by the store's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SEARCH = 'searchIntents';

	/**
	 * The key of the request that lists an intent's refunds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_REFUNDS = 'listRefunds';

	/**
	 * Returns the declaration of the gateway's reads, as its test makes it in readRequests().
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{method: string, path: string, body_key: string}> The reads.
	 */
	public static function reads(): array {
		return array_map(
			static fn( string $key ): array => array(
				'method'   => 'POST',
				'path'     => self::PATH,
				'body_key' => $key,
			),
			array( self::RETRIEVE, self::SEARCH, self::LIST_REFUNDS )
		);
	}

	/**
	 * Reads the intent, then refunds.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The provider's answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->readBeforeRefunding( $request );

		return parent::refund( $request );
	}

	/**
	 * Reads the intent a refund is of.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 */
	protected function readBeforeRefunding( GatewayRefund $request ): void {
		$this->send( $request->mode, 'GET', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ), array(), null );
	}

	/**
	 * Sends a read as a POST to the single endpoint, and any other request as the reference gateway does.
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
		return 'GET' === $method ? parent::send( $mode, 'POST', self::PATH, self::readOf( $path ), null ) : parent::send( $mode, $method, $path, $fields, $key );
	}

	/**
	 * Writes a request to the single endpoint as JSON, any other POST as the reference gateway does.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $path   The path, with its query.
	 * @param array<string, mixed> $fields The fields.
	 * @return array{string, string} The `Content-Type` and the body.
	 */
	protected function encoded( string $path, array $fields ): array {
		return self::PATH === $path ? array( 'application/json', (string) wp_json_encode( $fields ) ) : parent::encoded( $path, $fields );
	}

	/**
	 * Returns the body of the read that asks what a GET of the reference's API asks.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The GET's path, with its query.
	 * @return array<string, array<string, string>> The body: the request's key, and its fields.
	 */
	private static function readOf( string $path ): array {
		$route = (string) wp_parse_url( $path, PHP_URL_PATH );
		$query = array();

		parse_str( (string) wp_parse_url( $path, PHP_URL_QUERY ), $query );

		return match ( $route ) {
			'/v1/intents' => array( self::SEARCH => array( 'intent_uuid' => (string) ( $query['intent_uuid'] ?? '' ) ) ),
			'/v1/refunds' => array( self::LIST_REFUNDS => array( 'intent' => (string) ( $query['intent'] ?? '' ) ) ),
			default       => array( self::RETRIEVE => array( 'id' => basename( $route ) ) ),
		};
	}
}
