<?php
/**
 * Tests that nothing the payment contract carries can hold card data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every constructor parameter of every class of the payment contract is on an allow-list that holds no card field.
 *
 * A gateway receives tokens and references, never a card number, a security code or an expiry:
 * the provider's own script in the browser turns the card into a token. The classes are found,
 * not listed, so a class added to the contract's namespace is held to the list without being
 * named here.
 *
 * Planted violations, each shown red and removed: add `public ?string $cardNumber = null` to
 * VoidRequest's constructor, or `public ?string $accountNumber = null`: the scan names each.
 *
 * @since 0.1.0
 * @since 0.2.0 Scans the public payment contract.
 */
final class GatewayDtoTest extends TestCase {

	/**
	 * The parameters a contract class may take: identities, amounts, outcomes, tokens, references and declarations.
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
		'mode',
		'orderUuid',
		'orderNumber',
		'returnUrl',
		'reason',
		// Queries: when the intent's wait, for the customer or the gateway, runs out.
		'waitEndsAt',
		'waitEnded',
		// Descriptors, matrices and profiles.
		'id',
		'label',
		'type',
		'contract',
		'modes',
		'settings',
		'matrix',
		'idempotency',
		'hosts',
		'rows',
		'currency',
		'accountCountry',
		'operations',
		'keyRetentionSeconds',
		'searchable',
		'searchDelaySeconds',
		// Availability: the account is the gateway's non-secret settings.
		'billingCountry',
		'channel',
		'account',
		// Webhooks.
		'gatewayId',
		'headers',
		'rawBody',
		'receivedAt',
		'kind',
		'eventId',
		'eventType',
		'occurredAt',
		'result',
		// Webhook endpoints a gateway sets up: where, which events, the owner tag, and what was done.
		'url',
		'installUuid',
		'events',
		'secretHeld',
		'endpointId',
		'signingSecret',
		'removed',
		'elsewhere',
		// Exceptions.
		'message',
		'code',
		'previous',
		'setting',
	);

	/**
	 * Fragments of a parameter name, lower-cased and without underscores, that would hold card or bank account data.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 `account` alone became `accountn`: an account's country is no card field, its number is.
	 *
	 * @var list<string>
	 */
	private const CARD_FIELDS = array( 'pan', 'card', 'cvv', 'cvc', 'csc', 'expiry', 'exp', 'accountn', 'iban', 'routing', 'track' );

	/**
	 * Tests that every constructor parameter of every contract class is on the allow-list.
	 *
	 * @since 0.1.0
	 */
	public function test_no_contract_class_takes_a_card_field(): void {
		$classes = array();
		$unknown = array();

		foreach ( PhpSource::files( 'src/Contracts/Payment' ) as $source ) {
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

		$this->assertContains( PaymentRequest::class, $classes, 'The scan must find the contract\'s classes, or it proves nothing.' );
		$this->assertContains( GatewayResult::class, $classes );
		$this->assertContains( GatewayDescriptor::class, $classes );
		$this->assertContains( WebhookReading::class, $classes );
		$this->assertSame( array(), $unknown, 'A contract class takes only tokens, references and declarations; card data never reaches the plugin.' );
	}

	/**
	 * Tests that the allow-list itself names no card field.
	 *
	 * @since 0.1.0
	 */
	public function test_the_allow_list_names_no_card_field(): void {
		foreach ( self::ALLOWED as $name ) {
			$this->assertFalse( self::readsAsCardField( $name ), "{$name} reads as a card field." );
		}
	}

	/**
	 * Tests that the card fragments catch the names a card or bank account number goes by, however written.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider cardFieldNames
	 *
	 * @param string $name A name that holds card or bank account data.
	 */
	public function test_a_card_shaped_name_reads_as_a_card_field( string $name ): void {
		$this->assertTrue( self::readsAsCardField( $name ), "{$name} must read as a card field." );
	}

	/**
	 * Names that hold card or bank account data, in the spellings a declaration might use.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string}> The names.
	 */
	public static function cardFieldNames(): array {
		$names = array( 'accountNumber', 'account_number', 'account_no', 'accountNo', 'accountNum', 'cardNumber', 'pan', 'cvv', 'cvc', 'csc', 'expMonth', 'exp_year', 'expiry', 'iban', 'routingNumber', 'track2' );

		return array_combine( $names, array_map( static fn( string $name ): array => array( $name ), $names ) );
	}

	/**
	 * Tells whether a name reads as a card field: it holds one of the fragments, once lower-cased and without underscores.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The name.
	 * @return bool True when it does.
	 */
	private static function readsAsCardField( string $name ): bool {
		$normalized = strtolower( str_replace( '_', '', $name ) );

		foreach ( self::CARD_FIELDS as $fragment ) {
			if ( str_contains( $normalized, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
