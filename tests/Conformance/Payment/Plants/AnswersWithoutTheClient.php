<?php
/**
 * AnswersWithoutTheClient: a gateway whose provider is asked by a library of its own
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceProvider;

/**
 * Asks the provider as the reference gateway does, but through a library of its own instead of the plugin's client.
 *
 * It stands for a provider's SDK with its own HTTP stack: the provider answers, the payment
 * completes, and no request reaches the client, so none is recorded or replayed. The plant of
 * rule 12's run-time half: the suite must fail a payment that sent no request through the client.
 *
 * @since 0.2.0
 */
final class AnswersWithoutTheClient extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'the authorization sent no request of its own through the HTTP client';

	/**
	 * The library's own connection to the provider.
	 *
	 * @since 0.2.0
	 *
	 * @var ReferenceProvider|null
	 */
	private ?ReferenceProvider $library = null;

	/**
	 * Asks the provider through the library.
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
		$headers = array( 'Authorization' => 'Bearer ' . $this->credential( $mode ) );

		if ( null !== $key ) {
			$headers['Idempotency-Key'] = $this->keyFor( $key );
		}

		$this->library ??= new ReferenceProvider();

		$raw    = $this->library->answer(
			false,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => 'POST' === $method ? http_build_query( $fields ) : '',
			),
			$this->url( $path, $mode )
		);
		$raw    = is_array( $raw ) ? $raw : array();
		$answer = array(
			'status' => (int) ( $raw['response']['code'] ?? 0 ),
			'body'   => $this->decoded( (string) ( $raw['body'] ?? '' ) ),
		);

		$this->answered( $answer );

		return $answer;
	}
}
