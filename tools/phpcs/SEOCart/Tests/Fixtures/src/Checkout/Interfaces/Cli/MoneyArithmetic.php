<?php
/**
 * Fixture: Money arithmetic inside a module's own adapter directory (src/<Module>/Interfaces/)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

$total = $subtotal->add( $shipping ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall

WP_CLI::line( $total->minorUnits() / 100 ); // Expect: SEOCart.DRY.MoneyArithmeticInInterfaces.Operator

// A reviewed exception is silenced the usual way, with its reason.
$expires = $issued->add( $lifetime ); // phpcs:ignore SEOCart.DRY.MoneyArithmeticInInterfaces.MethodCall -- DateTimeImmutable, not Money.
