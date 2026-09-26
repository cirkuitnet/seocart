<?php
/**
 * RejectedCode: a promotion code that does not apply, and why
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A code the customer entered that did not become a promotion of the calculation.
 *
 * Owns one fact: what the trace records of a code that was turned away. The calculation
 * ignores it otherwise; the reason is for the store's own records, not for the customer.
 *
 * @since 0.1.0
 */
final readonly class RejectedCode {

	/**
	 * A code no promotion has.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Holds the code and the reason.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code   The code as entered.
	 * @param string $reason Why it does not apply, such as UNKNOWN.
	 */
	public function __construct(
		public string $code,
		public string $reason
	) {
	}

	/**
	 * Returns the rejection as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array{code: string, reason: string} The rejection.
	 */
	public function toArray(): array {
		return array(
			'code'   => $this->code,
			'reason' => $this->reason,
		);
	}
}
