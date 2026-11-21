<?php
/**
 * OrderLimits: what an order's goods come to, and whether that is within the store's limits for its currency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Measures the goods of a calculation the way the store's order limits are set, and judges them against the limits of its currency.
 *
 * Owns one fact: what an order limit is compared with. It is the sum of the lines after their
 * discounts, without shipping, its discounts or fees, on the basis the store entered its prices
 * in: with tax when every line's price was entered with tax, without it otherwise, as the order
 * records the basis (OrderDocument::priceEntryMode()). So it is the shelf prices a shopper was
 * shown, less what was taken off them. Every goods discount is a line's, so the lines' own figures
 * are the whole of it. The figure is the calculation's own (Totals::$linesAfterDiscounts), which
 * this class only reads, never a client's total, in the cart's currency, never converted: a
 * currency without an entry has no limit, and the bounds are inclusive.
 *
 * The session write tells the shopper where the cart stands (verdict()); the placement refuses an
 * order outside the limits (refuse()), the only place one is enforced.
 *
 * @since 0.2.0
 */
final class OrderLimits {

	/**
	 * The verdict of goods within the limits.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OK = 'ok';

	/**
	 * The verdict of goods below the minimum.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BELOW_MINIMUM = 'below_minimum';

	/**
	 * The verdict of goods above the maximum.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ABOVE_MAXIMUM = 'above_maximum';

	/**
	 * The basis of goods whose prices were all entered with tax: their gross.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GROSS = 'gross';

	/**
	 * The basis of any other goods: their net.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NET = 'net';

	/**
	 * The verdicts, in the order a client is told them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const STATUSES = array( self::OK, self::BELOW_MINIMUM, self::ABOVE_MAXIMUM );

	/**
	 * Cannot be called: the class is static functions.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns what a calculation's goods come to, and the basis they are measured on.
	 *
	 * @since 0.2.0
	 *
	 * @param Totals $totals The calculation's totals.
	 * @return array{amount: Money, basis: string} The lines after their discounts, as the calculation
	 *                                             added them up: gross when every line's price was
	 *                                             entered gross and net otherwise; the basis, GROSS or NET.
	 */
	public static function amountOf( Totals $totals ): array {
		$basis = self::GROSS === OrderDocument::priceEntryMode( $totals ) ? self::GROSS : self::NET;
		$goods = $totals->linesAfterDiscounts;

		return array(
			'amount' => self::GROSS === $basis ? $goods->gross() : $goods->net(),
			'basis'  => $basis,
		);
	}

	/**
	 * Returns where a calculation's goods stand against the limits of its currency.
	 *
	 * @since 0.2.0
	 *
	 * @param Totals $totals The calculation's totals.
	 * @param array  $limits The store's limits, by currency code (CheckoutRules::orderLimits()).
	 * @return array{status: string, amount_minor: int, minimum_minor: int|null, maximum_minor: int|null, basis: string}|null The verdict, by wire name; null when the currency has no limit.
	 *
	 * @phpstan-param array<string, array{max: int|null, min: int|null}> $limits
	 */
	public static function verdict( Totals $totals, array $limits ): ?array {
		$limit = $limits[ $totals->currency->code() ] ?? null;

		if ( null === $limit ) {
			return null;
		}

		$measured = self::amountOf( $totals );
		$amount   = $measured['amount'];
		$status   = self::OK;

		if ( null !== $limit['min'] && $amount->compare( Money::of( $limit['min'], $totals->currency ) ) < 0 ) {
			$status = self::BELOW_MINIMUM;
		} elseif ( null !== $limit['max'] && $amount->compare( Money::of( $limit['max'], $totals->currency ) ) > 0 ) {
			$status = self::ABOVE_MAXIMUM;
		}

		return array(
			'status'        => $status,
			'amount_minor'  => $amount->minorUnits(),
			'minimum_minor' => $limit['min'],
			'maximum_minor' => $limit['max'],
			'basis'         => $measured['basis'],
		);
	}

	/**
	 * Refuses an order whose goods are outside the limits of its currency.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.subtotal_below_minimum` or `checkout.subtotal_above_maximum`,
	 *                        with the details `amount_minor`, `limit_minor`, `currency` and `basis`.
	 *
	 * @param Totals $totals The totals the order is placed with.
	 * @param array  $limits The store's limits, by currency code.
	 *
	 * @phpstan-param array<string, array{max: int|null, min: int|null}> $limits
	 */
	public static function refuse( Totals $totals, array $limits ): void {
		$verdict = self::verdict( $totals, $limits );

		if ( null === $verdict || self::OK === $verdict['status'] ) {
			return;
		}

		$below = self::BELOW_MINIMUM === $verdict['status'];

		CodedException::raise(
			$below ? CheckoutError::SubtotalBelowMinimum : CheckoutError::SubtotalAboveMaximum,
			array(),
			array(
				'amount_minor' => $verdict['amount_minor'],
				'limit_minor'  => $below ? $verdict['minimum_minor'] : $verdict['maximum_minor'],
				'currency'     => $totals->currency->code(),
				'basis'        => $verdict['basis'],
			)
		);
	}
}
