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

if ( ! is_file( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, 'extension: run `composer install` in ' . dirname( __DIR__ ) . " first; the extension kit runs on SEOCart's development install.\n" );
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

exit( SEOCart\Tools\Extension\ExtensionCommand::main( dirname( __DIR__ ), array_slice( $argv, 1 ) ) );
