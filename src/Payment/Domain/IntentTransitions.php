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

use SEOCart\Contracts\Payment\Operation;

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
 * Beside the table, two lists say what a gateway's late answer does: DECLINABLE, the states a
 * decline of each operation fails an intent from, and STALE_APPROVALS, the approvals older than
 * the state they meet. The payment service decides every answer from them under the intent's
 * lock, and the test matrix of answers is generated from them too.
 *
 * @since 0.1.0
 * @since 0.2.0 Says which declines and approvals are stale.
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
	 * Each operation a gateway may decline, and the states its decline fails the intent from.
	 *
	 * A decline from any other state is about an attempt the intent has left behind, such as an
	 * earlier attempt's decline delivered after a later one was authorized: it is stale, and changes
	 * nothing. A declined void, the provider refusing to cancel, fails the intent from no state. A
	 * refund is absent: a declined refund is recorded, and changes no state.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	public const DECLINABLE = array(
		'authorize' => array( 'created', 'requires_action', 'processing' ),
		'capture'   => array( 'authorized', 'processing' ),
		'void'      => array(),
	);

	/**
	 * Each operation whose approval may come after the intent has left it behind, and the states such an approval is stale in.
	 *
	 * An authorization for an intent the provider was asked to cancel, and did, is older than the
	 * cancellation, which the ledger holds. A void for an intent already voided, or failed, has
	 * nothing left to release. Any other approval the intent's state cannot take moved money the
	 * ledger did not expect, and is kept for a person.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	public const STALE_APPROVALS = array(
		'authorize' => array( 'voided' ),
		'void'      => array( 'voided', 'failed' ),
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
	 * Returns the states a decline of an operation fails the intent from.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation $operation The operation declined.
	 * @return list<IntentStatus>|null The states, in DECLINABLE's order; null for a refund, whose decline changes no state.
	 */
	public static function declinableFrom( Operation $operation ): ?array {
		$states = self::DECLINABLE[ $operation->value ] ?? null;

		return null === $states ? null : array_map( static fn( string $state ): IntentStatus => IntentStatus::from( $state ), $states );
	}

	/**
	 * Tells whether a decline of an operation is stale for an intent in a state: about an attempt the intent has left behind.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation    $operation The operation declined.
	 * @param IntentStatus $state     The intent's state, as locked.
	 * @return bool True when the decline may not fail the intent; false for one that may, and for a refund's.
	 */
	public static function isStaleDecline( Operation $operation, IntentStatus $state ): bool {
		$from = self::declinableFrom( $operation );

		return null !== $from && ! in_array( $state, $from, true );
	}

	/**
	 * Tells whether an approval of an operation is stale for an intent in a state: older than what the intent has become.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation    $operation The operation approved.
	 * @param IntentStatus $state     The intent's state, as locked.
	 * @return bool True for an authorization of a voided intent, and a void of a voided or failed one.
	 */
	public static function isStaleApproval( Operation $operation, IntentStatus $state ): bool {
		return in_array( $state->value, self::STALE_APPROVALS[ $operation->value ] ?? array(), true );
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
