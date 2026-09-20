<?php
/**
 * Fixture: the Domain and Application layers are where money is computed
 *
 * Nothing here may be reported: SEOCart.DRY.MoneyArithmeticInInterfaces applies only to
 * files under a directory named Interfaces. This file's own name starts with that word and
 * is still not inside such a directory.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

$total   = $subtotal->add( $shipping )->subtract( $discount );
$parts   = $total->allocate( 1, 1, 1 );
$rounded = intdiv( $total->minorUnits() * $rate->basisPoints(), 10000 );
$running += $line->minorUnits();
