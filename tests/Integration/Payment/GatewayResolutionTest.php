<?php
/**
 * Tests that each intent's calls go to its own gateway, with its own mode's credentials, and that a gateway whose credentials fail disables only itself
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\RefundTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test damages a stored credential on purpose.

/**
 * Two gateways are registered, the stand-in and a gateway plugin's `second`, with test and live modes and a secret key: each intent is captured through its own gateway, with the mode it was created in, after the store has switched mode; a credential that does not open, or is missing, refuses that gateway's calls, and its refunds, before any is made or claimed, while the stand-in's go on; a gateway that no longer declares an intent's mode is refused for it by that mode; a gateway whose settings would be stored under a name already held is refused, and the others keep working; and the secrets vault counts and re-seals the gateway's credentials with the plugin's own.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::capture(), build the request with the gateway's effective mode instead of
 *   the intent's: the live intent's capture goes out in test mode;
 * - in GatewayModeSettings::secret(), return the stored text without opening it: the damaged
 *   credential is then used, and the gateway called;
 * - in RefundService::refund(), open the credentials after the refund is claimed: the refused
 *   refund leaves a claim behind;
 * - in Gateways::get(), drop the check of the intent's mode: the refusal names the credentials
 *   instead of the mode;
 * - in Gateways::register(), drop the check of the names held: `acme_test` is registered, and the
 *   vault's census throws.
 *
 * @since 0.2.0
 */
final class GatewayResolutionTest extends RefundTestCase {

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The gateway plugin's gateway, as it registered.
	 *
	 * @since 0.2.0
	 *
	 * @var DeclaredGateway|null
	 */
	private ?DeclaredGateway $second = null;

	/**
	 * Builds the kernel and has `second` register through the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				$this->second = new DeclaredGateway( DeclaredGateway::descriptor( 'second' ), $registry->context( 'second' ) );

				$registry->register( $this->second );
			}
		);
	}

	/**
	 * Deletes the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests that a live intent is captured live after the store switched to test, through its own gateway, while the stand-in captures its own.
	 *
	 * @since 0.2.0
	 */
	public function test_each_intent_is_captured_through_its_gateway_in_the_mode_it_was_created_in(): void {
		// Each mode set up in turn, live last: the store takes new payments live.
		$this->configure( Mode::Test, 'sk_test_planted' );
		$this->configure( Mode::Live, 'sk_live_planted' );

		$mode = $this->gateways()->configuredMode( 'second' );

		$this->assertSame( Mode::Live, $mode, 'The store is set to live.' );

		$live = $this->authorized( 'second', $mode );
		$stub = $this->authorized( StubGateway::ID, Mode::Test );

		$this->switchMode( Mode::Test );
		$this->assertSame( Mode::Test, $this->gateways()->configuredMode( 'second' ), 'New payments are now created in test mode.' );

		$capturer = $this->userWithRole();

		$this->payments()->capture( $live->uuid, $capturer );
		$this->payments()->capture( $stub->uuid, $capturer );

		$this->assertNotNull( $this->second );
		$this->assertSame(
			array(
				array(
					'method' => 'authorize',
					'mode'   => 'live',
				),
				array(
					'method' => 'capture',
					'mode'   => 'live',
				),
			),
			$this->second->calls,
			'The live intent stays live after the switch.'
		);
		$this->assertSame( array( 'second', 'live', 'captured' ), $this->intentFacts( $live->uuid ) );
		$this->assertSame( array( StubGateway::ID, 'test', 'captured' ), $this->intentFacts( $stub->uuid ) );
		$this->assertSame( array( 'second' ), $this->ledgerProviders( $live->uuid ), 'The ledger records the gateway that answered.' );
	}

