<?php
/**
 * Seeds a reference dataset into a disposable development site
 *
 * Test tooling, never shipped and never loaded by the plugin. Run it inside the site, from the
 * plugin's checkout, with the dataset in the environment:
 *
 *     SEOCART_SEED_DISPOSABLE=1 SEOCART_SEED_DATASET=medium wp eval-file tests/Support/Seed/seed-site.php
 *
 * It writes thousands of rows into whichever database the site uses, so it refuses, before it
 * writes anything, unless the site is marked disposable twice: its environment type
 * (wp_get_environment_type()) is `local` or `development`, and SEOCART_SEED_DISPOSABLE is 1 for
 * the run. The sites bin/dev/provision-site.sh builds set no environment type, so they report
 * `production` and satisfy neither on their own: give the run WP_ENVIRONMENT_TYPE=development
 * as well, or set the constant once with `wp config set WP_ENVIRONMENT_TYPE development`.
 *
 * The dataset is `small` (the default), `medium` or `large`. The site must have SEOCart active,
 * no products and no stock yet, and no post with an id from ReferenceSeed::FIRST_POST_ID; the
 * seed refuses it otherwise. It prints how many rows it wrote and how long it took; check the
 * seeded site afterwards with `wp seocart doctor`.
 *
 * `wp eval-file` evaluates the file's code, so it declares no strict types.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Seed\Dataset;
use SEOCart\Tests\Support\Seed\ReferenceSeed;

defined( 'ABSPATH' ) || exit;

( static function (): void {
	// The plugin loads the classes under src/ only; this loads the test classes the seed needs, and nothing else.
	spl_autoload_register(
		static function ( string $class_name ): void {
			$namespace = 'SEOCart\\Tests\\';

			if ( 0 === strncmp( $class_name, $namespace, strlen( $namespace ) ) && 1 === preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $class_name ) ) {
				$file = dirname( __DIR__, 2 ) . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $namespace ) ) ) . '.php';

				if ( is_file( $file ) ) {
					require $file;
				}
			}
		}
	);

	$environment = wp_get_environment_type();
	$missing     = array();

	if ( ! in_array( $environment, array( 'local', 'development' ), true ) ) {
		$missing[] = 'an environment type of local or development (this site\'s is ' . $environment . ')';
	}

	if ( '1' !== getenv( 'SEOCART_SEED_DISPOSABLE' ) ) {
		$missing[] = 'SEOCART_SEED_DISPOSABLE=1';
	}

	if ( array() !== $missing ) {
		fwrite( STDERR, 'Error: refused, and nothing was written. The seed runs only on a site marked disposable; this run lacks ' . implode( ' and ', $missing ) . ".\n" );
		exit( 1 );
	}

	$dataset = Dataset::from( (string) ( getenv( 'SEOCART_SEED_DATASET' ) ? getenv( 'SEOCART_SEED_DATASET' ) : Dataset::Small->value ) );
	$seed    = new ReferenceSeed( $dataset, (string) Kernel::container()->get( SettingsStore::class )->value( InternationalSettings::BASE_CURRENCY ), get_locale() );

	try {
		$written = $seed->write( Kernel::container()->get( Database::class ) );
	} catch ( \RuntimeException $refused ) {
		fwrite( STDERR, 'Error: ' . $refused->getMessage() . "\n" );
		exit( 1 );
	}

	$counts = array();

	foreach ( $written['rows'] as $table => $rows ) {
		$counts[] = $table . ' ' . $rows;
	}

	printf( "Seeded the %s reference dataset in %.1f seconds: %s.\n", $dataset->value, $written['seconds'], implode( ', ', $counts ) );
} )();
