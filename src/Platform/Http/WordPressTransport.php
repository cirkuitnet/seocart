<?php
/**
 * WordPressTransport: sends the outbound client's requests through WordPress's HTTP API
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use WpOrg\Requests\HookManager;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A TransportFailure's message is a fixed sentence naming a declared host, for the developer and the log; it is never HTML.

defined( 'ABSPATH' ) || exit;

/**
 * The production transport: wp_safe_remote_request(), with the client's limits kept against every filter.
 *
 * Owns one fact: how a request becomes a call of WordPress's HTTP API, and its answer an
 * OutboundResponse. Going through that API is what lets the plugin's transaction guard, which
 * watches `pre_http_request`, see every request; wp_safe_remote_request() also refuses a URL
 * that resolves to a private address.
 *
 * Other plugins can change any request through filters, and a common snippet turns certificate
 * checks off for every request. So for the length of one call the transport adds five
 * callbacks at the latest priority, which re-assert its own settings after every other one has
 * run:
 *
 * - on `http_request_args`, for this request only, the arguments it passed: certificate
 *   checks on, no redirect, the size limit, the timeout, the method, headers and body. The
 *   request is recognised by a token in its own arguments, so a request another plugin sends
 *   meanwhile, even to the same URL, keeps its own;
 * - on `pre_http_request`, the last filter before WordPress hands the request over, a refusal
 *   of a request to this call's URL whose arguments no longer carry the token: a filter
 *   rebuilt them, so none of the other callbacks could recognise the request. It is not sent.
 *   A request another plugin starts while this one's arguments are being filtered is that
 *   plugin's own, and is left alone, unless this call itself began inside an argument filter:
 *   the two cannot be told apart there, and such a request is refused too. So is another
 *   plugin's request to this call's exact URL started from a `pre_http_request` callback during
 *   the call, which carries no token either: refusing it is the safe outcome;
 * - on `requests-requests.before_request`, where WordPress has handed the request to the
 *   Requests library after all of its own filters, for this request's host, the options that
 *   library will use: certificate and host-name checks on (which undoes an `https_ssl_verify`
 *   filter that turned them off), the five-second connect timeout (which WordPress has no
 *   argument for), the total timeout, no redirect and the size limit. That library passes no
 *   arguments to its actions, so this callback recognises the call by its host. When it does,
 *   it has the request's own Requests hooks vouch for the cURL handle the request is sent
 *   with. Every request has hooks of its own, so a request another plugin sends meanwhile, to
 *   the same host or not, can vouch only for its own handle;
 * - on `http_api_curl`, where WordPress hands over the cURL handle it is about to send this
 *   request with, recognised by the same token, the handle's own options: certificate and
 *   host-name checks on, no redirect followed, https as the only protocol, the connect and
 *   total timeouts, and the request's URL. The Requests library applies its own certificate
 *   option to the handle after the last hook, so a call whose handle nothing vouched for,
 *   because an earlier callback moved it to another host, is refused here with a TLS failure
 *   before anything is sent;
 * - on `requests-curl.before_send`, which WordPress runs right after `http_api_curl` and is the
 *   last hook before the request is sent, the same options again, on the handle the
 *   `http_api_curl` callback recognised.
 *
 * All five are removed when the call returns or throws. Code in the same process that hooks
 * in later still, at the same priority or by other means, or that replaces the Requests
 * library's `hooks` or `transport` option, can change the request again. So can the hooks of
 * the socket transport WordPress falls back to without the cURL extension, which run after
 * the first three callbacks and have no handle for the last two.
 *
 * @since 0.2.0
 */
final class WordPressTransport implements Transport {

