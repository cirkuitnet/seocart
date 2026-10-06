<?php
/**
 * The PHP half of the extension kit: php tools/extension.php <command> [<argument> ...]
 *
 * SEOCart's extension scripts call it: bin/dev/new-extension.sh, bin/ci/extension.sh and
 * bin/dev/provision-site.sh. The commands are in tools/Extension/ExtensionCommand.php. It needs
 * SEOCart's development install (`composer install`), which the extension kit uses anyway.
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/*
 * Every file under src/ ends the script silently, with no message, when ABSPATH is not defined,
 * and the generator reads SEOCart's contract from them. WordPress is never loaded here, so ABSPATH
 * is a placeholder, as bin/generate-docs.php does it.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WordPress's own constant, defined here only as a placeholder.
	define( 'ABSPATH', dirname( __DIR__ ) . '/__wordpress-is-not-loaded-by-command-line-tools__/' );
}

if ( ! is_file( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, 'extension: run `composer install` in ' . dirname( __DIR__ ) . " first; the extension kit runs on SEOCart's development install.\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

exit( SEOCart\Tools\Extension\ExtensionCommand::main( dirname( __DIR__ ), array_slice( $argv, 1 ) ) );
