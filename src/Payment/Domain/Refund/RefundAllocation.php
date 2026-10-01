<?php
/**
 * RefundAllocation: what a refund returns of each stored figure of an order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * Allocates a refund's figures from what the order stored, less what earlier refunds returned: never from a rate, a tax rate, a price or the calculation.
 *
 * Owns one fact: how much of each stored figure a refund returns, in both currencies.
 *
 * - A tax component. The share of q units, of the u units its line has left, is the first part of
 *   `Money::allocate( remaining, array( q, u - q ) )`, for each figure: for a component authored
 *   gross, its gross and its tax, the net derived; for one authored net, its net and its tax, the
 *   gross derived. The base twins are split the same way, on their own. So the last units take
 *   exactly what remains, and a line refunded a unit at a time returns exactly its stored
 *   components, to the minor unit, in both currencies.
 * - A line. Its tax is the sum of its components' tax shares, and nothing for a line no rate
 *   taxed. Its kept figure, the gross of a line priced gross and the net of one priced net, is
 *   allocated from what remains of the line as a component's is, and the third figure derived, so
 *   `net + tax = gross` holds on the line in both currencies. A component's net is everything its
 *   rate was charged on, the whole of the line's net, so the components' nets are never added up:
 *   two rates on one line would count its net twice.
 * - The shipping. A refund gives back what is left of it, whole: its net less what earlier
 *   refunds returned of it, a free-shipping discount netting it, and its tax the sum of what
 *   remains of its components'.
 * - The total. The lines' and the shipping's figures added up.
 *
 * This is the one class of the refund that adds money up: it adds stored figures, and shares of
 * them, to state a refund document; the calculation alone produces an order's totals. The
 * scan that holds the refund code to no arithmetic allows this class, and only this one.
 *
 * @since 0.1.0
 */
final class RefundAllocation {

	/**
	 * Allocates what some units of a line return: the line's share and each of its tax components'.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When fewer than one unit, or more than the line has left, is asked for.
	 *
	 * @param RefundableLine $line       The line, as read.
	 * @param int            $quantity   How many of its units the refund returns.
	 * @param bool           $restock    Whether the units go back into stock.
	 * @param Share          $returned   What earlier refunds returned of the line.
	 * @param array          $components The line's tax components, each with what earlier refunds returned of it.
	 * @return LinePortion What the units return.
	 *
	 * @phpstan-param list<array{0: StoredTaxComponent, 1: Share}> $components
	 */
	public static function line( RefundableLine $line, int $quantity, bool $restock, Share $returned, array $components ): LinePortion {
		$left = $line->quantity - $line->refundedQuantity;

		if ( $quantity < 1 || $quantity > $left ) {
			throw new \InvalidArgumentException( sprintf( 'A refund returns 1 to %d units of this line.', $left ) );
		}

		$portions = self::components( $components, $quantity, $left );
		$stored   = $line->stored;

		return new LinePortion(
			$line,
			$quantity,
			$restock,
			new Share(
				self::kept( $stored->amount->subtract( $returned->amount ), $stored->basis, $quantity, $left, self::taxes( $portions, $stored->amount->currency(), false ) ),
				self::kept( $stored->base->subtract( $returned->base ), $stored->basis, $quantity, $left, self::taxes( $portions, $stored->base->currency(), true ) )
			),
			$portions
		);
	}

	/**
	 * Allocates what is left of the shipping, returned whole, with its tax components'.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $stored          The shipping adjustments together, as stored.
	 * @param TaxedMoney $storedBase      The same in the base currency.
	 * @param Money      $returnedNet     The shipping's net earlier refunds returned.
	 * @param Money      $returnedBaseNet The same in the base currency.
	 * @param array      $components      The shipping's tax components, each with what earlier refunds returned of it.
	 * @return ShippingPortion What the shipping returns.
	 *
	 * @phpstan-param list<array{0: StoredTaxComponent, 1: Share}> $components
	 */
	public static function shipping( TaxedMoney $stored, TaxedMoney $storedBase, Money $returnedNet, Money $returnedBaseNet, array $components ): ShippingPortion {
		$portions = self::components( $components, 1, 1 );

		return new ShippingPortion(
			$stored,
			$storedBase,
			$returnedNet,
			$returnedBaseNet,
			new Share(
				self::withTax( $stored->net()->subtract( $returnedNet ), self::taxes( $portions, $stored->currency(), false ) ),
				self::withTax( $storedBase->net()->subtract( $returnedBaseNet ), self::taxes( $portions, $storedBase->currency(), true ) )
			),
			$portions
		);
	}

	/**
	 * Adds up what a refund returns: its lines' and its shipping's figures.
	 *
	 * @since 0.1.0
	 *
	 * @param LinePortion[]        $lines        The lines.
	 * @param ShippingPortion|null $shipping     The shipping, or null.
	 * @param Currency             $currency     The order's currency.
	 * @param Currency             $baseCurrency The base currency.
	 * @return Share The refund's net, tax and gross, in both currencies.
	 *
	 * @phpstan-param list<LinePortion> $lines
	 */
	public static function total( array $lines, ?ShippingPortion $shipping, Currency $currency, Currency $baseCurrency ): Share {
		$shares = array_map( static fn( LinePortion $line ): Share => $line->share, $lines );
		$amount = TaxedMoney::zero( $currency );
		$base   = TaxedMoney::zero( $baseCurrency );

		foreach ( null === $shipping ? $shares : array_merge( $shares, array( $shipping->share ) ) as $share ) {
			$amount = $amount->add( $share->amount );
			$base   = $base->add( $share->base );
		}

		return new Share( $amount, $base );
	}

