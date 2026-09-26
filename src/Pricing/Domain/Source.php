<?php
/**
 * Source: what caused an adjustment to a total
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a value built by code, for the developer; it is never rendered.

/**
 * The origin of an adjustment: a promotion, a shipping method, a fee, and so on.
 *
 * Owns one fact: that every change to a total names what caused it, in one checked form. A
 * source is a lower-case kind, optionally followed by a colon and a key of up to 80 characters
 * of a-z, 0-9, `_`, `.`, `/` and `-`: `promotion:<uuid>`, `shipping:<method key>`,
 * `fee:<key>`. It is what `order_adjustments.source` stores and what answers "which promotion
 * or which plugin changed this total".
 *
 * @since 0.1.0
 */
final readonly class Source {

	/**
	 * The form every source has.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^[a-z]+(?::[a-z0-9_.\/-]{1,80})?\z/';

	/**
	 * The source, as stored.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Checks and holds a source.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the value is empty or not of the form above.
	 *
	 * @param string $value The source, such as `shipping:flat`.
	 */
	public function __construct( string $value ) {
		if ( 1 !== preg_match( self::PATTERN, $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'An adjustment source is a lower-case kind, optionally followed by ":" and a key of up to 80 characters of a-z, 0-9, "_", ".", "/" and "-"; "%s" is not one.', $value ) );
		}

		$this->value = $value;
	}

	/**
	 * Returns the source of a promotion's adjustments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The promotion's uuid.
	 * @return self `promotion:<uuid>`.
	 */
	public static function promotion( string $uuid ): self {
		return new self( 'promotion:' . $uuid );
	}

	/**
	 * Returns the source of a shipping method's charge.
	 *
	 * @since 0.1.0
	 *
	 * @param string $methodKey The method's key.
	 * @return self `shipping:<method key>`.
	 */
	public static function shipping( string $methodKey ): self {
		return new self( 'shipping:' . $methodKey );
	}

	/**
	 * Returns the source of a fee.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The fee's key.
	 * @return self `fee:<key>`.
	 */
	public static function fee( string $key ): self {
		return new self( 'fee:' . $key );
	}

	/**
	 * Returns the source as stored.
	 *
	 * @since 0.1.0
	 *
	 * @return string The source.
	 */
	public function toString(): string {
		return $this->value;
	}
}
