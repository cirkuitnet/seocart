<?php
/**
 * Tests for rule 12's reading of a gateway's source
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\NetworkCalls;

/**
 * Rule 12 names each way a gateway's source reaches the network past the plugin's client, and passes a name in a comment, a string or a method of the gateway's own.
 *
 * @since 0.2.0
 */
final class NetworkCallsTest extends TestCase {

	/**
	 * The file each case is written to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Picks the case's file.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->file = sys_get_temp_dir() . '/seocart-network-calls-' . bin2hex( random_bytes( 6 ) ) . '.php';
	}

	/**
	 * Removes the case's file.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}

		parent::tearDown();
	}

	/**
	 * Returns each way past the client, with the call its finding names.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The source, and the call named.
	 */
	public function calls(): array {
		return array(
			'cURL'                    => array( '$h = curl_init( $url );', 'curl_init()' ),
			'cURL, fully qualified'   => array( '$r = \\curl_exec( $h );', 'curl_exec()' ),
			'a socket'                => array( '$s = fsockopen( "api.example.com", 443 );', 'fsockopen()' ),
			'a stream socket'         => array( '$s = stream_socket_client( "tcp://api.example.com:443" );', 'stream_socket_client()' ),
			'a URL read as a file'    => array( '$b = file_get_contents( "https://api.example.com/v1/charges" );', 'file_get_contents() of a URL' ),
			'a URL opened'            => array( '$f = fopen( \'https://api.example.com/v1/charges\', "r" );', 'fopen() of a URL' ),
			'WordPress\'s HTTP API'   => array( '$r = wp_remote_post( $url, array() );', 'wp_remote_post()' ),
			'Guzzle'                  => array( '$c = new \\GuzzleHttp\\Client();', 'the class GuzzleHttp\\Client' ),
			'Guzzle, imported'        => array( "use GuzzleHttp\\Client;\n", 'the class GuzzleHttp\\Client' ),
			'Symfony\'s client'       => array( '$c = \\Symfony\\Component\\HttpClient\\HttpClient::create();', 'the class Symfony\\Component\\HttpClient\\HttpClient' ),
			'a provider\'s SDK'       => array( '$s = new \\Stripe\\StripeClient( $key );', 'the class Stripe\\StripeClient' ),
			'WordPress\'s HTTP class' => array( '$h = new WP_Http();', 'the class WP_Http' ),
			'a string callable'       => array( '$r = call_user_func( \'curl_exec\', $h );', 'the callable \'curl_exec\'' ),
			'a qualified callable'    => array( '$r = call_user_func_array( \'\\\\curl_exec\', array( $h ) );', 'the callable \'curl_exec\'' ),
			'a callable mapped'       => array( '$r = array_map( "curl_exec", $handles );', 'the callable \'curl_exec\'' ),
			'a variable function'     => array( '$send = \'wp_remote_post\'; $r = $send( $url );', 'the callable \'wp_remote_post\'' ),
			'a first-class callable'  => array( '$fetch = curl_exec( ... );', 'curl_exec()' ),
			'a function imported'     => array( 'use function curl_exec as fetch_answer;', 'the import of curl_exec()' ),
			'a qualified import'      => array( 'use function \\curl_init;', 'the import of curl_init()' ),
			'a client\'s function'    => array( 'use function GuzzleHttp\\Psr7\\stream_for;', 'the class GuzzleHttp\\Psr7\\stream_for' ),
			'a client in a string'    => array( '$client = \'GuzzleHttp\\\\Client\'; $c = new $client();', 'the class GuzzleHttp\\Client, named in a string' ),
		);
	}

	/**
	 * Tests that each way past the client is named, with its file and line.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider calls
	 * @group conformance
	 *
	 * @param string $source The source of the call.
	 * @param string $named  The call its finding names.
	 */
	public function test_a_network_call_past_the_client_is_named( string $source, string $named ): void {
		file_put_contents( $this->file, "<?php\nnamespace SEOCart\\GatewayForExample;\n" . $source . "\n" );

		$this->assertSame( array( $this->file . ' line 3: ' . $named ), NetworkCalls::in( $this->file ) );
	}

	/**
	 * Tests that a name in a comment or among the words of a string, a method or a function of the gateway's own, a file path and the plugin's client pass.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_names_that_are_not_calls_pass(): void {
		file_put_contents(
			$this->file,
			implode(
				"\n",
				array(
					'<?php',
					'namespace SEOCart\\GatewayForExample;',
					'use SEOCart\\Contracts\\OutboundRequest;',
					'// curl_init() is never called; the client sends every request.',
					'$note = "curl_exec() and new \\GuzzleHttp\\Client()";',
					'$this->curl_init( $request );',
					'self::fsockopen();',
					'$body = file_get_contents( __DIR__ . "/fixture.json" );',
					'$sent = $this->context->http()->send( new OutboundRequest( "host", "POST", $url, array(), null ) );',
					'$type = \'curl\';',
					'$id = \'stripe\'; $label = "Stripe"; $sdk = \'Guzzle\';',
					'$why = \'curl_exec failed, and wp_remote_post too\';',
					'$batch = $body[\'requests\'];',
					'use function sprintf;',
					'function curl_options(): array { return array(); }',
					'',
				)
			)
		);

		$this->assertSame( array(), NetworkCalls::in( $this->file ) );
	}
}
