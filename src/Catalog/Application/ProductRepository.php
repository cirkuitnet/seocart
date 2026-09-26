<?php
/**
 * ProductRepository: where products are stored and read, and the one fetch the sellability rule reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and loads the Product aggregate, and reads the facts every sellability verdict is made from.
 *
 * Owns one fact: the contract of product storage as the catalog's services see it. A variant, a
 * price and a binding are written only through their product, in the caller's unit of work;
 * there is no separate variant repository. The generation marker is written by save() only when
 * it creates a product. After that it changes only through markUpdating(), relock(),
 * leaveUpdating() and restoreMark(), the statements a product write relies on for its recovery,
 * and sellabilityFacts() is the one read of it that decides a sale. A product is deleted with
 * lock() and delete(), its rows children first. What a product post's trash or delete
 * does is decided on lockByPost() and lockVariants(): a read that decides under a lock is a
 * locking read, which sees the newest committed rows, whatever the transaction's snapshot. A
 * product's bindings change through addBinding(), moveBinding(), removeBinding() and
 * promoteSource(), under the lock lockWithPost() takes, and never leave a product with two
 * source bindings or none.
 *
 * Every path that locks catalog rows takes them in one order: product rows first, in ascending
 * id order when there are several, then `product_posts` rows, then variants; the inventory's rows
 * after the catalog's. A binding is changed only while its product's row is locked, so a binding
 * read under that lock stays as read until the transaction ends.
 *
 * @since 0.1.0
 */
interface ProductRepository {

	/**
	 * Loads a product by its id.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function find( int $productId ): ?Product;

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function findByPost( int $postId ): ?Product;

	/**
	 * Loads the product whose source binding is a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when no product has that source post.
	 */
	public function findBySourcePost( int $postId ): ?Product;

	/**
	 * Loads the product a variant belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant's id.
	 * @return Product|null The product, or null when no variant has that id.
	 */
	public function findByVariant( int $variantId ): ?Product;

	/**
	 * Writes a product with its bindings, its default variant and that variant's base-currency price, and gives it its ids.
	 *
	 * A new product is created with its marker; a stored one keeps the marker it has. Bindings
	 * the product gained are added. A base price the variant no longer has is removed. Run it
	 * inside the caller's transaction: it writes several rows.
	 *
	 * @since 0.1.0
	 *
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::SkuTaken when another variant
	 *         holds the SKU, never as a duplicate-key error of the database; a DatabaseException for
	 *         any other failure, a post already bound to another product among them.
	 *
	 * @param Product $product The product.
	 */
	public function save( Product $product ): void;

	/**
	 * Marks a product `updating`, whatever its marker was, and returns the marker it replaced and the instant of the mark.
	 *
	 * Run it inside the transaction that commits the mark: the marker is read under the row lock
	 * the mark then takes, and the instant is read back under that lock, to the microsecond, so
	 * it names this mark and no later one; every instant is later than the row's last one. A write
	 * that is rolled back hands both to restoreMark().
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return UpdatingMark|null The mark; null when there is no such product.
	 */
	public function markUpdating( int $productId ): ?UpdatingMark;

	/**
	 * Marks a product `updating` again as the first statement of a write's window, which takes the product's row lock.
	 *
	 * Two windows of one product serialise on this lock, even should the product's named lock
	 * that saves take turns on be lost. It writes the mark again, with a new
	 * instant, so a window that is committed by anyone, even a listener that ends the
	 * transaction early, leaves the product `updating` rather than under another save's
	 * settled marker, and leaves a mark restoreMark() cannot mistake for the one made before
	 * the window. A rollback of the window undoes it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return bool True when the product exists and was marked; false when there is no such product.
	 */
	public function relock( int $productId ): bool;

	/**
	 * Settles a product's marker at the end of a write: one conditional statement, which changes the marker only while it is `updating`.
	 *
	 * Only the write that holds the product's row lock settles it, so no other save's mark can be
	 * waiting on the row; the answer says whether the marker was still `updating`.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $to        The marker the write settled on.
	 * @return bool True when the product was `updating` and now has the marker; false otherwise.
	 */
	public function leaveUpdating( int $productId, GenerationState $to ): bool;

