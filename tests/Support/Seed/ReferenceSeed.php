<?php
/**
 * ReferenceSeed: a reference dataset of products and their stock, the same rows for the same seed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Seed;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\Secrets\SecretKeysTable;

/**
 * Generates a reference dataset and writes it with multi-row INSERTs.
 *
 * Owns one fact: what a seeded store holds. For each product of the Dataset:
 *
 * - its source post, published, and for every second product a second post in another of the
 *   store's locales, never the site's own;
 * - the product, complete, bound to its posts;
 * - its default variant, enabled, in the generation the product publishes, with a price in the
 *   base currency;
 * - its stock item, with a ledger of one to five merchant movements whose sum is the item's
 *   on_hand, and one hold row, or two for about one item in five, each of one unit: half of them
 *   live, half expired within the period the sweep is allowed. An item with fewer than two units
 *   on hand holds none, so no item holds more than it has.
 *
 * The rows are deterministic: they come from one fixed RNG seed and one anchor instant, so the
 * same seed and anchor give the same bytes on every machine. write() anchors on the database's
 * clock, because a hold is live or expired relative to it.
 *
 * It writes the tables directly rather than through the repositories and the stock service,
 * because a seed is only useful while it is fast: `medium` is about 90,000 rows and has three
 * minutes on a CI runner, where the services would spend a transaction, events and outbox rows on
 * every product. Bypassing them makes this class answerable for every invariant they keep, so
 * each row is written as they write it, and a seeded store is then checked by what checks a real
 * one, doctor and the sellability query (SeedVerifier), rather than trusted.
 *
 * It refuses a store that already has products, stock or posts in its id range. It is test
 * infrastructure and never ships: the release zip leaves tests/ out.
 *
 * @since 0.1.0
 */
final class ReferenceSeed {

	/**
	 * The RNG seed of the reference datasets.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const RNG_SEED = 20260924;

	/**
	 * The id of the first seeded post. A dataset's posts take consecutive ids from here.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FIRST_POST_ID = 1000001;

	/**
	 * Every table a dataset writes, by the key rows() yields: `posts` is WordPress's, the others the plugin's.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const TABLES = array(
		'posts',
		CatalogTables::PRODUCTS,
		CatalogTables::PRODUCT_POSTS,
		CatalogTables::VARIANTS,
		CatalogTables::VARIANT_PRICES,
		InventoryTables::ITEMS,
		InventoryTables::LEDGER,
		InventoryTables::HOLDS,
	);

	/**
	 * The most rows one INSERT writes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CHUNK = 1000;

	/**
	 * The store's locales: a second post takes, in turn, each of them that is not the site's.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const LOCALES = array( 'en_US', 'en_GB', 'de_DE' );

	/**
	 * How long a seeded hold lasts from when it was taken, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const HOLD_SECONDS = 900;

	/**
	 * The user who wrote the seeded posts and moved the seeded stock: the site's first user.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const AUTHOR_ID = 1;

	/**
	 * The dataset.
	 *
	 * @since 0.1.0
	 *
	 * @var Dataset
	 */
	private Dataset $dataset;

	/**
	 * The store's base currency, the currency of every seeded price.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $baseCurrency;

	/**
	 * The locale of the source posts: the site's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $sourceLocale;

	/**
	 * The RNG seed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $rngSeed;

	/**
	 * Describes a dataset. Writes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Dataset $dataset      The dataset.
	 * @param string  $baseCurrency The store's base currency, such as `USD`.
	 * @param string  $sourceLocale The site's locale, such as `en_US`.
	 * @param int     $rngSeed      Optional. The RNG seed. Default RNG_SEED.
	 */
	public function __construct( Dataset $dataset, string $baseCurrency, string $sourceLocale, int $rngSeed = self::RNG_SEED ) {
		$this->dataset      = $dataset;
		$this->baseCurrency = $baseCurrency;
		$this->sourceLocale = $sourceLocale;
		$this->rngSeed      = $rngSeed;
	}

