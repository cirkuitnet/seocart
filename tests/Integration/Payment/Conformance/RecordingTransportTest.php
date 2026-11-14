<?php
/**
 * Tests the transport the conformance suite puts under a gateway's HTTP client
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Tests\Conformance\Payment\CredentialRequests;
use SEOCart\Tests\Conformance\Payment\Exchange;
use SEOCart\Tests\Conformance\Payment\HttpWatch;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Tape;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use WP_UnitTestCase;

/**
 * Replays, records, loses answers, and notes what is wrong with a gateway's requests, through WordPress's HTTP API.
 *
 * @since 0.2.0
 */
final class RecordingTransportTest extends WP_UnitTestCase {

	/**
	 * The provider, by its scheme and host: the fake provider answers every request to it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PROVIDER = 'https://api.reference.example';

	/**
	 * The provider's URL the tests send to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const URL = self::PROVIDER . '/v1/intents';

	/**
	 * The path of the provider's token request.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOKEN_PATH = '/v1/oauth2/token';

	/**
	 * The credential the tests send.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'ref_test_transport_key';

	/**
	 * A provider's tracing header, an opaque token a recording drops unless the test declares the header.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TRACE = 'Zx81Qw7Er6Ty5Ui4Op3As2Df';

	/**
	 * The scratch tape directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * The watch.
	 *
	 * @since 0.2.0
	 *
	 * @var HttpWatch
	 */
	private HttpWatch $watch;

	/**
	 * The transaction manager whose depth the transport checks.
	 *
	 * @since 0.2.0
	 *
	 * @var FakeTransactionManager
	 */
	private FakeTransactionManager $tx;

