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
use SEOCart\Catalog\Domain\SellabilityFacts;

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
 * lockForDelete() and delete(), its rows children first. What a product post's trash or delete
 * does is decided on lockByPost() and lockVariants(): a read that decides under a lock is a
 * locking read, which sees the newest committed rows, whatever the transaction's snapshot.
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
	 * Loads a product for its deletion, under its row lock: the first statement locks the product's row, and every read is a locking read.
	 *
	 * A save of the product holds the same lock for its whole window, so the product is read as
	 * the last save that committed left it, and no save can change it before the transaction ends.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function lockForDelete( int $productId ): ?Product;

	/**
	 * Loads the product a post is bound to, under the row locks of the post's binding and the product: the first statement takes both, and every read is a locking read.
	 *
	 * A save of the product holds its row lock for its whole window, so the product is read as the
	 * last save that committed left it, whatever the transaction read before.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function lockByPost( int $postId ): ?Product;

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
	 * the transaction that called lockForDelete().
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, or the product was never stored.
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::ProductNotFound when the product's row is gone.
	 *
	 * @param Product $product The product, as lockForDelete() loaded it.
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
}
