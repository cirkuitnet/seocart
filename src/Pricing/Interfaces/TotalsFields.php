<?php
/**
 * TotalsFields: the declared shape of a calculation's totals on the wire
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Interfaces;

use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;

defined( 'ABSPATH' ) || exit;

/**
 * Declares what Totals::toArray() writes, as fields, so an operation that answers with totals declares them once.
 *
 * Owns one fact: the wire shape of totals. It lists every key of Totals::toArray() and of the
 * arrays of its lines, adjustments, tax components and summary, in their order, with its type; a
 * unit test holds the two equal on real totals. An amount is an integer of minor units of the
 * totals' currency, and every `base_` twin one of the base currency. Nothing here computes a
 * figure: the answer carries the calculation's own.
 *
 * Declarations are data: building the fields calls no WordPress function.
 *
 * @since 0.1.0
 */
final class TotalsFields {

	/**
	 * Returns the totals as an object field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The field's wire name.
	 * @param string   $description The field's machine description.
	 * @param \Closure $label       Returns the field's label through a literal gettext call.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	public static function totals( string $name, string $description, \Closure $label ): FieldSpec {
		return FieldSpec::object(
			$name,
			$description,
			$label,
			array(
				self::text( 'currency', 'The ISO 4217 code of the currency every amount is in.', 'USD', static fn(): string => __( 'Currency', 'seocart' ) ),
				self::text( 'base_currency', 'The ISO 4217 code of the store\'s base currency, which every base_ amount is in.', 'USD', static fn(): string => __( 'Base currency', 'seocart' ) ),
				self::text( 'conversion_context', 'The fingerprint of the exchange rates the base amounts were converted at.', 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9', static fn(): string => __( 'Conversion context', 'seocart' ) ),
				self::integer( 'rate_version', 'The version of the exchange rates the base amounts were converted at.', static fn(): string => __( 'Rate version', 'seocart' ), 0 ),
				self::text( 'cross_zone_policy', 'What stays fixed when a price is sold into another tax zone.', CrossZonePolicy::FixedNet->value, static fn(): string => __( 'Cross-zone policy', 'seocart' ), allowed: array_column( CrossZonePolicy::cases(), 'value' ) ),
				self::text( 'tax_rounding_mode', 'Where tax was rounded: per line or per subtotal.', TaxRoundingMode::PerLine->value, static fn(): string => __( 'Tax rounding', 'seocart' ), allowed: array_column( TaxRoundingMode::cases(), 'value' ) ),
				self::text( 'calculated_at', 'When the totals were calculated, in UTC.', '2026-09-25T10:00:00.000000Z', static fn(): string => __( 'Calculated at', 'seocart' ) ),
				FieldSpec::objectList( 'lines', 'The priced lines, in the order of the cart.', static fn(): string => __( 'Lines', 'seocart' ), self::lineFields(), required: true ),
				FieldSpec::objectList( 'adjustments', 'The discounts, shipping and fees, in the order they were applied.', static fn(): string => __( 'Adjustments', 'seocart' ), self::adjustmentFields(), required: true ),
				FieldSpec::object( 'summary', 'The sums of the lines and adjustments, and their twins in the base currency.', static fn(): string => __( 'Summary', 'seocart' ), self::summaryFields(), required: true ),
				self::integer( 'amount_due_minor', 'What is to be paid, in minor units.', static fn(): string => __( 'Amount due', 'seocart' ) ),
			),
			required: true
		);
	}

	/**
	 * Returns the fields of a priced line.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in the order TotalsLine::toArray() writes them.
	 */
	private static function lineFields(): array {
		return array_merge(
			array(
				self::text( 'key', 'The line\'s key: a cart line\'s identity.', 'b1', static fn(): string => __( 'Key', 'seocart' ) ),
				self::integer( 'variant_id', 'The variant priced.', static fn(): string => __( 'Variant', 'seocart' ), 42 ),
				self::integer( 'quantity', 'The units priced.', static fn(): string => __( 'Quantity', 'seocart' ), 2 ),
				self::integer( 'unit_price_minor', 'The price of one unit as authored, in minor units.', static fn(): string => __( 'Unit price', 'seocart' ) ),
				self::basis( 'unit_amount_basis', 'Whether the unit price was authored net or gross of tax.' ),
				self::text( 'price_source', 'Where the unit price came from.', PriceSource::Explicit->value, static fn(): string => __( 'Price source', 'seocart' ), allowed: array_column( PriceSource::cases(), 'value' ) ),
				self::text( 'tax_class', 'The tax class the line is taxed in.', 'standard', static fn(): string => __( 'Tax class', 'seocart' ) ),
				self::flag( 'auto_added', 'Whether a promotion added the line.', static fn(): string => __( 'Added by a promotion', 'seocart' ) ),
				self::integer( 'line_subtotal_minor', 'The unit price times the quantity, before discounts, in minor units.', static fn(): string => __( 'Line subtotal', 'seocart' ) ),
				self::integer( 'line_discount_minor', 'The discounts given on the line, in minor units.', static fn(): string => __( 'Line discount', 'seocart' ) ),
				self::integer( 'base_line_subtotal_minor', 'The line subtotal in the base currency, in minor units.', static fn(): string => __( 'Base line subtotal', 'seocart' ) ),
				self::integer( 'base_line_discount_minor', 'The line discount in the base currency, in minor units.', static fn(): string => __( 'Base line discount', 'seocart' ) ),
				self::integer( 'unit_gross_for_display_minor', 'The price of one unit with tax, for display, in minor units.', static fn(): string => __( 'Unit price with tax', 'seocart' ) ),
			),
			self::figures( 'the line after discounts' ),
			array( self::components() )
		);
	}

