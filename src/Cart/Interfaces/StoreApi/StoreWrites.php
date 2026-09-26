<?php
/**
 * StoreWrites: guards and counts the Store API's writes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Interfaces\Operations\PublicWrites;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Support\Error\CodedException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The Store API's side of a public write: its request policy, and its count.
 *
 * Owns one fact: which services guard and count a Store API write. policy() gives each write its
 * StoreRequestPolicy, which the route's permission callback asks and which changes nothing.
 * admit() counts one run of the write, when the REST adapter's callback runs it: under the write's
 * bucket and window, for the client (ClientIdentities::of(): the trusted address and the customer
 * id, never a cart token the request carries, which anyone can make up anew for every request).
 * Over the limit, the write is refused with `store_api.rate_limited` before its service runs; a
 * counter that cannot be written refuses it as the internal error it is.
 *
 * Building it sends nothing, and neither does policy().
 *
 * @since 0.1.0
 */
final class StoreWrites implements PublicWrites {

	/**
	 * Counts the clients' writes.
	 *
	 * @since 0.1.0
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Names the client.
	 *
	 * @since 0.1.0
	 *
	 * @var ClientIdentities
	 */
	private ClientIdentities $identities;

	/**
	 * Reads the cart token a request carries.
	 *
	 * @since 0.1.0
	 *
	 * @var CartTokenTransport
	 */
	private CartTokenTransport $tokens;

	/**
	 * Builds the errors a write is refused with.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTranslator
	 */
	private ErrorTranslator $translator;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param RateLimiter        $limiter    Counts the clients' writes.
	 * @param ClientIdentities   $identities Names the client.
	 * @param CartTokenTransport $tokens     Reads the cart token a request carries.
	 * @param ErrorTranslator    $translator Builds the errors a write is refused with.
	 */
	public function __construct( RateLimiter $limiter, ClientIdentities $identities, CartTokenTransport $tokens, ErrorTranslator $translator ) {
		$this->limiter    = $limiter;
		$this->identities = $identities;
		$this->tokens     = $tokens;
		$this->translator = $translator;
	}

	/**
	 * Returns the request policy of one Store API write.
	 *
	 * @since 0.1.0
	 *
	 * @param PublicWrite $write What the operation declared.
	 * @return StoreRequestPolicy The policy.
	 */
	public function policy( PublicWrite $write ): StoreRequestPolicy {
		return new StoreRequestPolicy( $write, $this->tokens, $this->translator );
	}

	/**
	 * Counts one run of a Store API write against its rate limit, for the client.
	 *
	 * @since 0.1.0
	 *
	 * @param PublicWrite $write What the operation declared.
	 * @return true|WP_Error True within the limit; the refusal over it, or when the count failed.
	 */
	public function admit( PublicWrite $write ): bool|WP_Error {
		$limit = $write->rateLimit();

		try {
			$count = $this->limiter->hit( $limit->bucket(), $this->identities->of( get_current_user_id() ), $limit->windowSeconds() );
		} catch ( CodedException $failure ) {
			return $this->translator->translate( $failure );
		}

		return $count > $limit->limit() ? $this->translator->translate( CodedException::because( StoreApiError::RateLimited ) ) : true;
	}
}
