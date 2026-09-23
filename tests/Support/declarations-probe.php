<?php
/**
 * Builds the operation registry in a process where every WordPress function is a counter
 *
 * Usage: php tests/Support/declarations-probe.php [--with-planted-factory]
 *
 * Run by DeclarationsAreDataTest in a fresh PHP process, so that nothing else has loaded
 * WordPress, a test double of it, or any plugin class. The process:
 *
 * 1. declares every global function the WordPress stubs declare as a counter that records the
 *    call and returns null, so a declaration that calls WordPress in any way is counted rather
 *    than crashing;
 * 2. replaces `$wpdb` with an object that records every property read and method call;
 * 3. records every file opened through PHP's file stream wrapper, includes and reads alike;
 * 4. builds the production registry together with the test-fixture operation, and compiles each
 *    definition into every dialect, as a surface would when it registers it;
 * 5. prints what it recorded as JSON.
 *
 * With `--with-planted-factory` it also adds an operation whose factory translates a string,
 * reads an option and reads a file, so the test can prove that each counter counts.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\Operations;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\RecordingFileStream;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root = dirname( __DIR__, 2 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WordPress's own constant, defined here only as a placeholder.
define( 'ABSPATH', __DIR__ . '/__wordpress-is-not-loaded-in-the-declarations-probe__/' );

require $seocart_root . '/vendor/autoload.php';

/*
 * Every global function of the WordPress stubs becomes a counter. The definitions are written to
 * a temporary file and included, because PHP declares a function only from source.
 */
$seocart_counters = array();
$seocart_stubs    = (string) file_get_contents( $seocart_root . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php' );
$seocart_global   = false;

foreach ( explode( "\n", $seocart_stubs ) as $seocart_line ) {
	if ( 1 === preg_match( '/^namespace\s*(\S*)\s*\{/', $seocart_line, $seocart_match ) ) {
		$seocart_global = '' === $seocart_match[1];
	} elseif ( $seocart_global && 1 === preg_match( '/^    function ([A-Za-z_][A-Za-z0-9_]*)\(/', $seocart_line, $seocart_match ) && ! function_exists( $seocart_match[1] ) ) {
		$seocart_counters[] = 'function ' . $seocart_match[1] . "() { \$GLOBALS['seocart_probe_calls'][ __FUNCTION__ ] = ( \$GLOBALS['seocart_probe_calls'][ __FUNCTION__ ] ?? 0 ) + 1; return null; }";
	}
}

$seocart_counter_file = tempnam( sys_get_temp_dir(), 'seocart-wp-counters-' );
file_put_contents( $seocart_counter_file, "<?php\n" . implode( "\n", $seocart_counters ) . "\n" );

$GLOBALS['seocart_probe_calls'] = array();

require $seocart_counter_file;
unlink( $seocart_counter_file );

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded in this process; the stand-in records every use of the database object.
$GLOBALS['wpdb'] = new class() {

	/**
	 * Every property read and method call, in order.
	 *
	 * @var list<string>
	 */
	public array $touched = array();

	/**
	 * Records a property read.
	 *
	 * @param string $name The property.
	 * @return null Nothing.
	 */
	public function __get( string $name ) {
		$this->touched[] = '->' . $name;

		return null;
	}

	/**
	 * Records a method call.
	 *
	 * @param string       $name      The method.
	 * @param array<mixed> $arguments The arguments.
	 * @return null Nothing.
	 */
	public function __call( string $name, array $arguments ) {
		unset( $arguments );

		$this->touched[] = '->' . $name . '()';

		return null;
	}
};

RecordingFileStream::start();

$seocart_registry = Operations::registry();
FixtureStockOperation::register( $seocart_registry );

if ( in_array( '--with-planted-factory', $argv, true ) ) {
	$seocart_registry->add(
		'fixture_stock.planted_stock',
		static function () use ( $seocart_root ): OperationDefinition {
			// Everything a declaration must never do while it is being built.
			call_user_func( '__', 'A translated text' );
			call_user_func( 'get_option', 'an_option' );
			file_get_contents( $seocart_root . '/composer.json' );

			$fixture = FixtureStockOperation::definition();

			return new OperationDefinition(
				id: 'fixture_stock.planted_stock',
				label: $fixture->label(),
				summary: $fixture->summary(),
				input: $fixture->input(),
				output: $fixture->output(),
				capability: $fixture->capability(),
				resource_field: null,
				errors: $fixture->errors(),
				annotations: $fixture->annotations(),
				service: $fixture->service(),
				ability: 'fixture-planted-stock'
			);
		}
	);
}

$seocart_ids = array();

foreach ( $seocart_registry->all() as $seocart_definition ) {
	$seocart_compiled = new CompiledOperation( $seocart_definition );
	$seocart_compiled->restArguments();
	$seocart_compiled->inputSchema();
	$seocart_compiled->outputSchema();
	$seocart_compiled->cliSynopsis();

	$seocart_ids[] = $seocart_definition->id();
}

$seocart_opened = RecordingFileStream::stop();

fwrite(
	STDOUT,
	(string) json_encode(
		array(
			'operations'          => $seocart_ids,
			'wordpress_functions' => count( $seocart_counters ),
			'wordpress_calls'     => $GLOBALS['seocart_probe_calls'],
			'wpdb'                => $GLOBALS['wpdb']->touched,
			'files_opened'        => array_map(
				static fn( string $path ): string => str_starts_with( $path, $seocart_root . '/' ) ? substr( $path, strlen( $seocart_root ) + 1 ) : $path,
				$seocart_opened
			),
		)
	)
);
