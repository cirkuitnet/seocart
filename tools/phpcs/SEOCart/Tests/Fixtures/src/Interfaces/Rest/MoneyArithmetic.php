<?php
/**
 * Fixture: Money arithmetic inside an adapter directory (src/Interfaces/)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// Violations: a call to a Money method that computes an amount.

$total = $subtotal->add( $shipping ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$total = $total->subtract( $discount ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$line  = $price->multiply( $quantity ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$each  = $line->divide( 3 ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$parts = $total->allocate( 1, 1, 1 ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$tax   = $total->percentage( $rate ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$owed  = $paid->negate(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$safe  = $order?->total()?->Add( $fee ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$sum   = Money::add( $one, $two ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$fluent = $order
	->total()
	->subtract( $refunded ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall
$scaled = $price->multiply( '1.5' ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall

// Violations: an arithmetic operator on the raw number.

$display = $total->minorUnits() / 100; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$display = 0.01 * $total->minorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$gross   = $net->amount() + $tax->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$change  = $paid->amount() - $order->total()->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$cents   = $total->minorUnits() % 100; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$squared = $total->minorUnits() ** 2; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$cast    = $quantity * (int) $lines[0]->price()->minorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$prop    = $total->amount * 2; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$nullish = 2 * $order?->total()?->MinorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$owed    = -$paid->minorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$grouped = ( $total->minorUnits() ) / 100; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$divisor = 100 / (float) ( ( $total->amount() ) ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator

$total->amount++; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
--$total->amount; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator

$running += $line->minorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$running -= $line->minorUnits(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$running *= $line->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$running /= $line->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$running %= $line->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator
$running **= $line->amount(); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator

$both = $a->add( $b )->minorUnits() + 1; // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall, SEOCart.DRY.MoneyArithmeticInInterfaces.Operator

// Look-alikes: formatting, comparing and ordinary arithmetic. None of these may be reported.

$label    = $formatter->format( $total );
$raw      = $total->minorUnits();
$text     = $total->amount() . ' ' . $total->currency()->code();
$is_free  = 0 === $total->minorUnits();
$is_more  = $total->amount() > $other->amount();
$pages    = $count / $per_page + 1;
$offset   = ( $page - 1 ) * $per_page;
$index    = $position++;
$padded   = $width - strlen( $total->formatted() );
$args     = array( $total->minorUnits(), -1 );
$chained  = 2 * $total->minorUnits()->scale();
$other    = $cart->count() + $cart->weight();
$named    = $registry->address( $id );
$adder    = $calculator->addition( 1, 2 );
$property = $stats->add;
$length   = strlen( (string) $total->amount() ) + 2;
$counted  = ( $page - 1 ) + count( $lines );
$ticks    = ++$position;

// A word is never a Money operand: WP_Error::add( $code, $message ).
$errors->add( 'seocart_invalid_amount', 'The amount is not valid.' );
$errors->add( 'seocart_' . $reason, $message );

$adapter = new class() extends BaseController {
	/**
	 * Calls on the adapter itself are never Money arithmetic.
	 *
	 * @param mixed $route A route.
	 */
	public function add( $route ) {
		$this->add( $route );
		self::add( $route );
		static::add( $route );
		parent::add( $route );
	}
};