	/**
	 * Tests that a damaged credential refuses that gateway's capture before the gateway is called, and a missing one likewise, while the stand-in captures.
	 *
	 * @since 0.2.0
	 */
	public function test_a_credential_that_cannot_be_used_disables_only_its_gateway(): void {
		$this->configure( Mode::Live, 'sk_live_planted' );

		$live = $this->authorized( 'second', Mode::Live );
		$stub = $this->authorized( StubGateway::ID, Mode::Test );

		$this->damageLiveSecret();

		$capturer = $this->userWithRole();

		$this->assertNotNull( $this->second );
		$this->second->calls = array();

		try {
			$this->payments()->capture( $live->uuid, $capturer );
			$this->fail( 'The gateway was asked with a credential that does not open.' );
		} catch ( CodedException $unavailable ) {
			$this->assertSame( PaymentError::GatewayUnavailable, $unavailable->errorCode() );
			$this->assertSame(
				array(
					'gateway_id' => 'second',
					'reason'     => 'credentials_unreadable',
				),
				$unavailable->context()
			);
		}

		$this->assertSame( array(), $this->second->calls, 'Nothing was asked of the gateway.' );
		$this->assertSame( array( 'second', 'live', 'authorized' ), $this->intentFacts( $live->uuid ), 'Nothing was written.' );

		$this->payments()->capture( $stub->uuid, $capturer );
		$this->assertSame( array( StubGateway::ID, 'test', 'captured' ), $this->intentFacts( $stub->uuid ), 'The stand-in is not disabled with it.' );

		try {
			$this->gateways()->get( 'second', Mode::Test );
			$this->fail( 'A mode whose credential was never saved was used.' );
		} catch ( CodedException $missing ) {
			$this->assertSame( 'credentials_missing', $missing->context()['reason'] ?? null );
		}
	}