	/**
	 * Puts back the marker a product had before a write whose transaction was rolled back: one conditional statement.
	 *
	 * It changes the marker only while it is still this write's own mark: `updating`, written at
	 * the instant markUpdating() returned. Once the write's window has ended, another save may
	 * have marked the product in its turn; that mark stays, because the other save's recovery
	 * depends on it, and the answer is false.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $before    The marker the product had before the write marked it.
	 * @param string          $markedAt  The instant markUpdating() returned for this write's mark.
	 * @return bool True when this write's mark was still there and the marker is put back; false otherwise.
	 */
	public function restoreMark( int $productId, GenerationState $before, string $markedAt ): bool;

	/**
	 * Loads a product under its row lock: the first statement locks the product's row, and every read is a locking read.
	 *
	 * A save of the product holds the same lock for its whole window, and every change of its
	 * bindings takes it first, so the product is read as the last write that committed left it,
	 * and nothing changes it before the transaction ends. Its deletion begins here.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function lock( int $productId ): ?Product;

	/**
	 * Loads the product a post is bound to under its row lock, as lockWithPost() locks it, every read a locking read.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function lockByPost( int $postId ): ?Product;

	/**
	 * Locks the rows of some products and of the product a post is bound to, in ascending order of id, then the post's binding, and loads each product with locking reads.
	 *
	 * The product rows come first, the order every path that locks catalog rows takes; the product
	 * a post presents is named by a read that takes no lock, and the post's binding, read again
	 * under the locks with a locking read, confirms it. A binding that moved in between is followed
	 * to its product, a few times at most. A save holds its product's row lock for its whole
	 * window, and a binding changes only under its product's, so every product is read as the last
	 * write that committed left it, whatever the transaction read before.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId        The post.
	 * @param int ...$productIds Other products to lock with it.
	 * @return array<int, Product> The products that exist, by id: the post's, when it presents one, among them.
	 */
	public function lockWithPost( int $postId, int ...$productIds ): array;

	/**
	 * Locks the bindings of some posts, in ascending order of post id, and returns the product each presents now: a locking read, which sees every binding committed so far.
	 *
	 * Taken after the products' rows, as the lock order has it: a caller that locked the products
	 * it planned to change reads here whether the posts it decided on still present those.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int ...$postIds The posts.
	 * @return array<int, int> The product each post presents, by post id; a post that presents none is left out.
	 */
	public function lockBindings( int ...$postIds ): array;

	/**
	 * Locks every variant of a product until the transaction ends, and returns their ids: a locking read, which sees every variant committed so far.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return list<int> The variants' ids, ascending, of every generation.
	 */
	public function lockVariants( int $productId ): array;

	/**
	 * Deletes a product's rows, children first: its variants' prices, its variants, its bindings, then the product itself.
	 *
	 * Every variant of the product goes, of every generation, whatever the aggregate loaded; their
	 * ids and SKUs are read with a locking read, which sees the newest committed rows. Run it inside
	 * the transaction that locked the product.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, or the product was never stored.
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::ProductNotFound when the product's row is gone.
	 *
	 * @param Product $product The product, as lock() or lockWithPost() loaded it.
	 * @return array<int, string> The SKU of each variant deleted, by the variant's id, ascending.
	 */
	public function delete( Product $product ): array;

	/**
	 * Reads, in one query, the facts Sellability judges each variant on.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists, in no particular order;
	 *                                none for an id no variant has.
	 */
	public function sellabilityFacts( int ...$variantIds ): array;

	/**
	 * Reads, in one query, the facts Sellability judges each variant on in one locale: the facts of the product's post in that locale.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale        The locale.
	 * @param int    ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists, in no particular order; none for an id no
	 *                                variant has. A product with no post in the locale is marked not translated.
	 */
	public function sellabilityFactsIn( Locale $locale, int ...$variantIds ): array;

