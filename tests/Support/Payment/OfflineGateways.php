<?php
/**
 * OfflineGateways: the plugin's gateway registry with each offline payment method recorded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Infrastructure\Gateway\OfflineGateway;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Http\OutboundClient;
use SEOCart\Platform\Http\WordPressTransport;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Clock;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Tests\Support\Doubles\RecordingGateway;

/**
 * Builds the gateway registry the plugin builds, with each offline payment method inside a recording double, so a test counts the calls each one gets.
 *
 * Owns one fact: how a test sees the calls to the offline payment methods, which the plugin
 * registers itself and no binding replaces. The registry is the kernel's own in every other
 * respect: the stand-in where the site allows it, the contexts, the reporter, the redaction and
 * the switches. A wrapper may be given for a test that scripts what a method answers, such as a
 * double that loses an answer; it is put inside the recording double.
 *
 * @since 0.2.0
 */
final class OfflineGateways {

	/**
	 * The recording double of each offline payment method, by gateway id, of the registry built last.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, RecordingGateway>
	 */
	public array $recorders = array();

	/**
	 * Creates the builder.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx   The connection whose transaction depth each call is recorded with.
	 * @param \Closure|null      $wrap Optional. Wraps each method before it is recorded. Default none.
	 *
	 * @phpstan-param (\Closure(PaymentGateway): PaymentGateway)|null $wrap
	 */
	public function __construct( private TransactionManager $tx, private ?\Closure $wrap = null ) {
	}

	/**
	 * Builds the registry over a kernel's container, as the plugin's binding does.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $c The container.
	 * @return Gateways The registry.
	 */
	public function registryOver( Container $c ): Gateways {
		$this->recorders = array();

		foreach ( OfflineGateway::all() as $method ) {
			$this->recorders[ $method->describe()->id ] = new RecordingGateway( null === $this->wrap ? $method : ( $this->wrap )( $method ), $this->tx );
		}

		return new Gateways(
			Gateways::stubAllowed( wp_get_environment_type(), defined( 'SEOCART_STUB_GATEWAY' ) ? constant( 'SEOCART_STUB_GATEWAY' ) : null ) ? $c->get( PaymentGateway::class ) : null,
			static fn( string $gatewayId ): GatewayContext => new GatewayContext(
				$gatewayId,
				$c->get( Gateways::class ),
				$c->get( Logger::class ),
				$c->get( Clock::class ),
				$c->get( SettingsStore::class ),
				$c->get( SecretVault::class ),
				static fn( array $hosts ): HttpClient => new OutboundClient( $hosts, new WordPressTransport(), array( $c->get( Logger::class ), 'log' ) )
			),
			$c->get( Reporter::class ),
			static function ( FieldSpec ...$fields ) use ( $c ): void {
				$c->get( Redactor::class )->declareFields( ...$fields );
			},
			$c->get( GatewaySwitches::class ),
			array_values( $this->recorders )
		);
	}

	/**
	 * Counts the calls every offline payment method got, of the registry built last.
	 *
	 * @since 0.2.0
	 *
	 * @return int The calls.
	 */
	public function calls(): int {
		return array_sum( array_map( static fn( RecordingGateway $recorder ): int => count( $recorder->calls ), $this->recorders ) );
	}
}
