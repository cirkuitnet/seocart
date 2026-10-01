<?php
/**
 * Tests resolving stored promotions by their codes: one query, the reasons, and the one answer a refused code gets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\RejectedCode;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Application\PromotionResolver;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Promotion\PromotionTestCase;

/**
 * Stored promotions resolve by code with one query, each row read into its effect; codes that do not apply are turned away with their reasons, and a customer's refused code always gets the same answer.
 *
 * The windows are written relative to a fixed instant, and the codes are resolved at it: no
 * test reads the database clock.
 *
 * Planted violation, shown red and removed: in Promotion::rejectionFor(), drop the end of the
 * window. The ended promotion then becomes a promotion of the calculation, and the reasons differ.
 *
 * @since 0.1.0
 */
final class PromotionResolverTest extends PromotionTestCase {

	/**
	 * The instant every code is resolved at.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-24 12:00:00';

	/**
	 * Tests that three stored codes resolve with one query into their effects, in code order.
	 *
	 * @since 0.1.0
	 */
	public function test_three_codes_resolve_with_one_query_into_their_effects(): void {
		$this->plantPromotion( 'TEN', array( 'priority' => 5 ) );
		$this->plantPromotion(
			'SHIP',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->plantPromotion( 'FIVE', self::fixed( 500, 'EUR', 'gross' ) );

		$resolved = null;
		$log      = $this->captureQueries(
			function () use ( &$resolved ): void {
				$resolved = $this->resolver->forCodes( array( 'SHIP', 'FIVE', 'TEN' ), Currency::of( 'EUR' ), self::now() );
			}
		);

		$this->assertQueryCount( 1, $log, 'Queries to resolve three codes' );
		$this->assertNotNull( $resolved );
		$this->assertSame( array(), $resolved->rejected );
		$this->assertSame(
			array( 'SHIP:free_shipping:10', 'FIVE:fixed:10', 'TEN:percent:5' ),
			array_map( static fn( PromotionFacts $facts ): string => $facts->code . ':' . $facts->effect->kind . ':' . $facts->priority, $resolved->facts ),
			'In the order of the codes; the evaluator orders them by priority.'
		);
		$this->assertSame( 10000000, $resolved->facts[2]->effect->percentage?->micropercent() );
		$this->assertEquals( Inputs::amount( '5.00', AmountBasis::Gross, 'EUR' ), $resolved->facts[1]->effect->amount );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $resolved->facts[0]->uuid );
	}

	/**
	 * Tests that codes that do not apply are turned away with their reasons, and that no code sends no query.
	 *
	 * @since 0.1.0
	 */
	public function test_codes_that_do_not_apply_are_turned_away_with_their_reasons(): void {
		$this->plantPromotion( 'GONE', array( 'ends_at' => '2026-09-24 11:59:59' ) );
		$this->plantPromotion( 'SOON', array( 'starts_at' => '2026-09-24 12:00:01' ) );
		$this->plantPromotion( 'DRAFT', array( 'status' => 'draft' ) );
		$this->plantPromotion( 'POUNDS', self::fixed( 1000, 'GBP', 'net' ) );
		$this->plantPromotion(
			'ONCE',
			array(
				'usage_limit' => 1,
				'used'        => 1,
			)
		);
		$this->plantPromotion(
			'TWICE',
			array(
				'usage_limit' => 2,
				'used'        => 1,
			)
		);
		$this->plantPromotion(
			'EDGES',
			array(
				'starts_at' => self::NOW,
				'ends_at'   => self::NOW,
			)
		);

		$resolved = $this->resolver->forCodes( array( 'GONE', 'NOPE', 'SOON', 'DRAFT', 'POUNDS', 'ONCE', 'TWICE', 'EDGES' ), Currency::of( 'EUR' ), self::now() );

		$this->assertSame( array( 'TWICE', 'EDGES' ), array_map( static fn( PromotionFacts $facts ): string => $facts->code, $resolved->facts ), 'A window includes its first and last instant, and a limit applies until it is reached.' );
		$this->assertSame(
			array( 'GONE:' . Promotion::ENDED, 'NOPE:' . RejectedCode::UNKNOWN, 'SOON:' . Promotion::NOT_STARTED, 'DRAFT:' . Promotion::NOT_ACTIVE, 'POUNDS:' . Promotion::OTHER_CURRENCY, 'ONCE:' . Promotion::USED_UP ),
			array_map( static fn( RejectedCode $rejected ): string => $rejected->code . ':' . $rejected->reason, $resolved->rejected )
		);
		$this->assertSame( 'POUNDS', $this->resolver->forCodes( array( 'POUNDS' ), Currency::of( 'GBP' ), self::now() )->facts[0]->code, 'The same fixed promotion applies to a cart in its own currency.' );

		$none = $this->captureQueries( fn() => $this->resolver->forCodes( array(), Currency::of( 'EUR' ), self::now() ) );

		$this->assertQueryCount( 0, $none, 'Queries to resolve no code' );
	}

	/**
	 * Tests that a customer's refused code gets the same answer whatever the reason, and that codes are compared exactly.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_code_gets_the_same_answer_whatever_the_reason(): void {
		$this->plantPromotion( 'GONE', array( 'ends_at' => '2026-09-24 11:59:59' ) );
		$this->plantPromotion( 'DRAFT', array( 'status' => 'draft' ) );
		$this->plantPromotion( 'POUNDS', self::fixed( 1000, 'GBP', 'net' ) );
		$this->plantPromotion(
			'ONCE',
			array(
				'usage_limit' => 1,
				'used'        => 1,
			)
		);
		$this->plantPromotion( 'GOOD' );

		$this->assertSame( 'GOOD', $this->resolverAtNow()->require( 'GOOD', Currency::of( 'EUR' ) )->code );

		$answers = array();

		foreach ( array( 'NOPE', 'GONE', 'DRAFT', 'ONCE', 'POUNDS', 'good' ) as $code ) {
			try {
				$this->resolverAtNow()->require( $code, Currency::of( 'EUR' ) );
				$this->fail( "The code {$code} was applied." );
			} catch ( CodedException $refused ) {
				$answers[ $code ] = array( $refused->errorCode(), $refused->context(), $refused->getMessage() );
			}
		}

		$this->assertSame( array( PromotionError::CodeInvalid, array() ), array_slice( $answers['NOPE'], 0, 2 ) );
		$this->assertCount( 1, array_unique( array_map( 'serialize', $answers ) ), 'Unknown, ended, not active, used up, in another currency, or in another case: one answer.' );
	}

	/**
	 * Returns the columns of a fixed amount off.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $minor    The amount in minor units.
	 * @param string $currency Its currency.
	 * @param string $basis    net or gross.
	 * @return array<string, int|string|null> The columns.
	 */
	private static function fixed( int $minor, string $currency, string $basis ): array {
		return array(
			'effect_kind'                 => 'fixed',
			'effect_percent_micropercent' => null,
			'effect_amount_minor'         => $minor,
			'effect_currency'             => $currency,
			'effect_amount_basis'         => $basis,
		);
	}

	/**
	 * Returns a resolver over this test's promotions whose clock shows the instant every code is resolved at.
	 *
	 * @since 0.1.0
	 *
	 * @return PromotionResolver The resolver.
	 */
	private function resolverAtNow(): PromotionResolver {
		return new PromotionResolver( $this->repository, FrozenClock::at( self::NOW ) );
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