	/**
	 * Writes the dataset into the current site's tables in one transaction, then refreshes their statistics.
	 *
	 * The statistics are refreshed so that a query plan read afterwards does not depend on when
	 * InnoDB would have sampled the new rows by itself.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the store already holds rows the dataset would collide with.
	 *
	 * @param Database $db The connection, on the site to seed.
	 * @return array{rows: array<string, int>, seconds: float} The rows written per table, and how long writing took.
	 */
	public function write( Database $db ): array {
		$started = hrtime( true );

		$this->refuseAnOccupiedStore( $db );

		$now    = new \DateTimeImmutable( (string) $db->fetchValue( 'SELECT UTC_TIMESTAMP()' ), new \DateTimeZone( 'UTC' ) );
		$counts = array_fill_keys( self::TABLES, 0 );

		$db->transaction(
			function () use ( $db, $now, &$counts ): void {
				foreach ( $this->rows( $now ) as list( $table, $rows ) ) {
					self::insert( $db, self::tableName( $db, $table ), $rows );

					$counts[ $table ] += count( $rows );
				}
			}
		);

		foreach ( self::TABLES as $table ) {
			$db->execute( 'ANALYZE TABLE %i', self::tableName( $db, $table ) );
		}

		return array(
			'rows'    => $counts,
			'seconds' => ( hrtime( true ) - $started ) / 1e9,
		);
	}

	/**
	 * Deletes the posts write() added. The plugin's tables are the caller's to drop.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection, on the seeded site.
	 */
	public function removePosts( Database $db ): void {
		$posts = $db->prefix() . 'posts';

		$db->execute( 'DELETE FROM %i WHERE ID BETWEEN %d AND %d', $posts, self::FIRST_POST_ID, self::FIRST_POST_ID + $this->postCount() - 1 );
		$db->execute( 'ALTER TABLE %i AUTO_INCREMENT = 1', $posts );
	}

	/**
	 * Returns a digest of every row, for a given anchor: equal digests mean byte-identical datasets.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $now The anchor instant, UTC.
	 * @return string SHA-256, in hexadecimal.
	 */
	public function digest( \DateTimeImmutable $now ): string {
		$hash = hash_init( 'sha256' );

		foreach ( $this->rows( $now ) as list( $table, $rows ) ) {
			hash_update( $hash, $table . "\n" . (string) wp_json_encode( $rows ) . "\n" );
		}

		return hash_final( $hash );
	}

	/**
	 * Returns the plugin's tables a dataset leaves empty, each with the reason.
	 *
	 * With TABLES, this names every table the plugin registers, which a test holds to the data
	 * registry: a module that adds a table either seeds it here or says here why it does not.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The reason, keyed by the table's unprefixed name.
	 */
	public static function notSeeded(): array {
		return array(
			PlatformTables::migrations()->name() => 'The migrator writes it when the schema is installed.',
			PlatformTables::locks()->name()      => 'It holds leases while work runs; a store at rest holds none.',
			OutboxTable::NAME                    => 'It holds events in flight; a store at rest has delivered them.',
			SecretKeysTable::NAME                => 'A secret is never seeded.',
			LogsTable::NAME                      => 'It holds diagnostics, not store data.',
			InventoryTables::ALLOCATIONS         => 'Only an order allocates stock, and there are no orders yet.',
			RateCountersTable::NAME              => 'It counts the requests of clients in their current windows; a store at rest has none, and a counter is read by its primary key only.',
		) + array_fill_keys( OrderTables::names(), 'The dataset has no orders yet: placing orders is a workload of its own.' );
	}

	/**
	 * Returns the id of the source post of a seeded product.
	 *
	 * @since 0.1.0
	 *
	 * @param int $product The product's id, 1 or more.
	 * @return int The post's id.
	 */
	public static function sourcePostId( int $product ): int {
		return self::FIRST_POST_ID + $product - 1;
	}

	/**
	 * Returns the id of the second post of a seeded product, which every second product has.
	 *
	 * @since 0.1.0
	 *
	 * @param int $product The product's id, an even number.
	 * @return int The post's id.
	 */
	public function secondPostId( int $product ): int {
		return self::FIRST_POST_ID + $this->dataset->products() + intdiv( $product, 2 ) - 1;
	}

