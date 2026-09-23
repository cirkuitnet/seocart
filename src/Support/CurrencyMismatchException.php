<?php
/**
 * CurrencyMismatchException: two amounts in different currencies were combined
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports an attempt to combine amounts in different currencies.
 *
 * This class owns one fact: that amounts in different currencies are never added, subtracted
 * or compared (target architecture §21.2). Converting first, through a ConversionContext, is
 * the only way across. Every operation that combines amounts asks assertSameCurrency(), so the
 * rule and its message live here once. Reaching this exception is a programming error, so it
 * is a LogicException and has no row in the error table.
 *
 * @since 0.1.0
 */
final class CurrencyMismatchException extends \LogicException {

	/**
	 * Refuses a currency other than the one an operation works in.
	 *
	 * @since 0.1.0
	 *
	 * @throws CurrencyMismatchException When the currencies differ.
	 *
	 * @param Currency $expected The currency the operation works in.
	 * @param Currency $actual   The currency it was given.
	 */
	public static function assertSameCurrency( Currency $expected, Currency $actual ): void {
		if ( ! $expected->equals( $actual ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message holds two ISO 4217 codes, which are three validated letters each, and is never rendered as HTML.
			throw new CurrencyMismatchException( sprintf( 'An amount in %1$s cannot be combined with an amount in %2$s.', $expected->code(), $actual->code() ) );
		}
	}
}
