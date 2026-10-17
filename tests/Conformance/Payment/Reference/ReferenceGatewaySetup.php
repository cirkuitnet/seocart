<?php
/**
 * ReferenceGatewaySetup: the conformance suite set up for the reference gateway and its fake provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;

/**
 * What every test of the reference gateway shares: its registration, its tokens, its keys' shape, its signer, and its provider answering whatever is not replayed.
 *
 * Owns one fact: how SEOCart's own tests run the suite on the reference gateway: the case that runs
 * every rule, the plant tests and the determinism test use it. A gateway plugin's test needs none of
 * this: its plugin registers the gateway, and its provider is real.
 *
 * @since 0.2.0
 */
trait ReferenceGatewaySetup {

	/**
	 * Where the reference gateway's tapes are.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TAPES = __DIR__ . '/tapes';

	/**
	 * The instant the recorded deliveries are signed at.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SIGNED_AT = '2026-06-01T12:00:00+00:00';

	/**
	 * The fake provider.
	 *
	 * @since 0.2.0
	 *
	 * @var ReferenceProvider
	 */
	protected ReferenceProvider $provider;

	/**
	 * Builds the suite with the provider answering every request the transport lets through.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		$this->provider = new ReferenceProvider();

		parent::set_up();

		$this->provider->install();
	}

	/**
	 * Returns the reference gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return ReferenceGateway::ID;
	}

	/**
	 * Returns the reference gateway's tapes.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The directory.
	 */
	protected function tapes(): ?string {
		return self::TAPES;
	}

	/**
	 * Returns the provider's tokens of each scenario.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The tokens by scenario.
	 */
	protected function tokens(): array {
		return ReferenceProvider::TOKENS;
	}

	/**
	 * Registers the reference gateway, as a plugin's listener would.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 */
	protected function register( GatewayRegistry $registry ): void {
		$registry->register( $this->gatewayOf( $registry ) );
	}

	/**
	 * Builds the gateway a test registers; the plant tests build a plant instead.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		return new ReferenceGateway( $registry->context( ReferenceGateway::ID ) );
	}

	/**
	 * Returns a stand-in key of the shape the provider takes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $field The setting.
	 * @param Mode   $mode  The mode.
	 * @return string The key.
	 */
	protected function placeholder( string $field, Mode $mode ): string {
		return 'ref_' . $mode->value . '_' . $field . '_conformance';
	}

	/**
	 * Returns the fields whose 64-hex digests are public: the delivery's signature header, an HMAC signed with the published test secret.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The header's name.
	 */
	protected function publicDigestFields(): array {
		return array( ReferenceGateway::SIGNATURE_HEADER );
	}

	/**
	 * Signs a delivery as the provider does.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The secret.
	 * @param \DateTimeImmutable    $at      When.
	 * @return array<string, string> The headers, signed.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		return ReferenceGateway::signed( $headers, $body, $secret, $at );
	}

	/**
	 * Returns the settings a recording of the fake provider uses: credentials of the provider's shape, made up.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string> The settings.
	 */
	protected function recordingSettings(): array {
		return array(
			ReferenceGateway::SECRET_KEY       => 'ref_test_recorded_secret_key',
			GatewayDescriptor::WEBHOOK_SECRET  => 'whsec_reference_recorded_signing_secret',
			GatewayDescriptor::ACCOUNT_COUNTRY => 'US',
		);
	}

	/**
	 * Returns two deliveries the provider sends, signed with the recording's secret: an authorization and an event the plugin does not act on.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> The deliveries by name.
	 */
	protected function freshDeliveries(): array {
		$at      = new \DateTimeImmutable( self::SIGNED_AT );
		$secret  = (string) $this->recordingSettings()[ GatewayDescriptor::WEBHOOK_SECRET ];
		$intent  = array(
			'id'           => 'ri_webhook',
			'status'       => 'requires_capture',
			'amount'       => 2000,
			'currency'     => 'usd',
			'intent_uuid'  => '01942f00-0000-7000-8000-00000000a001',
			'order_number' => '1',
			'charge'       => 'rch_webhook',
		);
		$account = array(
			'id'      => 'acct_1',
			'country' => 'US',
		);

		$deliveries = array();

		foreach ( array(
			'authorized'      => array( 'intent.authorized', $intent ),
			'account-updated' => array( 'account.updated', $account ),
		) as $name => list( $type, $object ) ) {
			$deliveries[ $name ] = array(
				'mode'      => Mode::Test->value,
				'signed_at' => self::SIGNED_AT,
			) + $this->provider->delivery( $type, $object, $at, $secret );
		}

		return $deliveries;
	}
}
