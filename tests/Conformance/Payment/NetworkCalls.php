<?php
/**
 * NetworkCalls: rule 12's reading of a gateway's source for requests that bypass the plugin's client
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\Payment\PaymentGateway;

/**
 * Finds, in a gateway's own source, each call that reaches the network without the HTTP client the plugin gives the gateway.
 *
 * A request made another way is neither recorded nor replayed: a gateway that talks to its
 * provider through cURL, a socket, a URL stream, WordPress's HTTP API, an HTTP library or a
 * provider's SDK would pass a replay that never asks it for anything. The suite reads each file's
 * tokens: a call by name, a function or a client class named whole in a string (a callable, or a
 * variable function's value) and a `use function` import are each found, and a name in a comment
 * or among other words of a string is not taken for a call. A URL held in a variable, or a name
 * built at run time, is beyond it, and the suite's run-time half catches that: each payment
 * operation must send its own request through the client.
 *
 * @since 0.2.0
 */
final class NetworkCalls {

	/**
	 * What the reading of the source can and cannot see, as the reference page states it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIMIT = 'Rule 12\'s reading of the source names the calls it can see by name: cURL, sockets, a URL written in the source and read as a file, WordPress\'s HTTP API, and the HTTP clients and providers\' SDKs it knows by namespace (Guzzle, Symfony\'s and PSR-18 clients, Requests, and the SDKs of Stripe, Braintree, PayPal, Authorize.Net, Adyen, Square, Mollie, Razorpay and Omnipay), whether called directly, named whole in a string as a callable or a variable function\'s value, or imported with `use function`. A URL held in a variable, a name built at run time, or a library it does not know, is beyond it; the rule\'s run-time half covers those, as each payment operation (the authorization, the capture, each refund and the void) must send a request of its own, one that moves money and names the operation\'s key, through the plugin\'s client. That half takes any such request for the operation\'s own: it proves an adapter that sends its calls through the client, not one built to send the money call past it and another request with the same key through it.';

	/**
	 * Functions that open a connection whatever their arguments.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const FUNCTIONS = array( 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect', 'wp_remote_request', 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_safe_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'download_url', '_wp_http_get_object' );

	/**
	 * Functions that open a connection when their first argument is a URL written in the source.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const URL_FUNCTIONS = array( 'file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'simplexml_load_file' );

	/**
	 * Namespaces of HTTP clients and of providers' SDKs, each of which sends requests of its own.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const CLIENT_NAMESPACES = array( 'GuzzleHttp\\', 'Symfony\\Component\\HttpClient\\', 'Symfony\\Contracts\\HttpClient\\', 'Http\\Client\\', 'Psr\\Http\\Client\\', 'Buzz\\', 'WpOrg\\Requests\\', 'Stripe\\', 'Braintree\\', 'PayPalCheckoutSdk\\', 'PayPalHttp\\', 'PaypalServerSdkLib\\', 'net\\authorize\\', 'Adyen\\', 'Square\\', 'Mollie\\', 'Razorpay\\', 'Omnipay\\' );

	/**
	 * Classes of the global namespace that send requests.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const CLIENT_CLASSES = array( 'wp_http', 'requests', 'wp_http_streams', 'wp_http_curl' );

	/**
	 * Returns rule 12's failure for a gateway's source: each network call outside the client, or null when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway     The gateway: the files of its class, its parents and its traits are read.
	 * @param string[]       $sourceRoots The directories of the gateway's own source, read whole.
	 * @return string|null The failure, beginning with the rule's message; null when the source makes no such call.
	 */
	public static function failure( PaymentGateway $gateway, array $sourceRoots ): ?string {
		$findings = array();

		foreach ( self::filesOf( $gateway, $sourceRoots ) as $file ) {
			$findings = array_merge( $findings, self::in( $file ) );
		}

		return array() === $findings ? null : Rule::EveryCallThroughTheClient->message() . 'the gateway\'s source reaches the network without the HTTP client the plugin gives it, so no tape records or replays the request: ' . implode( '; ', $findings ) . '.';
	}

	/**
	 * Lists the network calls one PHP file makes outside the client.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file.
	 * @return list<string> Each as "<file> line <n>: <call>"; none when the file makes none.
	 */
	public static function in( string $file ): array {
		$tokens   = \PhpToken::tokenize( (string) file_get_contents( $file ) );
		$findings = array();

		foreach ( $tokens as $index => $token ) {
			$call = self::callAt( $tokens, $index );

			if ( null !== $call ) {
				$findings[] = sprintf( '%1$s line %2$d: %3$s', $file, $token->line, $call );
			}
		}

		return $findings;
	}

