<?php
/**
 * StorefrontAssets: loads the storefront's script modules and its stylesheet for a block that renders
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues a storefront script module, or the storefront's stylesheet, the first time a rendering block asks for it in a request.
 *
 * Owns one fact: how a storefront file is loaded. Each file comes with the build's list of its
 * dependencies and its version, written beside it as JSON (`<file>.asset.json`); WordPress reads
 * only a PHP list beside a file a block.json names, which the plugin does not ship, so no
 * block.json names a file and the renderers ask here instead, while their block renders. Nothing
 * is registered before a block renders, so a page that renders no storefront block reads no asset
 * file and loads none of the plugin's bytes.
 *
 * A module that imports another of the storefront's modules gets it registered first, so the
 * page's import map names it, whether or not a block asked for it. A missing or unreadable list is
 * reported, and the file is not loaded: the block still renders its markup, which reads without
 * a script.
 *
 * @since 0.2.0
 */
final class StorefrontAssets {

	/**
	 * The storefront's script modules: each module's id, with the file it is built to under build/, without its extension.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const MODULES = array(
		'@seocart/store'   => 'blocks/store',
		'@seocart/product' => 'blocks/product',
	);

	/**
	 * The stylesheet's handle.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STYLE = 'seocart-storefront';

	/**
	 * The file the stylesheet is built to under build/, without its extension.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STYLESHEET = 'blocks/storefront';

	/**
	 * The main plugin file, which the files' addresses and paths are resolved from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $pluginFile;

	/**
	 * Receives a report: its code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * What was asked for in this request, by module id or by the stylesheet's handle: true when it was registered.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, bool>
	 */
	private array $registered = array();

	/**
	 * Creates the loader. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $pluginFile The main plugin file.
	 * @param callable $report     Receives a report: a code (string) and its context (array).
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct( string $pluginFile, callable $report ) {
		$this->pluginFile = $pluginFile;
		$this->report     = $report;
	}

	/**
	 * Loads a storefront script module: registered with its dependencies and version, the first time it is asked for, and enqueued.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not one of MODULES: a programming error.
	 *
	 * @param string $id The module's id, one of MODULES.
	 */
	public function module( string $id ): void {
		if ( $this->registerModule( $id ) ) {
			wp_enqueue_script_module( $id );
		}
	}

	/**
	 * Loads the storefront's stylesheet: registered with its version, the first time it is asked for, and enqueued.
	 *
	 * @since 0.2.0
	 */
	public function style(): void {
		if ( ! isset( $this->registered[ self::STYLE ] ) ) {
			$asset = $this->asset( self::STYLESHEET );

			if ( null !== $asset ) {
				wp_register_style( self::STYLE, plugins_url( 'build/' . self::STYLESHEET . '.css', $this->pluginFile ), array(), $asset['version'] );
			}

			$this->registered[ self::STYLE ] = null !== $asset;
		}

		if ( $this->registered[ self::STYLE ] ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Registers a module, and first the storefront's modules it imports, once a request.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not one of MODULES.
	 *
	 * @param string $id The module's id.
	 * @return bool Whether it is registered: false when its list could not be read.
	 */
	private function registerModule( string $id ): bool {
		if ( ! isset( self::MODULES[ $id ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'No storefront script module has the id "%s".', esc_html( $id ) ) );
		}

		if ( isset( $this->registered[ $id ] ) ) {
			return $this->registered[ $id ];
		}

		$asset = $this->asset( self::MODULES[ $id ] );

		$this->registered[ $id ] = null !== $asset;

		if ( null === $asset ) {
			return false;
		}

		foreach ( $asset['dependencies'] as $dependency ) {
			$imported = is_array( $dependency ) ? $dependency['id'] : $dependency;

			if ( isset( self::MODULES[ $imported ] ) ) {
				$this->registerModule( $imported );
			}
		}

		wp_register_script_module( $id, plugins_url( 'build/' . self::MODULES[ $id ] . '.js', $this->pluginFile ), $asset['dependencies'], $asset['version'] );

		return true;
	}

	/**
	 * Reads the build's list of a file's dependencies and its version.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file under build/, without its extension.
	 * @return array{dependencies: list<string|array{id: string, import?: string}>, version: string}|null The list, or null after reporting why it could not be read.
	 */
	private function asset( string $file ): ?array {
		$path = plugin_dir_path( $this->pluginFile ) . 'build/' . $file . '.asset.json';
		// A local file the build wrote, read whole; decoded here, so a malformed one is reported and raises no notice.
		$asset = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin's own build, never a URL.

		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) || ! is_string( $asset['version'] ?? null ) ) {
			( $this->report )( ReportCode::AssetUnreadable->value, array( 'file' => 'build/' . $file . '.asset.json' ) );

			return null;
		}

		return array(
			'dependencies' => array_values( $asset['dependencies'] ),
			'version'      => $asset['version'],
		);
	}
}