	/**
	 * Returns the fields of an adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in the order TotalsAdjustment::toArray() writes them.
	 */
	private static function adjustmentFields(): array {
		return array_merge(
			array(
				self::integer( 'position', 'The adjustment\'s place in the order adjustments were applied, from 0.', static fn(): string => __( 'Position', 'seocart' ), 0 ),
				self::text( 'scope', 'What the adjustment applies to.', AdjustmentScope::Shipping->value, static fn(): string => __( 'Scope', 'seocart' ), allowed: array_column( AdjustmentScope::cases(), 'value' ) ),
				self::text( 'type', 'What kind of adjustment it is.', AdjustmentType::Shipping->value, static fn(): string => __( 'Type', 'seocart' ), allowed: array_column( AdjustmentType::cases(), 'value' ) ),
				self::text( 'source', 'Where the adjustment comes from, such as shipping:flat or promotion:<uuid>.', 'shipping:flat', static fn(): string => __( 'Source', 'seocart' ) ),
				self::text( 'label_key', 'The key of the adjustment\'s label.', 'shipping.flat', static fn(): string => __( 'Label', 'seocart' ) ),
				self::text( 'line_key', 'The key of the line a line-scoped adjustment applies to; null for any other.', 'b1', static fn(): string => __( 'Line', 'seocart' ), nullable: true ),
				self::integer( 'amount_minor', 'The amount as authored, in minor units.', static fn(): string => __( 'Amount', 'seocart' ) ),
				self::basis( 'amount_basis', 'Whether the amount was authored net or gross of tax.' ),
				self::text( 'calculation_base', 'What a percentage adjustment was taken of; null for a fixed amount.', AdjustmentBase::SubtotalAfterDiscounts->value, static fn(): string => __( 'Calculation base', 'seocart' ), nullable: true ),
				self::text( 'tax_class', 'The tax class the adjustment is taxed in; null when it is not taxed.', 'standard', static fn(): string => __( 'Tax class', 'seocart' ), nullable: true ),
				self::integer( 'base_amount_minor', 'The amount as authored, in the base currency, in minor units.', static fn(): string => __( 'Base amount', 'seocart' ) ),
			),
			self::figures( 'the adjustment' ),
			array( self::components() )
		);
	}

