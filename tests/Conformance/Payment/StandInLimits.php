<?php
/**
 * StandInLimits: what the conformance suite knowingly skips for the stand-in gateway
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The checks the stand-in gateway cannot pass by design, each with the reason the suite states when it skips one.
 *
 * Owns one fact, read by the stand-in's conformance test, which prints it as the skip's reason,
 * and by the reference page, which lists it under the stand-in: the stand-in is stateless and
 * answers from the references it issues, so a check that needs a memory of what it did is not
 * true of it. Each limit is accepted only because the stand-in moves no money; every real adapter
 * is held to the check, and the reference gateway passes it.
 *
 * @since 0.2.0
 */
final class StandInLimits {

	/**
	 * Why rule 8's refund half is skipped for the stand-in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUNDS = 'Rule 8\'s refund half is skipped for the stand-in: it keeps no record of its refunds and answers every refund it is asked about as made, a known lie accepted only because it moves no money.';
}
