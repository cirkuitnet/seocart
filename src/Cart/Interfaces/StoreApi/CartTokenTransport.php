<?php
/**
 * CartTokenTransport: carries the cart token in a cookie and a header
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Support\Clock;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * How a cart token travels between the Store API and its clients.
 *
 * Owns one fact: the transport of the token, which is part of the plugin's public cookie contract.
 *
 * - Read back: the request header `X-SEOCart-Cart-Token`, which a headless client sends; else the
 *   cookie `seocart_cart_token`, which a browser sends. A value that is not a token is no token.
 * - Handed out: when a request creates a cart, the service issues its token here, and the
 *   successful response to that write carries it twice, in the same header and in the cookie. The
 *   cookie is `HttpOnly` and `SameSite=Lax`, scoped to the site's cookie path and domain, `Secure`
 *   when the site is served over HTTPS (as WordPress's own login cookies are), and lives as long as
 *   a cart of the customer's kind is kept: a guest's or a logged-in customer's period of the
 *   `carts` retention policy.
 *
 * A read never sets a cookie: a token issued while answering a request that is not a write, both as
 * WordPress routes it and as it arrived over HTTP (HttpMethod), is not sent, and the developer is
 * told. So a GET that names another method with `?_method=` gets no cookie either. Neither does a
 * request whose response is an error. So a page that is cached, or a Store API read, never carries
 * the cookie. The header is added to the
 * response, which WordPress serves; the cookie is sent through setcookie(), unless another sender
 * is given, as the tests do.
 *
 * Nothing is hooked until a token is issued: then one `rest_post_dispatch` filter, once.
 *
 * @since 0.1.0
 */
final class CartTokenTransport implements CartTokens {

	/**
	 * The cookie that carries the token to and from a browser.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COOKIE = 'seocart_cart_token';

	/**
	 * The header that carries the token to and from a headless client.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HEADER = 'X-SEOCart-Cart-Token';

	/**
	 * The retention policy of carts, whose periods the cookie lives for.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CART_RETENTION = 'carts';

	/**
	 * The clock the cookie's expiry is counted from.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Sends a cookie: the name, the value and setcookie()'s options.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(string, string, array<string, mixed>): void
	 */
	private \Closure $sendCookie;

	/**
	 * The token issued while this request is being answered, until the response carries it.
	 *
	 * @since 0.1.0
	 *
	 * @var CartToken|null
	 */
	private ?CartToken $issued = null;

	/**
	 * Whether the response filter has been added.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Creates the transport. Hooks nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Clock         $clock       The clock the cookie's expiry is counted from.
	 * @param \Closure|null $send_cookie Optional. Sends a cookie, given its name, value and
	 *                                   setcookie() options. Default null, setcookie().
	 *
	 * @phpstan-param (\Closure(string, string, array<string, mixed>): void)|null $send_cookie
	 */
	public function __construct( Clock $clock, ?\Closure $send_cookie = null ) {
		$this->clock      = $clock;
		$this->sendCookie = $send_cookie ?? static function ( string $name, string $value, array $options ): void {
			setcookie( $name, $value, $options );
		};
	}

	/**
	 * Returns the token the client sent: the header's, else the cookie's.
	 *
	 * @since 0.1.0
	 *
	 * @return CartToken|null The token, or null when neither carries one.
	 */
	public function presented(): ?CartToken {
		$sent = $_SERVER['HTTP_X_SEOCART_CART_TOKEN'] ?? $_COOKIE[ self::COOKIE ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CartToken::fromString() accepts 64 hexadecimal characters and nothing else.

		return is_string( $sent ) ? CartToken::fromString( trim( wp_unslash( $sent ) ) ) : null;
	}

	/**
	 * Hands a new cart's token to the client with the response to this request.
	 *
	 * @since 0.1.0
	 *
	 * @param CartToken $token The new cart's token.
	 */
	public function issue( CartToken $token ): void {
		$this->issued = $token;

		if ( ! $this->hooked ) {
			add_filter( 'rest_post_dispatch', array( $this, 'attach' ), 10, 3 );
			$this->hooked = true;
		}
	}

	/**
	 * Adds the issued token to a successful response to a write: the header, and the cookie.
	 * Hooked to `rest_post_dispatch` by issue().
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $result  The response WordPress is about to serve.
	 * @param WP_REST_Server  $server  The server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed The response, carrying the token's header when it was sent.
	 */
	public function attach( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );

		$token        = $this->issued;
		$this->issued = null;

		if ( null === $token || ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		if ( ! HttpMethod::isWrite( $request ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'A cart token was issued while answering a read. A read never sets a cookie, so the token was not sent: a cart is created only by a write.' ), '0.1.0' );

			return $result;
		}

		if ( $result->get_status() >= 300 ) {
			return $result;
		}

		$result->header( self::HEADER, $token->value() );
		( $this->sendCookie )( self::COOKIE, $token->value(), $this->cookieOptions() );

		return $result;
	}

	/**
	 * Returns the cookie's attributes, as setcookie() takes them.
	 *
	 * @since 0.1.0
	 *
	 * @return array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string} The attributes.
	 */
	private function cookieOptions(): array {
		$kind   = 0 === get_current_user_id() ? 'guest' : 'logged_in';
		$period = new \DateInterval( ( new RetentionCatalog() )->defaults( self::CART_RETENTION )[ $kind ] );

		// COOKIEPATH and COOKIE_DOMAIN are WordPress's, defined by wp_cookie_constants() when it loads.
		return array(
			'expires'  => $this->clock->now()->modify( $period->format( '+%y years +%m months +%d days +%h hours +%i minutes +%s seconds' ) )->getTimestamp(),
			'path'     => (string) constant( 'COOKIEPATH' ),
			'domain'   => (string) constant( 'COOKIE_DOMAIN' ),
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}
}
