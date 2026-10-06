<?php
/**
 * FakeWebhookProvider: a provider's webhook endpoints API, answered at `pre_http_request`, that records every request and the transaction depth it came at
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Tests\Support\Doubles\ProvisioningGateway;

/**
 * The endpoints a provider keeps, with their URL, metadata, events and signing secret, as ProvisioningGateway lists, deletes and creates them.
 *
 * Owns one fact: what the provider holds, and what it was asked. Nothing reaches the network: the
 * requests are answered at `pre_http_request`, after the gateway's own client has checked them.
 * A list shows no secret, as a provider shows a secret only when it creates the endpoint. A test
 * may make the listing or a creation fail with an error body of its choosing.
 *
 * @since 0.2.0
 */
final class FakeWebhookProvider {

	/**
	 * The endpoints the provider holds, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{id: string, url: string, enabled_events: list<string>, metadata: array<string, string>, secret: string}>
	 */
	public array $endpoints = array();

	/**
	 * Every request received: its method, its URL and the transaction depth it was sent at.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{method: string, url: string, depth: int}>
	 */
	public array $requests = array();

	/**
	 * The error body a listing fails with; null to list.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	public ?string $failListing = null;

	/**
	 * The error body a creation fails with; null to create it.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	public ?string $failCreation = null;

	/**
	 * How many endpoints were created, to name the next.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $created = 0;

	/**
	 * Answers the provider's API from now on.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $depth Returns the open transaction levels of the test's connection (int).
	 */
	public function __construct( private \Closure $depth ) {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Adds an endpoint the provider holds already.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id      Its id.
	 * @param string $url     Its URL.
	 * @param string $install The install uuid it is tagged with.
	 * @param string $mode    The mode it is tagged with.
	 */
	public function hold( string $id, string $url, string $install, string $mode ): void {
		$this->endpoints[ $id ] = array(
			'id'             => $id,
			'url'            => $url,
			'enabled_events' => ProvisioningGateway::EVENTS,
			'metadata'       => array(
				'seocart_install' => $install,
				'seocart_mode'    => $mode,
			),
			'secret'         => 'whsec_held_' . $id,
		);
	}

	/**
	 * Answers a request to the provider's API; leaves any other to WordPress. Filters `pre_http_request`.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $pre  What an earlier filter answered.
	 * @param mixed  $args The request's arguments.
	 * @param string $url  The URL.
	 * @return mixed The answer, in the shape WordPress's HTTP API returns; `$pre` for another host.
	 */
	public function answer( mixed $pre, mixed $args, string $url ): mixed {
		if ( ! str_starts_with( $url, ProvisioningGateway::API ) ) {
			return $pre;
		}

		$method = strtoupper( (string) ( ( (array) $args )['method'] ?? 'GET' ) );

		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'depth'  => (int) ( $this->depth )(),
		);

		if ( 'GET' === $method && null !== $this->failListing ) {
			return self::response( 500, array( 'error' => $this->failListing ) );
		}

		if ( 'GET' === $method ) {
			return self::response( 200, array( 'data' => array_values( array_map( static fn( array $endpoint ): array => array_diff_key( $endpoint, array( 'secret' => true ) ), $this->endpoints ) ) ) );
		}

		if ( 'DELETE' === $method ) {
			unset( $this->endpoints[ substr( $url, strlen( ProvisioningGateway::API ) + 1 ) ] );

			return self::response( 200, array( 'deleted' => true ) );
		}

		if ( null !== $this->failCreation ) {
			return self::response( 500, array( 'error' => $this->failCreation ) );
		}

		$body = (array) json_decode( (string) ( ( (array) $args )['body'] ?? '' ), true );
		$id   = 'we_' . ( ++$this->created );

		$this->endpoints[ $id ] = array(
			'id'             => $id,
			'url'            => (string) ( $body['url'] ?? '' ),
			'enabled_events' => array_values( (array) ( $body['enabled_events'] ?? array() ) ),
			'metadata'       => (array) ( $body['metadata'] ?? array() ),
			'secret'         => 'whsec_created_' . $id . '_' . wp_generate_password( 16, false ),
		);

		return self::response( 200, $this->endpoints[ $id ] );
	}

	/**
	 * Returns the methods of the requests received, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The methods.
	 */
	public function methods(): array {
		return array_column( $this->requests, 'method' );
	}

	/**
	 * Builds an answer in the shape WordPress's HTTP API returns.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $status The status.
	 * @param array<string, mixed> $body   The JSON body.
	 * @return array<string, mixed> The answer.
	 */
	private static function response( int $status, array $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Internal Server Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
