<?php
/**
 * Regenerates, or drift-checks, every generated document that is committed
 *
 * Usage: php bin/generate-docs.php [--check] [--plugin=<extension root>]
 *
 * Behind `composer docs:generate` and `composer docs:check`. Without an argument every
 * generated document is rewritten from its source. With `--check` nothing is written,
 * and the exit code is non-zero when a committed document has drifted from its source
 * or when a generator skipped a source item it does not declare it skips. WordPress is
 * never loaded.
 *
 * With `--plugin=<root>`, the documents are a SEOCart extension's instead, generated in its
 * root: the page of the payment gateways it registers (docs/capabilities.md). The extension's
 * main file is included as WordPress would include it, and its listener of the registration
 * action is run against a registry that keeps what it is given.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Tests\Conformance\Payment\DeclarationRegistry;
use SEOCart\Tools\Docs\DocsRunner;
use SEOCart\Tools\Docs\Generators;
use SEOCart\Tools\Packaging\PluginPackage;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root = dirname( __DIR__ );

if ( ! is_file( $seocart_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "generate-docs: vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 2 );
}

/*
 * The generators read declarations under src/. Every file there carries the direct-access
 * guard the WordPress.org directory expects, so ABSPATH is defined as a placeholder, exactly
 * as tests/bootstrap-unit.php does: the path does not exist, which makes any accidental
 * attempt to include WordPress fail loudly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WordPress's own constant, defined here only as a placeholder.
	define( 'ABSPATH', $seocart_root . '/__wordpress-is-not-loaded-by-command-line-tools__/' );
}

/*
 * The documents show every message in its English source. A message is a closure around a
 * literal gettext call, and without WordPress there is no gettext, so __() is defined here to
 * return its text unchanged: exactly what WordPress's own returns when no translation is loaded.
 */
if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the text unchanged: the English source string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text   The text.
	 * @param string $domain The text domain. Unused.
	 * @return string The text.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- gettext's own name, declared only because WordPress is not loaded here.
		unset( $domain );

		return $text;
	}
}

require $seocart_root . '/vendor/autoload.php';

$seocart_arguments = array_slice( $argv, 1 );
$seocart_plugins   = preg_grep( '/^--plugin=/', $seocart_arguments );
$seocart_output    = static function ( string $line ): void {
	fwrite( STDOUT, $line . "\n" );
};

if ( array() === $seocart_plugins ) {
	// Every generator, in the order it runs: Generators::all() is the one list, which its test pins.
	exit( ( new DocsRunner( Generators::all( $seocart_root ), $seocart_root, $seocart_output ) )->main( $seocart_arguments ) );
}

$seocart_plugin_root = realpath( substr( (string) reset( $seocart_plugins ), strlen( '--plugin=' ) ) );

if ( false === $seocart_plugin_root || ! is_dir( $seocart_plugin_root ) ) {
	fwrite( STDERR, "generate-docs: --plugin must name an extension's root directory.\n" );
	exit( 2 );
}

/*
 * An extension registers its gateways from its main file with add_action(). WordPress is not
 * loaded here, so add_action() is defined to keep each listener of an action, and the main file
 * is included as WordPress would include it.
 */
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Keeps a listener of an action instead of adding it: WordPress is not loaded.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $hook_name     The action.
	 * @param callable $callback      The listener.
	 * @param int      $priority      Unused.
	 * @param int      $accepted_args Unused.
	 * @return true Always.
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress's own name, declared only because WordPress is not loaded here.
		unset( $priority, $accepted_args );
		$GLOBALS['seocart_docs_listeners'][ $hook_name ][] = $callback;

		return true;
	}
}

require $seocart_plugin_root . '/' . PluginPackage::at( $seocart_plugin_root )->mainFile;

$seocart_gateways = DeclarationRegistry::registeredBy( ...( $GLOBALS['seocart_docs_listeners'][ GatewayRegistry::ACTION ] ?? array() ) );
$seocart_runner   = new DocsRunner( Generators::forPlugin( $seocart_gateways ), $seocart_plugin_root, $seocart_output, 'php ../seocart/bin/generate-docs.php --plugin=.' );

exit( $seocart_runner->main( array_values( array_diff( $seocart_arguments, $seocart_plugins ) ) ) );