	/**
	 * Reads, in one query, the prices a merchant authored for some variants in some currencies.
	 *
	 * One row per variant and currency that has a price, found through the `(variant_id,
	 * currency)` key; a variant or a currency without a price has no row. A price in one currency
	 * is never derived from another here.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $variantIds    The variants' ids.
	 * @param Currency ...$currencies The currencies.
	 * @return list<array{variantId: int, price: VariantPrice, taxClassId: int|null}> The prices, in no particular order, each with its tax class, or null for none.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function explicitPrices( array $variantIds, Currency ...$currencies ): array;

	/**
	 * Moves a post's binding to another locale: one statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::LocaleTaken when the product has a post in that locale.
	 *
	 * @param int    $postId The post.
	 * @param Locale $locale The locale.
	 * @return bool True when the binding moved; false when the post presents no product, or has that locale already.
	 */
	public function moveBinding( int $postId, Locale $locale ): bool;

	/**
	 * Binds a post to a product, in a locale: one row.
	 *
	 * @since 0.1.0
	 *
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::PostBoundElsewhere when the post is bound to
	 *         a product already, or CatalogError::LocaleTaken when the product has a post in that locale.
	 *
	 * @param int                $productId The product's id.
	 * @param ProductPostBinding $binding   The binding.
	 */
	public function addBinding( int $productId, ProductPostBinding $binding ): void;

	/**
	 * Removes a post's binding to a product, unless it is the product's source binding: one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @param int $postId    The post.
	 * @return bool True when the binding was removed; false when there was none, or it is the source binding.
	 */
	public function removeBinding( int $productId, int $postId ): bool;

	/**
	 * Moves a product's source binding from one of its posts to another: one conditional statement, which changes nothing unless the source is still that post.
	 *
	 * With a post named, it becomes the source only when it is one of the product's bindings. With
	 * none, the product's oldest published binding other than the current source becomes it, or
	 * the oldest remaining one when none is published, the oldest by the time it was bound, then
	 * by post id.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $productId  The product's id.
	 * @param int      $fromPostId The post that must be the source now.
	 * @param int|null $toPostId   The post to promote, or null for the oldest remaining binding as above.
	 * @return int|null The new source post, or null when nothing changed: the source was another post by then, or no binding could take its place.
	 */
	public function promoteSource( int $productId, int $fromPostId, ?int $toPostId ): ?int;

	// -------------------------------------------------------------------------------------------
	// Doctor's reads and conditional writes. Every list is a page, ascending by id, bounded
	// by $limit; a caller pages with the last id it saw as the next $afterId.
	// -------------------------------------------------------------------------------------------

	/**
	 * Lists products with no `product_posts` row at all (doctor check 1).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function unboundProductIds( int $afterId, int $limit ): array;

	/**
	 * Lists products whose source binding is invalid (doctor check 2): `source_post_id` is NULL,
	 * names no `product_posts` row of that product, or names a post that is missing or is not a
	 * product post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function invalidSourceBindings( int $afterId, int $limit ): array;

	/**
	 * Lists `product_posts` rows whose post no longer exists (doctor check 3), the row named by
	 * its product's `source_post_id` excluded: that one is check 2's to report.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{post_id: int, product_id: int}> The bindings.
	 */
	public function danglingBindings( int $limit ): array;

	/**
	 * Deletes one dangling binding (doctor --repair, check 3): re-states, in the statement itself,
	 * that the post is still missing and that the row is still not the product's source binding.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The binding's post.
	 * @param int $productId The binding's product.
	 * @return bool True when the row was deleted; false when it no longer matched (the post came
	 *              back, or it had become the source binding) — nothing to do, not an error.
	 */
	public function deleteDanglingBinding( int $postId, int $productId ): bool;

	/**
	 * Lists product posts (not `auto-draft`) with no `product_posts` row (doctor check 4).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only posts above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The post ids, ascending.
	 */
	public function unboundPostIds( int $afterId, int $limit ): array;

