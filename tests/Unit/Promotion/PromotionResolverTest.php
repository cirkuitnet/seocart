<?php
/**
 * Tests resolving codes into promotions, and the one answer a refused code gets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\RejectedCode;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Application\PromotionResolver;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\FakePromotionRepository;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Promotion\Promotions;

/**
 * Codes resolve with one read, in the order they were applied, each to its promotion or to a reason; a refused code always gets the same answer.
 *
 * Planted violation, shown red and removed: in PromotionResolver::forCodes(), keep the promotions
 * in the order the repository returns them. The facts then come in the reverse of the code order,
 * and the first test fails.
 *
 * @since 0.1.0
 */
final class PromotionResolverTest extends TestCase {

	/**
	 * The instant every code is resolved at.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-24 12:00:00';

	/**
	 * The store's promotions.
	 *
	 * @since 0.1.0
	 *
	 * @var FakePromotionRepository
	 */
	private FakePromotionRepository $promotions;

	/**
	 * Adds a promotion of each kind that applies, and one of each that does not.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->promotions = new FakePromotionRepository( new FakeTransactionManager() );

		$this->promotions->add( Promotions::of( 1, 'TEN' ) );
		$this->promotions->add( Promotions::of( 2, 'SHIP', PromotionEffect::freeShipping() ) );
		$this->promotions->add( Promotions::of( 3, 'FIVE', PromotionEffect::fixed( Inputs::amount( '5.00', AmountBasis::Net, 'USD' ) ) ) );
		$this->promotions->add( Promotions::of( 4, 'PAUSED', status: 'paused' ) );
		$this->promotions->add( Promotions::of( 5, 'SOON', startsAt: self::now()->modify( '+1 day' ) ) );
		$this->promotions->add( Promotions::of( 6, 'GONE', endsAt: self::now()->modify( '-1 day' ) ) );
		$this->promotions->add( Promotions::of( 7, 'FIVEGBP', PromotionEffect::fixed( Inputs::amount( '5.00', AmountBasis::Net, 'GBP' ) ) ) );
		$this->promotions->add( Promotions::of( 8, 'ONCE' ), 1, 1 );
	}

	/**
	 * Tests that codes resolve with one read, in the order they were applied, each once, and the rest with their reasons.
	 *
	 * @since 0.1.0
	 */
	public function test_codes_resolve_with_one_read_in_code_order_with_the_reasons_of_the_rest(): void {
		$resolved = $this->resolver()->forCodes( array( 'FIVE', 'NOPE', 'TEN', 'PAUSED', 'SHIP', 'SOON', 'GONE', 'ONCE', 'FIVEGBP', 'TEN' ), Currency::of( 'USD' ), self::now() );

		$this->assertSame( array( 'findByCodes:FIVE,NOPE,TEN,PAUSED,SHIP,SOON,GONE,ONCE,FIVEGBP' ), $this->promotions->calls, 'One read, each code once.' );
		$this->assertSame( array( 'FIVE', 'TEN', 'SHIP' ), array_map( static fn( PromotionFacts $facts ): string => $facts->code, $resolved->facts ) );
		$this->assertSame(
			array(
				'NOPE:' . RejectedCode::UNKNOWN,
				'PAUSED:' . Promotion::NOT_ACTIVE,
				'SOON:' . Promotion::NOT_STARTED,
				'GONE:' . Promotion::ENDED,
				'ONCE:' . Promotion::USED_UP,
				'FIVEGBP:' . Promotion::OTHER_CURRENCY,
			),
			array_map( static fn( RejectedCode $rejected ): string => $rejected->code . ':' . $rejected->reason, $resolved->rejected )
		);
	}

	/**
	 * Tests that no code reads nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_no_code_reads_nothing(): void {
		$resolved = $this->resolver()->forCodes( array(), Currency::of( 'USD' ), self::now() );

		$this->assertSame( array( array(), array() ), array( $resolved->facts, $resolved->rejected ) );
		$this->assertSame( array(), $this->promotions->calls );
	}

	/**
	 * Tests that a code a customer applies returns its promotion, and that every refused code gets the identical answer.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_code_gets_the_same_answer_whatever_the_reason(): void {
		$this->assertSame( 1, $this->resolver()->require( 'TEN', Currency::of( 'USD' ) )->id );

		$answers = array();

		foreach ( array( 'NOPE', 'PAUSED', 'SOON', 'GONE', 'ONCE', 'FIVEGBP', 'ten' ) as $code ) {
			try {
				$this->resolver()->require( $code, Currency::of( 'USD' ) );
				$this->fail( "The code {$code} was applied." );
			} catch ( CodedException $refused ) {
				$answers[ $code ] = array( $refused->errorCode(), $refused->context() );
			}
		}

		$this->assertSame( array_fill_keys( array( 'NOPE', 'PAUSED', 'SOON', 'GONE', 'ONCE', 'FIVEGBP', 'ten' ), array( PromotionError::CodeInvalid, array() ) ), $answers, 'Unknown, inactive, not started, ended, used up, in another currency, or in another case: one answer, with nothing to tell them apart.' );
	}

	/**
	 * Returns the resolver over the store's promotions, with its clock at the instant every code is resolved at.
	 *
	 * @since 0.1.0
	 *
	 * @return PromotionResolver The resolver.
	 */
	private function resolver(): PromotionResolver {
		return new PromotionResolver( $this->promotions, FrozenClock::at( self::NOW ) );
	}

	/**
	 * Returns the instant every code is resolved at.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant.
	 */
	private static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) );
	}
}
