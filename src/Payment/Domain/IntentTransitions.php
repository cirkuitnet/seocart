<?php
/**
 * IntentTransitions: which intent state may follow which
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The payment intent state machine, as one table.
 *
 * Owns one fact: the transitions of a payment intent. The table is fixed, not extensible: an
 * intent's states are the contract every gateway answers in. The repository compiles the WHERE
 * clause of each statement that changes an intent's state from allowedFrom(), so the database
 * refuses what the table does not allow, and the test matrix is generated from the same table.
 *
 * `refunded`, `voided` and `failed` are final. `partially_refunded` may follow itself: each
 * partial refund is one more transition into it.
 *
 * @since 0.1.0
 */
final class IntentTransitions {

	/**
	 * Each state, and the states that may follow it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<string>>
	 */
	public const TABLE = array(
		'created'            => array( 'authorized', 'requires_action', 'processing', 'failed', 'voided' ),
		'requires_action'    => array( 'authorized', 'processing', 'failed', 'voided' ),
		'processing'         => array( 'authorized', 'captured', 'failed', 'voided' ),
		'authorized'         => array( 'captured', 'voided', 'failed' ),
		'captured'           => array( 'partially_refunded', 'refunded' ),
		'partially_refunded' => array( 'partially_refunded', 'refunded' ),
		'refunded'           => array(),
		'voided'             => array(),
		'failed'             => array(),
	);

	/**
	 * Returns the states an intent may enter a state from.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $to The state entered.
	 * @return list<IntentStatus> The states it may be entered from, in the table's order; empty when none.
	 */
	public static function allowedFrom( IntentStatus $to ): array {
		$from = array();

		foreach ( self::TABLE as $state => $next ) {
			if ( in_array( $to->value, $next, true ) ) {
				$from[] = IntentStatus::from( $state );
			}
		}

		return $from;
	}

	/**
	 * Tells whether an intent may move from one state to another.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $from The state it is in.
	 * @param IntentStatus $to   The state wanted.
	 * @return bool True when the table allows it.
	 */
	public static function isAllowed( IntentStatus $from, IntentStatus $to ): bool {
		return in_array( $to->value, self::TABLE[ $from->value ], true );
	}

	/**
	 * Tells whether a state is final: no state follows it.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $state The state.
	 * @return bool True for a state the table lets an intent leave for none: refunded, voided and failed.
	 */
	public static function isFinal( IntentStatus $state ): bool {
		return array() === self::TABLE[ $state->value ];
	}

	/**
	 * Returns the values of states, for a statement's IN list.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus[] $states The states.
	 * @return list<string> Their values.
	 *
	 * @phpstan-param list<IntentStatus> $states
	 */
	public static function values( array $states ): array {
		return array_map( static fn( IntentStatus $state ): string => $state->value, $states );
	}
}
