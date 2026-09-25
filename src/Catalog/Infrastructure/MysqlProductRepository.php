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
		return $this->loadWhere( $this->db->fetchValue( 'SELECT product_id FROM %i WHERE post_id = %d', $this->table( CatalogTables::PRODUCT_POSTS ), $postId ) );
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
	 * Loads a product for its deletion, under its row lock: every read a locking read, the product's row first.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $productId The product's id.
	 * @return Product|null The product, or null when there is none with that id.
	 */
	public function lockForDelete( int $productId ): ?Product {
		$this->requireTransaction( __FUNCTION__ );

		return $this->load( $productId, true );
	}

	/**
	 * Loads the product a post is bound to, under the row locks of the binding and the product: one locking read takes both, then the product is read with locking reads.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param int $postId The post's id.
	 * @return Product|null The product, or null when the post is bound to none.
	 */
	public function lockByPost( int $postId ): ?Product {
		$this->requireTransaction( __FUNCTION__ );

		$productId = $this->db->fetchValue(
			'SELECT pp.product_id FROM %i pp JOIN %i p ON p.id = pp.product_id WHERE pp.post_id = %d FOR UPDATE',
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$postId
		);

		return null === $productId ? null : $this->load( (int) $productId, true );
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
	 * @param Product $product The product, as lockForDelete() loaded it.
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
		$ids = array_values( array_unique( array_filter( $variantIds, static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$rows = $this->db->fetchAll(
			'SELECT v.id AS variant_id, v.is_enabled, v.generation, p.id AS product_id, p.generation_state, p.active_variant_generation, p.source_post_id, pp.post_id, wp.post_status,'
				. ' EXISTS ( SELECT 1 FROM %i vp WHERE vp.variant_id = v.id AND vp.currency = %s ) AS has_base_price'
				. ' FROM %i v'
				. ' JOIN %i p ON p.id = v.product_id'
				. ' LEFT JOIN %i pp ON pp.post_id = p.source_post_id AND pp.product_id = p.id'
				. ' LEFT JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s'
				. ' WHERE v.id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
			$this->table( CatalogTables::VARIANT_PRICES ),
			$this->baseCurrency()->code(),
			$this->table( CatalogTables::VARIANTS ),
			$this->table( CatalogTables::PRODUCTS ),
			$this->table( CatalogTables::PRODUCT_POSTS ),
			$this->db->prefix() . 'posts',
			ProductCapabilities::POST_TYPE,
			...$ids
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
				1 === (int) $row['has_base_price']
			);
		}

		return $facts;
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
			$this->translatingSkuCollision(
				$variant->sku(),
				$variantId,
				fn(): int => $this->db->execute(
					'UPDATE %i SET sku = %s, generation = %d, is_enabled = %d, weight_grams = ' . self::placeholder( $variant->weightGrams() ) . ', updated_at = UTC_TIMESTAMP() WHERE id = %d',
					...self::given(
						$this->table( CatalogTables::VARIANTS ),
						$variant->sku()->toString(),
						$variant->generation(),
						$variant->isEnabled() ? 1 : 0,
						$variant->weightGrams(),
						$variantId
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

		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, compare_at_minor, created_at, updated_at )'
				. ' VALUES ( %d, %s, %s, %d, ' . $compareAt . ', UTC_TIMESTAMP(), UTC_TIMESTAMP() )'
				. ' ON DUPLICATE KEY UPDATE amount_basis = %s, price_minor = %d, compare_at_minor = ' . $compareAt . ', updated_at = UTC_TIMESTAMP()',
			...self::given(
				$table,
				$variantId,
				$price->currency()->code(),
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
