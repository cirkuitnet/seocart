<?php
/**
 * Tests that the declaration rules fail their planted gateways
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\DeclarationRules;
use SEOCart\Tests\Conformance\Payment\InertContext;
use SEOCart\Tests\Conformance\Payment\NetworkCalls;
use SEOCart\Tests\Conformance\Payment\Plants\CardNumberProperty;
use SEOCart\Tests\Conformance\Payment\Plants\CurlCall;
use SEOCart\Tests\Conformance\Payment\Plants\ReadsAnOption;
use SEOCart\Tests\Conformance\Payment\Plants\SourcePlant;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Rules 4 and 15, and rule 12's reading of the source, each fail the planted gateways that break them, with the rule's message.
 *
 * @since 0.2.0
 */
final class DeclarationPlantsTest extends TestCase {

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
	 * Tests that rule 4 fails a gateway with a property named like a card number.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_04_fails_a_card_number_property(): void {
		$failure = (string) DeclarationRules::cardFieldsFailure( new CardNumberProperty( new InertContext( ReferenceGateway::ID ) ), array() );

		$this->assertStringStartsWith( Rule::NoCardFields->message(), $failure );
		$this->assertStringContainsString( CardNumberProperty::class . '::$card_number', $failure );
	}

	/**
	 * Tests that rule 12's reading of the source fails each source plant of the rule, naming the plant's file, the line and the call it plants.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_12_fails_each_network_call_in_the_source(): void {
		$plants = array_values( array_filter( Rule::EveryCallThroughTheClient->plants(), static fn( string $plant ): bool => is_subclass_of( $plant, SourcePlant::class ) ) );

		$this->assertContains( CurlCall::class, $plants );

		foreach ( $plants as $plant ) {
			$gateway = new $plant( new InertContext( ReferenceGateway::ID ) );
			$name    = substr( (string) strrchr( $plant, '\\' ), 1 );

			if ( ! $gateway instanceof ReferenceGateway ) {
				self::fail( sprintf( '%s is not a planted reference gateway.', $name ) );
			}

			$failure = (string) NetworkCalls::failure( $gateway, array() );

			$this->assertStringStartsWith( Rule::EveryCallThroughTheClient->message(), $failure, $name );
			$this->assertMatchesRegularExpression( '#Plants/' . $name . '\.php line \d+: ' . preg_quote( (string) constant( $plant . '::FAILS_WITH' ), '#' ) . '#', $failure, $name );
		}

		$this->assertNull( NetworkCalls::failure( new ReferenceGateway( new InertContext( ReferenceGateway::ID ) ), array() ), 'The reference gateway, the plants\' parent, makes no such call.' );
	}

	/**
	 * Tests that rule 15 fails a gateway whose describe() reads an option.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_15_fails_a_describe_that_reads_an_option(): void {
		$failure = (string) DeclarationRules::describeFailure( new ReadsAnOption( new InertContext( ReferenceGateway::ID ) ) );

		$this->assertStringStartsWith( Rule::DescribeIsData->message(), $failure );
		$this->assertStringContainsString( 'get_option()', $failure );
	}

	/**
	 * Tests that rule 15 fails a describe() that translates, and one that adds a hook.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_15_fails_a_translation_and_a_hook(): void {
		$translates = new class( new InertContext( ReferenceGateway::ID ) ) extends ReferenceGateway {
			/**
			 * Translates while describing.
			 *
			 * @since 0.2.0
			 *
			 * @return \SEOCart\Contracts\Payment\GatewayDescriptor The descriptor.
			 */
			public function describe(): \SEOCart\Contracts\Payment\GatewayDescriptor {
				$label = __( 'Reference gateway', 'seocart' );
				unset( $label );

				return parent::describe();
			}
		};
		$hooks      = new class( new InertContext( ReferenceGateway::ID ) ) extends ReferenceGateway {
			/**
			 * Adds a hook while describing.
			 *
			 * @since 0.2.0
			 *
			 * @return \SEOCart\Contracts\Payment\GatewayDescriptor The descriptor.
			 */
			public function describe(): \SEOCart\Contracts\Payment\GatewayDescriptor {
				add_action( 'init', static function (): void {} );

				return parent::describe();
			}
		};

		$this->assertStringContainsString( '__()', (string) DeclarationRules::describeFailure( $translates ) );
		$this->assertStringContainsString( 'hook', (string) DeclarationRules::describeFailure( $hooks ) );
	}
}
