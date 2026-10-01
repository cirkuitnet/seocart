<?php
/**
 * Outcome: what a gateway answered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The answer a gateway result carries.
 *
 * Owns one fact: which answers are money facts. An approval or a decline is a fact about money
 * and is written to the ledger; a request for the customer to act, or a gateway still deciding,
 * moves no money and only changes the intent's state.
 *
 * @since 0.1.0
 */
enum Outcome: string {

	/**
	 * The gateway did what it was asked.
	 *
	 * @since 0.1.0
	 */
	case Approved = 'approved';

	/**
	 * The gateway refused.
	 *
	 * @since 0.1.0
	 */
	case Declined = 'declined';

	/**
	 * The customer must act before the gateway decides.
	 *
	 * @since 0.1.0
	 */
	case RequiresAction = 'requires_action';

	/**
	 * The gateway has not decided yet.
	 *
	 * @since 0.1.0
	 */
	case Pending = 'pending';

	/**
	 * Returns the answers that are facts about money, which the ledger records.
	 *
	 * @since 0.1.0
	 *
	 * @return list<self> An approval and a decline.
	 */
	public static function moneyFacts(): array {
		return array( self::Approved, self::Declined );
	}
}
