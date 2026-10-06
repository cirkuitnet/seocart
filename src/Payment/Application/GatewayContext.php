<?php
/**
 * GatewayContext: what the plugin gives one payment gateway to work with
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Logger;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Clock;

/**
 * One gateway's context: the plugin's logger and clock, an HTTP client for the gateway's own hosts, and the gateway's settings, each mode read when asked.
 *
 * Owns one fact: what a gateway reaches of the plugin, and the mode it is set to take new
 * payments in. The settings are the gateway's own document, as its descriptor declared them, read
 * through the settings store and opened through the secrets vault at call time. The HTTP client
 * is built the first time it is asked for, with the hosts the gateway's descriptor declares and
 * no other. The descriptor is the registry's, read once the gateway is registered.
 *
 * @since 0.2.0
 */
final class GatewayContext implements ExtensionContext {

	/**
	 * The gateway's settings for each mode asked for so far.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, GatewayModeSettings>
	 */
	private array $modes = array();

	/**
	 * The gateway's HTTP client, once asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var HttpClient|null
	 */
	private ?HttpClient $client = null;

	/**
	 * Mints the context. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $gatewayId The gateway's id.
	 * @param Gateways      $gateways  The registry, which holds the gateway's descriptor.
	 * @param Logger        $logger    The plugin's logger.
	 * @param Clock         $clock     The clock.
	 * @param SettingsStore $store     The settings store, which holds the gateway's document.
	 * @param SecretVault   $vault     The vault that opens the gateway's credentials.
	 * @param \Closure      $clients   Builds an HTTP client that sends to the hosts given (list<OutboundHost>) and to no other.
	 *
	 * @phpstan-param \Closure(list<OutboundHost>): HttpClient $clients
	 */
	public function __construct(
		private string $gatewayId,
		private Gateways $gateways,
		private Logger $logger,
		private Clock $clock,
		private SettingsStore $store,
		private SecretVault $vault,
		private \Closure $clients
	) {
	}

	/**
	 * Returns the gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function extensionId(): string {
		return $this->gatewayId;
	}

	/**
	 * Returns the version of the payment contract this plugin implements.
	 *
	 * @since 0.2.0
	 *
	 * @return string PaymentGateway::CONTRACT_VERSION.
	 */
	public function contractVersion(): string {
		return PaymentGateway::CONTRACT_VERSION;
	}

	/**
	 * Returns the plugin's logger.
	 *
	 * @since 0.2.0
	 *
	 * @return Logger The logger.
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Returns the clock.
	 *
	 * @since 0.2.0
	 *
	 * @return Clock The clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Returns the gateway's HTTP client, which sends only to the hosts its descriptor declares.
	 *
	 * @since 0.2.0
	 *
	 * @throws \SEOCart\Support\Error\CodedException `payment.gateway_unavailable` when the gateway is not registered.
	 *
	 * @return HttpClient The client, the same one each time.
	 */
	public function http(): HttpClient {
		return $this->client ??= ( $this->clients )( $this->descriptor()->hosts );
	}

	/**
	 * Returns the gateway's settings and credentials for one mode.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return GatewaySettings The settings.
	 */
	public function settings( Mode $mode ): GatewaySettings {
		return $this->modeSettings( $mode );
	}

	/**
	 * Returns the gateway's settings for one mode, with what the plugin reads of them besides the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 * @return GatewayModeSettings The settings.
	 */
	public function modeSettings( Mode $mode ): GatewayModeSettings {
		return $this->modes[ $mode->value ] ??= new GatewayModeSettings( $this->descriptor(), $mode, $this->store, $this->vault );
	}

	/**
	 * Returns the mode the gateway is set to take new payments in: its only mode, or the mode its settings name.
	 *
	 * The registry decides the mode new payments are created in from it, which is test while Safe
	 * Mode is on (Gateways::effectiveMode()). A gateway with one mode reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @return Mode The mode.
	 */
	public function mode(): Mode {
		$descriptor = $this->descriptor();
		$setting    = GatewaySettingsDeclaration::modeSetting( $descriptor );

		return null === $setting ? $descriptor->modes[0] : Mode::from( (string) $this->store->value( $setting->name() ) );
	}

	/**
	 * Returns the gateway's descriptor, as it registered.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	private function descriptor(): GatewayDescriptor {
		return $this->gateways->descriptor( $this->gatewayId );
	}
}
