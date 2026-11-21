<?php
/**
 * OrderFieldValue: the value of a checkout field an order is placed with, in the words the shopper saw
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * One value of a checkout field that is no address member, with what the field was when the order was placed: its key, who declared it, its section, type and label, and a chosen option's label.
 *
 * Owns one fact: what an order keeps of a checkout field's value. Every member is a snapshot in
 * the order's locale, never read back from the field's declaration, which may change or go. The
 * value is personal data, whatever its field.
 *
 * @since 0.2.0
 */
final readonly class OrderFieldValue {

	/**
	 * The longest label kept, in characters: its column's.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LABEL_MAX_LENGTH = 255;

	/**
	 * Records the value.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the key or the value is empty, or a label is longer than LABEL_MAX_LENGTH (kept() cuts one).
	 *
	 * @param string      $key        The field's key.
	 * @param string      $source     Who declared the field: core, or the id of the extension that registered it.
	 * @param string      $section    Where the form showed it: contact or order.
	 * @param string      $type       What its value was: text, textarea, select or checkbox.
	 * @param string      $label      The field's label the shopper saw.
	 * @param string      $value      The value: for a select, the chosen option's value; for a ticked checkbox, 1.
	 * @param string|null $valueLabel For a select, the chosen option's label the shopper saw; null for any other type.
	 */
	public function __construct(
		public string $key,
		public string $source,
		public string $section,
		public string $type,
		public string $label,
		public string $value,
		public ?string $valueLabel
	) {
		if ( '' === $key || '' === $value ) {
			throw new \InvalidArgumentException( 'An order keeps a checkout field\'s value by the field\'s key, and only a value given.' );
		}

		if ( mb_strlen( $label ) > self::LABEL_MAX_LENGTH || mb_strlen( (string) $valueLabel ) > self::LABEL_MAX_LENGTH ) {
			throw new \InvalidArgumentException( sprintf( 'An order keeps a label of at most %d characters: cut it with kept().', self::LABEL_MAX_LENGTH ) );
		}
	}

	/**
	 * Returns a label as the order keeps it: its first LABEL_MAX_LENGTH characters.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The label.
	 * @return string The label kept.
	 */
	public static function kept( string $label ): string {
		return mb_substr( $label, 0, self::LABEL_MAX_LENGTH );
	}
}
