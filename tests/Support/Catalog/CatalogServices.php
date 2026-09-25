<?php
/**
 * CatalogServices: the catalog's services over one test connection, sharing one post gateway
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\Lifecycle\DeleteProduct;
use SEOCart\Catalog\Application\Lifecycle\DuplicateProduct;
use SEOCart\Catalog\Application\Lifecycle\PostLifecycle;
use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\Lifecycle\TranslationBindings;
use SEOCart\Catalog\Application\Lifecycle\TranslationGroups;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Catalog\Infrastructure\WordPressPostGateway;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Tests\Support\KernelHooks;

/**
 * The product write and the product lifecycle as a test builds them, from ProductWrites::services().
 *
 * Owns one fact: how a test puts its product lifecycle where the kernel puts its own. attach()
 * takes the kernel's lifecycle callbacks off their three hooks and adds this lifecycle's through
 * the kernel's own Modules::catalogLifecycleHooks(), so the callbacks under test are the
 * production ones. The test framework puts every hook back after the test.
 *
 * Every service shares the one post gateway, so the lifecycle recognises the writes the plugin's
 * services make, exactly as in production.
 *
 * @since 0.1.0
 */
final readonly class CatalogServices {

	/**
	 * Holds the services.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlProductRepository $products   The repository.
	 * @param WordPressPostGateway   $posts      The one post gateway.
	 * @param StockService           $stock      The stock service.
	 * @param SaveProduct            $save       The product write.
	 * @param Reconciler             $reconciler The reconciler.
	 * @param DeleteProduct          $delete     The product delete.
	 * @param DuplicateProduct       $duplicate  The product copy.
	 * @param PostLifecycle          $lifecycle  The post lifecycle.
	 * @param TranslationBindings    $bindings   The commands that link, unlink and promote posts.
	 * @param TranslationGroups      $groups     What keeps the bindings in step with translation groups.
	 * @param PostLocales            $locales    The locale port every service shares.
	 */
	public function __construct(
		public MysqlProductRepository $products,
		public WordPressPostGateway $posts,
		public StockService $stock,
		public SaveProduct $save,
		public Reconciler $reconciler,
		public DeleteProduct $delete,
		public DuplicateProduct $duplicate,
		public PostLifecycle $lifecycle,
		public TranslationBindings $bindings,
		public TranslationGroups $groups,
		public PostLocales $locales
	) {
	}

	/**
	 * Hooks this lifecycle where the kernel hooks its own, in its place.
	 *
	 * @since 0.1.0
	 */
	public function attach(): void {
		$lifecycle = $this->lifecycle;

		KernelHooks::detach( 'wp_after_insert_post', 'transition_post_status', 'pre_delete_post' );

		Modules::catalogLifecycleHooks( static fn(): PostLifecycle => $lifecycle );
	}
}