	/**
	 * Returns the tax components of a line or an adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The list field.
	 */
	private static function components(): FieldSpec {
		return FieldSpec::objectList(
			'components',
			'The tax, one component per rate applied.',
			static fn(): string => __( 'Tax components', 'seocart' ),
			array_merge(
				array(
					self::text( 'component_key', 'The component\'s key.', 'b1:US-TX:standard', static fn(): string => __( 'Key', 'seocart' ) ),
					self::text( 'owner_line_key', 'The key of the line taxed; null for an adjustment.', 'b1', static fn(): string => __( 'Line', 'seocart' ), nullable: true ),
					self::integer( 'owner_adjustment_position', 'The position of the adjustment taxed; null for a line.', static fn(): string => __( 'Adjustment', 'seocart' ), 0, nullable: true ),
					self::text( 'jurisdiction_code', 'The jurisdiction that levies the rate.', 'US-TX', static fn(): string => __( 'Jurisdiction', 'seocart' ) ),
					self::text( 'rate_ref', 'The reference of the rate applied.', 'US-TX:standard', static fn(): string => __( 'Rate', 'seocart' ) ),
					self::text( 'rate_name', 'The name of the rate applied.', 'Sales tax', static fn(): string => __( 'Rate name', 'seocart' ) ),
					self::integer( 'rate_micropercent', 'The rate, in millionths of a percent.', static fn(): string => __( 'Rate', 'seocart' ), 8250000 ),
					self::flag( 'is_compound', 'Whether the rate is levied on the tax of the rates before it.', static fn(): string => __( 'Compound', 'seocart' ) ),
					self::integer( 'priority', 'The order compound rates are applied in.', static fn(): string => __( 'Priority', 'seocart' ), 1 ),
					self::basis( 'authored_basis', 'Whether the amount taxed was authored net or gross of tax.' ),
					self::integer( 'residual_minor', 'The minor unit the split gave this component beyond its exact share: 0, or 1 with the tax\'s sign.', static fn(): string => __( 'Residual', 'seocart' ), 0 ),
				),
				self::figures( 'the component' )
			),
			required: true
		);
	}

	/**
	 * Returns the fields of the summary.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields, in the order TotalsSummary::toArray() writes them.
	 */
	private static function summaryFields(): array {
		return array(
			self::integer( 'subtotal_minor', 'The lines\' subtotals added up, in minor units.', static fn(): string => __( 'Subtotal', 'seocart' ) ),
			self::text( 'subtotal_basis', 'Whether the subtotal adds net amounts, gross amounts, or both.', 'net', static fn(): string => __( 'Subtotal basis', 'seocart' ), allowed: array( 'net', 'gross', 'mixed' ) ),
			self::integer( 'discount_total_minor', 'The discounts added up, as authored, in minor units.', static fn(): string => __( 'Discounts', 'seocart' ) ),
			self::integer( 'shipping_total_minor', 'The shipping added up, as authored, in minor units.', static fn(): string => __( 'Shipping', 'seocart' ) ),
			self::integer( 'fee_total_minor', 'The fees added up, as authored, in minor units.', static fn(): string => __( 'Fees', 'seocart' ) ),
			self::integer( 'net_minor', 'The total without tax, in minor units.', static fn(): string => __( 'Net', 'seocart' ) ),
			self::integer( 'tax_minor', 'The tax, in minor units.', static fn(): string => __( 'Tax', 'seocart' ) ),
			self::integer( 'grand_minor', 'The total with tax, in minor units.', static fn(): string => __( 'Total', 'seocart' ) ),
			self::integer( 'base_subtotal_minor', 'The subtotal in the base currency, in minor units.', static fn(): string => __( 'Base subtotal', 'seocart' ) ),
			self::integer( 'base_discount_total_minor', 'The discounts in the base currency, in minor units.', static fn(): string => __( 'Base discounts', 'seocart' ) ),
			self::integer( 'base_shipping_total_minor', 'The shipping in the base currency, in minor units.', static fn(): string => __( 'Base shipping', 'seocart' ) ),
			self::integer( 'base_fee_total_minor', 'The fees in the base currency, in minor units.', static fn(): string => __( 'Base fees', 'seocart' ) ),
			self::integer( 'base_net_minor', 'The total without tax in the base currency, in minor units.', static fn(): string => __( 'Base net', 'seocart' ) ),
			self::integer( 'base_tax_minor', 'The tax in the base currency, in minor units.', static fn(): string => __( 'Base tax', 'seocart' ) ),
			self::integer( 'base_grand_minor', 'The total with tax in the base currency, in minor units.', static fn(): string => __( 'Base total', 'seocart' ) ),
		);
	}

