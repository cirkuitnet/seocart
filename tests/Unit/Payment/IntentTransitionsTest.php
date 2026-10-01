<?php
/**
 * Tests the intent state machine's table: every state, its exits, and the reading of it the statements compile
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;

/**
 * The table names every state once, its final states have no exit, and allowedFrom() is its exact inverse.
 *
 * Planted violation, shown red and removed: in IntentTransitions::TABLE, let `failed` lead back
 * to `created`: a final state has an exit, and created can be entered.
 *
 * @since 0.1.0
 */
final class IntentTransitionsTest extends TestCase {

	/**
	 * Tests that the table has one row per state, in the enum's order, and names only states.
	 *
	 * @since 0.1.0
	 */
	public function test_the_table_names_every_state_once(): void {
		$states = array_map( static fn( IntentStatus $state ): string => $state->value, IntentStatus::cases() );

		$this->assertSame( $states, array_keys( IntentTransitions::TABLE ) );

		foreach ( IntentTransitions::TABLE as $from => $next ) {
			$this->assertSame( array(), array_diff( $next, $states ), "{$from} leads to a state that does not exist." );
			$this->assertSame( array_values( array_unique( $next ) ), $next, "{$from} names a state twice." );
		}
	}

	/**
	 * Tests that refunded, voided and failed are final, and that nothing returns an intent to created.
	 *
	 * @since 0.1.0
	 */
	public function test_the_final_states_have_no_exit_and_nothing_enters_created(): void {
		foreach ( array( IntentStatus::Refunded, IntentStatus::Voided, IntentStatus::Failed ) as $final ) {
			$this->assertSame( array(), IntentTransitions::TABLE[ $final->value ], "{$final->value} is final." );
		}

		$this->assertSame( array(), IntentTransitions::allowedFrom( IntentStatus::Created ) );
	}

	/**
	 * Tests that allowedFrom() and isAllowed() read the table exactly, for every pair.
	 *
	 * @since 0.1.0
	 */
	public function test_allowed_from_is_the_tables_inverse(): void {
		foreach ( IntentStatus::cases() as $to ) {
			$from = array();

			foreach ( IntentStatus::cases() as $candidate ) {
				$allowed = in_array( $to->value, IntentTransitions::TABLE[ $candidate->value ], true );

				$this->assertSame( $allowed, IntentTransitions::isAllowed( $candidate, $to ), "{$candidate->value} -> {$to->value}" );

				if ( $allowed ) {
					$from[] = $candidate;
				}
			}

			$this->assertSame( $from, IntentTransitions::allowedFrom( $to ), "Entering {$to->value}." );
		}
	}

	/**
	 * Tests that the states reconciliation waits on are exactly those an authorization may come from.
	 *
	 * @since 0.1.0
	 */
	public function test_the_states_awaiting_a_result_are_those_an_authorization_comes_from(): void {
		$this->assertSame( IntentTransitions::allowedFrom( IntentStatus::Authorized ), IntentStatus::awaitingResult() );
	}
}
