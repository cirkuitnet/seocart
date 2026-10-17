<?php
/**
 * Tests that the conformance rules and the case's test methods match
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Every rule a test proves has its method in GatewayConformanceCase, every test method there proves a rule or the page, and every plant names its detail.
 *
 * The case is read as source: it extends WordPress's test case, which the unit suite does not load.
 *
 * @since 0.2.0
 */
final class RuleTestsTest extends TestCase {

	/**
	 * The test of the case that proves no rule: the extension's capabilities page is current.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PAGE_TEST = 'test_the_capability_page_is_current';

	/**
	 * Tests that the rules' test methods and the case's are the same set.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_every_rule_has_its_test_and_every_test_a_rule(): void {
		preg_match_all( '/public function (test_[a-z0-9_]+)\(/', (string) file_get_contents( dirname( __DIR__, 3 ) . '/Conformance/Payment/GatewayConformanceCase.php' ), $declared );

		$wanted = array( self::PAGE_TEST );

		foreach ( Rule::cases() as $rule ) {
			if ( null !== $rule->test() ) {
				$wanted[] = $rule->test();
			}
		}

		sort( $wanted, SORT_STRING );
		$found = $declared[1];
		sort( $found, SORT_STRING );

		$this->assertSame( $wanted, $found, 'GatewayConformanceCase has a test method for each rule that a test proves, and no other but the page\'s.' );
	}

	/**
	 * Tests that every plant declares the detail its failure must show.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_every_plant_names_the_detail_its_failure_shows(): void {
		foreach ( Rule::cases() as $rule ) {
			foreach ( $rule->plants() as $plant ) {
				$this->assertTrue( defined( $plant . '::FAILS_WITH' ) && '' !== constant( $plant . '::FAILS_WITH' ), sprintf( '%s names no FAILS_WITH.', $plant ) );
			}
		}
	}

	/**
	 * Tests that the plants the rules list and the plants in the plants' directory are the same set, each listed once.
	 *
	 * A plant left out of every rule would never run, so the rule it proves would go unproved.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_every_plant_is_a_rules_and_every_rules_plant_is_there(): void {
		$listed = array();

		foreach ( Rule::cases() as $rule ) {
			$listed = array_merge( $listed, $rule->plants() );
		}

		$found = array();

		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/Conformance/Payment/Plants/*.php' ) as $file ) {
			$plant = 'SEOCart\\Tests\\Conformance\\Payment\\Plants\\' . basename( (string) $file, '.php' );

			if ( class_exists( $plant ) ) {
				$found[] = $plant;
			}
		}

		$this->assertSame( count( $listed ), count( array_unique( $listed ) ), 'A plant is listed by two rules, or twice by one.' );

		sort( $listed, SORT_STRING );
		sort( $found, SORT_STRING );

		$this->assertSame( $found, $listed, 'Every plant in tests/Conformance/Payment/Plants is listed by the rule it breaks (Rule::plants()), and every plant listed is there.' );
	}
}
