<?php
/**
 * TranslationGroups: keeps the posts that present a product in step with the post's translation group
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Lifecycle;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a post's translation group, as the multilingual setup keeps it, into the catalog's bindings: the posts of one group present one product.
 *
 * Owns one fact: what a change of a post's language or translation group means for the catalog.
 * The group of the product's source post decides:
 *
 * - A post no product is bound to joins the product its group presents, in its own locale; a
 *   post whose group presents none is reconciled to an `incomplete` product of its own.
 * - A post that left its source post's group no longer presents the product: it is unlinked,
 *   and then treated as a post no product is bound to. When its new group presents another
 *   product while its own holds commerce data, it is left as it is, a conflict: a post that
 *   presents a product with a SKU, price and stock is never moved onto another product.
 * - A post the setup records no language for has no group, and its binding is left as it is.
 * - A group has one owner, found by productOfGroup(), even while every product in it holds
 *   nothing: the product with commerce data, or else the product of the group's oldest-bound
 *   post. A post bound to a product that is not the owner joins the owner when its own product
 *   holds nothing, which is then deleted; otherwise it is left as it is, a conflict.
 * - A post whose product is the owner has its binding follow its locale; the group's other posts
 *   join the product in theirs, their own empty products deleted, and the product's posts that
 *   left the group are unlinked, the source post never.
 *
 * The source post is never chosen from a site's default language. A post that cannot join, as
 * one bound to another product with a SKU of its own, or one in a locale the product has a post
 * in already, is left as it is and reported once as `catalog.translation_conflict`.
 *
 * Without a multilingual plugin every group is its post alone, and only the first rule applies.
 *
 * @since 0.1.0
 */
final class TranslationGroups {

	/**
	 * Reads which product each post presents.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Tells a post's locale and translation group.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * Links and unlinks posts.
	 *
	 * @since 0.1.0
	 *
	 * @var TranslationBindings
	 */
	private TranslationBindings $bindings;

	/**
	 * Gives a post whose group presents no product an `incomplete` product of its own.
	 *
	 * @since 0.1.0
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Creates the service. Does nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository   $products   Reads which product each post presents.
	 * @param PostLocales         $locales    Tells a post's locale and translation group.
	 * @param TranslationBindings $bindings   Links and unlinks posts.
	 * @param Reconciler          $reconciler Gives an ungrouped post a product of its own.
	 * @param callable            $report     Receives a machine code (string) and its context (array).
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct( ProductRepository $products, PostLocales $locales, TranslationBindings $bindings, Reconciler $reconciler, callable $report ) {
		$this->products   = $products;
		$this->locales    = $locales;
		$this->bindings   = $bindings;
		$this->reconciler = $reconciler;
		$this->report     = $report;
	}

	/**
	 * Brings the bindings of a post, and of its translation group, in step with the group.
	 *
	 * Runs in the caller's transaction; each change runs in a nested one, which locks what it
	 * decides on. It reads the post's group afresh, so it can be called for any one post at any
	 * time, outside a request that wrote the post, as a repair of a binding that disagrees with
	 * the multilingual setup's group.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable What a change throws, other than the conflicts that are reported.
	 *
	 * @param int $postId A product post that was written, or whose group changed.
	 * @return bool True when the post presented a product before.
	 */
	public function reconcile( int $postId ): bool {
		$group   = $this->locales->translationsOf( $postId );
		$locale  = $group[ $postId ] ?? $this->locales->localeOf( $postId );
		$product = $this->products->findByPost( $postId );

		if ( null === $product ) {
			$this->bindUnbound( $postId, $locale ?? $this->locales->siteLocale(), $group );

			return false;
		}

		if ( null === $locale ) {
			// The setup records no language for the post, and so no group: its binding is left as it is.
			return true;
		}

		$productId = (int) $product->id();
		$source    = $product->sourcePostId();

		if ( null !== $source && $source !== $postId && ! isset( $group[ $source ] ) ) {
			// The post left the group of the product's source post.
			$owner = self::productOfGroup( $this->products, $group );

			if ( null !== $owner && $product->hasCommerceData() ) {
				// Moving it onto the group's product would take it from a product with a SKU, price and stock.
				$this->reportConflicts( $postId, array( $postId => CatalogError::PostBoundElsewhere->value ) );

				return true;
			}

			$this->bindings->unlink( $postId );
			$this->bindUnbound( $postId, $locale, $group );

			return true;
		}

		$owner     = self::productOfGroup( $this->products, $group );
		$conflicts = array();

		if ( null !== $owner && $owner !== $productId ) {
			// The group presents another product: the post joins it only if its own holds nothing.
			if ( $product->holdsNothing() ) {
				$this->attempt( $owner, $postId, $locale, $conflicts );
			} else {
				$conflicts[ $postId ] = CatalogError::PostBoundElsewhere->value;
			}

			$this->reportConflicts( $postId, $conflicts );

			return true;
		}

		$this->attempt( $productId, $postId, $locale, $conflicts );

		foreach ( $group as $member => $memberLocale ) {
			if ( $member !== $postId ) {
				$this->attempt( $productId, $member, $memberLocale, $conflicts );
			}
		}

		foreach ( $product->bindings() as $binding ) {
			if ( $binding->postId() !== $source && ! isset( $group[ $binding->postId() ] ) ) {
				$this->bindings->unlink( $binding->postId() );
			}
		}

		$this->reportConflicts( $postId, $conflicts );

		return true;
	}

