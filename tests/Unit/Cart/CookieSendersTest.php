<?php
/**
 * Tests that the plugin sets a cookie in one place only: the cart-token transport
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Cart;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Guards the page-cache contract: a read never sets a cookie.
 *
 * The plugin's only cookie is the cart token, and the transport sends it only with the response to
 * a write. That holds only if nothing else in the plugin sends a cookie, so this test reads every
 * file under src/ for setcookie() and setrawcookie(), as calls or callback strings, and for a
 * `Set-Cookie` header written by hand: whole, as `header( 'Set-Cookie: …' )`, or by its name, as
 * `$response->header( 'Set-Cookie', … )` or `$server->send_header( 'Set-Cookie', … )`. The
 * transport is the one file allowed.
 *
 * Planted violations, one at a time:
 * - add `setcookie( 'seocart_seen', '1' );` to StoreSession::describe(): the failure names
 *   src/Cart/Interfaces/StoreApi/StoreSession.php and the line;
 * - in senders(), require the colon after the header's name again (`/^set-cookie\s*:/i`): the
 *   self-test's header set by its name is no longer found.
 *
 * @since 0.1.0
 */
final class CookieSendersTest extends TestCase {

	/**
	 * The one file that may send a cookie.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TRANSPORT = 'src/Cart/Interfaces/StoreApi/CartTokenTransport.php';

	/**
	 * The functions that send a cookie.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const SENDERS = array( 'setcookie', 'setrawcookie' );

	/**
	 * Tests that no file under src/ but the transport sends a cookie.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_cart_token_transport_sends_a_cookie(): void {
		$files = PhpSource::files( 'src' );
		$found = array();

		$this->assertArrayHasKey( self::TRANSPORT, $files, 'The scan did not find the transport, so an empty result would prove nothing.' );
		$this->assertCount( 1, self::senders( self::TRANSPORT, $files[ self::TRANSPORT ] ), 'The scan must see the transport\'s one setcookie(), or a clean scan of the other files proves nothing.' );

		foreach ( $files as $file => $source ) {
			if ( self::TRANSPORT !== $file ) {
				$found = array_merge( $found, self::senders( $file, $source ) );
			}
		}

		$this->assertSame( array(), $found, 'Only the cart-token transport may send a cookie: a cookie set anywhere else can reach a cached page or a read.' );
	}

	/**
	 * Provides every way of sending a cookie the scan must find.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The source of one statement each.
	 */
	public static function cookieSenders(): array {
		return array(
			'setcookie()'                   => array( "<?php setcookie( 'a', 'b' );" ),
			'a fully qualified setcookie()' => array( "<?php \setcookie( 'a', 'b' );" ),
			'setrawcookie()'                => array( "<?php setrawcookie( 'a', 'b' );" ),
			'setcookie as a callback'       => array( "<?php array_map( 'setcookie', \$names );" ),
			'the whole header'              => array( "<?php header( 'Set-Cookie: a=b' );" ),
			'the header by its name'        => array( "<?php \$response->header( 'Set-Cookie', 'a=b' );" ),
			'the header sent by its name'   => array( "<?php \$server->send_header( 'set-cookie', 'a=b' );" ),
		);
	}

	/**
	 * Tests that the scan finds each way of sending a cookie.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider cookieSenders
	 *
	 * @param string $source The source.
	 */
	public function test_the_scan_finds_every_way_of_sending_a_cookie( string $source ): void {
		$this->assertSame( array( 'fixture.php:1' ), self::senders( 'fixture.php', $source ) );
	}

	/**
	 * Tests that the scan leaves other headers and comments alone.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scan_finds_nothing_else(): void {
		$this->assertSame( array(), self::senders( 'fixture.php', "<?php \$response->header( 'Cache-Control', 'no-store' ); // setcookie() and Set-Cookie in a comment." ) );
	}

	/**
	 * Lists where a file sends a cookie.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file   The file, for the message.
	 * @param string $source Its source.
	 * @return list<string> `file:line` for each sender found.
	 */
	private static function senders( string $file, string $source ): array {
		$found = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$text = trim( $token[1], "\\'\"" );

			if ( in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING ), true ) && in_array( strtolower( $text ), self::SENDERS, true ) ) {
				$found[] = $file . ':' . $token[2];
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( '/^set-cookie\s*:?/i', $text ) ) {
				$found[] = $file . ':' . $token[2];
			}
		}

		return $found;
	}
}
