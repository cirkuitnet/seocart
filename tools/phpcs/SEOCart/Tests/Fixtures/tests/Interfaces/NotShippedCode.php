<?php
/**
 * Fixture: code that is not shipped (tests, tools, bin) is outside the DRY rules
 *
 * A test may spell out the schema array it expects a compiler to produce, reset an option
 * and add up amounts, even in a directory named Interfaces. The compliance lints have no
 * such scope: executable content is an error wherever PHP_CodeSniffer looks.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// Not reported: the DRY rules stop at shipped code.

$expected = array(
	'type' => 'string',
	'enum' => array( 'draft', 'publish' ),
);

delete_option( 'seocart_currency' );

$sum = $one->add( $two )->minorUnits() + 1;

// Reported everywhere. The argument is inert and this file is never executed.

$result = eval( 'return 1;' ); // Expect: Squiz.PHP.Eval.Discouraged