	/**
	 * Locks a post's row and returns its type and status, current: never through WordPress's
	 * post cache, which a concurrent write can leave stale. Must run inside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array{type: string, status: string}|null The post's type and status, or null when the row is gone.
	 */
	public function lockedPostTypeAndStatus( int $postId ): ?array;

	/**
	 * Lists `product_posts` bindings, every bound post with the product it presents and the
	 * locale its binding names (doctor check 11).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only bindings whose post is above this id.
	 * @param int $limit   The most to list.
	 * @return list<array{post_id: int, product_id: int, locale: string}> The bindings, ascending by post id.
	 */
	public function boundPostBindings( int $afterId, int $limit ): array;

	/**
	 * Lists products at their active generation with zero enabled variants, or whose default
	 * variant has no price in the base currency (doctor check 5).
	 *
	 * @since 0.1.0
	 *
	 * @param string $baseCurrency The store's base currency code.
	 * @param int    $afterId      Only products above this id.
	 * @param int    $limit        The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function incompleteMismatchIds( string $baseCurrency, int $afterId, int $limit ): array;

	/**
	 * Lists products left `updating` for longer than the given threshold (doctor check 7).
	 *
	 * @since 0.1.0
	 *
	 * @param string $before The threshold instant (UTC, `Y-m-d H:i:s.u`): only products marked
	 *                       before it.
	 * @param int    $limit  The most to list.
	 * @return list<array{product_id: int, updated_at: string}> The products, with the exact
	 *                                                           `updated_at` a repair must match.
	 */
	public function stuckUpdating( string $before, int $limit ): array;

	/**
	 * Lists `variants` rows whose product no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{variant_id: int, product_id: int}> The orphans.
	 */
	public function orphanVariants( int $limit ): array;

	/**
	 * Lists `variant_prices` rows whose variant no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{price_id: int, variant_id: int}> The orphans.
	 */
	public function orphanPrices( int $limit ): array;

	/**
	 * Counts products marked `incomplete` (doctor check 10).
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function incompleteCount(): int;

	/**
	 * Lists every variant's id whose product still exists, whatever its generation (doctor check
	 * 6). A variant whose product is gone is row 8's, reported there and never here: giving it a
	 * stock item would not make it any less orphaned.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only variants above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function variantIds( int $afterId, int $limit ): array;

	/**
	 * Filters a list of variant ids to the ones that still have a `variants` row, whatever their
	 * product (the reverse line: a stock item whose variant is gone tests the variant alone).
	 *
	 * @since 0.1.0
	 *
	 * @param array $variantIds The ids to test.
	 * @return list<int> The ones that exist.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function variantsExisting( array $variantIds ): array;

	/**
	 * Reloads a product under its row lock, for a repair that must re-check the defect before it
	 * writes: locking reads of the product row, its bindings and its default variant with its
	 * base-currency price — the same shape load() returns, plus the exact stored marker and
	 * `updated_at` the repair's compare-and-set must match.
	 *
	 * Run inside the caller's transaction, after the caller holds the product's named lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return array{product: Product, state: GenerationState, updatedAt: string}|null The reload,
	 *              or null when the product no longer exists.
	 */
	public function reloadUnderLock( int $productId ): ?array;

	/**
	 * Settles a product's marker, only while it still has exactly the state (and, when given, the
	 * exact `updated_at`) the caller read: one conditional `UPDATE`, doctor --repair's compare-
	 * and-set for checks 5 and 7. A save that changed the product since the read wins; 0 rows
	 * changed is "nothing to do", not an error.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId       The product's id.
	 * @param GenerationState $from            The marker the repair read.
	 * @param GenerationState $to              The marker settle() computed.
	 * @param string|null     $updatedAtMatch  Optional. When given, also requires this exact
	 *                                         `updated_at` (check 7's extra guard against a save
	 *                                         that re-marked the product in the meantime).
	 * @return bool True when the row still matched and was changed.
	 */
	public function settleIfUnchanged( int $productId, GenerationState $from, GenerationState $to, ?string $updatedAtMatch = null ): bool;
}