	/**
	 * The argument that carries a call's token: it marks the request the callbacks may change.
	 *
	 * WordPress ignores an argument it does not know, and passes it on to every filter and action
	 * that is given the arguments.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CALL_TOKEN = 'seocart_call_token';

	/**
	 * The code of the error WordPress returns for a call refused before it was sent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUSED = 'seocart_http_refused';

	/**
	 * The cURL error number of a timeout.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const CURL_TIMEOUT = 28;

	/**
	 * The cURL error numbers of a failed TLS handshake or certificate check.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private const CURL_TLS_ERRORS = array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 );

	/**
	 * Sends the request with wp_safe_remote_request() and returns the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When WordPress returned an error instead of an answer, or an answer
	 *                          without an HTTP status, or the call was refused because it could no
	 *                          longer be recognised as this call.
	 *
	 * @param OutboundRequest $request        The request, already checked against its declared host.
	 * @param int             $timeoutSeconds How long the whole request may take, in seconds.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request, int $timeoutSeconds ): OutboundResponse {
		$token     = bin2hex( random_bytes( 16 ) );
		$arguments = self::arguments( $request, $timeoutSeconds, $token );
		$host      = strtolower( (string) wp_parse_url( $request->url, PHP_URL_HOST ) );

		// A filter may send a request of its own, even to the same URL, while this one is under
		// way; only the arguments that carry this call's token are this request's.
		$keepArguments = static function ( mixed $args ) use ( $token, $arguments ): mixed {
			return self::isThisCall( $args, $token ) ? array_merge( $args, $arguments ) : $args;
		};

		// A filter that rebuilt the arguments without the token would leave every callback here
		// unable to recognise the request, so a request to this URL that lacks it is refused. One
		// that another plugin starts while this request's arguments are being filtered is its own.
		// That cannot be told when this call itself began inside such a filter: it is refused then.
		$startedInsideFilter = doing_filter( 'http_request_args' );
		$refuseUnmarked      = static function ( mixed $pre, mixed $args, mixed $url ) use ( $request, $token, $startedInsideFilter ): mixed {
			$anotherPluginsRequest = ! $startedInsideFilter && doing_filter( 'http_request_args' );

			if ( $url !== $request->url || $anotherPluginsRequest || self::isThisCall( $args, $token ) ) {
				return $pre;
			}

			return new \WP_Error( self::REFUSED, 'The request lost the token that marks it, so it was not sent.' );
		};

		// The Requests library passes the URL it will call and its options by reference, and no
		// arguments, so the call is recognised by its host. Its own hooks then vouch for the cURL
		// handle it is sent with, and only for that one.
		$vouched     = new \WeakMap();
		$keepOptions = static function ( mixed $url, mixed $headers, mixed $data, mixed $type, mixed &$options ) use ( $host, $timeoutSeconds, $vouched ): void {
			if ( is_string( $url ) && is_array( $options ) && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host ) {
				$options = self::options( $options, $timeoutSeconds );
				self::vouchForHandle( $options['hooks'] ?? null, $vouched );
			}
		};

		// WordPress gives `http_api_curl` the cURL handle with the request's arguments. The handle
		// is an object, so its options are set without taking it by reference.
		$sending    = null;
		$keepHandle = static function ( mixed $handle, mixed $args ) use ( $token, $request, $timeoutSeconds, $host, $vouched, &$sending ): void {
			if ( ! ( $handle instanceof \CurlHandle ) || ! self::isThisCall( $args, $token ) ) {
				return;
			}

			// The Requests library applies its own `verify` option to the handle after the last
			// hook. A handle nothing vouched for is a call the options callback did not recognise:
			// an earlier callback moved it to another host, and that option may turn certificate
			// checks off. Nothing is sent.
			if ( ! isset( $vouched[ $handle ] ) ) {
				throw new TransportFailure( TransportFailure::TLS, $host );
			}

			$sending = $handle;
			self::secureHandle( $handle, $request->url, $timeoutSeconds );
		};

		// The action after it comes with the handle alone: the one recognised above is this request's.
		$keepHandleLast = static function ( mixed $handle ) use ( $request, $timeoutSeconds, &$sending ): void {
			if ( $handle instanceof \CurlHandle && $handle === $sending ) {
				self::secureHandle( $handle, $request->url, $timeoutSeconds );
			}
		};

		add_filter( 'http_request_args', $keepArguments, PHP_INT_MAX, 1 );
		add_filter( 'pre_http_request', $refuseUnmarked, PHP_INT_MAX, 3 );
		add_action( 'requests-requests.before_request', $keepOptions, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $keepHandle, PHP_INT_MAX, 2 );
		add_action( 'requests-curl.before_send', $keepHandleLast, PHP_INT_MAX, 1 );

		// A refusal throws from inside `http_api_curl`, so WordPress never takes that action off its
		// list of the hooks now running. Left there, it would make doing_action() and
		// current_filter() wrong for the rest of the request, and could make the token guard of a
		// call around this one take that call for another plugin's. The list is put back as it was.
		$runningHooks = count( $GLOBALS['wp_current_filter'] ?? array() );

		try {
			$response = wp_safe_remote_request( $request->url, $arguments );
		} finally {
			remove_filter( 'http_request_args', $keepArguments, PHP_INT_MAX );
			remove_filter( 'pre_http_request', $refuseUnmarked, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $keepOptions, PHP_INT_MAX );
			remove_action( 'http_api_curl', $keepHandle, PHP_INT_MAX );
			remove_action( 'requests-curl.before_send', $keepHandleLast, PHP_INT_MAX );

			// The list exists only where WordPress is loaded, which unit tests do without.
			if ( isset( $GLOBALS['wp_current_filter'] ) ) {
				array_splice( $GLOBALS['wp_current_filter'], $runningHooks );
			}
		}

		if ( is_wp_error( $response ) ) {
			// A call refused before it was sent is a TLS failure: its certificate checks could not be kept.
			$kind = self::REFUSED === $response->get_error_code() ? TransportFailure::TLS : self::failureKind( $response->get_error_message() );

			throw new TransportFailure( $kind, $host );
		}

		return self::answer( $response, $host );
	}

	/**
	 * Tells whether the arguments a filter or action was given are this call's: they carry its token.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $args  The arguments.
	 * @param string $token The call's token.
	 * @return bool True for this call's request, false for any other.
	 *
	 * @phpstan-assert-if-true array<mixed> $args
	 */
	private static function isThisCall( mixed $args, string $token ): bool {
		return is_array( $args ) && isset( $args[ self::CALL_TOKEN ] ) && $args[ self::CALL_TOKEN ] === $token;
	}

