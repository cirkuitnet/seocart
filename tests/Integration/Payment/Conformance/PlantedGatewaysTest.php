<?php
/**
 * Tests that the conformance suite fails each planted gateway with the rule it breaks
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Plants\SourcePlant;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Runs each rule of the suite against the planted gateway that breaks it, and passes only when the suite fails with that rule's message.
 *
 * Each plant is the reference gateway with one method changed. The reference gateway passes every
 * rule (ReferenceGatewayConformanceTest), so a plant the suite fails is proved to break its rule,
 * and the rule's assertion is proved to catch it. The fake provider answers every request: a
 * plant sends what a tape would not hold. Rules 4 and 15 have their plants in the unit suite.
 *
 * @since 0.2.0
 */
final class PlantedGatewaysTest extends ConformanceHarness {

	use ReferenceGatewaySetup;

	/**
	 * Returns one case per rule whose test runs in this suite: the rule's number.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: int}> The cases, by the plant's name.
	 */
	public function plants(): array {
		$cases = array();

		foreach ( Rule::cases() as $rule ) {
			foreach ( null === $rule->test() ? array() : $rule->plants() as $plant ) {
				if ( ! is_subclass_of( $plant, SourcePlant::class ) ) {
					$cases[ substr( (string) strrchr( $plant, '\\' ), 1 ) ] = array( $rule->value, $plant );
				}
			}
		}

		return $cases;
	}

	/**
	 * Tests that the suite fails a plant with the message of the rule it breaks.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider plants
	 * @group conformance
	 *
	 * @param int    $number The rule's number.
	 * @param string $plant  The plant's class.
	 */
	public function test_the_suite_fails_the_plant_of_each_rule( int $number, string $plant ): void {
		$rule = Rule::from( $number );

		try {
			$this->check( $rule );
		} catch ( SkippedTest $skipped ) {
			self::fail( sprintf( 'The suite skipped rule %1$d for its plant instead of failing it: %2$s', $number, $skipped->getMessage() ) );
		} catch ( AssertionFailedError $failed ) {
			$this->assertStringStartsWith( $rule->message(), $failed->getMessage(), sprintf( 'The suite failed the plant of rule %d, but not with that rule\'s message.', $number ) );
			$this->assertStringContainsString( (string) constant( $plant . '::FAILS_WITH' ), $failed->getMessage(), sprintf( 'The suite failed %1$s by rule %2$d, but not for the reason it plants.', $plant, $number ) );

			return;
		}

		self::fail( sprintf( 'The suite passed %1$s, a plant of rule %2$d.', $plant, $number ) );
	}

	/**
	 * Lets the fake provider answer every request: no tape is compared or written.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		return RecordingTransport::passingThrough( $tx, $this->http );
	}

	/**
	 * Builds the plant of the case's rule.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The plant.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$plant = (string) $this->getProvidedData()[1];

		return new $plant( $registry->context( ReferenceGateway::ID ) );
	}
}
