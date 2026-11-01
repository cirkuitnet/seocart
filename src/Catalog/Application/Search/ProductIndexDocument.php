<?php
/**
 * ProductIndexDocument: the searchable words of one product in one locale
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * What a search ordered by relevance matches a product by, in one locale.
 *
 * Owns one fact: the text of a product's document. It is the locale's post's title and excerpt
 * and the SKUs of the product's enabled variants of its published generation, in that order,
 * as plain words: markup removed with the contents of a script or a style (each tag read as a
 * space, so two words it separated stay two), entities decoded, invalid UTF-8 replaced, and every
 * run of white space one space. It is cut at MOST_CHARACTERS, which a text column holds whatever
 * the characters. No content, no term and no price: terms are filtered as they are, and a price
 * is never a word.
 *
 * @since 0.2.0
 */
final readonly class ProductIndexDocument {

	/**
	 * The most characters a document holds: four bytes each still fit a text column.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_CHARACTERS = 16000;

	/**
	 * Holds the document's sources.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $productId The product.
	 * @param Locale   $locale    The locale of the post the title and excerpt come from.
	 * @param string   $title     The post's title, as stored.
	 * @param string   $excerpt   The post's excerpt, as stored.
	 * @param string[] $skus      The SKUs of the product's enabled variants of its published generation, in order.
	 *
	 * @phpstan-param list<string> $skus
	 */
	public function __construct(
		public int $productId,
		public Locale $locale,
		public string $title,
		public string $excerpt,
		public array $skus
	) {
	}

	/**
	 * Returns the document's text: its words, as plain text on one line.
	 *
	 * @since 0.2.0
	 *
	 * @return string The title, the excerpt and the SKUs, without markup, at most MOST_CHARACTERS characters; empty when none has a word.
	 */
	public function text(): string {
		$text = mb_scrub( implode( ' ', array_merge( array( $this->title, $this->excerpt ), $this->skus ) ), 'UTF-8' );
		$text = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return mb_substr( trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) ), 0, self::MOST_CHARACTERS, 'UTF-8' );
	}
}
