<?php
/**
 * ForbiddenInsideTransaction: work that must never happen while a transaction is open
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\DatabaseError;

defined( 'ABSPATH' ) || exit;

/**
 * Something that holds row locks across the network, or ends the transaction silently, was
 * attempted inside a transaction window.
 *
 * Owns one fact: which kind of forbidden work was attempted, and where. The kinds are the
 * KIND_* constants. In development the guards throw this at the call site; in production they
 * report the same code through the reporter and let the call proceed.
 *
 * @since 0.1.0
 */
final class ForbiddenInsideTransaction extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::ForbiddenInTransaction;

	/**
	 * Kind: an outbound HTTP request. The detail is the host.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KIND_HTTP = 'http';

	/**
	 * Kind: wp_mail(), which is HTTP or SMTP in disguise.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KIND_MAIL = 'mail';

	/**
	 * Kind: DDL, or a statement that ends the transaction implicitly. The detail is the statement.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KIND_DDL = 'ddl';

	/**
	 * Kind: taking a lease from the lock service, whose rows other runners must see at once.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KIND_LOCK = 'lock';

	/**
	 * Builds the exception without throwing it, for a caller that reports it instead.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind   One of the KIND_* constants.
	 * @param string $detail A host, a statement or a lock name. Cut to QueryFailed::STATEMENT_LENGTH.
	 * @return self The exception.
	 */
	public static function of( string $kind, string $detail ): self {
		return self::because(
			self::CODE,
			array(
				'kind'   => $kind,
				'detail' => QueryFailed::shorten( $detail ),
			)
		);
	}

	/**
	 * Returns the kind of forbidden work.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of the KIND_* constants.
	 */
	public function kind(): string {
		return (string) $this->context()['kind'];
	}

	/**
	 * Returns where the forbidden work was attempted.
	 *
	 * @since 0.1.0
	 *
	 * @return string A host, a statement or a lock name.
	 */
	public function detail(): string {
		return (string) $this->context()['detail'];
	}
}
