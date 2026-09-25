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
 * - A post the setup records no language for has no group, and its binding is left as it is,
 *   whether the change is its own or another post's: reconciling one post of a product never
 *   unlinks another of its bindings whose post the setup currently gives no language.
 * - A group has one owner, found by productOfGroup(), even while every product in it holds
 *   nothing: the product with commerce data, or else the product of the group's oldest-bound
 *   post. A post bound to a product that is not the owner joins the owner when its own product
 *   holds nothing, which is then deleted; otherwise it is left as it is, a conflict.
 * - A post whose product is the owner has its binding follow its locale; the group's other posts
 *   join the product in theirs, their own empty products deleted, and the product's posts that
 *   left the group, and still have a language, are unlinked, the source post never.
 *
 * The source post is never chosen from a site's default language. A post that cannot join, as
 * one bound to another product with a SKU of its own, or one in a locale the product has a post
 * in already, is left as it is and reported once as `catalog.translation_conflict`.
 *
 * Without a multilingual plugin every group is its post alone, and only the first rule applies;
 * disagreement() answers null for every post then, since keepsGroups() is false.
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
	 * the multilingual setup's group. It reads the same facts disagreement() classifies a post
	 * by, so the two can never decide differently about what is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable What a change throws, other than the conflicts that are reported.
	 *
	 * @param int $postId A product post that was written, or whose group changed.
	 * @return bool True when the post presented a product before.
	 */
	public function reconcile( int $postId ): bool {
		$facts   = $this->facts( $postId );
		$product = $facts['product'];
		$locale  = $facts['locale'];
		$group   = $facts['group'];

		if ( null === $product ) {
			$this->bindUnbound( $postId, $locale ?? $this->locales->siteLocale(), $group );

			return false;
		}

		if ( null === $locale ) {
			// The setup records no language for the post, and so no group: its binding is left as it is.
			return true;
		}

		$productId = (int) $product->id();
		$owner     = $facts['owner'];

		if ( $facts['leftGroup'] ) {
			// The post left the group of the product's source post.
			if ( null !== $owner && $product->hasCommerceData() ) {
				// Moving it onto the group's product would take it from a product with a SKU, price and stock.
				$this->reportConflicts( $postId, array( $postId => CatalogError::PostBoundElsewhere->value ) );

				return true;
			}

			$this->bindings->unlink( $postId );
			$this->bindUnbound( $postId, $locale, $group );

			return true;
		}

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

		foreach ( $facts['unlinks'] as $stalePostId ) {
			$this->bindings->unlink( $stalePostId );
		}

		$this->reportConflicts( $postId, $conflicts );

		return true;
	}

	/**
	 * Tells whether the locale port keeps a translation group at all.
	 *
	 * The one place that decides: doctor's translation-group check calls this once, not post by
	 * post, before it walks anything, so a store without a multilingual plugin never has a post
	 * to disagree with a group it does not keep.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a real multilingual setup keeps translation groups.
	 */
	public function keepsGroups(): bool {
		return $this->locales->translatesPosts();
	}

	/**
	 * Tells why reconcile() would change a post's binding, without changing anything: doctor's
	 * translation-group check reads this, so it can never drift from what a repair actually does.
	 *
	 * Decided from the port, not the post: a locale port that keeps no translation group at all
	 * never disagrees with any binding, the way a store without a multilingual plugin, SiteLocale
	 * among them, never does — whatever languages() says, since a one-language multilingual setup
	 * still keeps real groups, and a binding's stored locale can still disagree with the language
	 * the plugin gives the post there. Given a real multilingual setup, a post it gives no
	 * language keeps its binding as it is, and an unbound post is doctor's row 4's concern, not
	 * this one's. A post whose own binding is otherwise right still disagrees when reconciling it
	 * would unlink another of its product's bindings, so the check reports it before a repair
	 * drops a binding it never listed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId A bound product post.
	 * @return string|null One sentence naming why it disagrees, or null when it agrees.
	 */
	public function disagreement( int $postId ): ?string {
		if ( ! $this->keepsGroups() ) {
			return null;
		}

		$facts   = $this->facts( $postId );
		$product = $facts['product'];
		$locale  = $facts['locale'];

		if ( null === $product || null === $locale ) {
			return null;
		}

		if ( $facts['leftGroup'] ) {
			return null !== $facts['owner'] && $product->hasCommerceData()
				? 'it left the group of its product\'s source post, but its product holds commerce data and is never moved off it'
				: 'it left the group of its product\'s source post';
		}

		$productId = (int) $product->id();
		$owner     = $facts['owner'];

		if ( null !== $owner && $owner !== $productId ) {
			return $product->holdsNothing()
				? 'its group\'s product is another one'
				: 'its group\'s product is another one, and its own product holds commerce data and is never moved';
		}

		$stored = $product->bindingOf( $postId )?->locale();

		if ( null !== $stored && ! $locale->equals( $stored ) ) {
			return 'its locale is not the language the plugin gives it now';
		}

		if ( array() !== $facts['unlinks'] ) {
			return sprintf(
				'reconciling it would also unlink post%s %s, which left the group',
				1 === count( $facts['unlinks'] ) ? '' : 's',
				implode( ', ', $facts['unlinks'] )
			);
		}

		return null;
	}

	/**
	 * Reads the facts a post's binding is judged by: its group, its locale, the product it
	 * presents, whether it left its source post's group, its group's owner, and the product's
	 * other bindings reconciling this post would unlink.
	 *
	 * The one read reconcile() and disagreement() both use, so a post is classified the same way
	 * whichever of them asks. A binding is only ever listed to unlink when its own post is still
	 * given a language: one the setup gives none keeps its binding, whoever else is reconciled.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array{product: ?\SEOCart\Catalog\Domain\Product, locale: ?Locale, group: array<int, Locale>, leftGroup: bool, owner: ?int, unlinks: list<int>} The facts.
	 */
	private function facts( int $postId ): array {
		$group   = $this->locales->translationsOf( $postId );
		$locale  = $group[ $postId ] ?? $this->locales->localeOf( $postId );
		$product = $this->products->findByPost( $postId );

		$leftGroup = false;
		$owner     = null;
		$unlinks   = array();

		if ( null !== $product && null !== $locale ) {
			$source    = $product->sourcePostId();
			$leftGroup = null !== $source && $source !== $postId && ! isset( $group[ $source ] );
			$owner     = self::productOfGroup( $this->products, $group );

			foreach ( $product->bindings() as $binding ) {
				$memberId = $binding->postId();

				if ( $memberId === $source || isset( $group[ $memberId ] ) ) {
					continue;
				}

				if ( null !== $this->locales->localeOf( $memberId ) ) {
					$unlinks[] = $memberId;
				}
			}
		}

		return array(
			'product'   => $product,
			'locale'    => $locale,
			'group'     => $group,
			'leftGroup' => $leftGroup,
			'owner'     => $owner,
			'unlinks'   => $unlinks,
		);
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
