<?php
/**
 * PoisonedQuoters: shipping, tax and promotion ports that fail when the engine itself calls them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Pricing\Application\ShippingQuoteRequest;
use SEOCart\Pricing\Application\ShippingRateQuoter;
use SEOCart\Pricing\Application\TaxQuoter;
use SEOCart\Pricing\Application\TaxQuoteRequest;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Pricing\Domain\Quote\TaxQuote;

/**
 * Answers as scripted, counts every call, and throws when the call comes from where it must not.
 *
 * Owns one fact: the test of "the engine performs no I/O". The shipping and tax quoters throw
 * when any frame of the call stack belongs to the engine's namespace: a quote is taken by the
 * calculator between the engine's phases, never by the engine. The promotion evaluator is the
 * one port the engine calls, in its first phase; it throws when called from the second phase.
 * A failure scripted for the shipping quoter is thrown as it is, as a provider would.
 *
 * @since 0.1.0
 */
final class PoisonedQuoters {

	/**
	 * The namespace no quote may be taken from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ENGINE_NAMESPACE = 'SEOCart\\Pricing\\Domain\\Engine\\';

	/**
	 * How many times the shipping quoter was called.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $shippingCalls = 0;

	/**
	 * How many times the tax quoter was called.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $taxCalls = 0;

	/**
	 * How many times the evaluator was called.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $evaluations = 0;

	/**
	 * The tax classes of each tax quote asked for, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<list<string>>
	 */
	public array $taxClassesAsked = array();

	/**
	 * How many lines each shipping quote was asked for, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	public array $shippedLineCounts = array();

	/**
	 * Holds the answers.
	 *
	 * @since 0.1.0
	 *
	 * @param ShippingRateQuote[] $rates           What the shipping quoter answers.
	 * @param TaxQuote            $taxQuote        What the tax quoter answers.
	 * @param PromotionIntent[]   $intents         What the evaluator answers.
	 * @param \Throwable|null     $shippingFailure Optional. What the shipping quoter throws instead of answering. Default null.
	 *
	 * @phpstan-param list<ShippingRateQuote> $rates
	 * @phpstan-param list<PromotionIntent>   $intents
	 */
	public function __construct(
		private array $rates,
		private TaxQuote $taxQuote,
		private array $intents = array(),
		private ?\Throwable $shippingFailure = null
	) {
	}

	/**
	 * Returns the shipping quoter.
	 *
	 * @since 0.1.0
	 *
	 * @return ShippingRateQuoter The quoter.
	 */
	public function shipping(): ShippingRateQuoter {
		return new class( $this ) implements ShippingRateQuoter {

			/**
			 * Holds the answers.
			 *
			 * @since 0.1.0
			 *
			 * @param PoisonedQuoters $quoters The answers.
			 */
			public function __construct( private PoisonedQuoters $quoters ) {
			}

			/**
			 * Answers the scripted rates.
			 *
			 * @since 0.1.0
			 *
			 * @param ShippingQuoteRequest $request What to quote for.
			 * @return list<ShippingRateQuote> The rates.
			 */
			public function quote( ShippingQuoteRequest $request ): array {
				return $this->quoters->quoteShipping( $request );
			}
		};
	}

	/**
	 * Returns the tax quoter.
	 *
	 * @since 0.1.0
	 *
	 * @return TaxQuoter The quoter.
	 */
	public function tax(): TaxQuoter {
		return new class( $this ) implements TaxQuoter {

			/**
			 * Holds the answers.
			 *
			 * @since 0.1.0
			 *
			 * @param PoisonedQuoters $quoters The answers.
			 */
			public function __construct( private PoisonedQuoters $quoters ) {
			}

			/**
			 * Answers the scripted quote.
			 *
			 * @since 0.1.0
			 *
			 * @param TaxQuoteRequest $request What to quote for.
			 * @return TaxQuote The quote.
			 */
			public function quote( TaxQuoteRequest $request ): TaxQuote {
				return $this->quoters->quoteTax( $request );
			}
		};
	}

	/**
	 * Returns the promotion evaluator.
	 *
	 * @since 0.1.0
	 *
	 * @return PromotionEvaluator The evaluator.
	 */
	public function evaluator(): PromotionEvaluator {
		return new class( $this ) implements PromotionEvaluator {

			/**
			 * Holds the answers.
			 *
			 * @since 0.1.0
			 *
			 * @param PoisonedQuoters $quoters The answers.
			 */
			public function __construct( private PoisonedQuoters $quoters ) {
			}

			/**
			 * Answers the scripted intents.
			 *
			 * @since 0.1.0
			 *
			 * @param PhaseAView $view The promotions and the lines.
			 * @return list<PromotionIntent> The intents.
			 */
			public function evaluate( PhaseAView $view ): array {
				return $this->quoters->evaluate( $view );
			}
		};
	}

	/**
	 * Answers for the shipping quoter.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the engine called it.
	 * @throws \Throwable      The scripted failure.
	 *
	 * @param ShippingQuoteRequest $request What to quote for.
	 * @return list<ShippingRateQuote> The rates.
	 */
	public function quoteShipping( ShippingQuoteRequest $request ): array {
		self::refuseFrames( 'The shipping quoter', static fn( array $frame ): bool => str_starts_with( $frame['class'] ?? '', self::ENGINE_NAMESPACE ) );

		++$this->shippingCalls;
		$this->shippedLineCounts[] = count( $request->lines );

		if ( null !== $this->shippingFailure ) {
			throw $this->shippingFailure;
		}

		return $this->rates;
	}

	/**
	 * Answers for the tax quoter.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the engine called it.
	 *
	 * @param TaxQuoteRequest $request What to quote for.
	 * @return TaxQuote The quote.
	 */
	public function quoteTax( TaxQuoteRequest $request ): TaxQuote {
		self::refuseFrames( 'The tax quoter', static fn( array $frame ): bool => str_starts_with( $frame['class'] ?? '', self::ENGINE_NAMESPACE ) );

		++$this->taxCalls;
		$this->taxClassesAsked[] = $request->taxClasses;

		return $this->taxQuote;
	}

	/**
	 * Answers for the evaluator.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the engine's second phase called it.
	 *
	 * @param PhaseAView $view The promotions and the lines.
	 * @return list<PromotionIntent> The intents; none when it is shown no line.
	 */
	public function evaluate( PhaseAView $view ): array {
		self::refuseFrames( 'The promotion evaluator', static fn( array $frame ): bool => Engine::class === ( $frame['class'] ?? '' ) && 'phaseB' === $frame['function'] );

		++$this->evaluations;

		return array() === $view->lines ? array() : $this->intents;
	}

	/**
	 * Throws when a frame of the call stack is one the port may not be called from.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When one is.
	 *
	 * @param string   $port      The port, for the message.
	 * @param callable $forbidden Tells whether a frame is forbidden.
	 *
	 * @phpstan-param callable(array{function: string, class?: class-string}): bool $forbidden
	 */
	private static function refuseFrames( string $port, callable $forbidden ): void {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( $forbidden( $frame ) ) {
				throw new \LogicException( sprintf( '%s was called from %s::%s(), inside the engine.', $port, $frame['class'] ?? '', $frame['function'] ) );
			}
		}
	}
}
