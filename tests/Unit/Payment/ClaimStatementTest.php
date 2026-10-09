<?php
/**
 * Tests what a person's statement about a claimed refund must say to settle the claim
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentOperations;

/**
 * A statement that a refund was made names the provider's refund as the provider names it: printable ASCII with no space, which the ledger keeps as it is and finds the refund by.
 *
 * A blank provider refund would reach the ledger as no provider object at all, and one ending in a
 * no-break space as another object, the ledger's ASCII column replacing the character: either way
 * the refund the provider made could never be found by its key again.
 *
 * Its note is at most PaymentOperations::NOTE_MAX_LENGTH characters, counted as the operation's
 * schema counts them, whoever makes the statement.
 *
 * Planted violations, each shown red and removed: in ClaimStatement::problem(), take any provider
 * refund that is not empty: the single space, the no-break space, the tab, the newline and the
 * letter outside ASCII are then accepted; drop the note's length check: the note of 501
 * characters is accepted.
 *
 * @since 0.2.0
 */
final class ClaimStatementTest extends TestCase {

	/**
	 * Why the person says so, in every statement of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The provider\'s dashboard shows the refund.';

	/**
	 * Tests that a statement that the refund was made settles a claim only with a provider refund of printable ASCII and no space.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider providerRefunds
	 *
	 * @param string      $providerRefundId The provider refund the statement names.
	 * @param string|null $problem          What keeps the statement from settling a claim, or null for nothing.
	 */
	public function test_a_provider_refund_is_printable_ascii_with_no_space( string $providerRefundId, ?string $problem ): void {
		$this->assertSame( $problem, ( new ClaimStatement( true, self::NOTE, $providerRefundId, 1234 ) )->problem() );
	}

	/**
	 * Tests that a note of 501 characters keeps a statement from settling a claim, and that one of 500, of two bytes each, does not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_note_is_at_most_500_characters(): void {
		$this->assertSame( 500, PaymentOperations::NOTE_MAX_LENGTH );
		$this->assertSame( ClaimStatement::NOTE_TOO_LONG, ( new ClaimStatement( false, str_repeat( 'x', 501 ) ) )->problem() );
		$this->assertSame( ClaimStatement::NOTE_TOO_LONG, ( new ClaimStatement( true, str_repeat( 'x', 501 ), 're_3Q2ExampleRefund', 1234 ) )->problem() );
		$this->assertNull( ( new ClaimStatement( false, str_repeat( "\u{00E9}", 500 ) ) )->problem(), 'The note is counted in characters, not bytes.' );
	}

	/**
	 * Returns provider refunds, each with what keeps a statement naming it from settling a claim.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string|null}> The provider refund, and the problem or null.
	 */
	public static function providerRefunds(): array {
		return array(
			'a provider refund'             => array( 're_3Q2ExampleRefund', null ),
			'the first and last printable'  => array( '!re_3Q2Example-01.x~', null ),
			'empty'                         => array( '', ClaimStatement::INCOMPLETE ),
			'a single space'                => array( ' ', ClaimStatement::INCOMPLETE ),
			'a trailing no-break space'     => array( "re_3Q2Example\u{00A0}", ClaimStatement::INCOMPLETE ),
			'a space inside'                => array( 're_3Q2 Example', ClaimStatement::INCOMPLETE ),
			'a trailing tab'                => array( "re_3Q2Example\t", ClaimStatement::INCOMPLETE ),
			'a trailing newline'            => array( "re_3Q2Example\n", ClaimStatement::INCOMPLETE ),
			'a letter outside ASCII'        => array( "re_3Q2Ex\u{00E4}mple", ClaimStatement::INCOMPLETE ),
			'printable, with a card number' => array( 're_4111111111111111', ClaimStatement::CARD_NUMBER ),
		);
	}
}
