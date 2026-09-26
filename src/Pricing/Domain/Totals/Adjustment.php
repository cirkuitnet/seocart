<?php
/**
 * Adjustment: a signed change to the totals, as the calculation made it, before tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Taxability;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a value built by code, for the developer; it is never rendered.

/**
 * One adjustment: its place, scope and type, what caused it, and its authored amount with its taxability.
 *
 * Owns one fact: what an adjustment is before tax is worked out on it. Every adjustment names
 * its source, and a line-scoped one names its line. The amount is signed: a discount is
 * negative. The position is the order adjustments were made in, from 1, which is also the
 * order an order renders them in.
 *
 * @since 0.1.0
 */
final readonly class Adjustment {

	/**
	 * Checks and holds the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a line-scoped adjustment names no line, or another names one.
	 *
	 * @param int                 $position        The order the adjustment was made in, from 1.
	 * @param AdjustmentScope     $scope           What it changes.
	 * @param AdjustmentType      $type            What kind of change it is.
	 * @param Source              $source          What caused it.
	 * @param string              $labelKey        The key the storefront turns into its label.
	 * @param string|null         $lineKey         The line it changes, for a line-scoped adjustment; otherwise null.
	 * @param AuthoredAmount      $authoredAmount  The signed amount, with its basis.
	 * @param AdjustmentBase|null $calculationBase What a fee was calculated on; null for anything else.
	 * @param Taxability          $taxability      Whether it is taxed, and by which class.
	 */
	public function __construct(
		public int $position,
		public AdjustmentScope $scope,
		public AdjustmentType $type,
		public Source $source,
		public string $labelKey,
		public ?string $lineKey,
		public AuthoredAmount $authoredAmount,
		public ?AdjustmentBase $calculationBase,
		public Taxability $taxability
	) {
		if ( ( AdjustmentScope::Line === $scope ) !== ( null !== $lineKey ) ) {
			throw new \InvalidArgumentException( sprintf( 'Only a line-scoped adjustment names a line; adjustment %d is %s-scoped.', $position, $scope->value ) );
		}
	}

	/**
	 * Returns the adjustment as scalars, for the trace and the totals' array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|null> The adjustment.
	 */
	public function toArray(): array {
		return array(
			'position'         => $this->position,
			'scope'            => $this->scope->value,
			'type'             => $this->type->value,
			'source'           => $this->source->toString(),
			'label_key'        => $this->labelKey,
			'line_key'         => $this->lineKey,
			'amount_minor'     => $this->authoredAmount->amount->minorUnits(),
			'amount_basis'     => $this->authoredAmount->basis->value,
			'calculation_base' => $this->calculationBase?->value,
			'tax_class'        => $this->taxability->taxClass,
		);
	}
}