	/**
	 * Builds the arguments of wp_safe_remote_request() for a request.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest $request        The request.
	 * @param int             $timeoutSeconds The total timeout, in seconds.
	 * @param string          $token          The call's token, which marks these arguments as its own.
	 * @return array<string, mixed> The arguments.
	 */
	private static function arguments( OutboundRequest $request, int $timeoutSeconds, string $token ): array {
		return array(
			'method'              => $request->method,
			'headers'             => $request->headers,
			'body'                => $request->body,
			'timeout'             => $timeoutSeconds,
			'redirection'         => 0,
			'sslverify'           => true,
			// One byte more than the client accepts, so an answer cut off here is told apart from one exactly at the limit.
			'limit_response_size' => OutboundClient::MAX_RESPONSE_BYTES + 1,
			'reject_unsafe_urls'  => true,
			'blocking'            => true,
			'stream'              => false,
			'cookies'             => array(),
			self::CALL_TOKEN      => $token,
		);
	}

	/**
	 * Re-asserts the client's limits on the options the Requests library will send a request with.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $options        The options as WordPress and every earlier callback left them.
	 * @param int          $timeoutSeconds The total timeout, in seconds.
	 * @return array<mixed> The options with the limits in force.
	 */
	private static function options( array $options, int $timeoutSeconds ): array {
		$verify = $options['verify'] ?? null;

		return array_merge(
			$options,
			array(
				// Requests skips every check when this is false. Only a bundle's path is kept; anything
				// else becomes the certificate bundle the Requests library ships with.
				'verify'           => is_string( $verify ) && '' !== $verify ? $verify : Requests::get_certificate_path(),
				'verifyname'       => true,
				'connect_timeout'  => OutboundClient::CONNECT_TIMEOUT_SECONDS,
				'timeout'          => $timeoutSeconds,
				'follow_redirects' => false,
				'max_bytes'        => OutboundClient::MAX_RESPONSE_BYTES + 1,
			)
		);
	}

