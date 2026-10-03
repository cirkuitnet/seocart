<?php
/**
 * Seeds a reference dataset into a disposable development site
 *
 * Test tooling, never shipped and never loaded by the plugin. Run it inside the site, from the
 * plugin's checkout, with the dataset in the environment:
 *
 *     SEOCART_SEED_DISPOSABLE=1 SEOCART_SEED_DATASET=medium wp eval-file tests/Support/Seed/seed-site.php
 *
 * It writes thousands of rows into whichever database the site uses, so before it writes anything
 * it runs disposable-guard.php, which refuses a site not marked disposable. That file's header
 * says what the marking is and which sites already have it.
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
	require_once __DIR__ . '/test-autoloader.php';

	require_once __DIR__ . '/disposable-guard.php';

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
