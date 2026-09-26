<?php
/**
 * CustomerTaxFacts: whether the customer is exempt from tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What the calculation knows of the customer's tax position.
 *
 * Owns one fact: the customer facts the tax step reads. Only `exempt` changes a figure; the
 * reference to the exemption's evidence is carried into the trace so an order can show why no
 * tax was charged.
 *
 * @since 0.1.0
 */
final readonly class CustomerTaxFacts {

	/**
	 * Holds the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param bool        $exempt             Whether the customer pays no tax.
	 * @param string|null $exemptionReference The reference of the exemption's evidence, or null.
	 */
	public function __construct(
		public bool $exempt,
		public ?string $exemptionReference = null
	) {
	}

	/**
	 * Returns the facts of a customer who pays tax.
	 *
	 * @since 0.1.0
	 *
	 * @return self The facts.
	 */
	public static function notExempt(): self {
		return new self( false );
	}
}
