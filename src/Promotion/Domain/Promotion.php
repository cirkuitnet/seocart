<?php
/**
 * Promotion: a stored promotion, and whether it applies to a calculation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Domain;

use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * A promotion as a code finds it: what it does, its status, its window, its uses and its order among promotions.
 *
 * Owns one fact: when a promotion found by its code applies to a calculation. It applies while
 * it is active, from its start to its end (either may be open), while its uses are below its
 * usage limit (if it has one), and, when it takes a fixed amount off, only to a calculation in
 * that amount's currency: "10.00 off" in one currency is no amount at all in another. A
 * percentage and free shipping apply in any currency. A used-up promotion is turned away as
 * soon as its code is read, so a customer is never shown a discount no order can take; the
 * last use is still decided when an order is placed, by the claim, which holds however many
 * orders race for it.
 *
 * Conditions, thresholds, markets, per-customer limits and stacking policies are stored with a
 * promotion but not yet evaluated.
 *
 * @since 0.1.0
 */
final readonly class Promotion {

	/**
	 * The status of a promotion that applies.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ACTIVE = 'active';

	/**
	 * Every promotion code, as a regular expression without delimiters: one to 64 upper-case
	 * letters, digits, hyphens and underscores.
	 *
	 * A code a customer enters is trimmed and upper-cased first, then held to this. Every
	 * character fits in one segment of a URL as it is, so a code a cart holds can always be named
	 * in the path that removes it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE_PATTERN = '[A-Z0-9_-]{1,64}';

	/**
	 * Why a code does not apply: its promotion is not active.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOT_ACTIVE = 'not_active';

	/**
	 * Why a code does not apply: its promotion has not started.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOT_STARTED = 'not_started';

	/**
	 * Why a code does not apply: its promotion has ended.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ENDED = 'ended';

	/**
	 * Why a code does not apply: its promotion has been used as many times as its limit allows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const USED_UP = 'used_up';

	/**
	 * Why a code does not apply: its promotion takes an amount off in another currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OTHER_CURRENCY = 'other_currency';

	/**
	 * Holds the promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param int                     $id         The promotion's id.
	 * @param string                  $uuid       Its uuid, which its adjustments' source names.
	 * @param string                  $code       The code that finds it.
	 * @param string                  $status     ACTIVE, or another status in which it does not apply.
	 * @param PromotionEffect         $effect     What it does.
	 * @param int                     $priority   The order promotions apply in, lowest first.
	 * @param \DateTimeImmutable|null $startsAt   When it starts applying; null for no start.
	 * @param \DateTimeImmutable|null $endsAt     When it stops applying; null for no end.
	 * @param int                     $used       How many orders hold a use of it, as read.
	 * @param int|null                $usageLimit How many orders may use it; null for no limit.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $code,
		public string $status,
		public PromotionEffect $effect,
		public int $priority,
		public ?\DateTimeImmutable $startsAt,
		public ?\DateTimeImmutable $endsAt,
		public int $used,
		public ?int $usageLimit
	) {
	}

	/**
	 * Tells whether a text is written as a promotion code is: CODE_PATTERN, the whole text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code The text, already trimmed and upper-cased.
	 * @return bool True when it may be a code.
	 */
	public static function isCode( string $code ): bool {
		return 1 === preg_match( '/\A' . self::CODE_PATTERN . '\z/', $code );
	}

	/**
	 * Tells why the promotion does not apply to a calculation, or that it does.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency           $currency The calculation's currency.
	 * @param \DateTimeImmutable $now      The instant the calculation is asked for.
	 * @return string|null NOT_ACTIVE, NOT_STARTED, ENDED, USED_UP or OTHER_CURRENCY; null when it applies.
	 */
	public function rejectionFor( Currency $currency, \DateTimeImmutable $now ): ?string {
		$amount = $this->effect->amount;

		return match ( true ) {
			self::ACTIVE !== $this->status                                  => self::NOT_ACTIVE,
			null !== $this->startsAt && $now < $this->startsAt              => self::NOT_STARTED,
			null !== $this->endsAt && $now > $this->endsAt                  => self::ENDED,
			null !== $this->usageLimit && $this->used >= $this->usageLimit  => self::USED_UP,
			null !== $amount && ! $amount->currency()->equals( $currency ) => self::OTHER_CURRENCY,
			default                                                         => null,
		};
	}

	/**
	 * Returns what the calculation is told about the promotion.
	 *
	 * @since 0.1.0
	 *
	 * @return PromotionFacts The facts.
	 */
	public function facts(): PromotionFacts {
		return new PromotionFacts( $this->id, $this->uuid, $this->code, $this->effect, $this->priority );
	}
}
