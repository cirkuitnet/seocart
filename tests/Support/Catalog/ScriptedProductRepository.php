<?php
/**
 * ScriptedProductRepository: a product repository that writes to a step log and answers as scripted
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Locale;

/**
 * Records every call of the product write in the step log, with the answers a test scripts.
 *
 * Owns one fact: what a unit test of the product write sees of storage. findByPost() builds a
 * fresh product each time it is asked, as a read from storage would. A write outside a
 * transaction throws, as the real repository's marker writes do. save() gives a new product the
 * id 90 and a new default variant the id 91.
 *
 * @since 0.1.0
 */
final class ScriptedProductRepository implements ProductRepository {

	/**
	 * What markUpdating() answers: by default the mark of a complete product.
	 *
	 * @since 0.1.0
	 *
	 * @var UpdatingMark|null
	 */
	public ?UpdatingMark $mark;

	/**
	 * What relock() answers.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $relocks = true;

	/**
	 * What leaveUpdating() answers.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $settles = true;

	/**
	 * What restoreMark() throws after it is recorded, or null to answer true.
	 *
	 * @since 0.1.0
	 *
	 * @var \RuntimeException|null
	 */
	public ?\RuntimeException $restoreFailsWith = null;

	/**
	 * The step log.
	 *
	 * @since 0.1.0
	 *
	 * @var StepLog
	 */
	private StepLog $log;

	/**
	 * The transaction the writes must be part of.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Builds the stored product of a post, or null for an unbound post.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(int): ?Product
	 */
	private \Closure $stored;

	/**
	 * Holds the script.
	 *
	 * @since 0.1.0
	 *
	 * @param StepLog            $log    The step log.
	 * @param TransactionManager $tx     The transaction the writes must be part of.
	 * @param \Closure|null      $stored Optional. Builds the stored product of a post id, or null. Default none: every post is unbound.
	 *
	 * @phpstan-param (\Closure(int): ?Product)|null $stored
	 */
	public function __construct( StepLog $log, TransactionManager $tx, ?\Closure $stored = null ) {
		$this->log    = $log;
		$this->tx     = $tx;
		$this->stored = $stored ?? static fn(): ?Product => null;
		$this->mark   = new UpdatingMark( GenerationState::Complete, '2026-09-24 12:00:00.000001' );
	}

	/**
	 * Records the read and builds the stored product.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return Product|null The product, or null.
	 */
	public function findByPost( int $postId ): ?Product {
		$this->log->record( 'repository.findByPost' );

		return ( $this->stored )( $postId );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function findBySourcePost( int $postId ): never {
		throw new \LogicException( 'The product write finds a product by its post.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function find( int $productId ): never {
		throw new \LogicException( 'The product write finds a product by its post.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function lock( int $productId ): never {
		throw new \LogicException( 'The product write deletes nothing.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $postId Unused.
	 * @return never
	 */
	public function lockByPost( int $postId ): never {
		throw new \LogicException( 'The product write deletes nothing.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $productId Unused.
	 * @return never
	 */
	public function lockVariants( int $productId ): never {
		throw new \LogicException( 'The product write trashes nothing.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param Product $product Unused.
	 * @return never
	 */
	public function delete( Product $product ): never {
		throw new \LogicException( 'The product write deletes nothing.' );
	}

	/**
	 * Not used by the product write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $variantId Unused.
	 * @return never
	 */
	public function findByVariant( int $variantId ): never {
		throw new \LogicException( 'The product write finds a product by its post.' );
	}

	/**
	 * Records the write and gives new rows their ids.
	 *
	 * @since 0.1.0
	 *
	 * @param Product $product The product.
	 */
	public function save( Product $product ): void {
		$this->write( 'repository.save' );

		$variant = $product->defaultVariant();

		$product->identify( $product->id() ?? 90, null === $variant ? null : ( $variant->id() ?? 91 ) );
	}

	/**
	 * Records the mark and answers the scripted one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId Unused.
	 * @return UpdatingMark|null The scripted mark.
	 */
	public function markUpdating( int $productId ): ?UpdatingMark {
		$this->write( 'repository.markUpdating' );

		return $this->mark;
	}

	/**
	 * Records the relock and answers as scripted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId Unused.
	 * @return bool The scripted answer.
	 */
	public function relock( int $productId ): bool {
		$this->write( 'repository.relock' );

		return $this->relocks;
	}

	/**
	 * Records the settle statement and answers as scripted.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $productId Unused.
	 * @param GenerationState $to        The marker it would write.
	 * @return bool The scripted answer.
	 */
	public function leaveUpdating( int $productId, GenerationState $to ): bool {
		$this->write( 'repository.leaveUpdating: ' . $to->value );

		return $this->settles;
	}

	/**
	 * Records the restore, then fails it when told to.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When told to.
	 *
	 * @param int             $productId Unused.
	 * @param GenerationState $before    The marker it would put back.
	 * @param string          $markedAt  The instant of the mark it would take back.
	 * @return bool True.
	 */
	public function restoreMark( int $productId, GenerationState $before, string $markedAt ): bool {
		$this->log->record( sprintf( 'repository.restoreMark: %s at %s', $before->value, $markedAt ) );

		if ( null !== $this->restoreFailsWith ) {
			throw $this->restoreFailsWith;
		}

		return true;
	}

	/**
	 * Knows no facts: the product write's verdict is read from real storage in the integration tests.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$variantIds Unused.
	 * @return array{} None.
	 */
	public function sellabilityFacts( int ...$variantIds ): array {
		return array();
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
	 * Records a write, which must be inside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 *
	 * @param string $step The step.
	 */
	private function write( string $step ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( '%s runs inside a transaction only.', $step ) );
		}

		$this->log->record( $step );
	}

	/**
	 * Knows no facts in a locale either.
	 *
	 * @since 0.1.0
	 *
	 * @param Locale $locale        Unused.
	 * @param int    ...$variantIds Unused.
	 * @return array{} None.
	 */
	public function sellabilityFactsIn( Locale $locale, int ...$variantIds ): array {
		return array();
	}

	/**
	 * Not used by the product write.
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
		throw new \LogicException( 'The product write in these tests changes no binding.' );
	}

	/**
	 * Not used by the product write.
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
		throw new \LogicException( 'The product write in these tests changes no binding.' );
	}

	/**
	 * Not used by the product write.
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
		throw new \LogicException( 'The product write in these tests changes no binding.' );
	}

	/**
	 * Not used by the product write.
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
		throw new \LogicException( 'The product write in these tests changes no binding.' );
	}

	/**
	 * Not used by the product write.
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
		throw new \LogicException( 'The product write in these tests changes no binding.' );
	}
}
