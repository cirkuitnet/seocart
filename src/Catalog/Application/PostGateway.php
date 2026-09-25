<?php
/**
 * PostGateway: the catalog's one way to write and read a product's post
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a product post through WordPress, and reads what the catalog needs of one.
 *
 * Owns one fact: how the catalog's services reach WordPress posts, so no service calls a
 * WordPress function. write() is the product post's one write: it defers the
 * `wp_after_insert_post` hook, which runs where fireAfterInsert() is called, after the product's
 * transaction commits. While fireAfterInsert() runs, isFiringAfterInsert() says so for that
 * post, which is how the post lifecycle tells the plugin's own writes from every other.
 *
 * @since 0.1.0
 */
interface PostGateway {

	/**
	 * Inserts a product post, or updates one, without firing `wp_after_insert_post`.
	 *
	 * Inside a transaction the post's cache is flushed if the transaction rolls back.
	 *
	 * @since 0.1.0
	 *
	 * @throws \SEOCart\Support\Error\CodedException With Domain\CatalogError::PostRejected when WordPress refuses
	 *         the post, or CatalogError::PostNotProduct when `ID` names a post that is not a product post.
	 *
	 * @param array<string, mixed> $postarr The post's fields, unslashed, as core's REST controller
	 *                                      prepares them; an `ID` updates that post.
	 * @return int The post's id.
	 */
	public function write( array $postarr ): int;

	/**
	 * Fires `wp_after_insert_post` for a post write() wrote, once the write is durable.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $postId The post's id.
	 * @param bool        $update Whether write() updated an existing post.
	 * @param object|null $before The post as it was before the update, or null for an insert.
	 */
	public function fireAfterInsert( int $postId, bool $update, ?object $before ): void;

	/**
	 * Tells whether fireAfterInsert() is firing `wp_after_insert_post` for a post at this moment.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return bool True while this gateway fires the hook for the post: the write that reaches the hook is the plugin's.
	 */
	public function isFiringAfterInsert( int $postId ): bool;

	/**
	 * Returns what a copy of a product post takes from it: its title, content and excerpt, and its featured image.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return array<string, mixed>|null The fields, unslashed, as write() takes them, the featured image in `meta_input`
	 *                                   when the post has one; null when the post is not a product post.
	 */
	public function contentOf( int $postId ): ?array;

	/**
	 * Returns a post's status.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return string|null The status, such as `publish`, or null when there is no such post.
	 */
	public function statusOf( int $postId ): ?string;

	/**
	 * Returns a post's type.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post's id.
	 * @return string|null The post type, or null when there is no such post.
	 */
	public function postTypeOf( int $postId ): ?string;
}
