<?php
/**
 * Tests that a gateway's HTTP client sends only to the hosts the gateway declared
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Http\ReportCode;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Two gateway plugins register, `second` and `third`, each declaring its own provider's host: through the kernel's wiring, each gateway's client reaches its own host through WordPress's HTTP API, refuses the other's before anything is sent, and is the same client each time; a card number in a URL it logs is removed.
 *
 * Nothing here reaches the network: the requests are answered at `pre_http_request`, after the
 * client's own checks and before WordPress would send them.
 *
 * Planted violations, each shown red and removed:
 * - in Modules::paymentRegister(), build each gateway's client with the plugin's own hosts
 *   (OutboundEndpoints::all()) instead of the gateway's: `second` cannot reach its own host;
 * - in GatewayContext::http(), keep one client for every gateway: `third` gets `second`'s
 *   client, and cannot reach its own host.
 *
 * @since 0.2.0
 */
final class GatewayHttpTest extends PaymentTestCase {

	/**
	 * The production wiring.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The URL of every request that reached `pre_http_request`.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $sent = array();

	/**
	 * Builds the kernel, has `second` and `third` register with a host each, and answers every request.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				foreach ( array( 'second', 'third' ) as $id ) {
					$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( $id, array( Mode::Test ), array(), null, null, PaymentGateway::CONTRACT_VERSION, array( DeclaredGateway::host( $id ) ) ) ) );
				}
			}
		);
		add_filter(
			'pre_http_request',
			function ( mixed $pre, mixed $args, string $url ): array|\WP_Error {
				$this->sent[] = $url;

				// A path that names a card is answered with a failure, which the client logs with the URL.
				if ( str_contains( $url, '/cards/' ) ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
				}

				return array(
					'headers'  => array(),
					'body'     => '{"id":"ch_1"}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Deletes the data keys the kernel made.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests that each gateway's client reaches its own host, refuses the other gateway's before sending, and is one client per gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_calls_only_the_hosts_it_declared(): void {
		$gateways = $this->kernel->get( Gateways::class );
		$second   = $gateways->context( 'second' )->http();
		$answer   = $second->send( new OutboundRequest( 'second', 'POST', 'https://api.second.example/v1/charges', array(), 'amount=500' ) );

		$this->assertSame( array( 200, '{"id":"ch_1"}' ), array( $answer->status, $answer->body ) );
		$this->assertSame( $second, $gateways->context( 'second' )->http(), 'A gateway has one client.' );

		$gateways->context( 'third' )->http()->send( new OutboundRequest( 'third', 'GET', 'https://api.third.example/v1/charges/ch_1' ) );

		$elsewhere = array(
			'by its id'  => new OutboundRequest( 'third', 'GET', 'https://api.third.example/v1/charges/ch_1' ),
			'by its URL' => new OutboundRequest( 'second', 'GET', 'https://api.third.example/v1/charges/ch_1' ),
		);

		foreach ( $elsewhere as $case => $request ) {
			try {
				$second->send( $request );
				$this->fail( "The gateway second reached third's host {$case}." );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsStringIgnoringCase( 'outbound host', $refused->getMessage(), $case );
			}
		}

		$this->assertSame( array( 'https://api.second.example/v1/charges', 'https://api.third.example/v1/charges/ch_1' ), $this->sent, 'Each gateway reached its own host once, and nothing else was sent.' );
	}

	/**
	 * Tests that a card number in the path of a request a gateway logs is removed from the log line.
	 *
	 * Planted violation, shown red and removed: in Redactor::safe(), keep the text as it is instead
	 * of scrubbing its card numbers: the number then reaches the log.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_in_a_logged_url_is_removed(): void {
		try {
			$this->kernel->get( Gateways::class )->context( 'second' )->http()->send( new OutboundRequest( 'second', 'GET', 'https://api.second.example/v1/cards/4111111111111111' ) );
			$this->fail( 'The failed request was answered.' );
		} catch ( TransportFailure $failure ) {
			$this->assertSame( TransportFailure::CONNECT, $failure->kind );
		}

		$lines = $this->db->fetchAll( 'SELECT machine_code, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );

		$this->assertContains( ReportCode::Failed->value, array_column( $lines, 'machine_code' ), 'The failed request was logged, with its URL.' );
		$this->assertStringNotContainsString( '4111111111111111', (string) wp_json_encode( $lines ), 'The card number never reaches the log.' );
	}
}
