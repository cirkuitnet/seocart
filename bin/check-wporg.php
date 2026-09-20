<?php
/**
 * Validates readme.txt against the WordPress.org directory rules and the plugin header
 *
 * Usage: php bin/check-wporg.php [--tag=vX.Y.Z] [--readme=<path>] [--plugin-file=<path>] [--composer-json=<path>]
 *
 * Behind `composer wporg:check`. The release workflow passes the pushed git tag with
 * `--tag`, which proves that the plugin header's Version, readme.txt's Stable tag and the
 * git tag agree. The three path options replace readme.txt, seocart.php and composer.json
 * and exist for tests and scratch copies. The rules live in tools/WpOrg/ReadmeValidator.php.
 * WordPress is never loaded.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tools\WpOrg\ReadmeCheckCommand;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root = dirname( __DIR__ );

if ( ! is_file( $seocart_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "check-wporg: vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 2 );
}

require $seocart_root . '/vendor/autoload.php';

$seocart_command = new ReadmeCheckCommand(
	static function ( string $line ): void {
		fwrite( STDOUT, $line . "\n" );
	}
);

exit( $seocart_command->run( array_slice( $argv, 1 ), $seocart_root ) );