	/**
	 * Tests that a refund through a gateway whose credential does not open is refused before it is claimed: no claim row, nothing asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_through_a_gateway_whose_credential_does_not_open_claims_nothing(): void {
		$this->configure( Mode::Live, 'sk_live_planted' );

		$intent = $this->authorized( 'second', Mode::Live );

		$this->payments()->capture( $intent->uuid, $this->userWithRole() );
		$this->damageLiveSecret();

		$this->assertNotNull( $this->second );
		$this->second->calls = array();

		$order = (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $intent->orderId );
		$lines = $this->lineUuids( $intent->orderId );

		try {
			$this->refund( $order, array( $lines[0] => 1 ), false, $this->kernel->get( RefundService::class ) );
			$this->fail( 'The refund was asked with a credential that does not open.' );
		} catch ( CodedException $unavailable ) {
			$this->assertSame( array( PaymentError::GatewayUnavailable, 'credentials_unreadable' ), array( $unavailable->errorCode(), $unavailable->context()['reason'] ?? null ) );
		}

		$this->assertSame( array(), $this->second->calls, 'Nothing was asked of the gateway.' );
		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( RefundClaimTables::CLAIMS ) ), 'No refund was claimed.' );
	}

	/**
	 * Tests that a gateway with settings is refused for an intent of a mode it no longer declares, by that mode, before its credentials are read.
	 *
	 * @since 0.2.0
	 */
	public function test_an_intent_in_a_mode_its_gateway_no_longer_declares_is_refused_by_that_mode(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'test_only', array( Mode::Test ) ), $registry->context( 'test_only' ) ) );
			}
		);

		$document = NewOrders::forTwoLines( 'USD', 'USD' );
		$orders   = $this->kernel->get( Orders::class );
		$payments = $this->payments();

		// An intent created live, while the gateway still declared its live mode.
		list( $order, $intent ) = $this->db->transaction(
			static function () use ( $orders, $payments, $document ): array {
				$order = $orders->insert( $document, Actor::user( 0 ) );

				return array( $order, $payments->createIntent( $order->id, 'test_only', Mode::Live, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId ) );
			}
		);

		try {
			$payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ), $order->uuid, $order->orderNumber );
			$this->fail( 'A gateway was asked about a mode it does not declare.' );
		} catch ( CodedException $unavailable ) {
			$this->assertSame(
				array(
					'gateway_id' => 'test_only',
					'reason'     => 'live_mode_not_declared',
				),
				$unavailable->context()
			);
		}
	}

	/**
	 * Tests that a gateway whose settings would be stored under a name already held, by another gateway or by the plugin's own settings, is refused with a log line, and that the others, and the vault's census, keep working.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_whose_setting_names_are_held_is_refused_and_the_others_work(): void {
		$secret = static fn( string $name ): FieldSpec => new FieldSpec( name: $name, type: FieldType::String, description: 'A key of the provider.', label: static fn(): string => 'Key', example: 'x', privacy: Privacy::Secret );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ) use ( $secret ): void {
				// Both would store `acme_test_test_key`; the second is refused.
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'acme', array( Mode::Test ), array( $secret( 'test_key' ) ) ), $registry->context( 'acme' ) ) );
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'acme_test', array( Mode::Test ), array( $secret( 'key' ) ) ), $registry->context( 'acme_test' ) ) );
				// Its mode would be kept as the plugin's own `tax_rounding_mode`.
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'tax_rounding', array( Mode::Test, Mode::Live ), array() ) ) );
			}
		);

		$gateways = $this->gateways();

		$this->assertSame( 'acme', $gateways->descriptor( 'acme' )->id, 'The first gateway is registered.' );

		foreach ( array( 'acme_test', 'tax_rounding' ) as $refused ) {
			try {
				$gateways->descriptor( $refused );
				$this->fail( "The gateway {$refused} was registered." );
			} catch ( CodedException $unavailable ) {
				$this->assertSame( 'not_registered', $unavailable->context()['reason'] ?? null, $refused );
			}
		}

		$lines = array_map(
			static fn( array $line ): array => json_decode( (string) $line['context_json'], true ),
			$this->db->fetchAll( 'SELECT context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->table( LogsTable::NAME ), Gateways::REJECTED )
		);

		$this->assertSame( array( array( 'acme_test', 'settings_clash' ), array( 'tax_rounding', 'settings_clash' ) ), array_map( static fn( array $context ): array => array( $context['gateway'] ?? null, $context['reason'] ?? null ), $lines ) );
		$this->assertStringContainsString( 'acme_test_test_key', (string) ( $lines[0]['detail'] ?? '' ) );
		$this->assertStringContainsString( 'tax_rounding_mode', (string) ( $lines[1]['detail'] ?? '' ) );

		$this->configure( Mode::Test, 'sk_test_planted' );

		$this->assertSame( Mode::Test, $gateways->configuredMode( 'second' ), 'Another gateway keeps working.' );
		$this->assertSame( array( (string) $this->kernel->get( SecretKeys::class )->activeKeyId() => 1 ), $this->kernel->get( SecretVault::class )->counts(), 'The census counts the one credential saved.' );

		try {
			$gateways->get( 'acme', Mode::Test );
			$this->fail( 'The gateway without a saved credential was given.' );
		} catch ( CodedException $missing ) {
			$this->assertSame( 'credentials_missing', $missing->context()['reason'] ?? null, 'The registered gateway answers for its own credential, not with a clash.' );
		}
	}

	/**
	 * Tests that the vault counts the gateway's sealed credential with the plugin's records, re-seals it under a new key, and that it still opens after.
	 *
	 * @since 0.2.0
	 */
	public function test_the_vault_counts_and_reseals_the_gateways_credentials(): void {
		$this->configure( Mode::Live, 'sk_live_planted' );

		$keys   = $this->kernel->get( SecretKeys::class );
		$vault  = $this->kernel->get( SecretVault::class );
		$before = (string) $keys->activeKeyId();

		$this->assertSame( array( $before => 1 ), $vault->counts(), 'The census counts the gateway\'s credential.' );

		$after  = $keys->rotate();
		$report = $vault->rekey( 10 );

		$this->assertSame( 1, $report->resealed, 'The gateway\'s credential is re-sealed with the new key.' );
		$this->assertSame( array( $after => 1 ), $vault->counts() );
		$this->assertSame( 'sk_live_planted', $this->gateways()->context( 'second' )->settings( Mode::Live )->secret( 'secret_key' ), 'It still opens.' );
	}

	/**
	 * Tests that what a gateway logs through its context is redacted as the plugin's own lines are: a card number is removed, a credential named as declared is dropped.
	 *
	 * Planted violation, shown red and removed: have GatewayContext::logger() return a logger that
	 * writes its context as given: the card number then reaches the log.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateways_log_lines_are_redacted(): void {
		$this->configure( Mode::Test, 'sk_test_planted' );

		$this->gateways()->context( 'second' )->logger()->warning(
			'second.decline',
			'The provider declined the payment.',
			array(
				'note'       => 'card 4111 1111 1111 1111 declined',
				'secret_key' => 'sk_test_planted',
			)
		);

		$lines = $this->db->fetchAll( 'SELECT machine_code, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );
		$text  = (string) wp_json_encode( $lines );

		$this->assertContains( 'second.decline', array_column( $lines, 'machine_code' ), 'The line was written.' );
		$this->assertStringNotContainsString( '4111', $text, 'The card number never reaches the log.' );
		$this->assertStringNotContainsString( 'sk_test_planted', $text, 'A credential named as the gateway declared it is dropped.' );
	}

	/**
	 * Tests that a gateway plugin whose registration fails with a card number in its message has the number removed from the line the registry logs.
	 *
	 * Planted violation, shown red and removed: in Gateways::build(), report the failure through a
	 * callable that writes its context to the log as given: the card number then reaches the log.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_registration_logs_no_card_number(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function (): void {
				throw new \RuntimeException( 'The provider rejected card 4111 1111 1111 1111.' );
			},
			20
		);

		$this->assertSame( Mode::Test, $this->gateways()->configuredMode( StubGateway::ID ), 'The stand-in stays registered.' );

		$lines = $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );

		$this->assertContains( Gateways::REJECTED, array_column( $lines, 'machine_code' ), 'The refusal was logged.' );
		$this->assertStringNotContainsString( '4111', (string) wp_json_encode( $lines ), 'The card number never reaches the log.' );
	}

	/**
	 * Damages `second`'s live secret where it is stored: its header still names the key, its ciphertext is not what was sealed.
	 *
	 * @since 0.2.0
	 */
	private function damageLiveSecret(): void {
		global $wpdb;

		$option   = Setting::OPTION_PREFIX . 'gateway_second';
		$document = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) ), true );
		$name     = GatewayKernel::name( 'second', Mode::Live, 'secret_key' );
		$sealed   = (string) ( $document['values'][ $name ] ?? '' );

		$this->assertNotSame( '', $sealed, 'The credential is stored sealed.' );

		$document['values'][ $name ] = substr( $sealed, 0, -12 ) . str_repeat( 'A', 12 );

		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $document ) ), array( 'option_name' => $option ) );
		wp_cache_flush();
	}

	/**
	 * Writes `second`'s settings for a mode, and makes it the mode new payments are created in.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode   $mode   The mode.
	 * @param string $secret The secret key.
	 */
	private function configure( Mode $mode, string $secret ): void {
		GatewayKernel::writeDocument(
			$this->kernel,
			DeclaredGateway::descriptor( 'second' ),
			array(
				'second_mode' => $mode->value,
				GatewayKernel::name( 'second', $mode, 'secret_key' ) => $secret,
				GatewayKernel::name( 'second', $mode, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}

	/**
	 * Makes another mode the one new payments through `second` are created in.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 */
	private function switchMode( Mode $mode ): void {
		GatewayKernel::writeDocument( $this->kernel, DeclaredGateway::descriptor( 'second' ), array( 'second_mode' => $mode->value ) );
	}

	/**
	 * Places an order in USD, creates its intent through a gateway in a mode, and has the gateway authorize it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway.
	 * @param Mode   $mode      The intent's mode.
	 * @return IntentRef The intent, authorized.
	 */
	private function authorized( string $gatewayId, Mode $mode ): IntentRef {
		$document = NewOrders::forTwoLines( 'USD', 'USD' );
		$orders   = $this->kernel->get( Orders::class );
		$payments = $this->payments();

		list( $order, $intent ) = $this->db->transaction(
			static function () use ( $orders, $payments, $document, $gatewayId, $mode ): array {
				$order = $orders->insert( $document, Actor::user( 0 ) );

				return array( $order, $payments->createIntent( $order->id, $gatewayId, $mode, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId ) );
			}
		);

		$result = $payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ), $order->uuid, $order->orderNumber );

		$this->db->transaction( static fn() => $payments->applyGatewayResult( $result, Actor::user( 0 ) ) );

		return $intent;
	}

	/**
	 * Returns an intent's gateway, mode and status, as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The intent.
	 * @return list<string> The three.
	 */
	private function intentFacts( string $uuid ): array {
		$row = $this->db->fetchRow( 'SELECT gateway_id, mode, status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $uuid );

		return array( (string) $row['gateway_id'], (string) $row['mode'], (string) $row['status'] );
	}

	/**
	 * Returns the providers of an intent's ledger rows.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The intent.
	 * @return list<string> Each row's provider, once.
	 */
	private function ledgerProviders( string $uuid ): array {
		return array_values( array_unique( array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT t.provider FROM %i t JOIN %i i ON i.id = t.intent_id WHERE i.uuid = %s', $this->table( PaymentTables::TRANSACTIONS ), $this->table( PaymentTables::INTENTS ), $uuid ), 'provider' ) ) ) );
	}

	/**
	 * Returns the kernel's payment service.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentService The service.
	 */
	private function payments(): PaymentService {
		return $this->kernel->get( PaymentService::class );
	}

	/**
	 * Returns the kernel's gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	private function gateways(): Gateways {
		return $this->kernel->get( Gateways::class );
	}
}
