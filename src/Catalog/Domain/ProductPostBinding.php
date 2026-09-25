<?php
/**
 * ProductPostBinding: the link between a product and the post that presents it in one locale
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A LogicException names a value built by code, for the developer; it is never rendered.

/**
 * One post that presents a product, in one locale.
 *
 * Owns one fact: what a binding records. The post, the locale its content is in, when it was
 * linked and, when a multilingual plugin's adapter linked it, which one. A product has at most
 * one binding per locale; the storage key enforces that.
 *
 * @since 0.1.0
 */
final class ProductPostBinding {

	/**
	 * The longest locale a binding holds, in characters; the stored column is declared with this length.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCALE_MAX_LENGTH = 20;

	/**
	 * The post's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $postId;

	/**
	 * The locale of the post's content.
	 *
	 * @since 0.1.0
	 *
	 * @var Locale
	 */
	private Locale $locale;

	/**
	 * When the post was linked to the product.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $linkedAt;

	/**
	 * The multilingual adapter that linked it, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $linkedByAdapter;

	/**
	 * Records a binding.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the post id is not a post id.
	 *
	 * @param int                $postId          The post's id, 1 or more.
	 * @param Locale             $locale          The locale of its content.
	 * @param \DateTimeImmutable $linkedAt        When it was linked.
	 * @param string|null        $linkedByAdapter Optional. The multilingual adapter that linked it. Default null.
	 */
	public function __construct( int $postId, Locale $locale, \DateTimeImmutable $linkedAt, ?string $linkedByAdapter = null ) {
		if ( $postId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A binding names a post; %d is not a post id.', $postId ) );
		}

		$this->postId          = $postId;
		$this->locale          = $locale;
		$this->linkedAt        = $linkedAt;
		$this->linkedByAdapter = $linkedByAdapter;
	}

	/**
	 * Returns the post's id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function postId(): int {
		return $this->postId;
	}

	/**
	 * Returns the locale of the post's content.
	 *
	 * @since 0.1.0
	 *
	 * @return Locale The locale.
	 */
	public function locale(): Locale {
		return $this->locale;
	}

	/**
	 * Returns when the post was linked.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant.
	 */
	public function linkedAt(): \DateTimeImmutable {
		return $this->linkedAt;
	}

	/**
	 * Returns the multilingual adapter that linked the post.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Its name, or null when the store linked it itself.
	 */
	public function linkedByAdapter(): ?string {
		return $this->linkedByAdapter;
	}
}
