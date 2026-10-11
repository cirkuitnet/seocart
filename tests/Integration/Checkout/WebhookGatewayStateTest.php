<?php
/**
 * Tests that a delivery to a gateway that cannot be asked now is refused before any secret is opened, and that Safe Mode stops live deliveries only
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\SecretsHarness;

/**
 * `second` declares test and live, webhooks, and a webhook signing secret per mode. A live delivery while Safe Mode is on is refused `payment.gateway_unavailable` / `safe_mode` before the gateway reads anything or a secret is opened, while a test delivery goes on; a delivery to a mode whose credentials are missing or cannot be opened is refused with that reason. Each refusal keeps no receipt.
 *
 * Planted violation: in ReceiveWebhook::gatewayOf(), ask Gateways::get() for the test mode whatever
 * the address's mode: the live delivery is then read, and its secret opened, under Safe Mode.
 *
 * @since 0.2.0
 */
final class WebhookGatewayStateTest extends WebhookTestCase {

	/**
	 * The site's data key, the same for every wiring of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var EncryptionKey|null
	 */
	private ?EncryptionKey $key = null;

	/**
	 * Creates the data key, its table, and the receipts.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		$this->key = SecretsHarness::newEncryptionKey();

		parent::set_up();

		( new CreateSecretKeysMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		$this->kernel->get( SecretKeys::class )->initialize();
	}

	/**
	 * Declares `second` with webhooks and a webhook signing secret.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	protected static function descriptor(): GatewayDescriptor {
		return DeclaredGateway::descriptor(
			self::SECOND,
			array( Mode::Test, Mode::Live ),
			array(
				new FieldSpec( name: GatewayDescriptor::WEBHOOK_SECRET, type: FieldType::String, description: 'The provider\'s webhook signing secret.', label: static fn(): string => 'Webhook signing secret', example: 'example-signing-secret', privacy: Privacy::Secret ),
				new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The country of the provider account.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ),
			),
			DeclaredGateway::matrix( array( 'USD', 'GBP', 'EUR' ), array_values( array_diff( Operations::ALL, array( Operations::MULTI_CAPTURE, Operations::OFF_SESSION ) ) ) )
		);
	}

	/**
	 * Adds the site's data key to the wiring.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When a wiring is built before the key, which set_up() rules out.
	 *
	 * @return array<string, callable(Container): object> The replacements.
	 */
	protected function overrides(): array {
		$key = $this->key;

		return parent::overrides() + array( EncryptionKey::class => static fn(): EncryptionKey => $key ?? throw new \LogicException( 'The data key is made before any wiring.' ) );
	}

	/**
	 * Tests that a live delivery while Safe Mode is on is refused before the gateway reads it or any secret is opened, and that a test delivery goes on.
	 *
	 * @since 0.2.0
	 */
	public function test_safe_mode_refuses_a_live_delivery_before_any_secret_is_opened(): void {
		$this->writeSecrets( Mode::Test, Mode::Live );

		$this->safeMode = true;
		$receiver       = $this->kernelOver( $this->db, $this->tokens )->get( ReceiveWebhook::class );
		$delivery       = StubWebhooks::dispute( null );

		try {
			$receiver->receive( $delivery->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), Mode::Live, self::SECOND ) );
			$this->fail( 'A live delivery was received in Safe Mode.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( PaymentError::GatewayUnavailable, 'safe_mode' ), array( $refused->errorCode(), $refused->context()['reason'] ?? null ) );
		}

		$this->assertNotNull( $this->second );
		$this->assertSame( array(), $this->second->calls, 'The gateway read nothing.' );
		$this->assertSame( array(), $this->second->opened, 'No secret was opened.' );
		$this->assertSame( 0, $this->receiptCount() );

		$this->assertSame( ReceiptResult::Ignored, $receiver->receive( $delivery->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), Mode::Test, self::SECOND ) ), 'A test delivery goes on in Safe Mode.' );
		$this->assertSame( array( 'readWebhook' ), array_column( $this->second->calls, 'method' ) );
	}

	/**
	 * Tests that a delivery to a mode whose secret was never saved, or does not open, is refused with that reason and keeps no receipt; the provider sends it again once the merchant fixes it.
	 *
	 * @since 0.2.0
	 */
	public function test_credentials_that_cannot_be_used_refuse_a_delivery(): void {
		$this->writeSecrets( Mode::Test );

		$this->assertSame( 'credentials_missing', $this->reasonOf( Mode::Live ) );

		GatewayKernel::alterStored( self::SECOND, Mode::Test, GatewayDescriptor::WEBHOOK_SECRET, static fn(): string => 'not a sealed value' );

		$this->assertSame( 'credentials_unreadable', $this->reasonOf( Mode::Test ) );
		$this->assertSame( 0, $this->receiptCount() );
	}

	/**
	 * Saves `second`'s settings with a webhook secret for some modes.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode ...$modes The modes whose secret is saved.
	 */
	private function writeSecrets( Mode ...$modes ): void {
		$values = array( self::SECOND . '_mode' => Mode::Live->value );

		foreach ( array( Mode::Test, Mode::Live ) as $mode ) {
			$values[ GatewayKernel::name( self::SECOND, $mode, GatewayDescriptor::ACCOUNT_COUNTRY ) ] = 'US';
		}

		foreach ( $modes as $mode ) {
			$values[ GatewayKernel::name( self::SECOND, $mode, GatewayDescriptor::WEBHOOK_SECRET ) ] = 'example-signing-secret-' . $mode->value;
		}

		GatewayKernel::writeDocument( $this->kernel, self::descriptor(), $values );
	}

	/**
	 * Delivers a dispute to `second` at a mode in a fresh request, and returns why it was refused.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode of the address.
	 * @return string|null The reason of `payment.gateway_unavailable`, or null when it was not refused so.
	 */
	private function reasonOf( Mode $mode ): ?string {
		try {
			$this->kernelOver( $this->db, $this->tokens )->get( ReceiveWebhook::class )->receive( StubWebhooks::dispute( null )->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $mode, self::SECOND ) );
		} catch ( CodedException $refused ) {
			return PaymentError::GatewayUnavailable === $refused->errorCode() ? (string) ( $refused->context()['reason'] ?? '' ) : null;
		}

		return null;
	}
}