	/**
	 * Returns the three figures of a taxed amount and their base twins, as Totals::figures() writes them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $whose What the figures are of, for the descriptions.
	 * @return list<FieldSpec> net_minor, tax_minor, gross_minor, then the base_ three.
	 */
	private static function figures( string $whose ): array {
		return array(
			self::integer( 'net_minor', 'The net amount of ' . $whose . ', in minor units.', static fn(): string => __( 'Net', 'seocart' ) ),
			self::integer( 'tax_minor', 'The tax on ' . $whose . ', in minor units.', static fn(): string => __( 'Tax', 'seocart' ) ),
			self::integer( 'gross_minor', 'The gross amount of ' . $whose . ', in minor units.', static fn(): string => __( 'Gross', 'seocart' ) ),
			self::integer( 'base_net_minor', 'The net amount of ' . $whose . ' in the base currency, in minor units.', static fn(): string => __( 'Base net', 'seocart' ) ),
			self::integer( 'base_tax_minor', 'The tax on ' . $whose . ' in the base currency, in minor units.', static fn(): string => __( 'Base tax', 'seocart' ) ),
			self::integer( 'base_gross_minor', 'The gross amount of ' . $whose . ' in the base currency, in minor units.', static fn(): string => __( 'Base gross', 'seocart' ) ),
		);
	}

	/**
	 * Returns a required integer field: an amount in minor units unless an example says otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label.
	 * @param int      $example     Optional. A value. Default 1299.
	 * @param bool     $nullable    Optional. Whether it may be null. Default false.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function integer( string $name, string $description, \Closure $label, int $example = 1299, bool $nullable = false ): FieldSpec {
		return new FieldSpec( $name, FieldType::Integer, $description, $label, $example, required: true, nullable: $nullable );
	}

	/**
	 * Returns a required text field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param string   $example     A value.
	 * @param \Closure $label       Returns the label.
	 * @param bool     $nullable    Optional. Whether it may be null. Default false.
	 * @param string[] $allowed     Optional. The values it is limited to. Default any.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 * @phpstan-param list<string>       $allowed
	 */
	private static function text( string $name, string $description, string $example, \Closure $label, bool $nullable = false, array $allowed = array() ): FieldSpec {
		return new FieldSpec( $name, FieldType::String, $description, $label, $example, required: true, nullable: $nullable, allowed: $allowed );
	}

	/**
	 * Returns a required boolean field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function flag( string $name, string $description, \Closure $label ): FieldSpec {
		return new FieldSpec( $name, FieldType::Boolean, $description, $label, false, required: true );
	}

	/**
	 * Returns a required field naming an amount's basis: net or gross.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name        The wire name.
	 * @param string $description The machine description.
	 * @return FieldSpec The field.
	 */
	private static function basis( string $name, string $description ): FieldSpec {
		return self::text( $name, $description, AmountBasis::Net->value, static fn(): string => __( 'Basis', 'seocart' ), allowed: array_column( AmountBasis::cases(), 'value' ) );
	}
}
