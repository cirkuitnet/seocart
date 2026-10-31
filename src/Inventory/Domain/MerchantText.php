<?php
/**
 * MerchantText: the rule for text of several lines a merchant writes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Says whether a merchant's text of several lines may be kept: a location's pickup instructions, a transfer's note.
 *
 * Owns one fact: which characters such text may hold. Line breaks and tabs are text; any other
 * control character is not, because it shows as nothing, or as garbage, wherever the text is read.
 *
 * @since 0.2.0
 */
final class MerchantText {

	/**
	 * A character no text of several lines holds: a control character other than a tab or a line break.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOT_IN_TEXT = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	/**
	 * Tells whether a text holds only what text of several lines may.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return bool True when it holds no control character but tabs and line breaks.
	 */
	public static function isText( string $text ): bool {
		return 1 !== preg_match( self::NOT_IN_TEXT, $text );
	}
}
