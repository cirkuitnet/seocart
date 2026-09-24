<?php
/**
 * ProductSave: one request to save a product, its post and its commerce fields together
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\ProductWrite;

use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An InvalidArgumentException names a value built by code, for the developer; it is never rendered.

/**
 * What SaveProduct is asked to write.
 *
 * Owns one fact: the shape of a product save. The post it saves, or none for a new one; the
 * post's fields, unslashed, as core's REST controller prepares them, passed to WordPress
 * unchanged; the commerce fields the save gives, or none; and who saves.
 *
 * @since 0.1.0
 */
final class ProductSave {

	/**
	 * The post to save, or null to create one.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $postId;

	/**
	 * The post's fields, unslashed.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $editorial;

	/**
	 * The commerce fields the save gives, or null for none.
	 *
	 * @since 0.1.0
	 *
	 * @var CommerceInput|null
	 */
	private ?CommerceInput $commerce;

	/**
	 * Who saves.
	 *
	 * @since 0.1.0
	 *
	 * @var Actor
	 */
	private Actor $actor;

	/**
	 * Describes a save.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the post id is below 1.
	 *
	 * @param int|null             $postId    The product's post, or null to create one.
	 * @param array<string, mixed> $editorial The post's fields, unslashed, such as `post_title` and `post_status`;
	 *                                        an `ID` among them is ignored in favour of $postId.
	 * @param CommerceInput|null   $commerce  The commerce fields the save gives, or null for none.
	 * @param Actor                $actor     Who saves.
	 */
	public function __construct( ?int $postId, array $editorial, ?CommerceInput $commerce, Actor $actor ) {
		if ( null !== $postId && $postId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A post id is 1 or more, not %d.', $postId ) );
		}

		unset( $editorial['ID'] );

		$this->postId    = $postId;
		$this->editorial = $editorial;
		$this->commerce  = $commerce;
		$this->actor     = $actor;
	}

	/**
	 * Returns the post to save.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The post's id, or null to create one.
	 */
	public function postId(): ?int {
		return $this->postId;
	}

	/**
	 * Returns the post's fields.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The fields, unslashed, without an `ID`.
	 */
	public function editorial(): array {
		return $this->editorial;
	}

	/**
	 * Returns the commerce fields the save gives.
	 *
	 * @since 0.1.0
	 *
	 * @return CommerceInput|null The input, or null for none.
	 */
	public function commerce(): ?CommerceInput {
		return $this->commerce;
	}

	/**
	 * Returns who saves.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The actor.
	 */
	public function actor(): Actor {
		return $this->actor;
	}
}
