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
use SEOCart\Catalog\Domain\SellabilityFacts;

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
}
