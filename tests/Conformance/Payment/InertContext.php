<?php
/**
 * InertContext: the context a gateway is built with while only its declaration is read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\Logger;
use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Support\Clock;

/**
 * A gateway's context outside WordPress, where only the gateway's declaration is read: it names the gateway and refuses everything else.
 *
 * Owns one fact: that building a gateway and calling describe() need nothing of the plugin. A
 * gateway that asks its context for its logger, clock, client or settings while being built or
 * described fails with a LogicException saying so.
 *
 * @since 0.2.0
 */
final class InertContext implements ExtensionContext {

	/**
	 * Names the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The id the gateway was registered under.
	 */
	public function __construct( private string $gatewayId ) {
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
	 * Returns the contract's version.
	 *
	 * @since 0.2.0
	 *
	 * @return string PaymentGateway::CONTRACT_VERSION.
	 */
	public function contractVersion(): string {
		return PaymentGateway::CONTRACT_VERSION;
	}

	/**
	 * Refuses: no logger while the declaration is read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return Logger Never.
	 */
	public function logger(): Logger {
		return self::refuse( 'logger' );
	}

	/**
	 * Refuses: no clock while the declaration is read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return Clock Never.
	 */
	public function clock(): Clock {
		return self::refuse( 'clock' );
	}

	/**
	 * Refuses: no HTTP client while the declaration is read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return HttpClient Never.
	 */
	public function http(): HttpClient {
		return self::refuse( 'HTTP client' );
	}

	/**
	 * Refuses: no settings while the declaration is read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param Mode $mode The mode asked for.
	 * @return GatewaySettings Never.
	 */
	public function settings( Mode $mode ): GatewaySettings {
		unset( $mode );

		return self::refuse( 'settings' );
	}

	/**
	 * Refuses one member.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param string $what What was asked for.
	 * @return never
	 */
	private static function refuse( string $what ): never {
		throw new \LogicException( sprintf( 'The gateway asked its context for its %s while it was being built or described: a gateway\'s declaration needs nothing but itself.', $what ) );
	}
}