	/**
	 * Has a request's own Requests hooks vouch for the cURL handle that request is sent with.
	 *
	 * The Requests library runs the callbacks registered on a request's hooks for
	 * `curl.before_send` before WordPress's `http_api_curl` action, and every request has hooks
	 * of its own, so the handle recorded is that request's and no other's.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed    $hooks   The request's `hooks` option.
	 * @param \WeakMap $vouched The cURL handles vouched for, each mapped to true; this one joins them.
	 */
	private static function vouchForHandle( mixed $hooks, \WeakMap $vouched ): void {
		if ( ! ( $hooks instanceof HookManager ) ) {
			return;
		}

		$hooks->register(
			'curl.before_send',
			static function ( mixed $handle ) use ( $vouched ): void {
				if ( $handle instanceof \CurlHandle ) {
					$vouched[ $handle ] = true;
				}
			}
		);
	}

	/**
	 * Re-asserts the client's limits on the cURL handle a request is about to be sent with.
	 *
	 * Where WordPress sends with cURL, the handle's options decide where the request goes and
	 * which answer is accepted. After the last hook, the Requests library still sets the
	 * certificate bundle from its own options and, when its `verify` option is false, turns the
	 * checks off again. That option is kept by the `requests-requests.before_request` callback,
	 * and a handle that callback did not vouch for is refused before this runs.
	 *
	 * @since 0.2.0
	 *
	 * @param \CurlHandle $handle         The handle.
	 * @param string      $url            The request's URL.
	 * @param int         $timeoutSeconds The total timeout, in seconds.
	 */
	private static function secureHandle( \CurlHandle $handle, string $url, int $timeoutSeconds ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Sets options on the handle WordPress's HTTP API hands to its own hooks; that API still sends the request.
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL             => $url,
				CURLOPT_SSL_VERIFYPEER  => true,
				CURLOPT_SSL_VERIFYHOST  => 2,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_CONNECTTIMEOUT  => OutboundClient::CONNECT_TIMEOUT_SECONDS,
				CURLOPT_TIMEOUT         => $timeoutSeconds,
			)
		);
	}

	/**
	 * Turns the array WordPress returned into an OutboundResponse.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When the answer has no HTTP status.
	 *
	 * @param array<mixed> $response What wp_safe_remote_request() returned.
	 * @param string       $host     The host the request was sent to.
	 * @return OutboundResponse The answer.
	 */
	private static function answer( array $response, string $host ): OutboundResponse {
		$status = wp_remote_retrieve_response_code( $response );

		if ( ! is_numeric( $status ) || (int) $status < 100 || (int) $status > 599 ) {
			throw new TransportFailure( TransportFailure::CONNECT, $host );
		}

		return new OutboundResponse( (int) $status, self::headers( wp_remote_retrieve_headers( $response ) ), wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Flattens the headers WordPress returned: lower-case names, and repeated values joined.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $headers What wp_remote_retrieve_headers() returned.
	 * @return array<string, string> Header values, keyed by lower-case name.
	 */
	private static function headers( mixed $headers ): array {
		if ( $headers instanceof CaseInsensitiveDictionary ) {
			$headers = $headers->getAll();
		}

		$flat = array();

		foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
			$values                               = array_filter( (array) $value, 'is_scalar' );
			$flat[ strtolower( (string) $name ) ] = implode( ', ', array_map( 'strval', $values ) );
		}

		return $flat;
	}

	/**
	 * Tells what kind of failure WordPress's error message describes.
	 *
	 * The cURL transport, which WordPress uses wherever the extension is present, names its error
	 * by number. The socket transport used without it has no numbers, so its words are read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $message The message of the WP_Error.
	 * @return string One of the TransportFailure kinds, never SIZE.
	 */
	private static function failureKind( string $message ): string {
		if ( 1 === preg_match( '/\bcURL error (\d+)\b/', $message, $matches ) ) {
			$number = (int) $matches[1];

			if ( self::CURL_TIMEOUT === $number ) {
				return TransportFailure::TIMEOUT;
			}

			return in_array( $number, self::CURL_TLS_ERRORS, true ) ? TransportFailure::TLS : TransportFailure::CONNECT;
		}

		$message = strtolower( $message );

		if ( str_contains( $message, 'timed out' ) ) {
			return TransportFailure::TIMEOUT;
		}

		return str_contains( $message, 'certificate' ) || str_contains( $message, 'openssl' ) ? TransportFailure::TLS : TransportFailure::CONNECT;
	}
}