	/**
	 * Tells whether a share fits what is left of a stored figure: never against the figure's sign, and never past what remains.
	 *
	 * A positive figure takes shares of zero or more until what remains is zero; a negative one,
	 * such as a free-shipping discount's, shares of zero or less. The statements that write a
	 * refund carry the same rule in their WHERE clauses.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $stored   The stored figure.
	 * @param Money $returned What earlier refunds returned of it.
	 * @param Money $share    What this refund would return.
	 * @return bool True when it fits.
	 */
	public static function fits( Money $stored, Money $returned, Money $share ): bool {
		$towardsStored = static fn( Money $amount ): bool => $amount->isZero() || $amount->isNegative() === $stored->isNegative();

		return $towardsStored( $share ) && $towardsStored( $stored->subtract( $returned )->subtract( $share ) );
	}

	/**
	 * Allocates each component's share of some units.
	 *
	 * @since 0.1.0
	 *
	 * @param array $components The components, each with what earlier refunds returned of it.
	 * @param int   $quantity   The units returned.
	 * @param int   $left       The units left before this refund.
	 * @return list<ComponentPortion> The portions.
	 *
	 * @phpstan-param list<array{0: StoredTaxComponent, 1: Share}> $components
	 */
	private static function components( array $components, int $quantity, int $left ): array {
		return array_map(
			static function ( array $entry ) use ( $quantity, $left ): ComponentPortion {
				list( $component, $returned ) = $entry;

				$stored = $component->stored;

				return new ComponentPortion(
					$component,
					new Share(
						self::split( $stored->amount->subtract( $returned->amount ), $stored->basis, $quantity, $left ),
						self::split( $stored->base->subtract( $returned->base ), $stored->basis, $quantity, $left )
					)
				);
			},
			$components
		);
	}

	/**
	 * Splits what remains of a component: its kept figure and its tax allocated, the third derived.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney  $remaining What remains of it.
	 * @param AmountBasis $basis     The basis it was authored in.
	 * @param int         $quantity  The units returned.
	 * @param int         $left      The units left before this refund.
	 * @return TaxedMoney The share.
	 */
	private static function split( TaxedMoney $remaining, AmountBasis $basis, int $quantity, int $left ): TaxedMoney {
		return self::kept( $remaining, $basis, $quantity, $left, self::part( $remaining->tax(), $quantity, $left ) );
	}

	/**
	 * Allocates the kept figure of what remains, gross for an amount authored gross and net for one authored net, and derives the third from the tax given.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney  $remaining What remains.
	 * @param AmountBasis $basis     The basis it was authored in.
	 * @param int         $quantity  The units returned.
	 * @param int         $left      The units left before this refund.
	 * @param Money       $tax       The share's tax.
	 * @return TaxedMoney The share.
	 */
	private static function kept( TaxedMoney $remaining, AmountBasis $basis, int $quantity, int $left, Money $tax ): TaxedMoney {
		if ( AmountBasis::Gross === $basis ) {
			$gross = self::part( $remaining->gross(), $quantity, $left );

			return new TaxedMoney( $gross->subtract( $tax ), $tax, $gross );
		}

		return self::withTax( self::part( $remaining->net(), $quantity, $left ), $tax );
	}

	/**
	 * Returns the share of some units of what remains: the first part of an allocation by largest remainder, so the last units take all of it.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $remaining What remains.
	 * @param int   $quantity  The units returned, 1 or more.
	 * @param int   $left      The units left before this refund, at least $quantity.
	 * @return Money The share.
	 */
	private static function part( Money $remaining, int $quantity, int $left ): Money {
		return $remaining->allocate( array( $quantity, $left - $quantity ) )[0];
	}

	/**
	 * Adds the sum of some components' tax shares, in one currency.
	 *
	 * @since 0.1.0
	 *
	 * @param ComponentPortion[] $portions The components' shares.
	 * @param Currency           $currency The currency of the figures added.
	 * @param bool               $base     Whether to add the base twins.
	 * @return Money The tax; nothing for no component.
	 *
	 * @phpstan-param list<ComponentPortion> $portions
	 */
	private static function taxes( array $portions, Currency $currency, bool $base ): Money {
		$tax = Money::zero( $currency );

		foreach ( $portions as $portion ) {
			$tax = $tax->add( ( $base ? $portion->share->base : $portion->share->amount )->tax() );
		}

		return $tax;
	}

	/**
	 * Returns a net amount with its tax, and their sum as the gross.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $net The net amount.
	 * @param Money $tax The tax.
	 * @return TaxedMoney The figures.
	 */
	private static function withTax( Money $net, Money $tax ): TaxedMoney {
		return new TaxedMoney( $net, $tax, $net->add( $tax ) );
	}
}
