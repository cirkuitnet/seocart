<?php
/**
 * RendersProductBlocks: renders the storefront blocks over a container of a test's own
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Storefront\Blocks;
use SEOCart\Platform\Storefront\StorefrontAssets;
use SEOCart\Tests\Support\KernelContainer;
use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * Registers the storefront blocks again over a container a test builds, renders them for a post, and puts the kernel's registrations back.
 *
 * Owns one fact: how a test renders the storefront blocks as a request does. The container is the
 * production wiring over the test's connection, so each render starts with nothing kept from an
 * earlier one, as a request does; its asset loader reads a build of the test's own, a plugin
 * directory in the system's temporary directory holding the three .asset.json files, so a
 * checkout without a build renders the blocks as one with a build. Each render starts the
 * page's Interactivity data afresh, and the storefront's modules and stylesheet unregistered.
 *
 * A test using it calls restoreProductBlocks() in its tear_down().
 *
 * @since 0.2.0
 */
trait RendersProductBlocks {

	/**
	 * The kernel's registrations of the blocks, kept to put back.
	 *
	 * @since 0.2.0
	 *
	 * @var list<WP_Block_Type>
	 */
	private array $kernelBlocks = array();

	/**
	 * The suite's Interactivity API, while a test renders over one of its own.
	 *
	 * @since 0.2.0
	 *
	 * @var \WP_Interactivity_API|null
	 */
	private ?\WP_Interactivity_API $interactivity = null;

	/**
	 * The test's plugin directory, once made.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $builtPlugin = '';

	/**
	 * Registers the blocks over a new container and starts the page afresh.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db     The connection the container's services use.
	 * @param callable $report Receives every report: a code (string) and its context (array).
	 * @return Container The container the blocks render over.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	protected function renderOver( Database $db, callable $report ): Container {
		$container = KernelContainer::build(
			$db,
			$report,
			array( StorefrontAssets::class => fn(): StorefrontAssets => new StorefrontAssets( $this->builtPlugin(), $report ) )
		);
		$registry  = WP_Block_Type_Registry::get_instance();
		$first     = array() === $this->kernelBlocks;

		foreach ( array_keys( Modules::STOREFRONT_BLOCKS ) as $name ) {
			$type = $registry->get_registered( $name );

			if ( null !== $type ) {
				if ( $first ) {
					$this->kernelBlocks[] = $type;
				}

				$registry->unregister( $name );
			}
		}

		Blocks::register( Modules::STOREFRONT_BLOCKS, $container );

		$this->interactivity       ??= wp_interactivity();
		$GLOBALS['wp_interactivity'] = new \WP_Interactivity_API(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A page of its own; the suite's is put back after the test.

		foreach ( array_keys( StorefrontAssets::MODULES ) as $id ) {
			wp_script_modules()->dequeue( $id );
			wp_script_modules()->deregister( $id );
		}

		wp_dequeue_style( StorefrontAssets::STYLE );
		wp_deregister_style( StorefrontAssets::STYLE );

		return $container;
	}

	/**
	 * Renders blocks with a post in their context, as a page of that post does.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $postId The post.
	 * @param string ...$names The blocks, in order.
	 * @return string Their markup, one after the other.
	 */
	protected function renderBlocks( int $postId, string ...$names ): string {
		$GLOBALS['post'] = get_post( $postId ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page's post, as the main query sets it.

		$markup = '';

		foreach ( $names as $name ) {
			$markup .= render_block(
				array(
					'blockName'    => $name,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		}

		return $markup;
	}

	/**
	 * Returns what the page sends the Interactivity API's stores: their configuration and their state, as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @return string The data.
	 */
	protected function interactivityData(): string {
		return (string) wp_json_encode( wp_interactivity()->filter_script_module_interactivity_data( array() ) );
	}

	/**
	 * Puts the kernel's registrations of the blocks back, and removes the test's plugin directory.
	 *
	 * @since 0.2.0
	 */
	protected function restoreProductBlocks(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $this->kernelBlocks as $type ) {
			if ( $registry->is_registered( $type->name ) ) {
				$registry->unregister( $type->name );
			}

			$registry->register( $type );
		}

		$this->kernelBlocks = array();

		if ( null !== $this->interactivity ) {
			$GLOBALS['wp_interactivity'] = $this->interactivity; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The suite's, put back.
			$this->interactivity         = null;
		}

		foreach ( array_keys( StorefrontAssets::MODULES ) as $id ) {
			wp_script_modules()->dequeue( $id );
			wp_script_modules()->deregister( $id );
		}

		wp_dequeue_style( StorefrontAssets::STYLE );
		wp_deregister_style( StorefrontAssets::STYLE );

		if ( '' !== $this->builtPlugin ) {
			$directory = dirname( $this->builtPlugin );

			foreach ( (array) glob( $directory . '/build/blocks/*' ) as $file ) {
				unlink( (string) $file );
			}

			rmdir( $directory . '/build/blocks' );
			rmdir( $directory . '/build' );
			unlink( $this->builtPlugin );
			rmdir( $directory );

			$this->builtPlugin = '';
		}

		unset( $GLOBALS['post'] );
	}

	/**
	 * Returns the main file of the test's plugin directory, making the directory and its build's lists the first time.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file.
	 */
	private function builtPlugin(): string {
		if ( '' === $this->builtPlugin ) {
			$directory = (string) tempnam( sys_get_temp_dir(), 'seocart-blocks-' );

			unlink( $directory );
			mkdir( $directory . '/build/blocks', 0700, true );

			$lists = array(
				'blocks/store'      => array( '@wordpress/interactivity' ),
				'blocks/product'    => array( '@seocart/store', '@wordpress/interactivity' ),
				'blocks/storefront' => array(),
			);

			foreach ( $lists as $file => $dependencies ) {
				file_put_contents(
					$directory . '/build/' . $file . '.asset.json',
					(string) wp_json_encode(
						array(
							'dependencies' => $dependencies,
							'version'      => 'test',
						)
					)
				);
			}

			$this->builtPlugin = $directory . '/seocart.php';

			touch( $this->builtPlugin );
		}

		return $this->builtPlugin;
	}
}
