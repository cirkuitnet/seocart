<?php
/**
 * NextAction: what the shopper's browser must do for the provider before a payment is decided
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to the developer; they are never HTML.

/**
 * The step a provider asks of the shopper when it answers an authorization `requires_action`: a page to go to, or a handle for its script in the browser.
 *
 * Owns one fact: how the shopper is sent to act for the provider, for example to confirm the
 * payment with their bank. A `redirect` names the provider's page, as the provider gave it; an
 * `sdk` action gives the provider's script in the browser the handle it needs, such as a
 * client secret. A redirect may carry a handle too, for a provider whose script finishes the
 * redirect. The handle is the browser's one-time key to this payment at the provider: it is
 * answered to the shopper who placed the order and to a retry of the same request, and never
 * written to a log or put in a URL by the plugin.
 *
 * The browser only follows the step: whatever the provider decides is applied by the plugin,
 * from the provider's own answer, never from what the browser reports.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class NextAction {

	/**
	 * The shopper goes to the provider's page.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REDIRECT = 'redirect';

	/**
	 * The provider's script in the browser takes the step, with the handle.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SDK = 'sdk';

	/**
	 * Every type of step.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const TYPES = array( self::REDIRECT, self::SDK );

	/**
	 * The longest URL a step names, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const URL_MAX_LENGTH = 2048;

	/**
	 * The longest handle a step carries, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const CLIENT_TOKEN_MAX_LENGTH = 512;

	/**
	 * Records the step.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the type is not one of TYPES; a redirect has no URL, or an `sdk` step no
	 *                                   handle; the URL is not an absolute http or https URL of at most URL_MAX_LENGTH
	 *                                   characters, or holds white space or a control character; or the handle is
	 *                                   empty, longer than CLIENT_TOKEN_MAX_LENGTH, or holds white space or a control
	 *                                   character.
	 *
	 * @param string      $type        REDIRECT or SDK.
	 * @param string|null $url         Optional. The provider's page; required for a redirect. Default null.
	 * @param string|null $clientToken Optional. The handle the provider's script in the browser needs; required for an
	 *                                 `sdk` step. Default null.
	 */
	public function __construct(
		public string $type,
		public ?string $url = null,
		#[\SensitiveParameter]
		public ?string $clientToken = null
	) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'A next action is one of %s.', implode( ', ', self::TYPES ) ) );
		}

		if ( self::REDIRECT === $type && null === $url ) {
			throw new \InvalidArgumentException( 'A redirect names the page the shopper goes to.' );
		}

		if ( self::SDK === $type && null === $clientToken ) {
			throw new \InvalidArgumentException( 'A step taken by the provider\'s script carries the handle the script needs.' );
		}

		if ( null !== $url && ! self::isUrl( $url ) ) {
			throw new \InvalidArgumentException( sprintf( 'A next action\'s URL is an absolute http or https URL of at most %d characters, without white space.', self::URL_MAX_LENGTH ) );
		}

		if ( null !== $clientToken && ! self::isToken( $clientToken ) ) {
			throw new \InvalidArgumentException( sprintf( 'A next action\'s handle is 1 to %d characters, without white space.', self::CLIENT_TOKEN_MAX_LENGTH ) );
		}
	}

	/**
	 * Creates a redirect to the provider's page.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $url         The provider's page.
	 * @param string|null $clientToken Optional. A handle for the provider's script as well. Default null.
	 * @return self The step.
	 */
	public static function redirect( string $url, #[\SensitiveParameter] ?string $clientToken = null ): self {
		return new self( self::REDIRECT, $url, $clientToken );
	}

	/**
	 * Creates a step the provider's script in the browser takes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $clientToken The handle the script needs.
	 * @return self The step.
	 */
	public static function sdk( #[\SensitiveParameter] string $clientToken ): self {
		return new self( self::SDK, null, $clientToken );
	}

	/**
	 * Returns what a dump of the step shows: the handle masked.
	 *
	 * @since 0.2.0
	 *
	 * @return array{type: string, url: string|null, clientToken: string|null} The step, its handle replaced.
	 */
	public function __debugInfo(): array {
		return array(
			'type'        => $this->type,
			'url'         => $this->url,
			'clientToken' => null === $this->clientToken ? null : '[masked]',
		);
	}

	/**
	 * Tells whether a URL is one a step may name: absolute, http or https, with a host, bounded, and without white space or a control character.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url The URL.
	 * @return bool True when it may.
	 */
	private static function isUrl( string $url ): bool {
		if ( strlen( $url ) > self::URL_MAX_LENGTH || 1 === preg_match( '/[\s\x00-\x1F\x7F]/', $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The public contract calls no WordPress function.
		$host   = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The public contract calls no WordPress function.

		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host;
	}

	/**
	 * Tells whether a handle is one a step may carry: 1 to CLIENT_TOKEN_MAX_LENGTH characters, without white space or a control character.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token The handle.
	 * @return bool True when it may.
	 */
	private static function isToken( #[\SensitiveParameter] string $token ): bool {
		return '' !== $token && strlen( $token ) <= self::CLIENT_TOKEN_MAX_LENGTH && 1 !== preg_match( '/[\s\x00-\x1F\x7F]/', $token );
	}
}
