<?php
/**
 * Tests the declaration of the checkout session write
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Operations;
use SEOCart\Application\Operations\RequestHeader;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CurrencyChangeLimit;
use SEOCart\Cart\Application\StoreApiError;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * `checkout.update_session` as it is declared: a public write of the Store API, served by PUT, counted with the cart's writes, whose addresses are personal data.
 *
 * Planted violation: in CheckoutOperations::address(), declare `email` without
 * `privacy: Privacy::Pii`: the address field is then public, and a guest's answer would carry it.
 *
 * @since 0.1.0
 */
final class CheckoutOperationsTest extends TestCase {

	/**
	 * Tests the surface: the production registry holds the write, a PUT route of the Store API, and nothing else.
	 *
	 * @since 0.1.0
	 */
	public function test_the_write_is_a_put_route_of_the_store_api_only(): void {
		$definition = CheckoutOperations::updateSession();
		$rest       = $definition->rest();
		$ids        = array_map( static fn( $registered ): string => $registered->id(), Operations::registry()->all() );

		$this->assertContains( CheckoutOperations::UPDATE_SESSION, $ids );
		$this->assertNotNull( $rest );
		$this->assertSame( array( 'PUT', '/checkout', RestBinding::STORE_NAMESPACE ), array( $definition->httpMethod(), $rest->route(), $rest->restNamespace() ) );
		$this->assertSame( array( null, null, null ), array( $definition->capability(), $definition->abilityName(), $definition->cli() ) );
		$this->assertFalse( $definition->isAgentExposed() );
		$this->assertSame( array( UpdateCheckoutSession::class, 'update' ), $definition->service() );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			array_intersect_key( $definition->annotations()->toArray(), array_flip( array( 'readonly', 'destructive', 'idempotent' ) ) )
		);
	}

	/**
	 * Tests the write's policy: the cart must exist, and the write counts against the cart writes' limit, being one.
	 *
	 * @since 0.1.0
	 */
	public function test_the_write_counts_with_the_carts_writes(): void {
		$write = CheckoutOperations::updateSession()->publicWrite();

		$this->assertNotNull( $write );
		$this->assertTrue( $write->requiresCart() );
		$this->assertSame( array( CartOperations::WRITE_BUCKET, CartOperations::WRITE_LIMIT, CartOperations::WRITE_WINDOW ), array( $write->rateLimit()->bucket(), $write->rateLimit()->limit(), $write->rateLimit()->windowSeconds() ) );
	}

	/**
	 * Tests the input: the cart's version as every cart write declares it, two addresses of the document's fields, all personal data, and the two methods.
	 *
	 * @since 0.1.0
	 */
	public function test_the_input_declares_the_addresses_as_personal_data(): void {
		$fields = self::byName( CheckoutOperations::updateSession()->input() );

		$this->assertSame( array( 'cart_version', 'billing_address', 'shipping_address', 'shipping_method_key', 'payment_method_key' ), array_keys( $fields ) );
		$this->assertEquals( CartOperations::cartVersion( 1 ), $fields['cart_version'] );

		foreach ( array( 'billing_address', 'shipping_address' ) as $name ) {
			$address = $fields[ $name ];
			$members = self::byName( $address->fields() );

			$this->assertSame( array( FieldType::Object, false, false ), array( $address->type(), $address->isRequired(), $address->isNullable() ) );
			$this->assertSame( array_keys( AddressDocument::FIELDS ), array_keys( $members ) );

			foreach ( $members as $member => $field ) {
				$this->assertSame( array( FieldType::String, Privacy::Pii, AddressDocument::FIELDS[ $member ] ), array( $field->type(), $field->privacy(), $field->maxLength() ), "{$name}.{$member}" );
				$this->assertSame( 'country' === $member, $field->isRequired(), "{$name}.{$member}" );
			}
		}

		// Any gateway the store offers, checked against its gateways; as long as a gateway id may be.
		$this->assertSame( array( array(), GatewayDescriptor::ID_MAX_LENGTH ), array( $fields['payment_method_key']->allowedValues(), $fields['payment_method_key']->maxLength() ) );
		$this->assertSame( CheckoutDetails::METHOD_KEY_MAX_LENGTH, $fields['shipping_method_key']->maxLength() );
	}

	/**
	 * Tests the output and the errors: the session, the version and the totals; the cart's refusals, the invalid address and the calculation's codes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_answer_and_the_errors(): void {
		$definition = CheckoutOperations::updateSession();
		$output     = self::byName( $definition->output()->fields() );
		$session    = self::byName( $output['checkout_session']->fields() );
		$codes      = array_map( static fn( $code ): string => (string) $code->value, $definition->errors() );

		$this->assertSame( array( 'checkout_session', 'version', 'totals' ), array_keys( $output ) );
		$this->assertSame( array( 'billing_address', 'shipping_address', 'shipping_method_key', 'payment_method_key' ), array_keys( $session ) );
		$this->assertSame( array( true, true ), array( $session['billing_address']->isNullable(), $session['shipping_address']->isNullable() ) );
		$this->assertSame( Privacy::Pii, $session['shipping_address']->privacy() );

		foreach ( array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CheckoutError::InvalidAddress ) as $code ) {
			$this->assertContains( $code->value, $codes );
		}
	}

	/**
	 * Tests the placement's surface: a POST route of the Store API, its key read from the Idempotency-Key header, a wait answered with a Retry-After, and a limit of its own.
	 *
	 * Planted violation: in CheckoutOperations::placeOrder(), declare `idempotency_key` required:
	 * the declaration is refused, since a header the request leaves out is the service's refusal.
	 *
	 * @since 0.1.0
	 */
	public function test_the_placement_is_a_post_route_with_its_key_in_a_header(): void {
		$definition = CheckoutOperations::placeOrder();
		$rest       = $definition->rest();
		$write      = $definition->publicWrite();
		$ids        = array_map( static fn( $registered ): string => $registered->id(), Operations::registry()->all() );

		$this->assertContains( CheckoutOperations::PLACE_ORDER, $ids );
		$this->assertNotNull( $rest );
		$this->assertNotNull( $write );
		$this->assertSame( array( 'POST', '/checkout', RestBinding::STORE_NAMESPACE ), array( $definition->httpMethod(), $rest->route(), $rest->restNamespace() ) );
		$this->assertEquals( array( 'idempotency_key' => new RequestHeader( 'Idempotency-Key', true ) ), $rest->headers(), 'The key is read from its header, which a client must send.' );
		$this->assertSame( CheckoutOperations::RETRY_AFTER_SECONDS, $rest->retryAfter( CheckoutError::PlacementInProgress->value ) );
		$this->assertSame( array( null, null, null ), array( $definition->capability(), $definition->abilityName(), $definition->cli() ) );
		$this->assertSame( array( PlaceOrder::class, 'place' ), $definition->service() );
		$this->assertTrue( $write->requiresCart() );
		$this->assertSame( array( CheckoutOperations::PLACE_BUCKET, CheckoutOperations::PLACE_LIMIT, CheckoutOperations::PLACE_WINDOW ), array( $write->rateLimit()->bucket(), $write->rateLimit()->limit(), $write->rateLimit()->windowSeconds() ) );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			array_intersect_key( $definition->annotations()->toArray(), array_flip( array( 'readonly', 'destructive', 'idempotent' ) ) )
		);
	}

	/**
	 * Tests the placement's input and answer: the payment token is a secret, the order key is answered, and every outcome is declared.
	 *
	 * @since 0.1.0
	 */
	public function test_the_placement_takes_a_secret_token_and_answers_the_order_key(): void {
		$definition = CheckoutOperations::placeOrder();
		$input      = self::byName( $definition->input() );
		$output     = self::byName( $definition->output()->fields() );
		$codes      = array_map( static fn( $code ): string => (string) $code->value, $definition->errors() );

		$this->assertSame( array( 'cart_version', 'grand_total_minor', 'currency', 'payment_data', 'idempotency_key' ), array_keys( $input ) );
		$this->assertFalse( $input['idempotency_key']->isRequired() );
		$this->assertSame( Privacy::Secret, self::byName( $input['payment_data']->fields() )['payment_token']->privacy() );
		$this->assertSame( array( 'order_uuid', 'order_number', 'order_key', 'cart_version', 'outcome', 'status', 'payment_status' ), array_keys( $output ) );
		$this->assertSame( array_map( static fn( PlacementOutcome $outcome ): string => $outcome->value, PlacementOutcome::cases() ), $output['outcome']->allowedValues() );

		foreach ( array( CheckoutError::IdempotencyKeyMissing, CheckoutError::IdempotencyKeyReused, CheckoutError::PlacementInProgress, CheckoutError::CartEmpty, CheckoutError::SessionIncomplete, CheckoutError::TotalsChanged, CheckoutError::PaymentMethodUnavailable, CheckoutError::LineUnsellable, CheckoutError::PaymentDeclined, CheckoutError::GatewayUnavailable, CartError::NotOpen, CartError::VersionStale ) as $code ) {
			$this->assertContains( $code->value, $codes );
		}
	}

	/**
	 * Tests the currency switch's surface: a POST route of the Store API under the cart, counted with the cart's writes, for a cart that must exist.
	 *
	 * @since 0.1.0
	 */
	public function test_the_currency_switch_is_a_post_route_counted_with_the_carts_writes(): void {
		$definition = CheckoutOperations::changeCurrency();
		$rest       = $definition->rest();
		$write      = $definition->publicWrite();
		$ids        = array_map( static fn( $registered ): string => $registered->id(), Operations::registry()->all() );

		$this->assertContains( CheckoutOperations::CHANGE_CURRENCY, $ids );
		$this->assertNotNull( $rest );
		$this->assertNotNull( $write );
		$this->assertSame( array( 'POST', '/cart/currency', RestBinding::STORE_NAMESPACE ), array( $definition->httpMethod(), $rest->route(), $rest->restNamespace() ) );
		$this->assertSame( array( null, null, null ), array( $definition->capability(), $definition->abilityName(), $definition->cli() ) );
		$this->assertSame( array( ChangeCartCurrency::class, 'change' ), $definition->service() );
		$this->assertTrue( $write->requiresCart() );
		$this->assertSame( array( CartOperations::WRITE_BUCKET, CartOperations::WRITE_LIMIT, CartOperations::WRITE_WINDOW ), array( $write->rateLimit()->bucket(), $write->rateLimit()->limit(), $write->rateLimit()->windowSeconds() ) );
		$this->assertSame( array( 'cart.currency_change', 10, 3600 ), array( CurrencyChangeLimit::perCart()->bucket(), CurrencyChangeLimit::perCart()->limit(), CurrencyChangeLimit::perCart()->windowSeconds() ), 'Ten switches per cart and hour, besides the cart\'s writes.' );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			array_intersect_key( $definition->annotations()->toArray(), array_flip( array( 'readonly', 'destructive', 'idempotent' ) ) )
		);
	}

	/**
	 * Tests the currency switch's input and answer: the cart's version as every cart write declares it, a currency code with no list of currencies baked in, and the cart as its read declares it.
	 *
	 * Planted violation: in CheckoutOperations::changeCurrency(), declare the currency with
	 * `allowed: array( 'USD', 'EUR' )`: the enabled currencies are data, and the declaration would
	 * freeze them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_currency_switch_takes_a_code_and_answers_the_cart(): void {
		$definition = CheckoutOperations::changeCurrency();
		$input      = self::byName( $definition->input() );
		$codes      = array_map( static fn( $code ): string => (string) $code->value, $definition->errors() );

		$this->assertSame( array( 'cart_version', 'currency' ), array_keys( $input ) );
		$this->assertEquals( CartOperations::cartVersion( 1 ), $input['cart_version'] );
		$this->assertSame( array( FieldType::String, true, 3, array(), Privacy::Public ), array( $input['currency']->type(), $input['currency']->isRequired(), $input['currency']->maxLength(), $input['currency']->allowedValues(), $input['currency']->privacy() ) );
		$this->assertEquals( CartOperations::getCart()->output(), $definition->output(), 'The answer is the cart, as its read declares it.' );

		foreach ( array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CheckoutError::CurrencyNotEnabled, StoreApiError::RateLimited ) as $code ) {
			$this->assertContains( $code->value, $codes );
		}
	}

	/**
	 * Indexes fields by name.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return array<string, FieldSpec> The fields, by name, in order.
	 */
	private static function byName( array $fields ): array {
		$named = array();

		foreach ( $fields as $field ) {
			$named[ $field->name() ] = $field;
		}

		return $named;
	}
}
