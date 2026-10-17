<?php
/**
 * Tests gateways' declarations against the conformance rules that need no WordPress
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Conformance\Payment\DeclarationRules;
use SEOCart\Tests\Conformance\Payment\InertContext;
use SEOCart\Tests\Conformance\Payment\NetworkCalls;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Holds the stand-in, the reference gateway and, when SEOCART_EXTENSION_PATH names an extension, every gateway it registers to rules 4 and 15.
 *
 * Rule 4: no property or parameter of the contract's classes or the gateway's own is named like
 * card data. Rule 15: describe() calls no WordPress function. An extension's gateways are read
 * the way WordPress would load them, its main file included once and its registration listener run,
 * outside WordPress; the extension needs no test of its own for these two rules.
 *
 * @since 0.2.0
 */
final class DeclarationConformanceTest extends TestCase {

	/**
	 * Sets Brain Monkey up.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests rule 4 on every gateway.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_04_no_card_fields(): void {
		foreach ( $this->gateways() as $name => list( $gateway, $roots ) ) {
			$this->assertNull( DeclarationRules::cardFieldsFailure( $gateway, $roots ), sprintf( 'The gateway %s:', $name ) );
		}
	}

	/**
	 * Tests rule 12's reading of the source on every gateway: no call reaches the network past the plugin's client.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_12_the_source_calls_no_network_api(): void {
		foreach ( $this->gateways() as $name => list( $gateway, $roots ) ) {
			$this->assertNull( NetworkCalls::failure( $gateway, $roots ), sprintf( 'The gateway %s:', $name ) );
		}
	}

	/**
	 * Tests rule 15 on every gateway.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_15_describe_is_data(): void {
		foreach ( $this->gateways() as $name => list( $gateway ) ) {
			$this->assertNull( DeclarationRules::describeFailure( $gateway ), sprintf( 'The gateway %s:', $name ) );
		}
	}

	/**
	 * Returns the gateways held to the rules, with their source directories.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: PaymentGateway, 1: list<string>}> Each gateway and its source roots, by name.
	 */
	private function gateways(): array {
		$gateways = array(
			'stand-in'  => array( new StubGateway(), array() ),
			'reference' => array( new ReferenceGateway( new InertContext( ReferenceGateway::ID ) ), array() ),
		);

		$root = rtrim( (string) getenv( 'SEOCART_EXTENSION_PATH' ), '/' );

		if ( '' === $root ) {
			return $gateways;
		}

		$registered = DeclarationRules::extensionGateways( $root );
		$this->assertNotSame( array(), $registered, sprintf( 'The extension at %s registers no gateway when its listener of the registration action runs.', $root ) );

		foreach ( $registered as $gateway ) {
			$gateways[ basename( $root ) . ': ' . get_class( $gateway ) ] = array( $gateway, array( $root . '/src' ) );
		}

		return $gateways;
	}
}
