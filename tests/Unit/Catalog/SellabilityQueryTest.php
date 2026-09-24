<?php
/**
 * Tests Query\Sellability: every verdict comes from the one fetch, judged by the one rule
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;

/**
 * The query asks the repository once for every variant's facts, answers in the order asked with
 * each id once, and answers `unknown_variant` for an id with no facts; with no id it asks nothing.
 *
 * @since 0.1.0
 */
final class SellabilityQueryTest extends TestCase {

	/**
	 * Tests that the verdicts come from one fetch, in the order asked, each id once.
	 *
	 * @since 0.1.0
	 */
	public function test_the_verdicts_come_from_one_fetch(): void {
		$repository = new FixedFactsRepository(
			new SellabilityFacts( 7, 3, GenerationState::Complete, 1, 1, true, 42, 42, 'publish', true ),
			new SellabilityFacts( 8, 3, GenerationState::Updating, 1, 1, true, 42, 42, 'publish', true ),
			new SellabilityFacts( 9, 4, GenerationState::Complete, 1, 1, true, 43, 43, 'private', true )
		);

		$verdicts = ( new Sellability( $repository ) )->of( array( 9, 7, 8, 404, 7 ), false );

		$this->assertSame( array( array( 9, 7, 8, 404 ) ), $repository->asked, 'One fetch, each id once.' );
		$this->assertSame(
			array(
				9   => SellabilityReason::PrivateProduct,
				7   => SellabilityReason::Sellable,
				8   => SellabilityReason::Updating,
				404 => SellabilityReason::UnknownVariant,
			),
			$verdicts
		);
		$this->assertSame( SellabilityReason::Sellable, ( new Sellability( $repository ) )->of( array( 9 ), true )[9], 'A reader of private products may buy a private one.' );
	}

	/**
	 * Tests that no id asks nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_no_id_asks_nothing(): void {
		$repository = new FixedFactsRepository();

		$this->assertSame( array(), ( new Sellability( $repository ) )->of( array(), false ) );
		$this->assertSame( array(), $repository->asked );
	}
}
