<?php
/**
 * OptionValue: one value of an option axis, such as 250 g
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * A value of an option axis: its key, its label, its place among the axis's values, and the image and colour swatch a shopper picks it by.
 *
 * Owns one fact: what a valid option value is. The key is fixed once the value exists and is part
 * of the combination hash of every variant that selects it; the label is the name a shopper
 * reads, 1 to 100 characters with no control character; the swatch is `#rrggbb` in lower case;
 * the image is a Media Library attachment, which may since have been deleted: the value then shows
 * no image, and doctor reports it. A value is changed only through its product, so it is a value.
 *
 * @since 0.2.0
 */
final class OptionValue {

	/**
	 * The most characters a label may have: the width of its column.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LABEL_MAX_LENGTH = 100;

	/**
	 * The shape of a colour swatch.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SWATCH_PATTERN = '/^#[0-9a-f]{6}\z/';

	/**
	 * Creates a checked value.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $key               The key.
	 * @param string      $label             The label.
	 * @param int         $position          Where it sorts among the axis's values.
	 * @param int|null    $imageAttachmentId The attachment that shows it, or null.
	 * @param string|null $swatchHex         The colour that shows it, or null.
	 */
	private function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly int $position,
		public readonly ?int $imageAttachmentId,
		public readonly ?string $swatchHex
	) {
	}

	/**
	 * Returns a value, checked.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid when the key, the label, the position, the image or the swatch is not one a value may have.
	 *
	 * @param string      $key               The key: 1 to 32 lower-case letters, digits, "-" or "_".
	 * @param string      $label             The label: 1 to 100 characters, no control character; surrounding white space is trimmed.
	 * @param int         $position          Where it sorts among the axis's values, 0 or more.
	 * @param int|null    $imageAttachmentId Optional. The attachment that shows it, 1 or more. Default null, none.
	 * @param string|null $swatchHex         Optional. The colour that shows it, `#rrggbb` in lower case. Default null, none.
	 * @return self The value.
	 */
	public static function of( string $key, string $label, int $position, ?int $imageAttachmentId = null, ?string $swatchHex = null ): self {
		$label = self::label( 'value', $key, $label );

		if ( 1 !== preg_match( VariantSelection::KEY_PATTERN, $key ) ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'value key',
					'value' => self::shown( $key ),
				)
			);
		}

		if ( $position < 0 ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'position',
					'value' => (string) $position,
				)
			);
		}

		if ( null !== $imageAttachmentId && $imageAttachmentId < 1 ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'image',
					'value' => (string) $imageAttachmentId,
				)
			);
		}

		if ( null !== $swatchHex && 1 !== preg_match( self::SWATCH_PATTERN, $swatchHex ) ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => 'swatch',
					'value' => self::shown( $swatchHex ),
				)
			);
		}

		return new self( $key, $label, $position, $imageAttachmentId, $swatchHex );
	}

	/**
	 * Returns a label checked for an axis or a value: trimmed, 1 to 100 characters, no control or formatting character.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException With CatalogError::OptionInvalid when the label is empty, too long, or not valid UTF-8 text.
	 *
	 * @param string $of    What the label names, `axis` or `value`, for the error.
	 * @param string $key   The key of what it names, for the error.
	 * @param string $label The label as given.
	 * @return string The label, trimmed.
	 */
	public static function label( string $of, string $key, string $label ): string {
		$label = trim( $label );
		$valid = '' !== $label
			&& 1 === preg_match( '//u', $label )
			&& 0 === preg_match( '/[\p{Cc}\p{Cf}]/u', $label )
			&& (int) preg_match_all( '/./su', $label ) <= self::LABEL_MAX_LENGTH;

		if ( ! $valid ) {
			CodedException::raise(
				CatalogError::OptionInvalid,
				array(
					'field' => $of . ' label',
					'value' => self::shown( $key ),
				)
			);
		}

		return $label;
	}

	/**
	 * Returns what a refused value's error shows: printable ASCII, at most 40 characters.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value as given.
	 * @return string The value to show.
	 */
	public static function shown( string $value ): string {
		return substr( (string) preg_replace( '/[^\x20-\x7e]/', '?', $value ), 0, 40 );
	}

	/**
	 * Tells whether two values are the same in every field.
	 *
	 * @since 0.2.0
	 *
	 * @param OptionValue $other The value to compare with.
	 * @return bool True when they are.
	 */
	public function equals( OptionValue $other ): bool {
		return $this->key === $other->key
			&& $this->label === $other->label
			&& $this->position === $other->position
			&& $this->imageAttachmentId === $other->imageAttachmentId
			&& $this->swatchHex === $other->swatchHex;
	}
}
