<?php
/**
 * StoreRequestPolicy: decides whether a Store API write may run
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Platform\Authorization\RequestPolicy;
use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Support\Error\CodedException;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The Store API's request policy: what a write anyone may send must satisfy instead of a capability.
 *
 * Owns one fact: the conditions of a public write. Every Store API write is guarded by
 * PermissionCallback::publicWrite() with one of these, configured by the operation's PublicWrite.
 * The checks run cheapest first, and each refusal has its own code (StoreApiError):
 *
 * 1. The request arrived as a write: its HTTP method, not only the one WordPress routes it by, is
 *    POST, PUT, PATCH or DELETE (HttpMethod). A GET that names another method with `?_method=`
 *    is still a GET, which a prefetch, a crawler or a cache may send, and it changes nothing.
 * 2. The request header `X-SEOCart-Store: 1`. A cross-site form cannot add a header, and a script
 *    on another origin can add one only after a CORS preflight, which the site does not grant for
 *    this header. So a page elsewhere cannot make a visitor's browser write to its cart, although
 *    the cart cookie is sent by the browser on its own. The value is fixed: the header's presence
 *    is the control, not a secret.
 * 3. A login cookie is honoured only with its nonce. When a request carries a valid login cookie
 *    but no nonce, WordPress quietly treats it as a guest's; such a write is refused instead of
 *    running as a guest, so a logged-in customer never builds a guest cart unawares. A request
 *    with an invalid nonce never gets here: WordPress refuses it first.
 * 4. A write that changes an existing cart must carry a cart token. This checks the token's
 *    presence and its form only: whether it names a stored cart is the cart module's answer
 *    (`cart.not_found`), given when the write looks the cart up.
 *
 * The policy changes nothing and counts nothing, so it may be asked any number of times: WordPress
 * asks it before the endpoint runs, again for the `Allow` header of the response and for an
 * OPTIONS request, and another plugin may wrap the callback that asks it. Every check applies to
 * every call. The write's rate limit is counted once, where the endpoint runs
 * (StoreWrites::admit()).
 *
 * write() builds the PublicWrite a Store API operation declares, with the refusals of these checks
 * and of the count, so a declaration lists them without restating them.
 *
 * @since 0.1.0
 */
final class StoreRequestPolicy implements RequestPolicy {

	/**
	 * The header every Store API write must carry.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HEADER = 'X-SEOCart-Store';

	/**
	 * The value the header must have.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HEADER_VALUE = '1';

	/**
	 * What the operation declared: its rate limit and whether it needs an existing cart.
	 *
	 * @since 0.1.0
	 *
	 * @var PublicWrite
	 */
	private PublicWrite $write;

	/**
	 * Reads the cart token the request carries.
	 *
	 * @since 0.1.0
	 *
	 * @var CartTokenTransport
	 */
	private CartTokenTransport $tokens;

	/**
	 * Builds the errors a request is refused with.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTranslator
	 */
	private ErrorTranslator $translator;

	/**
	 * Creates the policy of one operation.
	 *
	 * @since 0.1.0
	 *
	 * @param PublicWrite        $write      What the operation declared.
	 * @param CartTokenTransport $tokens     Reads the cart token the request carries.
	 * @param ErrorTranslator    $translator Builds the errors a request is refused with.
	 */
	public function __construct( PublicWrite $write, CartTokenTransport $tokens, ErrorTranslator $translator ) {
		$this->write      = $write;
		$this->tokens     = $tokens;
		$this->translator = $translator;
	}

	/**
	 * Declares a Store API write: the PublicWrite its operation definition carries.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bucket         What the rate limit counts, such as `cart.write`.
	 * @param int    $limit          The most requests one client may send in one window.
	 * @param int    $window_seconds The length of a window, in seconds.
	 * @param bool   $requires_cart  Whether the write changes a cart that must already exist.
	 * @return PublicWrite The declaration, with the refusals of this policy and of the count.
	 */
	public static function write( string $bucket, int $limit, int $window_seconds, bool $requires_cart ): PublicWrite {
		$refusals = array( StoreApiError::ReadMethod, StoreApiError::HeaderMissing, StoreApiError::NonceMissing );

		if ( $requires_cart ) {
			$refusals[] = StoreApiError::CartTokenMissing;
		}

		$refusals[] = StoreApiError::RateLimited;

		return new PublicWrite( new RateLimit( $bucket, $limit, $window_seconds ), $requires_cart, $refusals );
	}

	/**
	 * Tells whether a Store API write may run. Changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request    The request being answered.
	 * @param string|null     $capability Null: a public write checks no capability.
	 * @return true|WP_Error True, or the refusal.
	 */
	public function allows( WP_REST_Request $request, ?string $capability ): bool|WP_Error {
		unset( $capability );

		if ( ! HttpMethod::isWrite( $request ) ) {
			return $this->refuse( StoreApiError::ReadMethod );
		}

		if ( self::HEADER_VALUE !== trim( (string) $request->get_header( self::HEADER ) ) ) {
			return $this->refuse( StoreApiError::HeaderMissing );
		}

		if ( self::isDowngradedLogin() ) {
			return $this->refuse( StoreApiError::NonceMissing );
		}

		if ( $this->write->requiresCart() && null === $this->tokens->presented() ) {
			return $this->refuse( StoreApiError::CartTokenMissing );
		}

		return true;
	}

	/**
	 * Tells whether WordPress found a valid login cookie, got no nonce with it, and so treats the
	 * request as a guest's.
	 *
	 * WordPress records that the login cookie was valid while it determines the user, and sets the
	 * user to 0 when a REST request carries the cookie without a nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a logged-in customer's request that would run as a guest.
	 */
	private static function isDowngradedLogin(): bool {
		global $wp_rest_auth_cookie;

		return true === $wp_rest_auth_cookie && 0 === get_current_user_id();
	}

	/**
	 * Builds a refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param StoreApiError $code The refusal's code.
	 * @return WP_Error The error, with the documented data members.
	 */
	private function refuse( StoreApiError $code ): WP_Error {
		return $this->translator->translate( CodedException::because( $code ) );
	}
}
