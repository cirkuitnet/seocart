<?php
/**
 * Fixture: executable content, which the WordPress.org directory rejects
 *
 * This file is never executed or included: the self-test only tokenizes it, to prove that
 * the compliance lints report these constructs as errors. The arguments are inert on purpose.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// Violations.

$result = eval( 'return 1;' ); // Expect: Squiz.PHP.Eval.Discouraged
$lambda = create_function( '', 'return 1;' ); // Expect: WordPress.PHP.RestrictedPHPFunctions.create_function_create_function

// Look-alikes. None of these may be reported.

$evaluated = $template->eval( $context );
$callback  = $factory->create_function( 'name' );
$closure   = static function () {
	return 1;
};
$label     = 'eval( $code ) and create_function() are only text here';
