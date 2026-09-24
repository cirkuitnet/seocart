<?php
/**
 * ScriptedPostGateway: a post gateway that writes to a step log instead of WordPress
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Catalog\Application\PostGateway;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\TransactionManager;

/**
 * Answers post types from a map, records each write in the step log, and fails a write when told to.
 *
 * Owns one fact: what a unit test of the product write sees of WordPress. A write outside a
 * transaction is a programming error here, as it would be a half-built product in production.
 * A new post gets the next id from 500 and becomes a product post.
 *
 * @since 0.1.0
 */
final class ScriptedPostGateway implements PostGateway {

	/**
	 * What the next write throws after it is recorded, or null to succeed.
	 *
	 * @since 0.1.0
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $failWith = null;

	/**
	 * The posts written, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $written = array();

	/**
	 * The transaction a write must be part of.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * The step log.
	 *
	 * @since 0.1.0
	 *
	 * @var StepLog
	 */
	private StepLog $log;

	/**
	 * The post type of each post, by id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, string>
	 */
	private array $types;

	/**
	 * The id the next new post gets.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $nextId = 500;

	/**
	 * Holds the posts that exist.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $tx    The transaction a write must be part of.
	 * @param StepLog            $log   The step log.
	 * @param array              $types Optional. The post type of each post, by id. Default none.
	 *
	 * @phpstan-param array<int, string> $types
	 */
	public function __construct( TransactionManager $tx, StepLog $log, array $types = array() ) {
		$this->tx    = $tx;
		$this->log   = $log;
		$this->types = $types;
	}

	/**
	 * Records the write, then fails it when told to; otherwise returns the post's id.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open.
	 * @phpstan-throws \Throwable
	 *
	 * @param array $postarr The post's fields.
	 * @return int The post's id.
	 *
	 * @phpstan-param array<string, mixed> $postarr
	 */
	public function write( array $postarr ): int {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( 'A product post is written inside the save\'s transaction only.' );
		}

		$this->log->record( isset( $postarr['ID'] ) ? 'post.update' : 'post.insert' );

		$this->written[] = $postarr;

		if ( null !== $this->failWith ) {
			throw $this->failWith;
		}

		if ( isset( $postarr['ID'] ) ) {
			return (int) $postarr['ID'];
		}

		$id = $this->nextId++;

		$this->types[ $id ] = ProductCapabilities::POST_TYPE;

		return $id;
	}

	/**
	 * Not used by the product write: its caller fires the hook.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int         $postId Unused.
	 * @param bool        $update Unused.
	 * @param object|null $before Unused.
	 */
	public function fireAfterInsert( int $postId, bool $update, ?object $before ): void {
		throw new \LogicException( 'The product write fires no after-insert hook.' );
	}

	/**
	 * Returns `publish` for a post that exists.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return string|null The status, or null when there is no such post.
	 */
	public function statusOf( int $postId ): ?string {
		return isset( $this->types[ $postId ] ) ? 'publish' : null;
	}

	/**
	 * Returns the post's type from the map.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId The post.
	 * @return string|null The type, or null when there is no such post.
	 */
	public function postTypeOf( int $postId ): ?string {
		return $this->types[ $postId ] ?? null;
	}
}
