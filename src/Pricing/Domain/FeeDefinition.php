<?php
/**
 * FeeDefinition: a fee the order is charged, on a declared base
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * A fee: a fixed amount or a percentage of its declared base, taxed or not.
 *
 * Owns one fact: what a fee declares. A percentage fee is a share of its base and is rounded
 * once; a fixed fee is charged as authored and its base is recorded only. Both declare their
 * taxability, so a fee is never taxed by accident or left untaxed by one. A fee takes nothing
 * off: its amount or percentage is never negative.
 *
 * @since 0.1.0
 */
final readonly class FeeDefinition {

	/**
	 * The source of the fee's adjustment: `fee:<key>`.
	 *
	 * @since 0.1.0
	 *
	 * @var Source
	 */
	public Source $source;

	/**
	 * Checks and holds the fee.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the key cannot name a source, or the amount or the percentage is negative.
	 *
	 * @param string                    $key        The fee's key; its adjustment's source is `fee:<key>`.
	 * @param AuthoredAmount|Percentage $amount     A fixed amount, or a percentage of the base.
	 * @param AdjustmentBase            $base       What the fee is calculated on.
	 * @param Taxability                $taxability Whether the fee is taxed, and by which class.
	 */
	public function __construct(
		public string $key,
		public AuthoredAmount|Percentage $amount,
		public AdjustmentBase $base,
		public Taxability $taxability
	) {
		$this->source = Source::fee( $key );

		$negative = $amount instanceof Percentage ? $amount->micropercent() < 0 : $amount->amount->isNegative();

		if ( $negative ) {
			throw new \InvalidArgumentException( 'A fee is never negative.' );
		}
	}

	/**
	 * Returns the fee as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|null> The fee.
	 */
	public function toArray(): array {
		$percentage = $this->amount instanceof Percentage ? $this->amount : null;
		$fixed      = $this->amount instanceof AuthoredAmount ? $this->amount : null;

		return array(
			'key'                  => $this->key,
			'percent_micropercent' => $percentage?->micropercent(),
			'amount_minor'         => $fixed?->amount->minorUnits(),
			'amount_basis'         => $fixed?->basis->value,
			'base'                 => $this->base->value,
			'tax_class'            => $this->taxability->taxClass,
		);
	}
}
