<?php
/**
 * Taxability: whether an adjustment is taxed, and by which tax class
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Whether an amount is taxed, and if it is, the tax class whose rates apply.
 *
 * Owns one fact: that an adjustment declares its taxability rather than inheriting a store-wide
 * switch. A taxable amount names its class; an amount that is not taxable has none and is never
 * given tax, whatever the destination.
 *
 * @since 0.1.0
 */
final readonly class Taxability {

	/**
	 * Holds the tax class, or null for an amount that is not taxed.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $taxClass The tax class, or null.
	 */
	private function __construct( public ?string $taxClass ) {
	}

	/**
	 * Returns the taxability of an amount taxed by a class.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the class is empty.
	 *
	 * @param string $taxClass The tax class, such as `standard`.
	 * @return self The taxability.
	 */
	public static function taxable( string $taxClass ): self {
		if ( '' === $taxClass ) {
			throw new \InvalidArgumentException( 'A taxable amount names its tax class.' );
		}

		return new self( $taxClass );
	}

	/**
	 * Returns the taxability of an amount that is never taxed.
	 *
	 * @since 0.1.0
	 *
	 * @return self The taxability.
	 */
	public static function notTaxable(): self {
		return new self( null );
	}

	/**
	 * Tells whether the amount is taxed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it has a tax class.
	 */
	public function isTaxable(): bool {
		return null !== $this->taxClass;
	}
}