	/**
	 * Yields the dataset's rows, in chunks of at most CHUNK rows of one table.
	 *
	 * The draws are made product by product in a fixed order, so the rows do not depend on where
	 * the chunks end.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $now The anchor instant, UTC.
	 * @return \Generator<int, array{0: string, 1: list<array<string, int|string|null>>}> Each chunk: the table's key in TABLES, and its rows.
	 */
	public function rows( \DateTimeImmutable $now ): \Generator {
		$random   = new \Random\Randomizer( new \Random\Engine\Mt19937( $this->rngSeed ) );
		$products = $this->dataset->products();
		$anchor   = $now->getTimestamp();
		$hash     = Variant::defaultCombinationHash();
		$reasons  = LedgerReason::merchant();
		$buffers  = array_fill_keys( self::TABLES, array() );
		$ledgerId = 0;
		$holdId   = 0;
		$second   = array_values( array_diff( self::LOCALES, array( $this->sourceLocale ) ) );

		for ( $product = 1; $product <= $products; $product++ ) {
			$createdAt = $anchor - ( $products - $product + 1 ) * 60;
			$created   = gmdate( 'Y-m-d H:i:s', $createdAt );
			$sourceId  = self::sourcePostId( $product );

			$buffers['posts'][] = self::post( $sourceId, $product, null, $created );

			$buffers[ CatalogTables::PRODUCTS ][] = array(
				'id'                        => $product,
				'uuid'                      => self::uuid( $random ),
				'source_post_id'            => $sourceId,
				'generation_state'          => GenerationState::Complete->value,
				'active_variant_generation' => Variant::FIRST_GENERATION,
				'variant_count'             => 1,
				'enabled_variant_count'     => 1,
				'created_at'                => $created,
				'updated_at'                => $created . '.000000',
			);

			$buffers[ CatalogTables::PRODUCT_POSTS ][] = $this->binding( $sourceId, $product, $this->sourceLocale, $created );

			if ( 0 === $product % 2 ) {
				$locale   = $second[ intdiv( $product, 2 ) % count( $second ) ];
				$secondId = $this->secondPostId( $product );

				$buffers['posts'][]                        = self::post( $secondId, $product, $locale, $created );
				$buffers[ CatalogTables::PRODUCT_POSTS ][] = $this->binding( $secondId, $product, $locale, $created );
			}

			$buffers[ CatalogTables::VARIANTS ][] = array(
				'id'               => $product,
				'uuid'             => self::uuid( $random ),
				'product_id'       => $product,
				'sku'              => sprintf( 'SEED-%06d', $product ),
				'combination_hash' => $hash,
				'generation'       => Variant::FIRST_GENERATION,
				'is_enabled'       => 1,
				'weight_grams'     => 0 === $random->getInt( 0, 9 ) ? null : $random->getInt( 50, 5000 ),
				'position'         => 0,
				'created_at'       => $created,
				'updated_at'       => $created,
			);

			$price = $random->getInt( 99, 99999 );

			$buffers[ CatalogTables::VARIANT_PRICES ][] = array(
				'id'               => $product,
				'variant_id'       => $product,
				'currency'         => $this->baseCurrency,
				'amount_basis'     => 'net',
				'price_minor'      => $price,
				'compare_at_minor' => 0 === $random->getInt( 0, 4 ) ? $price + $random->getInt( 100, 5000 ) : null,
				'created_at'       => $created,
				'updated_at'       => $created,
			);

			$onHand = 0;

			for ( $entry = 0, $entries = $random->getInt( 1, 5 ); $entry < $entries; $entry++ ) {
				list( $reason, $delta ) = self::movement( $random, $reasons, $entry, $onHand );

				$onHand += $delta;

				$buffers[ InventoryTables::LEDGER ][] = array(
					'id'             => ++$ledgerId,
					'variant_id'     => $product,
					'delta'          => $delta,
					'on_hand_after'  => $onHand,
					'reason'         => $reason->value,
					'actor_type'     => 'user',
					'actor_id'       => self::AUTHOR_ID,
					'correlation_id' => self::uuid( $random ),
					'created_at'     => gmdate( 'Y-m-d H:i:s', $createdAt + $entry ) . '.000000',
				);
			}

			$held = $onHand < 2 ? 0 : ( 0 === $random->getInt( 0, 4 ) ? 2 : 1 );

			for ( $hold = 0; $hold < $held; $hold++ ) {
				// Half the holds are live; the others expired within the period the sweep is allowed.
				$expires = 0 === $random->getInt( 0, 1 ) ? $anchor + self::HOLD_SECONDS - $random->getInt( 0, self::HOLD_SECONDS - 60 ) : $anchor - $random->getInt( 60, 3600 );

				$buffers[ InventoryTables::HOLDS ][] = array(
					'id'            => ++$holdId,
					'variant_id'    => $product,
					'cart_id'       => $random->getInt( 1, 1000000 ),
					'order_id'      => null,
					'hold_group'    => self::uuid( $random ),
					'quantity'      => 1,
					'expires_at'    => gmdate( 'Y-m-d H:i:s', $expires ),
					'reclaim_token' => null,
					'created_at'    => gmdate( 'Y-m-d H:i:s', $expires - self::HOLD_SECONDS ) . '.000000',
				);
			}

			$buffers[ InventoryTables::ITEMS ][] = array(
				'variant_id'          => $product,
				'on_hand'             => $onHand,
				'allocated'           => 0,
				'held'                => $held,
				'track'               => 1,
				'backorder_policy'    => 'no',
				'low_stock_threshold' => 0 === $random->getInt( 0, 2 ) ? $random->getInt( 1, 10 ) : null,
				'updated_at'          => $created . '.000000',
			);

			foreach ( $buffers as $table => $rows ) {
				if ( count( $rows ) >= self::CHUNK ) {
					yield array( $table, $rows );

					$buffers[ $table ] = array();
				}
			}
		}

		foreach ( $buffers as $table => $rows ) {
			if ( array() !== $rows ) {
				yield array( $table, $rows );
			}
		}
	}