	/**
	 * Binds a post no product is bound to: to the product its group presents, or to an `incomplete` product of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $postId The post.
	 * @param Locale             $locale Its locale.
	 * @param array<int, Locale> $group  Its translation group.
	 */
	private function bindUnbound( int $postId, Locale $locale, array $group ): void {
		$owner     = self::productOfGroup( $this->products, $group );
		$conflicts = array();

		if ( null !== $owner && $this->attempt( $owner, $postId, $locale, $conflicts ) ) {
			return;
		}

		$this->reportConflicts( $postId, $conflicts );
		$this->reconciler->reconcile( $postId );
	}

	/**
	 * Links a post to a product, and notes a conflict instead of failing when the post cannot join it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException A refusal other than a conflict of the post with the product.
	 *
	 * @param int                $productId The product.
	 * @param int                $postId    The post.
	 * @param Locale             $locale    Its locale.
	 * @param array<int, string> $conflicts The conflicts noted so far, by post: the code of each; one is added on a refusal.
	 * @return bool True when the post presents the product now.
	 */
	private function attempt( int $productId, int $postId, Locale $locale, array &$conflicts ): bool {
		try {
			$this->bindings->link( $productId, $postId, $locale );
		} catch ( CodedException $refused ) {
			if ( ! in_array( $refused->errorCode(), array( CatalogError::PostBoundElsewhere, CatalogError::LocaleTaken ), true ) ) {
				throw $refused;
			}

			$conflicts[ $postId ] = (string) $refused->errorCode()->value;

			return false;
		}

		return true;
	}

	/**
	 * Returns the product that owns a translation group: the one its posts present together.
	 *
	 * Only a product whose source post is in the group counts. A group has one owner even while
	 * every such product holds nothing, so that its posts never end up presenting two products:
	 *
	 * - a product with commerce data owns the group; the lowest id when there are several, the
	 *   others being conflicts;
	 * - otherwise, a product that holds more than its one post owns it, the lowest id when there
	 *   are several;
	 * - otherwise, the product of the group's oldest-bound post owns it, by the time the post was
	 *   bound, then by post id.
	 *
	 * The one rule a first save of a post in another language and the lifecycle both find a
	 * group's product by.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository  $products Reads which product each post presents.
	 * @param array<int, Locale> $group    The group: the locale of each post, by post id.
	 * @return int|null The owning product, or null when no post of the group is a product's source post.
	 */
	public static function productOfGroup( ProductRepository $products, array $group ): ?int {
		$selling = array();
		$holding = array();
		$empty   = array();

		foreach ( array_keys( $group ) as $member ) {
			$product = $products->findByPost( $member );
			$binding = $product?->bindingOf( $member );

			if ( null === $product || null === $binding || $member !== $product->sourcePostId() ) {
				continue;
			}

			if ( $product->hasCommerceData() ) {
				$selling[] = (int) $product->id();
			} elseif ( $product->holdsNothing() ) {
				$empty[] = array( $binding->linkedAt(), $member, (int) $product->id() );
			} else {
				$holding[] = (int) $product->id();
			}
		}

		foreach ( array( $selling, $holding ) as $owners ) {
			if ( array() !== $owners ) {
				sort( $owners );

				return $owners[0];
			}
		}

		if ( array() === $empty ) {
			return null;
		}

		usort( $empty, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

		return $empty[0][2];
	}

	/**
	 * Reports, once, the posts of a group that could not join its product.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $postId    The post whose change was reconciled.
	 * @param array<int, string> $conflicts The code of each refusal, by post.
	 */
	private function reportConflicts( int $postId, array $conflicts ): void {
		if ( array() === $conflicts ) {
			return;
		}

		ksort( $conflicts );

		( $this->report )(
			ReportCode::TranslationConflict->value,
			array(
				'post_id'   => $postId,
				'conflicts' => $conflicts,
			)
		);
	}
}
