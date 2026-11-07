<?php
/**
 * Tests an order placed with an offline payment method: accepted at once, waiting for the money, with the method's name and instructions on the answer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Payment\Infrastructure\Gateway\OfflineGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\CreateOfflinePayments;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;

/**
 * An order paid by bank transfer, cheque or cash on delivery is placed as one the stand-in approves is: accepted, its units allocated, its cart converted and its promotion's use committed; its payment waits for the money, authorized, and the answer names the method and carries the merchant's instructions.
 *
 * The gate's walk is one test: an order placed through the Store API's route, its payment received
 * recorded with the day it arrived, then refunded in part and in full, each refund money returned
 * outside a provider.
 *
 * Planted violation, shown red and removed: in OfflineGateway::authorize(), answer the promise
 * Pending: the placement waits for the gateway instead of being accepted.
 *
 * @since 0.2.0
 */
final class OfflinePlacementTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;
	use PlacementSnapshots;
	use ServesStoreApi;

	/**
	 * The settings files the test wrote, removed in tear_down().
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Creates the table of payments received offline, and boots the Store API's server.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateOfflinePayments() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->bootStoreApi();
	}

	/**
	 * Removes the server, the users, the settings files and the methods' settings the test wrote.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();
		$this->deleteCreatedUsers();
		GatewayKernel::deleteOptions();

		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		parent::tear_down();
	}

	/**
	 * Tests that each method is registered on a store that never set it up, live and configured, its settings document unwritten.
	 *
	 * @since 0.2.0
	 */
	public function test_each_method_is_live_and_configured_on_a_fresh_store(): void {
		$gateways = $this->kernel->get( Gateways::class );

		foreach ( OfflineMethod::ids() as $id ) {
			$this->assertSame( Mode::Live, $gateways->configuredMode( $id ), $id );
			$this->assertTrue( $gateways->isEnabled( $id ), $id . ' starts enabled.' );
			$this->assertFalse( get_option( 'seocart_' . GatewaySettingsDeclaration::group( $id ) ), $id . ': nothing was written to configure it.' );
		}

		$this->assertSame( array( ...OfflineMethod::ids(), StubGateway::ID ), $gateways->ids(), 'The store\'s own methods register first, then the stand-in.' );
	}

	/**
	 * Tests each method's placement: accepted and allocated as the stand-in's approval is, the payment authorized, the answer naming the method with its empty instructions, and kept for a retry.
	 *
	 * @since 0.2.0
	 */
	public function test_an_offline_placement_is_settled_as_the_stand_ins_approval_is(): void {
		$standIn = $this->changesOfAPlacement( StubGateway::ID, 'STANDIN' );

		foreach ( OfflineMethod::cases() as $method ) {
			$changes = $this->changesOfAPlacement( $method->value, strtoupper( 'code' . $method->value ) );

			$this->assertSame(
				(array) json_decode( str_replace( 'stub-ch-', $method->value . '-promise-', (string) wp_json_encode( $standIn['changes'] ) ), true ),
				$changes['changes'],
				$method->value . ': every row the placement wrote, as the stand-in\'s approval writes them.'
			);
			$this->assertSame( array( 'approved', 'processing', 'authorized' ), array( $changes['answer']['outcome'], $changes['answer']['status'], $changes['answer']['payment_status'] ) );
			$this->assertSame( array( ( $method->label() )(), '' ), array( $changes['answer']['payment_method_label'], $changes['answer']['payment_instructions'] ), $method->value . ': the method\'s own name, and no instructions yet.' );
			$this->assertSame( $changes['answer'], $this->placement->place( $changes['input'], self::guest() ), $method->value . ': a retry with the same key is answered as the placement was.' );
		}

		$this->assertSame( array( 'Stand-in gateway', null ), array( $standIn['answer']['payment_method_label'], $standIn['answer']['payment_instructions'] ), 'A provider\'s method has a name and no instructions.' );
	}

	/**
	 * Tests the merchant's name and instructions: configured from a file giving the instructions alone, the name then the method's own; a name of the merchant's then; and instructions too long refused.
	 *
	 * @since 0.2.0
	 */
	public function test_the_merchants_name_and_instructions_reach_the_placements_answer(): void {
		$operator     = $this->userGranted( SettingsService::SECRETS_CAPABILITY );
		$command      = new GatewayCommandRecorder( $this->kernel );
		$instructions = "Pay into account 12345678, quoting your order number.\nWe ship once the money arrives.";

		$this->assertSame( 0, $command->run( array( 'configure', 'bank_transfer' ), $this->settingsFile( array( OfflineMethod::INSTRUCTIONS => $instructions ) ), $operator->userId() ), $command->printed() );

		$answer = $this->placeWith( 'bank_transfer', 'first' );

		$this->assertSame( array( 'Bank transfer', $instructions ), array( $answer['payment_method_label'], $answer['payment_instructions'] ), 'An empty name is the method\'s own.' );

		$this->assertSame( 0, $command->run( array( 'configure', 'bank_transfer' ), $this->settingsFile( array( OfflineMethod::LABEL => 'Pay by bank' ) ), $operator->userId() ), $command->printed() );
		$this->assertSame( 'Pay by bank', $this->placeWith( 'bank_transfer', 'second' )['payment_method_label'] );

		$this->assertSame( 1, $command->run( array( 'configure', 'bank_transfer' ), $this->settingsFile( array( OfflineMethod::INSTRUCTIONS => str_repeat( 'a', OfflineGateway::INSTRUCTIONS_MAX_LENGTH + 1 ) ) ), $operator->userId() ), 'Instructions longer than the setting holds were saved.' );
		$this->assertSame( 'Pay by bank', $this->placeWith( 'bank_transfer', 'third' )['payment_method_label'], 'The refused file changed nothing.' );
	}

	/**
	 * Tests the walk of the gate: an order placed by bank transfer through the Store API's route, its payment received recorded with the day it arrived, and refunded in part and then in full, each refund money returned outside a provider.
	 *
	 * @since 0.2.0
	 */
	public function test_an_offline_order_is_placed_paid_and_refunded(): void {
		$placed = $this->placeOverTheWire( 'bank_transfer' );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
		$this->assertSame( array( 'approved', 'processing', 'authorized', 'Bank transfer', '' ), array( $placed['body']['outcome'], $placed['body']['status'], $placed['body']['payment_status'], $placed['body']['payment_method_label'], $placed['body']['payment_instructions'] ) );

		$orderUuid = (string) $placed['body']['order_uuid'];
		$orderId   = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $orderUuid );
		$intent    = (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $orderId );
		$person    = $this->userGranted( PaymentService::CAPTURE_CAPABILITY, RefundService::CAPABILITY );
		$day       = (string) $this->db->fetchValue( "SELECT DATE_FORMAT( UTC_DATE() - INTERVAL 1 DAY, '%Y-%m-%d' )" );

		$received = $this->kernel->get( PaymentService::class )->capturePayment(
			array(
				'intent_uuid' => $intent,
				'received_on' => $day,
				'reference'   => 'TRF 000123',
			),
			$person
		);

		$this->assertSame( array( 'applied', 'captured', 'paid' ), array( $received['outcome'], $received['status'], $received['payment_status'] ) );
		$this->assertSame( array( $day, 'TRF 000123' ), array_values( (array) $this->db->fetchRow( 'SELECT received_on, reference FROM %i WHERE order_id = %d', $this->table( PaymentTables::OFFLINE_PAYMENTS ), $orderId ) ) );

		$refunds = $this->kernel->get( RefundService::class );
		$line    = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d', $this->table( OrderTables::LINES ), $orderId );
		$part    = $refunds->refundOrder( $this->refundInput( $orderUuid, array( $line => 1 ), false, 'walk-part' ), $person );
		$rest    = $refunds->refundOrder( $this->refundInput( $orderUuid, array(), true, 'walk-rest' ), $person );

		$this->assertNotSame( $part['refund_uuid'], $rest['refund_uuid'] );
		$this->assertSame( array( '1', '1' ), array_column( $this->db->fetchAll( 'SELECT is_offline FROM %i WHERE order_id = %d ORDER BY id', $this->table( RefundTables::REFUNDS ), $orderId ), 'is_offline' ), 'Each refund is money returned outside a provider.' );

		$ledger = $this->db->fetchAll( 'SELECT operation, result, applied, provider_object_id FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId );

		$this->assertSame(
			array(
				array( 'authorize', 'approved', '1', 'bank_transfer-promise-' . $intent ),
				array( 'capture', 'approved', '1', 'bank_transfer-received-' . $intent ),
				array( 'refund', 'approved', '1', 'bank_transfer-refund-' . $part['refund_uuid'] ),
				array( 'refund', 'approved', '1', 'bank_transfer-refund-' . $rest['refund_uuid'] ),
			),
			array_map( 'array_values', $ledger )
		);
		$this->assertSame( array( 'refunded', 'refunded' ), array( $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent ), $this->db->fetchValue( 'SELECT payment_status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ) ) );
	}

	/**
	 * Places one unit of a new variant with a promotion code through a method, and returns every row the placement wrote, described, with its answer and input.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @param string $code   The promotion code, planted.
	 * @return array{changes: array<string, array{gone: list<string>, new: list<string>}>, answer: array<string, mixed>, input: array<string, mixed>} The rows, the answer and the input.
	 */
	private function changesOfAPlacement( string $method, string $code ): array {
		$this->plantPromotion( $code );

		$variant = $this->sellable();
		$cart    = $this->readyCart( array( $variant => 1 ), array( $code ) );

		$this->writeCheckout( $cart->version, $method );

		$input  = $this->placeInput( 'placed-' . $method );
		$before = $this->takeSnapshot();

		if ( StubGateway::ID !== $method ) {
			unset( $input['payment_data'] );
		}

		$answer  = $this->placement->place( $input, self::guest() );
		$orderId = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );

		return array(
			'changes' => $this->changesSince(
				$before,
				array(
					'payment' => array(
						'order_id'    => $orderId,
						'variant'     => $variant,
						'intent_uuid' => (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $orderId ),
					),
				)
			),
			'answer'  => $answer,
			'input'   => $input,
		);
	}

	/**
	 * Places one unit of a new variant through a method, with no payment data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @param string $key    The idempotency key.
	 * @return array<string, mixed> The answer.
	 */
	private function placeWith( string $method, string $key ): array {
		$cart = $this->readyCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, $method );

		$input = $this->placeInput( $key );

		unset( $input['payment_data'] );

		return $this->freshPlacement()->place( $input, self::guest() );
	}

	/**
	 * Starts a cart over the Store API's route, writes its checkout with a method, and places it with no payment data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The method.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} The placement's answer.
	 */
	private function placeOverTheWire( string $method ): array {
		unset( $_COOKIE[ CartTokenTransport::COOKIE ] );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $this->sellable( 5, 1999 ) => 1 ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );

		$address  = array(
			'country'  => 'US',
			'line1'    => '1 Main Street',
			'city'     => 'Austin',
			'postcode' => '78701',
		);
		$checkout = $this->store(
			'PUT',
			CheckoutOperations::ROUTE,
			array(
				'cart_version'       => 1,
				'billing_address'    => $address + array(
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'email'      => 'ada@example.com',
				),
				'shipping_address'   => $address,
				'payment_method_key' => $method,
			)
		);

		$this->assertSame( 200, $checkout['status'], (string) wp_json_encode( $checkout['body'] ) );

		return $this->serve(
			$this->server,
			'POST',
			'/' . RestBinding::STORE_NAMESPACE . CheckoutOperations::ROUTE,
			array(
				StoreRequestPolicy::HEADER             => StoreRequestPolicy::HEADER_VALUE,
				CheckoutOperations::IDEMPOTENCY_HEADER => 'walk-placement',
			),
			array(
				'cart_version'      => $checkout['body']['version'],
				'grand_total_minor' => $checkout['body']['totals']['summary']['grand_minor'],
				'currency'          => $checkout['body']['totals']['currency'],
			)
		);
	}

	/**
	 * Writes a settings file for an offline method's live mode, readable by its owner alone, and returns configure's options for it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $values The settings.
	 * @return array<string, string> The options: the mode and the file.
	 */
	private function settingsFile( array $values ): array {
		$file = (string) wp_tempnam( 'seocart-offline' );

		$this->files[] = $file;

		file_put_contents( $file, (string) wp_json_encode( $values ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test's own temporary file.
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- configure reads only a file its owner alone can read.

		return array(
			'mode'      => Mode::Live->value,
			'from-file' => $file,
		);
	}

	/**
	 * Builds the refund operation's prepared input.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $orderUuid The order.
	 * @param array<string, int> $units     The units of each line, by line uuid.
	 * @param bool               $shipping  Whether to give back what is left of the shipping.
	 * @param string             $key       The idempotency key.
	 * @return array<string, mixed> The input.
	 */
	private function refundInput( string $orderUuid, array $units, bool $shipping, string $key ): array {
		$lines = array();

		foreach ( $units as $lineUuid => $quantity ) {
			$lines[] = array(
				'line_uuid' => (string) $lineUuid,
				'quantity'  => $quantity,
			);
		}

		return array(
			'order_uuid'      => $orderUuid,
			'lines'           => $lines,
			'shipping'        => $shipping,
			'reason_code'     => 'customer_return',
			'idempotency_key' => $key,
		);
	}
}
