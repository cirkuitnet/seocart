<?php
/**
 * Percentage: an exact percentage, to a millionth of a percent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An exact percentage, such as a tax rate or a percentage discount.
 *
 * This class owns one fact: the storage form of a rate, `rate_micropercent` — an integer
 * count of millionths of a percent, so 20% is 20000000 and 7.25% is
 * 7250000 — and how that form becomes a multiplication factor. It is exact: a percentage that
 * needs more than six decimal places is refused rather than rounded, and nothing is a float.
 *
 * @since 0.1.0
 */
final class Percentage {

	/**
	 * The number of implied decimal places of a percent in the storage form.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MICROPERCENT_SCALE = 6;

	/**
	 * The scale of the factor: a percent is a hundredth, so two more places than the storage form.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const FACTOR_SCALE = 8;

	/**
	 * The percentage in millionths of a percent.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $micropercent;

	/**
	 * Creates a percentage from its storage form.
	 *
	 * @since 0.1.0
	 *
	 * @param int $micropercent The percentage in millionths of a percent.
	 */
	private function __construct( int $micropercent ) {
		$this->micropercent = $micropercent;
	}

	/**
	 * Creates a percentage from its storage form, `rate_micropercent`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $micropercent The percentage in millionths of a percent: 20000000 is 20%.
	 * @return self The percentage.
	 */
	public static function fromMicropercent( int $micropercent ): self {
		return new self( $micropercent );
	}

	/**
	 * Creates a percentage from a decimal string of percent.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the string is not a decimal, or needs more than six
	 *                                   decimal places to be exact.
	 *
	 * @param string $percent The percentage in percent, as Decimal::of() reads it: '20', '7.25'.
	 * @return self The percentage.
	 */
	public static function fromString( string $percent ): self {
		$value        = Decimal::of( $percent );
		$micropercent = $value->rescale( self::MICROPERCENT_SCALE, RoundingMode::TowardZero );

		if ( ! $micropercent->equals( $value ) ) {
			throw new \InvalidArgumentException( 'A percentage is exact to a millionth of a percent, the precision of rate_micropercent; this one needs more decimal places.' );
		}

		return new self( $micropercent->toUnscaledInt() );
	}

	/**
	 * Returns the storage form.
	 *
	 * @since 0.1.0
	 *
	 * @return int The percentage in millionths of a percent.
	 */
	public function micropercent(): int {
		return $this->micropercent;
	}

	/**
	 * Returns the factor to multiply an amount by: 0.2 for 20%.
	 *
	 * @since 0.1.0
	 *
	 * @return Decimal The factor, exactly, at scale 8.
	 */
	public function toFactor(): Decimal {
		return Decimal::ofUnscaled( $this->micropercent, self::FACTOR_SCALE );
	}

	/**
	 * Tells whether two percentages are equal.
	 *
	 * @since 0.1.0
	 *
	 * @param Percentage $other The percentage to compare with.
	 * @return bool True when both are the same number of millionths of a percent.
	 */
	public function equals( Percentage $other ): bool {
		return $this->micropercent === $other->micropercent;
	}
}
