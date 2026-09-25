<?php
/**
 * MysqlProductRepository: products in the catalog's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Stores products in `products`, `product_posts`, `variants` and `variant_prices`, through Database.
 *
 * Owns one fact: how the Product aggregate maps onto the catalog's rows, and the one query the
 * sellability rule is fed by. It is the only class that reads or writes `generation_state`, and
 * it decides a sale on it in one place, sellabilityFacts(); the other reads load the marker as
 * state, and the only statements that change it are the creation of a product, markUpdating(),
 * relock(), leaveUpdating() and restoreMark(). Every write of a product's `updated_at` moves it
 * strictly forward, so the instant of a mark identifies that mark. A deletion takes the product's
 * row lock first, as a save's window does, reads the product with locking reads, then removes its
 * rows children first.
 *
 * Every statement that locks catalog rows takes them in one order, whichever path sends it:
 *
 * 1. `products` rows, in ascending order of id when there are several: a save's mark and relock,
 *    lock(), and lockWithPost() and lockByPost(), which name a post's product with a plain read,
 *    lock it, and only then read the post's binding again with a locking read;
 * 2. `product_posts` rows: the bindings a locking load reads, and every binding written, always
 *    under its product's row lock, so a binding read under that lock stays as read;
 * 3. `variants` and `variant_prices` rows: the default variant a locking load reads,
 *    lockVariants(), a save's variant and price writes, and delete();
 * 4. the inventory's rows, which its own service locks, after the catalog's.
 *
 * A binding that moved to another product between lockWithPost()'s plain read and its lock is
 * followed to that product, whose row is then locked out of that order; the unit of work's
 * deadlock retry covers that rare case, and a locking read decides every time.
 *
 * A SKU collision is the `sku` key doing its job. When a variant write breaks a unique key, a
 * locking read asks whether another variant holds the SKU; a locking read sees the newest
 * committed row, whatever this transaction's snapshot is. If one does, the failure is
 * `catalog.sku_taken`; any other duplicate is a fault and stays the database's.
 *
 * A price is read and written in the store's base currency, which is asked for when it is needed.
 *
 * @since 0.1.0
 */
final class MysqlProductRepository implements ProductRepository {

	/**
	 * The format of a DATETIME column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DATETIME = 'Y-m-d H:i:s';

	/**
	 * The status of a published post, which a promotion prefers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PUBLISHED = 'publish';

	/**
	 * The instant a statement writes into a product's `updated_at`: now, or one microsecond after the row's last instant when that is later.
	 *
	 * A marker is identified by its instant, so no two writes of one row may share one: two
	 * statements in the same microsecond, or a database clock that steps back, would otherwise
	 * let a restore take a newer mark for its own.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NEXT_INSTANT = 'GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND )';

	/**
	 * What turns a read into a locking read: it takes the rows' locks until the transaction ends, and reads the newest committed rows, whatever the transaction's snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LOCKING = ' FOR UPDATE';

	/**
	 * How often lockWithPost() follows a binding that moved to another product while it locked: a binding moves only under its product's lock, so twice is already rare.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LOCK_ATTEMPTS = 3;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db           The connection.
	 * @param callable $baseCurrency Returns the store's base currency (a Currency), when a price is read or written.
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 */
	public function __construct( Database $db, callable $baseCurrency ) {
		$this->db           = $db;
		$this->baseCurrency = $baseCurrency;
	}

	/**
	 * Loads a product by its id.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function find( int $productId ): ?Product {
		return $this->load( $productId );
	}

	/**
	 * Loads the product a post is bound to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function findByPost( int $postId ): ?Product {
		return $this->loadWhere( $this->productOf( $postId, false ) );
	}

	/**
	 * Loads the product whose source binding is a post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when no product has that source post.
	 */
	public function findBySourcePost( int $postId ): ?Product {
		return $this->loadWhere( $this->db->fetchValue( 'SELECT id FROM %i WHERE source_post_id = %d', $this->table( CatalogTables::PRODUCTS ), $postId ) );
	}

	/**
	 * Loads the product a variant belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant's id.
	 * @return Product|null The product, or null when no variant has that id.
	 */
	public function findByVariant( int $variantId ): ?Product {
		return $this->loadWhere( $this->db->fetchValue( 'SELECT product_id FROM %i WHERE id = %d', $this->table( CatalogTables::VARIANTS ), $variantId ) );
	}

	/**
	 * Writes a product with its bindings, its default variant and that variant's base-currency price, and gives it its ids.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::SkuTaken when another variant holds the SKU, or
	 *                        CatalogError::ProductNotFound when a stored product's row is gone.
	 *
	 * @param Product $product The product.
	 */
	public function save( Product $product ): void {
		$id     = $product->id();
		$stored = null !== $id;

		if ( null === $id ) {
			$id = $this->insertProduct( $product );
		} else {
			$this->updateProduct( $id, $product );
		}

		$this->addBindings( $id, $product, $stored );

		$product->identify( $id, $this->writeDefaultVariant( $id, $product ) );
	}

	/**
	 * Marks a product `updating`, whatever its marker was, and returns the marker it replaced and the instant of the mark.
	 *
	 * Three statements: a locking read of the marker, the mark, and the read of the instant it
	 * wrote. The lock is held until the transaction ends, so both reads are this mark's.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, so the reads might be another writer's.
	 *
	 * @param int $productId The product's id.
	 * @return UpdatingMark|null The mark; null when there is no such product.
	 */
	public function markUpdating( int $productId ): ?UpdatingMark {
		$this->requireTransaction( __FUNCTION__ );

		$before = $this->db->fetchValue( 'SELECT generation_state FROM %i WHERE id = %d FOR UPDATE', $this->table( CatalogTables::PRODUCTS ), $productId );

		if ( null === $before || 1 !== $this->remark( $productId ) ) {
			return null;
		}

		return new UpdatingMark(
			GenerationState::fromStored( (string) $before ),
			(string) $this->db->fetchValue( 'SELECT updated_at FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId )
		);
	}

	/**
	 * Marks a product `updating` again as the first statement of a write's window, taking its row lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return bool True when the product exists and was marked.
	 */
	public function relock( int $productId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->remark( $productId );
	}

	/**
	 * Takes a product out of `updating`, only while it is `updating`.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $to        The marker to set.
	 * @return bool True when the product was `updating` and now has the marker.
	 */
	public function leaveUpdating( int $productId, GenerationState $to ): bool {
		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$to->value,
			$productId,
			GenerationState::Updating->value
		);
	}

