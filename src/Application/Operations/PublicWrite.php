<?php
/**
 * PublicWrite: what a write anyone may send declares instead of a capability
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Support\Error\ErrorCode;

defined( 'ABSPATH' ) || exit;

/**
 * The declaration of a public write: the rate limit it is counted against, whether it needs a
 * cart that already exists, and the error codes its policy can refuse it with.
 *
 * This class owns one fact: what an operation that checks no capability states about the policy
 * that decides for it. An OperationDefinition without a capability cannot be a write without one
 * of these, and the REST adapter guards the route with PermissionCallback::publicWrite(), whose
 * policy the Store API configures from it. The policy that knows its own error codes builds this
 * declaration (StoreRequestPolicy::write()), so the codes are written once; the definition adds
 * them to the codes it declares, and every generated document lists them.
 *
 * Declarations are data: nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class PublicWrite {

	/**
	 * The rate limit of the write, for each client.
	 *
	 * @since 0.1.0
	 *
	 * @var RateLimit
	 */
	private RateLimit $rateLimit;

	/**
	 * Whether the write changes a cart that must already exist, so its token must be sent.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $requiresCart;

	/**
	 * The error codes the policy can refuse the write with.
	 *
	 * @since 0.1.0
	 *
	 * @var list<ErrorCode>
	 */
	private array $refusals;

	/**
	 * Declares the public write.
	 *
	 * The refusals are checked with the operation's other error codes, by the definition that
	 * carries this declaration.
	 *
	 * @since 0.1.0
	 *
	 * @param RateLimit   $rate_limit    The rate limit, for each client.
	 * @param bool        $requires_cart Whether the write changes a cart that must already exist.
	 * @param ErrorCode[] $refusals      The error codes the policy can refuse the write with.
	 *
	 * @phpstan-param list<ErrorCode> $refusals
	 */
	public function __construct( RateLimit $rate_limit, bool $requires_cart, array $refusals ) {
		$this->rateLimit    = $rate_limit;
		$this->requiresCart = $requires_cart;
		$this->refusals     = $refusals;
	}

	/**
	 * Returns the rate limit of the write.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit The limit, for each client.
	 */
	public function rateLimit(): RateLimit {
		return $this->rateLimit;
	}

	/**
	 * Tells whether the write changes a cart that must already exist.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the request must send the cart's token.
	 */
	public function requiresCart(): bool {
		return $this->requiresCart;
	}

	/**
	 * Returns the error codes the policy can refuse the write with.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorCode> The codes.
	 */
	public function refusals(): array {
		return $this->refusals;
	}
}
