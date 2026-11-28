<?php
/**
 * Tests what a product's page costs the plugin: its three product blocks rendered together
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Storefront\Blocks;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\QueryLog;
use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * The price, the variant picker and the add-to-cart block of one product, rendered together, cost the plugin the same few statements whatever the number of its variants.
 *
 * A statement is counted when it names one of the plugin's tables or options; WordPress's own
 * reads are printed beside the count and not counted. The blocks share one read of the product:
 * one load of the product with its options and the variants it publishes, their prices included,
 * and one read of the units for sale of all its variants. A product of six variants and one of
 * 250 are measured, and must cost the same. Cold is a request on a host with no persistent object
 * cache: the object cache emptied but for the autoloaded options. Warm is the same request after
 * one has run, with what it left in the object cache: a host with a persistent one.
 *
 * Each measure renders the blocks over a container of their own, as a request builds one, so the
 * read a request keeps for its other blocks is not left over from an earlier measure. The site is
 * measured installed: its installation record, which activation writes and WordPress loads with
 * the other autoloaded options, is planted for the measures and put back afterwards, so reading
 * the exchange-rate version from it costs nothing, as on a real store.
 *
 * Planted violations, shown red and removed: in ProductPage::of(), read the view on every call
 * (the three blocks then cost three reads: COLD and WARM fail); in ProductPage::read(), ask for the
 * units for sale of each variant on its own (the 250-variant product then costs 249 statements
 * more: the equal-count assertions fail).
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class ProductPageBudgetTest extends ProductWriteTestCase {

	use SavesVariantSets;

	/**
	 * The plugin's statements of the three blocks on a cold request, as measured.
	 *
	 * The product bound to the post, its row, its bindings, its variants with their prices, its
	 * option axes with their values and the values its variants select: the one load. The store's
	 * international settings, for the base currency. The facts the sellability rule judges the
	 * variants on: its one fetch. The inventory settings and the list of locations, which the read of
	 * the units for sale needs, and that read. Eleven, the same on MySQL and on MariaDB.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const COLD = 11;

	/**
	 * The plugin's statements of the three blocks on a warm request, as measured.
	 *
	 * COLD's, less the two groups of settings and the list of locations, which the object cache
	 * holds: eight.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const WARM = 8;

	/**
	 * What a counted statement looks like: one that names a table or an option of the plugin's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const COUNTED = '/seocart_/';

	/**
	 * The kernel's registrations of the blocks, taken out by the test and put back after it.
	 *
	 * @since 0.2.0
	 *
	 * @var list<WP_Block_Type>
	 */
	private array $kernels = array();

	/**
	 * The installation record stored before the test planted one, or null when there was none; false until the test plants it.
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private mixed $recordBefore = false;

	/**
	 * Keeps the kernel's registrations of the blocks, to put back after the test.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( array_keys( Modules::STOREFRONT_BLOCKS ) as $name ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

			if ( $type instanceof WP_Block_Type ) {
				$this->kernels[] = $type;
			}
		}
	}

	/**
	 * Puts back the kernel's registrations of the blocks.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $this->kernels as $type ) {
			if ( $registry->is_registered( $type->name ) ) {
				$registry->unregister( $type->name );
			}

			$registry->register( $type );
		}

		$this->kernels = array();

		if ( null === $this->recordBefore ) {
			delete_option( BootOption::NAME );
		} elseif ( false !== $this->recordBefore ) {
			update_option( BootOption::NAME, $this->recordBefore );
		}

		$this->recordBefore = false;

		parent::tear_down();
	}

	/**
	 * Tests that the three blocks cost the pinned statements, cold and warm, the same for six variants and for 250.
	 *
	 * @since 0.2.0
	 */
	public function test_the_three_blocks_cost_the_same_few_statements_whatever_the_variants(): void {
		$six  = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'SIX', VariantSets::SIZES, VariantSets::GRINDS, array(), false ) )->postId;
		$many = $this->saveSet( null, VariantSets::options( self::keys( 's', 10 ), self::keys( 'g', 25 ) ), VariantSets::variants( 'MANY', self::keys( 's', 10 ), self::keys( 'g', 25 ), array(), false ) )->postId;

		$this->plantInstalledRecord();

		$measures = array(
			'six variants, cold' => $this->measure( $six, true ),
			'six variants, warm' => $this->measure( $six, false ),
			'250 variants, cold' => $this->measure( $many, true ),
			'250 variants, warm' => $this->measure( $many, false ),
		);

		foreach ( $measures as $name => $log ) {
			self::report( $name, $log );
		}

		$counted = array_map( static fn( QueryLog $log ): int => $log->matching( self::COUNTED )->count(), $measures );

		$this->assertSame( $counted['six variants, cold'], $counted['250 variants, cold'], 'A product of 250 variants costs another number of statements cold than one of six.' );
		$this->assertSame( $counted['six variants, warm'], $counted['250 variants, warm'], 'A product of 250 variants costs another number of statements warm than one of six.' );
		$this->assertSame( self::COLD, $counted['six variants, cold'], 'COLD: ' . $measures['six variants, cold']->matching( self::COUNTED )->describe() );
		$this->assertSame( self::WARM, $counted['six variants, warm'], 'WARM: ' . $measures['six variants, warm']->matching( self::COUNTED )->describe() );
	}

	/**
	 * Renders the three blocks of a product's page together, over a container of their own, and returns the statements sent.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $postId The product's post.
	 * @param bool $cold   Whether the object cache is emptied first, but for the autoloaded options.
	 * @return QueryLog The statements.
	 */
	private function measure( int $postId, bool $cold ): QueryLog {
		$this->registerOver( KernelContainer::build( $this->db, $this->reporter() ) );

		if ( $cold ) {
			wp_cache_flush();
			wp_load_alloptions();
		}

		$GLOBALS['post'] = get_post( $postId ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page's post, as the main query sets it.

		return $this->captureQueries(
			static function (): void {
				foreach ( array_keys( Modules::STOREFRONT_BLOCKS ) as $name ) {
					render_block(
						array(
							'blockName'    => $name,
							'attrs'        => array(),
							'innerBlocks'  => array(),
							'innerHTML'    => '',
							'innerContent' => array(),
						)
					);
				}
			}
		);
	}

	/**
	 * Stores an installation record, as activation does, keeping what was stored to put back.
	 *
	 * @since 0.2.0
	 */
	private function plantInstalledRecord(): void {
		$this->recordBefore = get_option( BootOption::NAME, null );

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn( BootRecord $record ): BootRecord => $record->isAbsent() ? KernelTestCase::installedRecord( KernelTestCase::codeHead() ) : $record );
	}

	/**
	 * Registers the blocks again, over a container of their own.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $container The container the renderers are resolved from.
	 */
	private function registerOver( Container $container ): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array_keys( Modules::STOREFRONT_BLOCKS ) as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}

		Blocks::register( Modules::STOREFRONT_BLOCKS, $container );
	}

	/**
	 * Returns a number of value keys, such as `s0` to `s9`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $prefix The keys' prefix.
	 * @param int    $count  How many.
	 * @return list<string> The keys.
	 */
	private static function keys( string $prefix, int $count ): array {
		return array_map( static fn( int $at ): string => $prefix . $at, range( 0, $count - 1 ) );
	}

	/**
	 * Prints a measure's statements, the counted ones marked, for the report: WordPress's own by their beginning only, which names what they read without any value they carry.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name What was measured.
	 * @param QueryLog $log  The statements.
	 */
	private static function report( string $name, QueryLog $log ): void {
		$lines = array( sprintf( "\nThe three blocks, %s: %d counted statements, %d in all.", $name, $log->matching( self::COUNTED )->count(), $log->count() ) );

		foreach ( $log->sqls() as $index => $sql ) {
			$mark    = 1 === preg_match( self::COUNTED, $sql ) ? '*' : ' ';
			$lines[] = sprintf( '  %3d %s %s', $index + 1, $mark, substr( $sql, 0, '*' === $mark ? 200 : 70 ) );
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