	/**
	 * How many requests the fake provider answered.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $answered = 0;

	/**
	 * Makes a scratch tape and a provider answering every request to its host with the request's body.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->directory = sys_get_temp_dir() . '/seocart-transport-' . bin2hex( random_bytes( 6 ) );
		$this->watch     = new HttpWatch();
		$this->tx        = new FakeTransactionManager();
		$this->answered  = 0;
		$this->watch->install();

		add_filter(
			'pre_http_request',
			function ( mixed $pre, mixed $args, mixed $url ): mixed {
				if ( false !== $pre || ! is_string( $url ) || ! str_starts_with( $url, self::PROVIDER . '/' ) ) {
					return $pre;
				}

				++$this->answered;

				return array(
					'headers'  => array(
						'content-type' => 'application/json',
						'set-cookie'   => 'session=' . self::KEY,
						'x-trace'      => self::TRACE,
					),
					'body'     => (string) wp_json_encode(
						array(
							'id'   => 'ri_' . $this->answered,
							'echo' => (string) ( is_array( $args ) ? $args['body'] ?? '' : '' ),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Removes the scratch tape.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$tape = new Tape( $this->directory );

		foreach ( $tape->files() as $file ) {
			unlink( $file );
		}

		$directories = glob( $this->directory . '/*', GLOB_ONLYDIR );

		foreach ( false === $directories ? array() : $directories as $directory ) {
			rmdir( $directory );
		}

		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}

		parent::tear_down();
	}

	/**
	 * Tests that a recording takes the credential out, drops the secret headers, and replays to the same answer.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_is_scrubbed_and_replays_to_the_same_answer(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'one' );
		$recorded = $recorder->send( self::request( 'amount=1000&key=' . self::KEY ), 15 );
		$recorder->finish();

		$this->assertSame( array(), $recorder->violations() );

		$taped = (string) file_get_contents( $this->directory . '/one/1.json' );
		$this->assertStringNotContainsString( self::KEY, $taped, 'The credential reached the tape.' );
		$this->assertStringContainsString( 'key={{secret:secret_key}}', $taped );
		$this->assertStringNotContainsStringIgnoringCase( '"authorization"', $taped );
		$this->assertStringNotContainsStringIgnoringCase( '"set-cookie"', $taped );
		$this->assertStringNotContainsString( self::TRACE, $taped, 'A response header the test does not declare reached the tape.' );
		$this->assertStringContainsString( '"content-type": "application/json"', $taped );

		$player = $this->player();
		$player->scenario( 'one' );
		$replayed = $player->send( self::request( 'amount=1000&key=' . self::KEY ), 15 );
		$player->finish();

		$this->assertSame( array(), $player->violations() );
		$this->assertSame( 1, $this->answered, 'The replay reached the provider.' );
		$this->assertSame( json_decode( $recorded->body, true )['id'], json_decode( $replayed->body, true )['id'] );
		$this->assertSame( 2, $this->watch->seen(), 'Each request passed WordPress\'s HTTP API once.' );
		$this->assertSame( array(), $this->watch->foreign() );
	}

	/**
	 * Tests that a request that differs from its tape, one past its end, and an exchange never asked for are each noted.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_replay_notes_every_difference_from_the_tape(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'two' );
		$recorder->send( self::request( 'amount=1000' ), 15 );
		$recorder->send( self::request( 'amount=2000' ), 15 );
		$recorder->finish();

		$player = $this->player();
		$player->scenario( 'two' );

		try {
			$player->send( self::request( 'amount=1001' ), 15 );
			self::fail( 'A request unlike its tape was answered.' );
		} catch ( TransportFailure $refused ) {
			$this->assertSame( TransportFailure::CONNECT, $refused->kind );
		}

		$player->finish();
		$this->assertCount( 2, $player->violations() );
		$this->assertStringContainsString( 'differs from its tape in its body', $player->violations()[0] );
		$this->assertStringContainsString( 'holds 2 exchanges, and the gateway sent 0', $player->violations()[1] );

		$beyond = $this->player();
		$beyond->scenario( 'two' );
		$beyond->send( self::request( 'amount=1000' ), 15 );
		$beyond->send( self::request( 'amount=2000' ), 15 );

		try {
			$beyond->send( self::request( 'amount=3000' ), 15 );
			self::fail( 'A request past the tape\'s end was answered.' );
		} catch ( TransportFailure $refused ) {
			$this->assertStringContainsString( 'its tape holds 2', $beyond->violations()[0] );
		}
	}

	/**
	 * Tests that a lost answer reaches the provider and fails the gateway's request, on the recording and on its replay.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_lost_answer_is_answered_by_the_provider_and_never_reaches_the_gateway(): void {
		foreach ( array( $this->recorder(), $this->player() ) as $transport ) {
			$transport->scenario( 'lost' );
			$transport->loseNextAnswer();

			try {
				$transport->send( self::request( 'amount=1000' ), 15 );
				self::fail( 'A lost answer reached the gateway.' );
			} catch ( TransportFailure $lost ) {
				$this->assertSame( TransportFailure::TIMEOUT, $lost->kind );
			}

			$transport->finish();
			$this->assertSame( array(), $transport->violations() );
		}

		$this->assertSame( 1, $this->answered, 'The provider answered the recording only.' );
		$this->assertTrue( Exchange::fromTape( (array) json_decode( (string) file_get_contents( $this->directory . '/lost/1.json' ), true ) )->answerLost );
	}

	/**
	 * Tests that the answer lost is that of the next request that can move money: a read and a declared credential request before it are answered, on the recording and on its replay.
	 *
	 * Planted violation, shown red and removed: in RecordingTransport::send(), lose the answer to the
	 * next request whatever it is: the read's answer is lost, and this test fails.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_lost_answer_falls_on_the_next_request_that_can_move_money(): void {
		$credentials = new CredentialRequests(
			array(
				array(
					'method' => 'POST',
					'path'   => self::TOKEN_PATH,
				),
			),
			array( 'api.reference.example' )
		);

		foreach ( array( $this->recorder(), $this->player() ) as $transport ) {
			$transport->declareCredentialRequests( $credentials );
			$transport->scenario( 'read-first' );
			$transport->loseNextAnswer();

			$this->assertSame( 200, $transport->send( new OutboundRequest( 'reference-api', 'GET', self::URL, array( 'Authorization' => 'Bearer ' . self::KEY ), null ), 15 )->status, 'A read lost its answer.' );
			$this->assertSame( 200, $transport->send( self::request( 'grant_type=client_credentials', self::PROVIDER . self::TOKEN_PATH ), 15 )->status, 'A declared credential request lost its answer.' );

			try {
				$transport->send( self::request( 'amount=1000' ), 15 );
				self::fail( 'The answer to the request that can move money reached the gateway.' );
			} catch ( TransportFailure $lost ) {
				$this->assertSame( TransportFailure::TIMEOUT, $lost->kind );
			}

			$this->assertSame( 200, $transport->send( self::request( 'amount=2000' ), 15 )->status, 'A second answer was lost.' );
			$transport->finish();
			$this->assertSame( array(), $transport->violations() );
		}

		$this->assertSame( 4, $this->answered, 'The provider answered the recording only.' );
		$this->assertSame( array( false, false, true, false ), array_map( fn( int $number ): bool => Exchange::fromTape( (array) json_decode( (string) file_get_contents( $this->directory . '/read-first/' . $number . '.json' ), true ) )->answerLost, array( 1, 2, 3, 4 ) ) );
	}

	/**
	 * Tests that a recording keeps a provider's ids of a shape its test declares, whose digits pass the card number's checksum, in a request's URL, an answer's field and the links it holds, and that its tape passes rule 14's rescan; the same ids are refused as card numbers with no shape declared.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_keeps_ids_of_a_declared_shape_that_hold_a_cards_digits(): void {
		$id    = 'A4242424242424242';
		$order = self::PROVIDER . '/v2/checkout/orders/' . $id;

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, mixed $args, mixed $url ) use ( $id, $order ): mixed {
				unset( $args );

				if ( false !== $pre || $order !== $url ) {
					return $pre;
				}

				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => (string) wp_json_encode(
						array(
							'id'     => $id,
							'status' => 'COMPLETED',
							'links'  => array(
								array(
									'href' => $order,
									'rel'  => 'self',
								),
								array(
									'href' => self::PROVIDER . '/v2/payments/authorizations/' . $id . '/capture',
									'rel'  => 'capture',
								),
							),
						),
						JSON_UNESCAPED_SLASHES
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			5,
			3
		);

		foreach ( array(
			'declared'   => array( '' => 17 ),
			'undeclared' => array(),
		) as $scenario => $shapes ) {
			$scrubber = new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), $shapes );
			$recorder = RecordingTransport::recording( new Tape( $this->directory ), $this->tx, $this->watch );
			$recorder->scrubWith( $scrubber );
			$recorder->scenario( $scenario );
			$recorder->send( new OutboundRequest( 'reference-api', 'GET', $order, array( 'Authorization' => 'Bearer ' . self::KEY ), null ), 15 );
			$recorder->finish();

			if ( 'declared' === $scenario ) {
				$this->assertSame( array(), $recorder->violations() );
				$scrubber->scanFile( $this->directory . '/declared/1.json' );
				$this->assertStringContainsString( $id, (string) file_get_contents( $this->directory . '/declared/1.json' ) );
				continue;
			}

			$this->assertStringContainsString( 'Exchange 1 of undeclared, its URL holds a card number', implode( ' ', $recorder->violations() ) );
			$this->assertFileDoesNotExist( $this->directory . '/undeclared/1.json' );
		}
	}

	/**
	 * Tests that a recording holding a card's verification code is refused and not written, the part and the field named, while one holding the card fields a receipt shows is written.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_with_a_verification_code_is_not_written(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'card' );
		$recorder->send( self::request( 'card[last4]=0002&card[exp_month]=12&card[cvc]=737' ), 15 );
		$recorder->finish();

		$noted = implode( ' ', $recorder->violations() );

		$this->assertStringContainsString( 'Exchange 1 of card, its request body field card.cvc is named cvc', $noted );
		$this->assertStringNotContainsString( '737', $noted, 'A refusal never quotes the value.' );
		$this->assertFileDoesNotExist( $this->directory . '/card/1.json' );

		$receipt = $this->recorder();
		$receipt->scenario( 'receipt' );
		$receipt->send( self::request( 'card[last4]=0002&card[exp_month]=12&card[exp_year]=2034' ), 15 );
		$receipt->finish();

		$this->assertSame( array(), $receipt->violations() );
		$this->assertStringContainsString( 'card[last4]=0002&card[exp_month]=12&card[exp_year]=2034', (string) file_get_contents( $this->directory . '/receipt/1.json' ) );
	}

	/**
	 * Tests that a recording holding a secret the run did not declare is refused and not written, the part named and the value not.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_with_a_provider_secret_is_not_written(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'secret' );
		$recorder->send( self::request( 'client_secret=cs_test_a1B2c3D4e5F6g7H8' ), 15 );
		$recorder->finish();

		$noted = implode( ' ', $recorder->violations() );

		$this->assertStringContainsString( 'Exchange 1 of secret, its request body field client_secret holds a client secret (cs_', $noted );
		$this->assertStringNotContainsString( 'a1B2c3D4e5F6g7H8', $noted, 'A refusal never quotes the secret.' );
		$this->assertFileDoesNotExist( $this->directory . '/secret/1.json' );
	}

	/**
	 * Tests that a recording keeps a publishable key in the shape its test declares, and refuses a secret key in the same shape, the field named and the value not.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_keeps_a_publishable_key_and_refuses_a_secret_key(): void {
		$suffix = str_repeat( 'Ab1', 8 );

		foreach ( array(
			'publishable' => 'pk_test_' . $suffix,
			'secret'      => 'sk_test_' . $suffix,
		) as $scenario => $key ) {
			$recorder = RecordingTransport::recording( new Tape( $this->directory ), $this->tx, $this->watch );
			$recorder->scrubWith(
				new TapeScrubber(
					array( 'secret_key' => array( self::KEY ) ),
					array(),
					array(
						'pk_test_' => 24,
						'sk_test_' => 24,
					)
				)
			);
			$recorder->scenario( $scenario );
			$recorder->send( self::request( 'amount=1000&publishable_key=' . $key ), 15 );
			$recorder->finish();

			$noted = implode( ' ', $recorder->violations() );

			if ( 'publishable' === $scenario ) {
				$this->assertSame( array(), $recorder->violations() );
				$this->assertStringContainsString( 'publishable_key=' . $key, (string) file_get_contents( $this->directory . '/publishable/1.json' ) );
				continue;
			}

			$this->assertStringContainsString( 'Exchange 1 of secret, its request body field publishable_key holds a secret or restricted key', $noted );
			$this->assertStringNotContainsString( $suffix, $noted, 'A refusal never quotes the key.' );
			$this->assertFileDoesNotExist( $this->directory . '/secret/1.json' );
		}
	}

	/**
	 * Tests that a recording holding an opaque token in no public shape, lower case or digits only, is refused and not written, the field named and the value not.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_with_an_opaque_token_is_not_written(): void {
		foreach ( array(
			'lower'  => 'auth_live_abcdefghijkl1234567890',
			'digits' => '73519284602738495061',
		) as $scenario => $token ) {
			$recorder = $this->recorder();
			$recorder->scenario( $scenario );
			$recorder->send( self::request( 'amount=1000&session_key=' . $token ), 15 );
			$recorder->finish();

			$noted = implode( ' ', $recorder->violations() );

			$this->assertStringContainsString( sprintf( 'Exchange 1 of %s, its request body field session_key holds an opaque token of 20 characters or more', $scenario ), $noted );
			$this->assertStringNotContainsString( $token, $noted, 'A refusal never quotes the token.' );
			$this->assertFileDoesNotExist( $this->directory . '/' . $scenario . '/1.json' );
		}
	}

	/**
	 * Tests that a recording holding text in the shape of a scrubbed secret's placeholder that the scrubber did not write is refused and not written, while the scrubber's own placeholder is written.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_with_a_placeholder_the_scrubber_did_not_write_is_not_written(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'forged' );
		$recorder->send( self::request( 'amount=1000&key=' . self::KEY . '&note=' . rawurlencode( '{{secret:abcdefghijklmnopqrst}}' ) ), 15 );
		$recorder->finish();

		$noted = implode( ' ', $recorder->violations() );

		$this->assertStringContainsString( 'Exchange 1 of forged, its request body field note holds text in the shape of a scrubbed secret\'s placeholder', $noted );
		$this->assertStringNotContainsString( 'abcdefghijklmnopqrst', $noted, 'A refusal never quotes the value.' );
		$this->assertFileDoesNotExist( $this->directory . '/forged/1.json' );
	}

	/**
	 * Tests that a recording refuses a response header its test declares when it holds an opaque token, the header named and the value not, as it scans every header it keeps.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_recording_scans_a_declared_response_header(): void {
		$recorder = RecordingTransport::recording( new Tape( $this->directory ), $this->tx, $this->watch );
		$recorder->scrubWith( new TapeScrubber( array( 'secret_key' => array( self::KEY ) ), array(), array(), array(), array(), array( 'x-trace' ) ) );
		$recorder->scenario( 'traced' );
		$recorder->send( self::request( 'amount=1000' ), 15 );
		$recorder->finish();

		$noted = implode( ' ', $recorder->violations() );

		$this->assertStringContainsString( 'Exchange 1 of traced, its response header x-trace holds an opaque token of 20 characters or more', $noted );
		$this->assertStringNotContainsString( self::TRACE, $noted, 'A refusal never quotes the token.' );
		$this->assertFileDoesNotExist( $this->directory . '/traced/1.json' );
	}

	/**
	 * Tests that a request sent inside a transaction is noted.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_request_inside_a_transaction_is_noted(): void {
		$recorder = $this->recorder();
		$recorder->scenario( 'inside' );

		$this->tx->transaction(
			function () use ( $recorder ): void {
				$recorder->send( self::request( 'amount=1000' ), 15 );
			}
		);

		$this->assertCount( 1, $recorder->sentInsideTransactions() );
	}

	/**
	 * Builds a recording transport over the scratch tape, scrubbing the test's credential.
	 *
	 * @since 0.2.0
	 *
	 * @return RecordingTransport The transport.
	 */
	private function recorder(): RecordingTransport {
		$transport = RecordingTransport::recording( new Tape( $this->directory ), $this->tx, $this->watch );
		$transport->scrubWith( new TapeScrubber( array( 'secret_key' => array( self::KEY ) ) ) );

		return $transport;
	}

	/**
	 * Builds a replaying transport over the scratch tape.
	 *
	 * @since 0.2.0
	 *
	 * @return RecordingTransport The transport.
	 */
	private function player(): RecordingTransport {
		$transport = RecordingTransport::replaying( new Tape( $this->directory ), $this->tx, $this->watch );
		$transport->scrubWith( new TapeScrubber( array( 'secret_key' => array( self::KEY ) ) ) );

		return $transport;
	}

	/**
	 * Builds a request to the provider.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The form body.
	 * @param string $url  Optional. Where it is sent. Default URL.
	 * @return OutboundRequest The request.
	 */
	private static function request( string $body, string $url = self::URL ): OutboundRequest {
		return new OutboundRequest(
			'reference-api',
			'POST',
			$url,
			array(
				'Authorization' => 'Bearer ' . self::KEY,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			$body
		);
	}
}
