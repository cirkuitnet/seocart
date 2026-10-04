<?php
/**
 * ExtensionContext: what the plugin gives an extension to work with
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's services an extension uses, minted for one extension id.
 *
 * Owns one fact: what an extension may reach of the plugin. An extension never writes the
 * plugin's tables, never writes its own log, and never reads a credential from anywhere but its
 * settings: it reaches each through here, so the plugin's rules (redaction, the outbound rules,
 * sealed credentials) apply to it unchanged.
 *
 * @since 0.2.0
 *
 * @api
 */
interface ExtensionContext {

	/**
	 * Returns the extension's id: a gateway's is its gateway id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function extensionId(): string;

	/**
	 * Returns the version of the payment contract this plugin implements, PaymentGateway::CONTRACT_VERSION.
	 *
	 * @since 0.2.0
	 *
	 * @return string The version, `major.minor.patch`.
	 */
	public function contractVersion(): string;

	/**
	 * Returns the plugin's logger, which redacts what it is given before it writes.
	 *
	 * @since 0.2.0
	 *
	 * @return Logger The logger.
	 */
	public function logger(): Logger;

	/**
	 * Returns the clock, which tells UTC time; a test replaces it.
	 *
	 * @since 0.2.0
	 *
	 * @return Clock The clock.
	 */
	public function clock(): Clock;

	/**
	 * Returns the extension's HTTP client, which sends only to the hosts the extension declared.
	 *
	 * A gateway's client knows the hosts of its descriptor (GatewayDescriptor::$hosts) and no
	 * other: a request to any other host is refused before anything is sent. Call it outside any
	 * database transaction, as every call to a provider is made.
	 *
	 * @since 0.2.0
	 *
	 * @return HttpClient The client, the same one each time.
	 */
	public function http(): HttpClient;

	/**
	 * Returns the extension's settings and credentials for one mode, read when asked.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode: the request's, never the mode the store is set to now.
	 * @return GatewaySettings The settings.
	 */
	public function settings( Mode $mode ): GatewaySettings;
}
