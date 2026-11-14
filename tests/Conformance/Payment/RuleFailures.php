<?php
/**
 * RuleFailures: an assertion of the suite's own tests that a rule fails a gateway for a reason
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;

/**
 * Asserts that the suite fails a rule against the gateway of the test, with the rule's message and a given reason, for SEOCart's own tests of what the suite refuses.
 *
 * @since 0.2.0
 */
trait RuleFailures {

	/**
	 * Asserts the suite fails a rule with its message and a reason; a skip or a pass fails the test.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule   $rule    The rule.
	 * @param string $because What the failure must say, after the rule's message.
	 */
	private function assertRuleFails( Rule $rule, string $because ): void {
		try {
			$this->check( $rule );
		} catch ( SkippedTest $skipped ) {
			self::fail( sprintf( 'The suite skipped rule %1$d instead of failing it: %2$s', $rule->value, $skipped->getMessage() ) );
		} catch ( AssertionFailedError $failed ) {
			$this->assertStringStartsWith( $rule->message(), $failed->getMessage() );
			$this->assertStringContainsString( $because, $failed->getMessage() );

			return;
		}

		self::fail( sprintf( 'The suite passed rule %1$d, which must fail: %2$s', $rule->value, $because ) );
	}
}
