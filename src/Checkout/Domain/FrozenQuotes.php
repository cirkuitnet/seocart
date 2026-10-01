<?php
/**
 * FrozenQuotes: the shipping and tax quotes a calculation of a cart consumed, as its checkout session keeps them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The quotes behind a cart's totals at one version: the shipping rate selected, and the tax quote's fingerprint.
 *
 * Owns one fact: what a checkout session keeps of a calculation's quotes. The rate is kept as the
 * document the calculation recorded of it; the tax quote is kept as a fingerprint, which is
 * enough to tell whether a later quote is the same one.
 *
 * @since 0.1.0
 */
final readonly class FrozenQuotes {

	/**
	 * Holds the quotes.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the fingerprint is not a SHA-256 in lower-case hexadecimal.
	 *
	 * @param array<string, mixed>|null $shippingRate   The shipping rate selected, as the calculation
	 *                                                  recorded it; null when no shipping was charged.
	 * @param string                    $taxFingerprint The SHA-256 of the tax quote, in lower-case hexadecimal.
	 */
	public function __construct(
		public ?array $shippingRate,
		public string $taxFingerprint
	) {
		if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $taxFingerprint ) ) {
			throw new \InvalidArgumentException( 'A tax quote fingerprint is a SHA-256 in lower-case hexadecimal: 64 characters.' );
		}
	}
}