	/**
	 * Names the network call a token begins, if it begins one.
	 *
	 * @since 0.2.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @param int         $index  The token's position.
	 * @return string|null The call, such as "curl_init()" or "the class GuzzleHttp\Client"; null when the token begins none.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function callAt( array $tokens, int $index ): ?string {
		$token = $tokens[ $index ];

		if ( $token->is( T_CONSTANT_ENCAPSED_STRING ) ) {
			return self::callableIn( $token->text );
		}

		if ( ! $token->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ) ) ) {
			return null;
		}

		$name       = ltrim( $token->text, '\\' );
		$lowered    = strtolower( $name );
		$previousAt = self::beside( $tokens, $index, -1 );
		$previous   = self::at( $tokens, $previousAt );
		$nextAt     = self::beside( $tokens, $index, 1 );
		$next       = self::at( $tokens, $nextAt );

		// `use function curl_exec;`, which lets the source call it by another name.
		if ( self::isNetworkFunction( $lowered ) && true === $previous?->is( T_FUNCTION ) && true === self::at( $tokens, self::beside( $tokens, (int) $previousAt, -1 ) )?->is( T_USE ) ) {
			return sprintf( 'the import of %s()', $lowered );
		}

		// A namespaced name: a client's or an SDK's class, or a function of some namespace, which no network function is.
		if ( str_contains( $name, '\\' ) ) {
			foreach ( self::CLIENT_NAMESPACES as $namespace ) {
				if ( str_starts_with( $lowered . '\\', strtolower( $namespace ) ) ) {
					return sprintf( 'the class %s', $name );
				}
			}

			return null;
		}

		if ( in_array( $lowered, self::CLIENT_CLASSES, true ) && ( true === $previous?->is( T_NEW ) || true === $next?->is( T_DOUBLE_COLON ) ) ) {
			return sprintf( 'the class %s', $name );
		}

		if ( null === $next || '(' !== $next->text || ( null !== $previous && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ) ) ) ) {
			return null;
		}

		if ( self::isNetworkFunction( $lowered ) ) {
			return $lowered . '()';
		}

		$argument = self::at( $tokens, self::beside( $tokens, (int) $nextAt, 1 ) );

		if ( in_array( $lowered, self::URL_FUNCTIONS, true ) && null !== $argument && $argument->is( T_CONSTANT_ENCAPSED_STRING ) && 1 === preg_match( '#^["\'](?:https?|ftps?)://#i', $argument->text ) ) {
			return sprintf( '%s() of a URL', $lowered );
		}

		return null;
	}

	/**
	 * Names the network function or client class a string literal holds whole, as a callable or a class is named in one.
	 *
	 * `call_user_func( 'curl_exec', $handle )`, `$send = 'wp_remote_post'; $send( $url )` and
	 * `new $client()` with `$client = 'GuzzleHttp\Client'` each name the call in a string; a string
	 * that only mentions one among other words is text, and passes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $literal The string's token, its quotes included.
	 * @return string|null The callable or the class named; null for any other string.
	 */
	private static function callableIn( string $literal ): ?string {
		$literal = 'b' === strtolower( $literal[0] ) ? substr( $literal, 1 ) : $literal;
		$quote   = $literal[0];
		$text    = substr( $literal, 1, -1 );
		$content = '"' === $quote ? stripcslashes( $text ) : str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $text );
		$name    = ltrim( $content, '\\' );

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name ) ) {
			return null;
		}

		$lowered = strtolower( $name );

		if ( self::isNetworkFunction( $lowered ) ) {
			return sprintf( 'the callable \'%s\'', $lowered );
		}

		// A class is named whole only with its namespace: a bare word, such as a gateway's id `stripe`, is text.
		if ( ! str_contains( $name, '\\' ) ) {
			return null;
		}

		foreach ( self::CLIENT_NAMESPACES as $namespace ) {
			if ( str_starts_with( $lowered . '\\', strtolower( $namespace ) ) ) {
				return sprintf( 'the class %s, named in a string', $name );
			}
		}

		return null;
	}

	/**
	 * Tells whether a function's name, in lower case, is one that reaches the network whatever its arguments.
	 *
	 * @since 0.2.0
	 *
	 * @param string $lowered The name, in lower case, without a leading `\`.
	 * @return bool True for cURL's functions and FUNCTIONS.
	 */
	private static function isNetworkFunction( string $lowered ): bool {
		return str_starts_with( $lowered, 'curl_' ) || in_array( $lowered, self::FUNCTIONS, true );
	}

	/**
	 * Returns the position of the nearest token on one side that is not whitespace or a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @param int         $index  Where to start.
	 * @param int         $step   -1 for the previous token, 1 for the next.
	 * @return int|null The position; null at the file's edge.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function beside( array $tokens, int $index, int $step ): ?int {
		for ( $at = $index + $step; isset( $tokens[ $at ] ); $at += $step ) {
			if ( ! $tokens[ $at ]->isIgnorable() ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Returns the token at a position, if there is one.
	 *
	 * @since 0.2.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @param int|null    $at     The position.
	 * @return \PhpToken|null The token.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function at( array $tokens, ?int $at ): ?\PhpToken {
		return null === $at ? null : $tokens[ $at ];
	}

	/**
	 * Lists the files a gateway's source is read from.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway     The gateway.
	 * @param string[]       $sourceRoots Directories read whole.
	 * @return list<string> The PHP files, each once.
	 */
	private static function filesOf( PaymentGateway $gateway, array $sourceRoots ): array {
		$files = array();

		for ( $class = new \ReflectionClass( $gateway ); false !== $class; $class = $class->getParentClass() ) {
			foreach ( array_merge( array( $class ), array_values( $class->getTraits() ) ) as $declared ) {
				if ( false !== $declared->getFileName() ) {
					$files[] = $declared->getFileName();
				}
			}
		}

		foreach ( $sourceRoots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$files = array_values( array_unique( $files ) );
		sort( $files, SORT_STRING );

		return $files;
	}
}
