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
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
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

		$this->assertSame( CheckoutOperations::PAYMENT_METHODS, $fields['payment_method_key']->allowedValues() );
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