	/**
	 * Puts back the marker a product had before a rolled-back write, only while this write's own mark is there.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId The product's id.
	 * @param GenerationState $before    The marker the product had before the write marked it.
	 * @param string          $markedAt  The instant markUpdating() returned for this write's mark.
	 * @return bool True when this write's mark was still there and the marker is put back.
	 */
	public function restoreMark( int $productId, GenerationState $before, string $markedAt ): bool {
		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s AND updated_at = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$before->value,
			$productId,
			GenerationState::Updating->value,
			$markedAt
		);
	}

	/**
	 * Loads a product under its row lock: every read a locking read, the product's row first.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function lock( int $productId ): ?Product {
		$this->requireTransaction( __FUNCTION__ );

		return $this->load( $productId, true );
	}

	/**
	 * Loads the product a post is bound to under its row lock, as lockWithPost() locks it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws CodedException With CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function lockByPost( int $postId ): ?Product {
		foreach ( $this->lockWithPost( $postId ) as $product ) {
			if ( null !== $product->bindingOf( $postId ) ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Locks the rows of some products and of the product a post is bound to, in ascending order of id, then the post's binding, and loads each product with locking reads.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @throws CodedException With CatalogError::WriteConflict when the post's binding keeps moving.
	 *
	 * @param int $postId        The post.
	 * @param int ...$productIds Other products to lock with it.
	 * @return array<int, Product> The products that exist, by id: the post's, when it presents one, among them.
	 */
	public function lockWithPost( int $postId, int ...$productIds ): array {
		$this->requireTransaction( __FUNCTION__ );

		// A read without a lock names the post's product, whose row is locked before its binding.
		$current = self::intOrNull( $this->productOf( $postId, false ) );
		$locked  = array();

		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; ++$attempt ) {
			$ids = array_unique( array_merge( $productIds, null === $current ? array() : array( $current ) ) );

			sort( $ids );

			foreach ( $ids as $id ) {
				if ( ! array_key_exists( $id, $locked ) ) {
					$locked[ $id ] = $this->load( $id, true );
				}
			}

			// Under the locks, a locking read of the binding names the product the post presents now.
			$now = self::intOrNull( $this->productOf( $postId, true ) );

			if ( null === $now || array_key_exists( $now, $locked ) ) {
				return array_filter( $locked, static fn( ?Product $product ): bool => null !== $product );
			}

			$current = $now;
		}

		CodedException::raise( CatalogError::WriteConflict, array( 'post_id' => $postId ) );
	}

	/**
	 * Locks every variant of a product until the transaction ends, and returns their ids: one locking read.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return list<int> The variants' ids, ascending.
	 */
	public function lockVariants( int $productId ): array {
		$this->requireTransaction( __FUNCTION__ );

		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i WHERE product_id = %d ORDER BY id FOR UPDATE', $this->table( CatalogTables::VARIANTS ), $productId ), 'id' ) );
	}

	/**
	 * Deletes a product's rows, children first: its variants' prices, its variants, its bindings, then the product itself.
	 *
	 * Fails with CatalogError::ProductNotFound when the product's row is gone.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, or the product was never stored.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param Product $product The product, as lock() loaded it.
	 * @return array<int, string> The SKU of each variant deleted, by the variant's id, ascending.
	 */
	public function delete( Product $product ): array {
		$this->requireTransaction( __FUNCTION__ );

		$productId = $product->id();

		if ( null === $productId ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message naming the product's public identifier; it is never rendered.
			throw new \LogicException( sprintf( 'Product %s was never stored, so there is nothing to delete.', $product->uuid() ) );
		}

		// A locking read: every variant committed so far, of every generation, whatever this transaction's snapshot.
		$skus = array();

		foreach ( $this->db->fetchAll( 'SELECT id, sku FROM %i WHERE product_id = %d ORDER BY id FOR UPDATE', $this->table( CatalogTables::VARIANTS ), $productId ) as $variant ) {
			$skus[ (int) $variant['id'] ] = (string) $variant['sku'];
		}

		$variantIds = array_keys( $skus );

		if ( array() !== $variantIds ) {
			$this->db->execute(
				'DELETE FROM %i WHERE variant_id IN ( ' . implode( ', ', array_fill( 0, count( $variantIds ), '%d' ) ) . ' )',
				$this->table( CatalogTables::VARIANT_PRICES ),
				...$variantIds
			);
			$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->table( CatalogTables::VARIANTS ), $productId );
		}

		$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $productId );

		if ( 1 !== $this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId ) ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}

		return $skus;
	}

	/**
	 * Reads, in one query, the facts Sellability judges each variant on.
	 *
	 * The variant is joined to its product, the product to its binding to its source post, and
	 * that binding to the post only when the post is a product post; whether the variant has a
	 * price in the base currency is one EXISTS.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists; none for an unknown id.
	 */
	public function sellabilityFacts( int ...$variantIds ): array {
		return $this->facts( null, $variantIds );
	}

	/**
	 * Reads, in one query, the facts Sellability judges each variant on in one locale.
	 *
	 * The same query as sellabilityFacts(), joined to the product's binding in the locale instead
	 * of its source binding, through the `(product_id, locale)` key.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale        The locale.
	 * @param int    ...$variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists; none for an unknown id.
	 */
	public function sellabilityFactsIn( Locale $locale, int ...$variantIds ): array {
		return $this->facts( $locale, $variantIds );
	}

	/**
	 * Moves a post's binding to another locale.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::LocaleTaken when the product has a post in that locale.
	 *
	 * @param int    $postId The post.
	 * @param Locale $locale The locale.
	 * @return bool True when the binding moved; false when the post presents no product, or has that locale already.
	 */
	public function moveBinding( int $postId, Locale $locale ): bool {
		try {
			return 1 === $this->db->execute( 'UPDATE %i SET locale = %s WHERE post_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $locale->toString(), $postId );
		} catch ( DuplicateKey $taken ) {
			CodedException::raise(
				CatalogError::LocaleTaken,
				array(
					'product_id' => (int) $this->db->fetchValue( 'SELECT product_id FROM %i WHERE post_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $postId ),
					'locale'     => $locale->toString(),
				)
			);
		}
	}

	/**
	 * Binds a post to a product, in a locale.
	 *
	 * When a unique key refuses the row, a locking read of the post's binding tells which one:
	 * the post is bound already, or the product has a post in the locale.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::PostBoundElsewhere or CatalogError::LocaleTaken.
	 *
	 * @param int                $productId The product's id.
	 * @param ProductPostBinding $binding   The binding.
	 */
	public function addBinding( int $productId, ProductPostBinding $binding ): void {
		try {
			$this->db->execute(
				'INSERT INTO %i ( post_id, product_id, locale, linked_at, linked_by_adapter, created_at ) VALUES ( %d, %d, %s, %s, ' . self::placeholder( $binding->linkedByAdapter() ) . ', UTC_TIMESTAMP() )',
				...self::given(
					$this->table( CatalogTables::PRODUCT_POSTS ),
					$binding->postId(),
					$productId,
					$binding->locale()->toString(),
					self::formatInstant( $binding->linkedAt() ),
					$binding->linkedByAdapter()
				)
			);
		} catch ( DuplicateKey $taken ) {
			$holder = $this->productOf( $binding->postId(), true );

			if ( null !== $holder ) {
				CodedException::raise(
					CatalogError::PostBoundElsewhere,
					array(
						'post_id'    => $binding->postId(),
						'product_id' => (int) $holder,
					)
				);
			}

			CodedException::raise(
				CatalogError::LocaleTaken,
				array(
					'product_id' => $productId,
					'locale'     => $binding->locale()->toString(),
				)
			);
		}
	}

	/**
	 * Removes a post's binding to a product, unless it is the product's source binding.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @param int $postId    The post.
	 * @return bool True when the binding was removed.
	 */
	public function removeBinding( int $productId, int $postId ): bool {
		return 1 === $this->db->execute(
			'DELETE pp FROM %i pp JOIN %i p ON p.id = pp.product_id WHERE pp.post_id = %d AND pp.product_id = %d AND NOT ( p.source_post_id <=> pp.post_id )',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$postId,
			$productId
		);
	}

	/**
	 * Moves a product's source binding from one of its posts to another, in one conditional statement.
	 *
	 * The statement changes the row only while the source is still the post the caller read, so
	 * two promotions of one product cannot both apply, and a promotion never names a post that is
	 * not one of the product's bindings. With no post named, the statement picks the post itself,
	 * in its own order: published bindings first, then by the time each was bound, then by post id.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $productId  The product's id.
	 * @param int      $fromPostId The post that must be the source now.
	 * @param int|null $toPostId   The post to promote, or null for the oldest remaining binding.
	 * @return int|null The new source post, or null when nothing changed.
	 */
	public function promoteSource( int $productId, int $fromPostId, ?int $toPostId ): ?int {
		if ( null !== $toPostId ) {
			$changed = $this->db->execute(
				'UPDATE %i SET source_post_id = %d, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND source_post_id = %d'
					. ' AND EXISTS ( SELECT 1 FROM %i pp WHERE pp.post_id = %d AND pp.product_id = %d )',
				$this->table( CatalogTables::PRODUCTS ),
				$toPostId,
				$productId,
				$fromPostId,
				$this->table( CatalogTables::PRODUCT_POSTS ),
				$toPostId,
				$productId
			);
		} else {
			$changed = $this->db->execute(
				'UPDATE %i p JOIN ( SELECT pp.post_id FROM %i pp LEFT JOIN %i wp ON wp.ID = pp.post_id'
					. ' WHERE pp.product_id = %d AND pp.post_id <> %d'
					. ' ORDER BY ( wp.post_status = %s ) DESC, pp.linked_at, pp.post_id LIMIT 1 ) next ON 1 = 1'
					. ' SET p.source_post_id = next.post_id, p.updated_at = ' . self::NEXT_INSTANT
					. ' WHERE p.id = %d AND p.source_post_id = %d',
				$this->table( CatalogTables::PRODUCTS ),
				$this->table( CatalogTables::PRODUCT_POSTS ),
				$this->db->prefix() . 'posts',
				$productId,
				$fromPostId,
				self::PUBLISHED,
				$productId,
				$fromPostId
			);
		}

		if ( 1 !== $changed ) {
			return null;
		}

		return (int) $this->db->fetchValue( 'SELECT source_post_id FROM %i WHERE id = %d FOR UPDATE', $this->table( CatalogTables::PRODUCTS ), $productId );
	}

	/**
	 * Reads the facts of variants, judged on the product's source binding or on its binding in a locale.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale|null $locale     The locale, or null for the source binding.
	 * @param int[]       $variantIds The variants' ids.
	 * @return list<SellabilityFacts> One entry per variant that exists.
	 */
	private function facts( ?Locale $locale, array $variantIds ): array {
		$ids = array_values( array_unique( array_filter( $variantIds, static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$binding = null === $locale ? 'pp.post_id = p.source_post_id AND pp.product_id = p.id' : 'pp.product_id = p.id AND pp.locale = %s';
		$rows    = $this->db->fetchAll(
			'SELECT v.id AS variant_id, v.is_enabled, v.generation, p.id AS product_id, p.generation_state, p.active_variant_generation, p.source_post_id, pp.post_id, wp.post_status,'
				. ' EXISTS ( SELECT 1 FROM %i vp WHERE vp.variant_id = v.id AND vp.currency = %s ) AS has_base_price'
				. ' FROM %i v'
				. ' JOIN %i p ON p.id = v.product_id'
				. ' LEFT JOIN %i pp ON ' . $binding
				. ' LEFT JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s'
				. ' WHERE v.id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
			...array_merge(
				array(
					$this->table( CatalogTables::VARIANT_PRICES ),
					$this->baseCurrency()->code(),
					$this->table( CatalogTables::VARIANTS ),
					$this->table( CatalogTables::PRODUCTS ),
					$this->table( CatalogTables::PRODUCT_POSTS ),
				),
				null === $locale ? array() : array( $locale->toString() ),
				array( $this->db->prefix() . 'posts', ProductCapabilities::POST_TYPE ),
				$ids
			)
		);

		$facts = array();

		foreach ( $rows as $row ) {
			$facts[] = new SellabilityFacts(
				(int) $row['variant_id'],
				(int) $row['product_id'],
				GenerationState::fromStored( (string) $row['generation_state'] ),
				(int) $row['active_variant_generation'],
				(int) $row['generation'],
				1 === (int) $row['is_enabled'],
				self::intOrNull( $row['source_post_id'] ),
				self::intOrNull( $row['post_id'] ),
				null === $row['post_status'] ? null : (string) $row['post_status'],
				1 === (int) $row['has_base_price'],
				null === $locale || null !== $row['post_id']
			);
		}

		return $facts;
	}

	/**
	 * Lists products with no `product_posts` row at all (doctor check 1).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function unboundProductIds( int $afterId, int $limit ): array {
		$args = array( $this->table( CatalogTables::PRODUCTS ), $this->table( CatalogTables::PRODUCT_POSTS ) );

		$sql  = 'SELECT p.id FROM %i p LEFT JOIN %i pp ON pp.product_id = p.id WHERE pp.product_id IS NULL';
		$sql .= self::afterFragment( 'p.id', $afterId, $args );
		$sql .= ' ORDER BY p.id LIMIT %d';

		$args[] = $limit;

		return self::intColumn( $this->db->fetchAll( $sql, ...$args ), 'id' );
	}

	/**
	 * Lists products whose source binding is invalid (doctor check 2).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only products above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function invalidSourceBindings( int $afterId, int $limit ): array {
		$args = array( $this->table( CatalogTables::PRODUCTS ), $this->table( CatalogTables::PRODUCT_POSTS ), $this->postsTable() );

		$sql = 'SELECT p.id FROM %i p'
			. ' LEFT JOIN %i pp ON pp.product_id = p.id AND pp.post_id = p.source_post_id'
			. ' LEFT JOIN %i wpp ON wpp.ID = p.source_post_id'
			. ' WHERE ( p.source_post_id IS NULL OR pp.post_id IS NULL OR wpp.ID IS NULL OR wpp.post_type <> %s )';

		$args[] = ProductCapabilities::POST_TYPE;

		$sql .= self::afterFragment( 'p.id', $afterId, $args );
		$sql .= ' ORDER BY p.id LIMIT %d';

		$args[] = $limit;

		return self::intColumn( $this->db->fetchAll( $sql, ...$args ), 'id' );
	}

	/**
	 * Lists `product_posts` rows whose post no longer exists (doctor check 3), the source binding excluded.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{post_id: int, product_id: int}> The bindings.
	 */
	public function danglingBindings( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT pp.post_id, pp.product_id FROM %i pp'
				. ' LEFT JOIN %i p ON p.id = pp.product_id'
				. ' LEFT JOIN %i wpp ON wpp.ID = pp.post_id'
				. ' WHERE ( wpp.ID IS NULL OR p.id IS NULL ) AND ( p.id IS NULL OR p.source_post_id IS NULL OR p.source_post_id <> pp.post_id )'
				. ' ORDER BY pp.post_id LIMIT %d',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$this->postsTable(),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'post_id'    => (int) $row['post_id'],
				'product_id' => (int) $row['product_id'],
			),
			$rows
		);
	}

	/**
	 * Deletes one dangling binding (doctor --repair, check 3), re-stating both conditions in the statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The binding's post.
	 * @param int $productId The binding's product.
	 * @return bool True when the row was deleted.
	 */
	public function deleteDanglingBinding( int $postId, int $productId ): bool {
		return 1 === $this->db->execute(
			'DELETE pp FROM %i pp'
				. ' LEFT JOIN %i p ON p.id = pp.product_id'
				. ' LEFT JOIN %i wpp ON wpp.ID = pp.post_id'
				. ' WHERE pp.post_id = %d AND pp.product_id = %d AND ( wpp.ID IS NULL OR p.id IS NULL ) AND ( p.id IS NULL OR p.source_post_id IS NULL OR p.source_post_id <> pp.post_id )',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$this->postsTable(),
			$postId,
			$productId
		);
	}

	/**
	 * Lists product posts (not `auto-draft`) with no `product_posts` row (doctor check 4).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only posts above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The post ids, ascending.
	 */
	public function unboundPostIds( int $afterId, int $limit ): array {
		$args = array( $this->postsTable(), $this->table( CatalogTables::PRODUCT_POSTS ), ProductCapabilities::POST_TYPE, ProductCapabilities::AUTO_DRAFT );

		$sql  = 'SELECT wpp.ID FROM %i wpp LEFT JOIN %i pp ON pp.post_id = wpp.ID'
			. ' WHERE wpp.post_type = %s AND wpp.post_status <> %s AND pp.post_id IS NULL';
		$sql .= self::afterFragment( 'wpp.ID', $afterId, $args );
		$sql .= ' ORDER BY wpp.ID LIMIT %d';

		$args[] = $limit;

		return self::intColumn( $this->db->fetchAll( $sql, ...$args ), 'ID' );
	}

	/**
	 * Locks a post's row and returns its type and status, current: never through WordPress's
	 * post cache, which a concurrent write can leave stale.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return array{type: string, status: string}|null The post's type and status, or null when the row is gone.
	 */
	public function lockedPostTypeAndStatus( int $postId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->db->fetchRow( 'SELECT post_type, post_status FROM %i WHERE ID = %d FOR SHARE', $this->postsTable(), $postId );

		if ( null === $row ) {
			return null;
		}

		return array(
			'type'   => (string) $row['post_type'],
			'status' => (string) $row['post_status'],
		);
	}

	/**
	 * Lists complete products at their active generation with zero enabled variants, or whose
	 * default variant has no price in the base currency (doctor check 5).
	 *
	 * Scoped to `generation_state = 'complete'`: an incomplete product missing a price is the
	 * ordinary, expected state of a product still being set up, not a finding.
	 *
	 * @since 0.1.0
	 *
	 * @param string $baseCurrency The store's base currency code.
	 * @param int    $afterId      Only products above this id.
	 * @param int    $limit        The most to list.
	 * @return list<int> The product ids, ascending.
	 */
	public function incompleteMismatchIds( string $baseCurrency, int $afterId, int $limit ): array {
		$args = array(
			$this->table( CatalogTables::PRODUCTS ),
			$this->table( CatalogTables::VARIANTS ),
			Variant::defaultCombinationHash(),
			$this->table( CatalogTables::VARIANT_PRICES ),
			$baseCurrency,
		);

		$sql = 'SELECT p.id FROM %i p'
			. ' LEFT JOIN %i v ON v.product_id = p.id AND v.combination_hash = %s'
			. ' LEFT JOIN %i vp ON vp.variant_id = v.id AND vp.currency = %s'
			. ' WHERE p.generation_state = %s';

		$args[] = GenerationState::Complete->value;

		$sql .= self::afterFragment( 'p.id', $afterId, $args );
		$sql .= ' AND ( p.enabled_variant_count = 0 OR v.id IS NULL OR vp.id IS NULL )';
		$sql .= ' ORDER BY p.id LIMIT %d';

		$args[] = $limit;

		return self::intColumn( $this->db->fetchAll( $sql, ...$args ), 'id' );
	}

	/**
	 * Lists products left `updating` for longer than the given threshold (doctor check 7).
	 *
	 * @since 0.1.0
	 *
	 * @param string $before The threshold instant (UTC).
	 * @param int    $limit  The most to list.
	 * @return list<array{product_id: int, updated_at: string}> The products.
	 */
	public function stuckUpdating( string $before, int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT id AS product_id, updated_at FROM %i WHERE generation_state = %s AND updated_at < %s ORDER BY id LIMIT %d',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Updating->value,
			$before,
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'product_id' => (int) $row['product_id'],
				'updated_at' => (string) $row['updated_at'],
			),
			$rows
		);
	}

	/**
	 * Lists `variants` rows whose product no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{variant_id: int, product_id: int}> The orphans.
	 */
	public function orphanVariants( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT v.id AS variant_id, v.product_id FROM %i v LEFT JOIN %i p ON p.id = v.product_id WHERE p.id IS NULL ORDER BY v.id LIMIT %d',
			$this->table( CatalogTables::VARIANTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'variant_id' => (int) $row['variant_id'],
				'product_id' => (int) $row['product_id'],
			),
			$rows
		);
	}

	/**
	 * Lists `variant_prices` rows whose variant no longer exists (doctor check 8).
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most to list.
	 * @return list<array{price_id: int, variant_id: int}> The orphans.
	 */
	public function orphanPrices( int $limit ): array {
		$rows = $this->db->fetchAll(
			'SELECT vp.id AS price_id, vp.variant_id FROM %i vp LEFT JOIN %i v ON v.id = vp.variant_id WHERE v.id IS NULL ORDER BY vp.id LIMIT %d',
			$this->table( CatalogTables::VARIANT_PRICES ),
			$this->table( CatalogTables::VARIANTS ),
			$limit
		);

		return array_map(
			static fn( array $row ): array => array(
				'price_id'   => (int) $row['price_id'],
				'variant_id' => (int) $row['variant_id'],
			),
			$rows
		);
	}

	/**
	 * Counts products marked `incomplete` (doctor check 10).
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function incompleteCount(): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM %i WHERE generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Incomplete->value
		);
	}

	/**
	 * Lists every variant's id whose product still exists (doctor check 6).
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId Only variants above this id.
	 * @param int $limit   The most to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function variantIds( int $afterId, int $limit ): array {
		$args = array( $this->table( CatalogTables::VARIANTS ), $this->table( CatalogTables::PRODUCTS ) );

		$sql  = 'SELECT v.id FROM %i v JOIN %i p ON p.id = v.product_id';
		$sql .= self::afterFragment( 'v.id', $afterId, $args );
		$sql .= ' ORDER BY v.id LIMIT %d';

		$args[] = $limit;

		return self::intColumn( $this->db->fetchAll( $sql, ...$args ), 'id' );
	}

	/**
	 * Filters a list of variant ids to the ones that still have a `variants` row (the reverse
	 * line).
	 *
	 * @since 0.1.0
	 *
	 * @param array $variantIds The ids to test.
	 * @return list<int> The ones that exist.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function variantsExisting( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		return self::intColumn(
			$this->db->fetchAll(
				'SELECT id FROM %i WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $variantIds ), '%d' ) ) . ' )',
				$this->table( CatalogTables::VARIANTS ),
				...$variantIds
			),
			'id'
		);
	}

	/**
	 * Reloads a product under its row lock, for a repair that must re-check the defect before it
	 * writes: load()'s own locking read (the one lock() also uses), plus the exact stored
	 * `updated_at` the repair's compare-and-set must match, which the aggregate itself does not carry.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return array{product: Product, state: GenerationState, updatedAt: string}|null The reload, or null.
	 */
	public function reloadUnderLock( int $productId ): ?array {
		$this->requireTransaction( __FUNCTION__ );

		$product = $this->load( $productId, true );

		if ( null === $product ) {
			return null;
		}

		// The row's lock is already held by load()'s own read above; this is a plain read of the
		// same, now-locked row, only for the one column the aggregate does not carry.
		$updatedAt = $this->db->fetchValue( 'SELECT updated_at FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $productId );

		return array(
			'product'   => $product,
			'state'     => $product->generation(),
			'updatedAt' => (string) $updatedAt,
		);
	}

	/**
	 * Settles a product's marker, only while it still has exactly the state (and, when given, the
	 * exact `updated_at`) the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId      The product's id.
	 * @param GenerationState $from           The marker the repair read.
	 * @param GenerationState $to             The marker settle() computed.
	 * @param string|null     $updatedAtMatch Optional. Also requires this exact `updated_at`.
	 * @return bool True when the row still matched and was changed.
	 */
	public function settleIfUnchanged( int $productId, GenerationState $from, GenerationState $to, ?string $updatedAtMatch = null ): bool {
		if ( null !== $updatedAtMatch ) {
			return 1 === $this->db->execute(
				'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s AND updated_at = %s',
				$this->table( CatalogTables::PRODUCTS ),
				$to->value,
				$productId,
				$from->value,
				$updatedAtMatch
			);
		}

		return 1 === $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d AND generation_state = %s',
			$this->table( CatalogTables::PRODUCTS ),
			$to->value,
			$productId,
			$from->value
		);
	}

	/**
	 * Returns the full name of `wp_posts` on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string The prefixed name.
	 */
	private function postsTable(): string {
		return $this->db->prefix() . 'posts';
	}

	/**
	 * Reads one integer column of every row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows.
	 * @param string                           $column The column.
	 * @return list<int> The values.
	 */
	private static function intColumn( array $rows, string $column ): array {
		return array_map( 'intval', array_column( $rows, $column ) );
	}

	/**
	 * The `AND <column> > <id>` fragment doctor's paging reads add only when they carry a cursor.
	 *
	 * Every caller in this codebase pages from 0, so the fragment is normally left out: `id > 0`
	 * matches every row of the table anyway, and keeping the comparison would still make MySQL
	 * cost a primary-key range over it, which it estimates near half the table for a keyset scan
	 * like this one's, defeating the cheap, LIMIT-bounded plan the check is meant to get for no
	 * reason. A genuine cursor (a caller walking a table page by page) still gets the comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column  The column, qualified as the statement needs it.
	 * @param int    $afterId The cursor; no fragment when it is not positive.
	 * @param array  $args    The statement's arguments, appended to when the fragment is used.
	 * @return string The fragment, or '' when there is no cursor.
	 *
	 * @phpstan-param list<mixed> $args
	 */
	private static function afterFragment( string $column, int $afterId, array &$args ): string {
		if ( $afterId <= 0 ) {
			return '';
		}

		$args[] = $afterId;

		return ' AND ' . $column . ' > %d';
	}

	/**
	 * Loads a product by its id, when a lookup found one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $productId The id a lookup returned, or null.
	 * @return Product|null The product, or null.
	 */
	private function loadWhere( mixed $productId ): ?Product {
		return null === $productId ? null : $this->load( (int) $productId );
	}

	/**
	 * Reads the product a post's binding names: a plain read, or a locking read that takes the binding's row lock and sees the newest committed row.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $postId  The post's id.
	 * @param bool $locking Whether the read is a locking read.
	 * @return mixed The product's id as the database returns it, or null when the post is bound to none.
	 */
	private function productOf( int $postId, bool $locking ): mixed {
		return $this->db->fetchValue( 'SELECT product_id FROM %i WHERE post_id = %d' . ( $locking ? self::LOCKING : '' ), $this->table( CatalogTables::PRODUCT_POSTS ), $postId );
	}

	/**
	 * Loads a product, its bindings and its default variant with that variant's base-currency price.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $productId The product's id.
	 * @param bool $locking   Optional. Whether every read is a locking read, the product's row first. Default false.
	 * @return Product|null The product, or null when there is no such row.
	 */
	private function load( int $productId, bool $locking = false ): ?Product {
		$lock = $locking ? self::LOCKING : '';
		$row  = $this->db->fetchRow(
			'SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM %i WHERE id = %d' . $lock,
			$this->table( CatalogTables::PRODUCTS ),
			$productId
		);

		if ( null === $row ) {
			return null;
		}

		$bindings = array();

		foreach ( $this->db->fetchAll( 'SELECT post_id, locale, linked_at, linked_by_adapter FROM %i WHERE product_id = %d ORDER BY linked_at, post_id' . $lock, $this->table( CatalogTables::PRODUCT_POSTS ), $productId ) as $binding ) {
			$bindings[] = new ProductPostBinding(
				(int) $binding['post_id'],
				Locale::of( (string) $binding['locale'] ),
				self::instant( (string) $binding['linked_at'] ),
				null === $binding['linked_by_adapter'] ? null : (string) $binding['linked_by_adapter']
			);
		}

		return Product::stored(
			(int) $row['id'],
			(string) $row['uuid'],
			self::intOrNull( $row['source_post_id'] ),
			$bindings,
			GenerationState::fromStored( (string) $row['generation_state'] ),
			(int) $row['active_variant_generation'],
			$this->loadDefaultVariant( $productId, $lock )
		);
	}

	/**
	 * Loads a product's default variant with its base-currency price.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $productId The product's id.
	 * @param string $lock      What ends the read: LOCKING for a locking read, or nothing.
	 * @return Variant|null The variant, or null when the product has none.
	 */
	private function loadDefaultVariant( int $productId, string $lock ): ?Variant {
		$base = $this->baseCurrency();
		$row  = $this->db->fetchRow(
			'SELECT v.id, v.uuid, v.sku, v.combination_hash, v.generation, v.is_enabled, v.weight_grams, vp.currency, vp.amount_basis, vp.price_minor, vp.compare_at_minor'
				. ' FROM %i v LEFT JOIN %i vp ON vp.variant_id = v.id AND vp.currency = %s'
				. ' WHERE v.product_id = %d AND v.combination_hash = %s' . $lock,
			$this->table( CatalogTables::VARIANTS ),
			$this->table( CatalogTables::VARIANT_PRICES ),
			$base->code(),
			$productId,
			Variant::defaultCombinationHash()
		);

		if ( null === $row ) {
			return null;
		}

		$price = null === $row['price_minor']
			? null
			: VariantPrice::stored( $base, (int) $row['price_minor'], self::intOrNull( $row['compare_at_minor'] ), (string) $row['amount_basis'] );

		return Variant::stored(
			(int) $row['id'],
			(string) $row['uuid'],
			Sku::of( (string) $row['sku'] ),
			(string) $row['combination_hash'],
			(int) $row['generation'],
			1 === (int) $row['is_enabled'],
			self::intOrNull( $row['weight_grams'] ),
			$price
		);
	}

	/**
	 * Creates a product's row, with its marker.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product.
	 * @return int The id the row was given.
	 */
	private function insertProduct( Product $product ): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at )'
				. ' VALUES ( %s, ' . self::placeholder( $product->sourcePostId() ) . ', %s, %d, %d, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
			...self::given(
				$this->table( CatalogTables::PRODUCTS ),
				$product->uuid(),
				$product->sourcePostId(),
				$product->generation()->value,
				$product->activeGeneration(),
				$product->variantCount(),
				$product->enabledVariantCount()
			)
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Updates a stored product's row, leaving its marker alone.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With CatalogError::ProductNotFound when the row is gone.
	 *
	 * @param int     $productId The product's id.
	 * @param Product $product   The product.
	 */
	private function updateProduct( int $productId, Product $product ): void {
		// The row always changes, because updated_at only moves forward: no changed row means no row.
		$changed = $this->db->execute(
			'UPDATE %i SET source_post_id = ' . self::placeholder( $product->sourcePostId() ) . ', active_variant_generation = %d, variant_count = %d, enabled_variant_count = %d, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d',
			...self::given(
				$this->table( CatalogTables::PRODUCTS ),
				$product->sourcePostId(),
				$product->activeGeneration(),
				$product->variantCount(),
				$product->enabledVariantCount(),
				$productId
			)
		);

		if ( 1 !== $changed ) {
			CodedException::raise( CatalogError::ProductNotFound, array( 'product_id' => $productId ) );
		}
	}

	/**
	 * Adds the bindings a product has and its stored rows do not.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $productId     The product's id.
	 * @param Product $product       The product.
	 * @param bool    $productStored Whether the product was stored before, so bindings may exist.
	 */
	private function addBindings( int $productId, Product $product, bool $productStored ): void {
		$stored = $productStored
			? array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT post_id FROM %i WHERE product_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $productId ), 'post_id' ) )
			: array();

		foreach ( $product->bindings() as $binding ) {
			if ( in_array( $binding->postId(), $stored, true ) ) {
				continue;
			}

			if ( $productStored ) {
				// A stored product gaining a post: a refusal names the post or the locale that is taken.
				$this->addBinding( $productId, $binding );

				continue;
			}

			$this->db->execute(
				'INSERT INTO %i ( post_id, product_id, locale, linked_at, linked_by_adapter, created_at ) VALUES ( %d, %d, %s, %s, ' . self::placeholder( $binding->linkedByAdapter() ) . ', UTC_TIMESTAMP() )',
				...self::given(
					$this->table( CatalogTables::PRODUCT_POSTS ),
					$binding->postId(),
					$productId,
					$binding->locale()->toString(),
					self::formatInstant( $binding->linkedAt() ),
					$binding->linkedByAdapter()
				)
			);
		}
	}

	/**
	 * Writes a product's default variant and its base-currency price.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $productId The product's id.
	 * @param Product $product   The product.
	 * @return int|null The variant's id, or null when the product has none.
	 */
	private function writeDefaultVariant( int $productId, Product $product ): ?int {
		$variant = $product->defaultVariant();

		if ( null === $variant ) {
			return null;
		}

		$variantId = $variant->id();

		if ( null === $variantId ) {
			$this->translatingSkuCollision(
				$variant->sku(),
				0,
				fn(): int => $this->db->execute(
					'INSERT INTO %i ( uuid, product_id, sku, combination_hash, generation, is_enabled, weight_grams, position, created_at, updated_at )'
						. ' VALUES ( %s, %d, %s, %s, %d, %d, ' . self::placeholder( $variant->weightGrams() ) . ', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
					...self::given(
						$this->table( CatalogTables::VARIANTS ),
						$variant->uuid(),
						$productId,
						$variant->sku()->toString(),
						$variant->combinationHash(),
						$variant->generation(),
						$variant->isEnabled() ? 1 : 0,
						$variant->weightGrams()
					)
				)
			);

			$variantId = $this->db->lastInsertId();
		} else {
			$weight = self::placeholder( $variant->weightGrams() );

			// A row whose values are all unchanged is left alone, so its updated_at keeps saying when it last changed.
			$this->translatingSkuCollision(
				$variant->sku(),
				$variantId,
				fn(): int => $this->db->execute(
					'UPDATE %i SET sku = %s, generation = %d, is_enabled = %d, weight_grams = ' . $weight . ', updated_at = UTC_TIMESTAMP()'
						. ' WHERE id = %d AND NOT ( CAST( sku AS BINARY ) <=> CAST( %s AS BINARY ) AND generation <=> %d AND is_enabled <=> %d AND weight_grams <=> ' . $weight . ' )',
					...self::given(
						$this->table( CatalogTables::VARIANTS ),
						$variant->sku()->toString(),
						$variant->generation(),
						$variant->isEnabled() ? 1 : 0,
						$variant->weightGrams(),
						$variantId,
						$variant->sku()->toString(),
						$variant->generation(),
						$variant->isEnabled() ? 1 : 0,
						$variant->weightGrams()
					)
				)
			);
		}

		$this->writeBasePrice( $variantId, $variant->basePrice(), null !== $variant->id() );

		return $variantId;
	}

	/**
	 * Writes a variant's base-currency price row, or removes it when the variant has no price.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $variantId The variant's id.
	 * @param VariantPrice|null $price     The price, or null for none.
	 * @param bool              $stored    Whether the variant was stored before, so a row may exist.
	 */
	private function writeBasePrice( int $variantId, ?VariantPrice $price, bool $stored ): void {
		$table = $this->table( CatalogTables::VARIANT_PRICES );

		if ( null === $price ) {
			if ( $stored ) {
				$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d AND currency = %s', $table, $variantId, $this->baseCurrency()->code() );
			}

			return;
		}

		$compareAt = self::placeholder( $price->compareAtMinor() );

		// updated_at is assigned first, so it compares the stored values before they are overwritten: an unchanged price keeps it.
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, compare_at_minor, created_at, updated_at )'
				. ' VALUES ( %d, %s, %s, %d, ' . $compareAt . ', UTC_TIMESTAMP(), UTC_TIMESTAMP() )'
				. ' ON DUPLICATE KEY UPDATE updated_at = IF( CAST( amount_basis AS BINARY ) <=> CAST( %s AS BINARY ) AND price_minor <=> %d AND compare_at_minor <=> ' . $compareAt . ', updated_at, UTC_TIMESTAMP() ),'
				. ' amount_basis = %s, price_minor = %d, compare_at_minor = ' . $compareAt,
			...self::given(
				$table,
				$variantId,
				$price->currency()->code(),
				$price->amountBasis(),
				$price->priceMinor(),
				$price->compareAtMinor(),
				$price->amountBasis(),
				$price->priceMinor(),
				$price->compareAtMinor(),
				$price->amountBasis(),
				$price->priceMinor(),
				$price->compareAtMinor()
			)
		);
	}

	/**
	 * Runs a variant write, and turns a unique-key failure caused by the SKU into `catalog.sku_taken`.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|DuplicateKey With CatalogError::SkuTaken when another variant holds the SKU;
	 *                                    the DuplicateKey itself when another unique key refused the row.
	 *
	 * @param Sku      $sku       The SKU being written.
	 * @param int      $variantId The id of the variant being written, or 0 for a new one.
	 * @param callable $write     Sends the statement.
	 *
	 * @phpstan-param callable(): int $write
	 */
	private function translatingSkuCollision( Sku $sku, int $variantId, callable $write ): void {
		try {
			$write();
		} catch ( DuplicateKey $duplicate ) {
			// A locking read sees the newest committed row, even where this transaction's snapshot is older.
			$holder = $this->db->fetchValue(
				'SELECT id FROM %i WHERE sku = %s AND id <> %d LIMIT 1 LOCK IN SHARE MODE',
				$this->table( CatalogTables::VARIANTS ),
				$sku->toString(),
				$variantId
			);

			if ( null === $holder ) {
				throw $duplicate;
			}

			CodedException::raise( CatalogError::SkuTaken, array( 'sku' => $sku->toString() ) );
		}
	}

	/**
	 * Writes the `updating` mark with a new instant: one statement.
	 *
	 * The instant is always later than the row's last one, so the row always changes and the
	 * answer counts it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return int The rows changed: 1, or 0 when there is no such product.
	 */
	private function remark( int $productId ): int {
		return $this->db->execute(
			'UPDATE %i SET generation_state = %s, updated_at = ' . self::NEXT_INSTANT . ' WHERE id = %d',
			$this->table( CatalogTables::PRODUCTS ),
			GenerationState::Updating->value,
			$productId
		);
	}

	/**
	 * Refuses a marker statement outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param string $method The statement's method, for the message.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message naming one of this class's methods; it is never rendered.
			throw new \LogicException( sprintf( '%s() runs inside a transaction: the mark must be read under the lock it takes, and committed or rolled back with the write it belongs to.', $method ) );
		}
	}

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	private function baseCurrency(): Currency {
		return ( $this->baseCurrency )();
	}

	/**
	 * Returns the full name of a catalog table on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of CatalogTables' names.
	 * @return string The prefixed name.
	 */
	private function table( string $name ): string {
		return $this->db->table( $name );
	}

	/**
	 * Returns the placeholder of a value that may be NULL: the literal NULL for null, or the value's placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null $value The value.
	 * @return string `NULL`, `%d` or `%s`.
	 */
	private static function placeholder( int|string|null $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		return is_int( $value ) ? '%d' : '%s';
	}

	/**
	 * Returns the arguments of a statement whose NULL values are written as literals by placeholder().
	 *
	 * @since 0.1.0
	 *
	 * @param int|string|null ...$values The values, in placeholder order, nulls included.
	 * @return list<int|string> The values without the nulls.
	 */
	private static function given( int|string|null ...$values ): array {
		return array_values( array_filter( $values, static fn( int|string|null $value ): bool => null !== $value ) );
	}

	/**
	 * Reads a column that holds an integer or NULL.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The column's value.
	 * @return int|null The integer, or null.
	 */
	private static function intOrNull( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Reads a DATETIME column, which holds UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The column's value.
	 * @return \DateTimeImmutable The instant.
	 */
	private static function instant( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Writes an instant as a DATETIME column holds it: UTC, to the second.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant.
	 * @return string The value.
	 */
	private static function formatInstant( \DateTimeImmutable $instant ): string {
		return $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::DATETIME );
	}
}
