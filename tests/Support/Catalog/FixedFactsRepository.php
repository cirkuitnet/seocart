<?php
/**
 * FixedFactsRepository: a product repository that answers the sellability fetch from facts it is given
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Support\Locale;

/**
 * Answers sellabilityFacts() from a fixed list and records every set of ids it was asked about.
 *
 * Owns one fact: what a unit test of a sellability reader sees of storage. Every other method
 * is a programming error here and throws.
 *
 * @since 0.1.0
 */
final class FixedFactsRepository implements ProductRepository {

	/**
	 * The ids of each fetch, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<list<int>>
	 */
	public array $asked = array();

	/**
	 * The locale and the ids of each fetch in a locale, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: string, 1: list<int>}>
	 */
	public array $askedIn = array();

	/**
	 * The facts held.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SellabilityFacts>
	 */
	private array $facts;

	/**
	 * Holds the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param SellabilityFacts ...$facts The facts.
	 */
	public function __construct( SellabilityFacts ...$facts ) {
		$this->facts = array_values( $facts );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function findByPost( int $postId ): never {
		throw new \LogicException( 'A sellability reader loads no product.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function findBySourcePost( int $postId ): never {
		throw new \LogicException( 'A sellability reader loads no product.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function find( int $productId ): never {
		throw new \LogicException( 'A sellability reader loads no product.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function lock( int $productId ): never {
		throw new \LogicException( 'A sellability reader deletes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function lockByPost( int $postId ): never {
		throw new \LogicException( 'A sellability reader deletes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function lockVariants( int $productId ): never {
		throw new \LogicException( 'A sellability reader trashes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param Product $product Unused.
	 * @return never
	 */
	public function delete( Product $product ): never {
		throw new \LogicException( 'A sellability reader deletes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $variantId Unused.
	 * @return never
	 */
	public function findByVariant( int $variantId ): never {
		throw new \LogicException( 'A sellability reader loads no product.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param Product $product Unused.
	 * @return never
	 */
	public function save( Product $product ): never {
		throw new \LogicException( 'A sellability reader writes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function markUpdating( int $productId ): never {
		throw new \LogicException( 'A sellability reader writes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function relock( int $productId ): never {
		throw new \LogicException( 'A sellability reader writes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int             $productId Unused.
	 * @param GenerationState $to        Unused.
	 * @return never
	 */
	public function leaveUpdating( int $productId, GenerationState $to ): never {
		throw new \LogicException( 'A sellability reader writes nothing.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int             $productId Unused.
	 * @param GenerationState $before    Unused.
	 * @param string          $markedAt  Unused.
	 * @return never
	 */
	public function restoreMark( int $productId, GenerationState $before, string $markedAt ): never {
		throw new \LogicException( 'A sellability reader writes nothing.' );
	}

	/**
	 * Returns the held facts of the variants asked about, and records the ids.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$variantIds The ids.
	 * @return list<SellabilityFacts> The facts.
	 */
	public function sellabilityFacts( int ...$variantIds ): array {
		$this->asked[] = array_values( $variantIds );

		return array_values( array_filter( $this->facts, static fn( SellabilityFacts $fact ): bool => in_array( $fact->variantId, $variantIds, true ) ) );
	}

	/**
	 * Returns the fixed facts of the variants asked for, as the facts in a locale, and records the locale with the ids.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale        The locale.
	 * @param int    ...$variantIds The variants.
	 * @return list<SellabilityFacts> Their facts.
	 */
	public function sellabilityFactsIn( Locale $locale, int ...$variantIds ): array {
		$this->askedIn[] = array( $locale->toString(), array_values( $variantIds ) );

		return array_values( array_filter( $this->facts, static fn( SellabilityFacts $fact ): bool => in_array( $fact->variantId, $variantIds, true ) ) );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $afterId Unused.
	 * @param int $limit   Unused.
	 * @return never
	 */
	public function unboundProductIds( int $afterId, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $afterId Unused.
	 * @param int $limit   Unused.
	 * @return never
	 */
	public function invalidSourceBindings( int $afterId, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $limit Unused.
	 * @return never
	 */
	public function danglingBindings( int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId    Unused.
	 * @param int $productId Unused.
	 * @return never
	 */
	public function deleteDanglingBinding( int $postId, int $productId ): bool {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $afterId Unused.
	 * @param int $limit   Unused.
	 * @return never
	 */
	public function unboundPostIds( int $afterId, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function lockedPostTypeAndStatus( int $postId ): ?array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param string $baseCurrency Unused.
	 * @param int    $afterId      Unused.
	 * @param int    $limit        Unused.
	 * @return never
	 */
	public function incompleteMismatchIds( string $baseCurrency, int $afterId, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param string $before Unused.
	 * @param int    $limit  Unused.
	 * @return never
	 */
	public function stuckUpdating( string $before, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $limit Unused.
	 * @return never
	 */
	public function orphanVariants( int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $limit Unused.
	 * @return never
	 */
	public function orphanPrices( int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @return never
	 */
	public function incompleteCount(): int {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $afterId Unused.
	 * @param int $limit   Unused.
	 * @return never
	 */
	public function variantIds( int $afterId, int $limit ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param array $variantIds Unused.
	 * @return never
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function variantsExisting( array $variantIds ): array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function reloadUnderLock( int $productId ): ?array {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by the tests this double serves.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int             $productId      Unused.
	 * @param GenerationState $from           Unused.
	 * @param GenerationState $to             Unused.
	 * @param string|null     $updatedAtMatch Unused.
	 * @return never
	 */
	public function settleIfUnchanged( int $productId, GenerationState $from, GenerationState $to, ?string $updatedAtMatch = null ): bool {
		throw new \LogicException( 'Not used by the tests this double serves.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId        Unused.
	 * @param int ...$productIds Unused.
	 * @return never
	 */
	public function lockWithPost( int $postId, int ...$productIds ): never {
		throw new \LogicException( 'A sellability reader changes no binding.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int    $postId Unused.
	 * @param Locale $locale Unused.
	 * @return never
	 */
	public function moveBinding( int $postId, Locale $locale ): never {
		throw new \LogicException( 'A sellability reader changes no binding.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int                $productId Unused.
	 * @param ProductPostBinding $binding   Unused.
	 * @return never
	 */
	public function addBinding( int $productId, ProductPostBinding $binding ): never {
		throw new \LogicException( 'A sellability reader changes no binding.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @param int $postId    Unused.
	 * @return never
	 */
	public function removeBinding( int $productId, int $postId ): never {
		throw new \LogicException( 'A sellability reader changes no binding.' );
	}

	/**
	 * Not used by a reader.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int      $productId  Unused.
	 * @param int      $fromPostId Unused.
	 * @param int|null $toPostId   Unused.
	 * @return never
	 */
	public function promoteSource( int $productId, int $fromPostId, ?int $toPostId ): never {
		throw new \LogicException( 'A sellability reader changes no binding.' );
	}
}