	/**
	 * Draws one ledger movement: the first receives stock, the others move it without taking on_hand below zero.
	 *
	 * @since 0.1.0
	 *
	 * @param \Random\Randomizer $random  The RNG.
	 * @param LedgerReason[]     $reasons The merchant's reasons.
	 * @param int                $entry   The movement's position in the item's ledger, from 0.
	 * @param int                $onHand  The item's on_hand before it.
	 * @return array{0: LedgerReason, 1: int} The reason, and the change of on_hand, never 0.
	 *
	 * @phpstan-param list<LedgerReason> $reasons
	 */
	private static function movement( \Random\Randomizer $random, array $reasons, int $entry, int $onHand ): array {
		if ( 0 === $entry ) {
			return array( LedgerReason::Received, $random->getInt( 5, 200 ) );
		}

		$reason = $reasons[ $random->getInt( 0, count( $reasons ) - 1 ) ];

		if ( $onHand < 1 || in_array( $reason, array( LedgerReason::Received, LedgerReason::Returned ), true ) ) {
			return array( $onHand < 1 ? LedgerReason::Received : $reason, $random->getInt( 1, 50 ) );
		}

		$delta = -$random->getInt( 1, min( 20, $onHand ) );

		return array( $reason, LedgerReason::Damaged === $reason || 0 === $random->getInt( 0, 1 ) ? $delta : -$delta );
	}

	/**
	 * Returns the row of a seeded post.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $id      The post's id.
	 * @param int         $product The product it presents.
	 * @param string|null $locale  The locale of a second post, or null for the source post.
	 * @param string      $date    When it was written, UTC.
	 * @return array<string, int|string|null> The row.
	 */
	private static function post( int $id, int $product, ?string $locale, string $date ): array {
		return array(
			'ID'                    => $id,
			'post_author'           => self::AUTHOR_ID,
			'post_date'             => $date,
			'post_date_gmt'         => $date,
			'post_content'          => '',
			'post_title'            => sprintf( 'Seed product %d', $product ) . ( null === $locale ? '' : ' (' . $locale . ')' ),
			'post_excerpt'          => '',
			'post_status'           => 'publish',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => sprintf( 'seed-product-%d', $product ) . ( null === $locale ? '' : '-' . strtolower( str_replace( '_', '-', $locale ) ) ),
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $date,
			'post_modified_gmt'     => $date,
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => '',
			'menu_order'            => 0,
			'post_type'             => ProductCapabilities::POST_TYPE,
			'post_mime_type'        => '',
			'comment_count'         => 0,
		);
	}

