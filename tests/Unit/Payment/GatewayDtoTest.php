<?php
/**
 * Tests that nothing a gateway is given or gives back can hold card data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every constructor parameter of every class in the gateway's namespace is on an allow-list that holds no card field.
 *
 * A gateway receives tokens and references, never a card number, a security code or an expiry:
 * the provider's own script in the browser turns the card into a token. The classes are found,
 * not listed, so a class added to the namespace is held to the list without being named here.
 *
 * Planted violation, shown red and removed: add `public ?string $pan = null` to PaymentRequest's
 * constructor: the scan names it.
 *
 * @since 0.1.0
 */
final class GatewayDtoTest extends TestCase {

	/**
	 * The parameters a gateway class may take: identities, amounts, outcomes, tokens and references.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ALLOWED = array(
		// Results.
		'provider',
		'operation',
		'outcome',
		'intentUuid',
		'amount',
		'providerObjectId',
		'providerIntentId',
		'errorCode',
		'settlement',
		// Settlements.
		'rate',
		'fee',
		'source',
		// Requests.
		'paymentToken',
		'refundUuid',
		// Exceptions.
		'message',
		'code',
		'previous',
	);

	/**
	 * Fragments of a parameter name that would hold card data.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CARD_FIELDS = array( 'pan', 'card', 'cvv', 'cvc', 'expiry', 'exp', 'account', 'iban', 'track' );

	/**
	 * Tests that every constructor parameter of every gateway class is on the allow-list.
	 *
	 * @since 0.1.0
	 */
	public function test_no_gateway_class_takes_a_card_field(): void {
		$classes = array();
		$unknown = array();

		foreach ( PhpSource::files( 'src/Payment/Domain/Gateway' ) as $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				$classes[]   = $class;
				$constructor = ( new \ReflectionClass( $class ) )->getConstructor();

				foreach ( null === $constructor ? array() : $constructor->getParameters() as $parameter ) {
					if ( ! in_array( $parameter->getName(), self::ALLOWED, true ) ) {
						$unknown[] = $class . '::$' . $parameter->getName();
					}
				}
			}
		}

		$this->assertContains( PaymentRequest::class, $classes, 'The scan must find the gateway classes, or it proves nothing.' );
		$this->assertContains( GatewayResult::class, $classes );
		$this->assertSame( array(), $unknown, 'A gateway class takes only tokens and references; card data never reaches the plugin.' );
	}

	/**
	 * Tests that the allow-list itself names no card field.
	 *
	 * @since 0.1.0
	 */
	public function test_the_allow_list_names_no_card_field(): void {
		foreach ( self::ALLOWED as $name ) {
			foreach ( self::CARD_FIELDS as $field ) {
				$this->assertStringNotContainsStringIgnoringCase( $field, $name, "{$name} reads as a card field." );
			}
		}
	}
}