	/**
	 * Returns the row that binds a post to its product, as the repository writes it.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $postId  The post.
	 * @param int    $product The product.
	 * @param string $locale  The post's locale.
	 * @param string $date    When it was bound, UTC.
	 * @return array<string, int|string|null> The row.
	 */
	private function binding( int $postId, int $product, string $locale, string $date ): array {
		return array(
			'post_id'           => $postId,
			'product_id'        => $product,
			'locale'            => $locale,
			'linked_at'         => $date,
			'linked_by_adapter' => null,
			'created_at'        => $date,
		);
	}

	/**
	 * Draws a version 4 UUID, in lower case.
	 *
	 * @since 0.1.0
	 *
	 * @param \Random\Randomizer $random The RNG.
	 * @return string The UUID.
	 */
	private static function uuid( \Random\Randomizer $random ): string {
		$bytes    = $random->getBytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return sprintf( '%s-%s-%s-%s-%s', substr( $hex, 0, 8 ), substr( $hex, 8, 4 ), substr( $hex, 12, 4 ), substr( $hex, 16, 4 ), substr( $hex, 20 ) );
	}

	/**
	 * Returns how many posts the dataset writes.
	 *
	 * @since 0.1.0
	 *
	 * @return int The posts.
	 */
	private function postCount(): int {
		return $this->dataset->products() + intdiv( $this->dataset->products(), 2 );
	}

	/**
	 * Refuses a store that already holds products, stock or a post in the seeded id range.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When it does.
	 *
	 * @param Database $db The connection.
	 */
	private function refuseAnOccupiedStore( Database $db ): void {
		foreach ( self::TABLES as $table ) {
			$taken = 'posts' === $table
				? $db->fetchValue( 'SELECT ID FROM %i WHERE ID >= %d LIMIT 1', self::tableName( $db, $table ), self::FIRST_POST_ID )
				: $db->fetchValue( 'SELECT 1 FROM %i LIMIT 1', self::tableName( $db, $table ) );

			if ( null !== $taken ) {
				throw new \RuntimeException( sprintf( 'The table %s already holds rows the seed would collide with. Seed a store that has no products, no stock and no post with an id from %d.', self::tableName( $db, $table ), self::FIRST_POST_ID ) );
			}
		}
	}

	/**
	 * Writes rows of one table with one INSERT.
	 *
	 * @since 0.1.0
	 *
	 * @param Database                             $db    The connection.
	 * @param string                               $table The table's full name.
	 * @param list<array<string, int|string|null>> $rows  The rows, each with the same columns.
	 */
	private static function insert( Database $db, string $table, array $rows ): void {
		$columns   = array_keys( $rows[0] );
		$tuples    = array();
		$arguments = array( $table );

		foreach ( $rows as $row ) {
			$placeholders = array();

			foreach ( $columns as $column ) {
				$value = $row[ $column ];

				if ( null === $value ) {
					$placeholders[] = 'NULL';

					continue;
				}

				$placeholders[] = is_int( $value ) ? '%d' : '%s';
				$arguments[]    = $value;
			}

			$tuples[] = '( ' . implode( ', ', $placeholders ) . ' )';
		}

		$db->execute( 'INSERT INTO %i ( ' . implode( ', ', $columns ) . ' ) VALUES ' . implode( ', ', $tuples ), ...$arguments );
	}

	/**
	 * Returns the full name of a table rows() names.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db    The connection.
	 * @param string   $table A key of TABLES.
	 * @return string The prefixed name.
	 */
	private static function tableName( Database $db, string $table ): string {
		return 'posts' === $table ? $db->prefix() . 'posts' : $db->table( $table );
	}
}
