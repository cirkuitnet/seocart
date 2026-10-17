<?php
/**
 * RecordingTransport: the outbound transport the conformance suite puts under a gateway's HTTP client
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Http\Transport;
use SEOCart\Platform\Http\WordPressTransport;

/**
 * Sits under the plugin's own OutboundClient and either replays a provider's recorded answers, records them, lets a test's fake provider answer, or refuses every request.
 *
 * Owns one fact: what a gateway sent in a scenario and what it was answered. Every mode sends
 * through the plugin's real WordPressTransport, so each request passes WordPress's
 * `pre_http_request` filter once, as in production, and HttpWatch can count it:
 *
 * - **replay** (the default): the n-th request of a scenario must equal the tape's n-th in method,
 *   path with query, and body, once both are scrubbed; it is answered with the tape's answer;
 * - **record** (SEOCART_TAPE=record): the request is sent for real, and at the scenario's end
 *   every exchange is scrubbed, scanned and written;
 * - **pass-through**: the request is sent, and a fake provider of the test answers it through
 *   `pre_http_request`; nothing is compared or written;
 * - **refusing**: every request is answered with a connection failure; what was sent is still
 *   kept, for the rules that only look at requests.
 *
 * It also checks that every request is sent outside any transaction. A mismatch, a request past
 * the tape's end or one inside a transaction is noted (violations()) and fails the request with
 * a TransportFailure: an assertion thrown inside a gateway could be caught by it. The test asks
 * finish() at the scenario's end and fails on any violation.
 *
 * @since 0.2.0
 */
final class RecordingTransport implements Transport {

	/**
	 * The environment variable that selects record mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ENVIRONMENT = 'SEOCART_TAPE';

	/**
	 * The value of ENVIRONMENT that records.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECORD = 'record';

	/**
	 * Replays the tape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REPLAYING = 'replay';

	/**
	 * Records the tape.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RECORDING = 'record';

	/**
	 * Lets the test's fake provider answer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PASSING = 'pass';

	/**
	 * Answers every request with a connection failure.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUSING = 'refuse';

	/**
	 * The mode: one of the four constants above.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * The tapes replayed or recorded; null when passing through or refusing.
	 *
	 * @since 0.2.0
	 *
	 * @var Tape|null
	 */
	private ?Tape $tape;

	/**
	 * The transaction manager whose depth must be 0 at every send.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * The watch told which URL the client is sending.
	 *
	 * @since 0.2.0
	 *
	 * @var HttpWatch
	 */
	private HttpWatch $watch;

	/**
	 * The plugin's own transport, which every request goes through.
	 *
	 * @since 0.2.0
	 *
	 * @var Transport
	 */
	private Transport $inner;

	/**
	 * What takes the run's credentials out of an exchange.
	 *
	 * @since 0.2.0
	 *
	 * @var TapeScrubber
	 */
	private TapeScrubber $scrubber;

	/**
	 * The current scenario's name; empty before the first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $scenario = '';

	/**
	 * The current scenario's taped exchanges, when replaying.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Exchange>|null
	 */
	private ?array $taped = null;

	/**
	 * What the gateway sent in the current scenario, and what it was answered, unscrubbed.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Exchange>
	 */
	private array $sent = array();

	/**
	 * Every response body served since the transport was built, for the logger's check.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $served = array();

	/**
	 * Whether the next request's answer is lost.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $loseNext = false;

	/**
	 * What went wrong, one sentence each.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $violations = array();

	/**
	 * The requests sent inside a transaction, one sentence each.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $insideTransactions = array();

	/**
	 * Whether the current scenario sends requests; one that must not has no tape.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $expectsRequests = true;

	/**
	 * Builds a transport of a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $mode  The mode.
	 * @param Tape|null          $tape  The tapes, for replay and record.
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 */
	private function __construct( string $mode, ?Tape $tape, TransactionManager $tx, HttpWatch $watch ) {
		$this->mode     = $mode;
		$this->tape     = $tape;
		$this->tx       = $tx;
		$this->watch    = $watch;
		$this->inner    = new WordPressTransport();
		$this->scrubber = new TapeScrubber( array() );
	}

	/**
	 * Builds the transport the environment asks for: recording when SEOCART_TAPE is `record`, replaying otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param Tape               $tape  The tapes.
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 * @return self The transport.
	 */
	public static function fromEnvironment( Tape $tape, TransactionManager $tx, HttpWatch $watch ): self {
		return self::RECORD === getenv( self::ENVIRONMENT ) ? self::recording( $tape, $tx, $watch ) : self::replaying( $tape, $tx, $watch );
	}

	/**
	 * Builds a transport that replays a tape.
	 *
	 * @since 0.2.0
	 *
	 * @param Tape               $tape  The tapes.
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 * @return self The transport.
	 */
	public static function replaying( Tape $tape, TransactionManager $tx, HttpWatch $watch ): self {
		return new self( self::REPLAYING, $tape, $tx, $watch );
	}

	/**
	 * Builds a transport that records a tape.
	 *
	 * @since 0.2.0
	 *
	 * @param Tape               $tape  The tapes.
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 * @return self The transport.
	 */
	public static function recording( Tape $tape, TransactionManager $tx, HttpWatch $watch ): self {
		return new self( self::RECORDING, $tape, $tx, $watch );
	}

	/**
	 * Builds a transport whose requests a test's fake provider answers.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 * @return self The transport.
	 */
	public static function passingThrough( TransactionManager $tx, HttpWatch $watch ): self {
		return new self( self::PASSING, null, $tx, $watch );
	}

	/**
	 * Builds a transport that answers every request with a connection failure.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx    The transaction manager.
	 * @param HttpWatch          $watch The watch.
	 * @return self The transport.
	 */
	public static function refusing( TransactionManager $tx, HttpWatch $watch ): self {
		return new self( self::REFUSING, null, $tx, $watch );
	}

	/**
	 * Tells whether this transport records.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True in record mode.
	 */
	public function records(): bool {
		return self::RECORDING === $this->mode;
	}

	/**
	 * Tells whether this transport replays.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True in replay mode.
	 */
	public function replays(): bool {
		return self::REPLAYING === $this->mode;
	}

	/**
	 * Gives the transport the scrubber of the run's credentials.
	 *
	 * @since 0.2.0
	 *
	 * @param TapeScrubber $scrubber The scrubber.
	 */
	public function scrubWith( TapeScrubber $scrubber ): void {
		$this->scrubber = $scrubber;
	}

	/**
	 * Answers every request from now on with a connection failure, keeping what was sent.
	 *
	 * @since 0.2.0
	 */
	public function refuseFromNowOn(): void {
		$this->mode = self::REFUSING;
	}

	/**
	 * Starts a scenario: the next request is its first.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name            The scenario's name, the directory of its tape.
	 * @param bool   $expectsRequests Whether it sends requests; one that must not has no tape, and any request of it fails.
	 */
	public function scenario( string $name, bool $expectsRequests = true ): void {
		$this->scenario        = $name;
		$this->sent            = array();
		$this->loseNext        = false;
		$this->expectsRequests = $expectsRequests;
		$this->taped           = null;

		if ( $this->replays() && null !== $this->tape ) {
			$this->taped = $expectsRequests ? $this->tape->exchanges( $name ) : array();
		}

		if ( $this->replays() && null === $this->taped ) {
			$this->violations[] = sprintf( 'there is no tape of the scenario %1$s in %2$s: record it with %3$s=%4$s', $name, $this->tape?->directory(), self::ENVIRONMENT, self::RECORD );
		}
	}

	/**
	 * Loses the answer to the next request: the provider answers, and the gateway is told the request timed out.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $lose False takes the order back, when no request was sent.
	 */
	public function loseNextAnswer( bool $lose = true ): void {
		$this->loseNext = $lose;
	}

	/**
	 * Sends a request in the transport's mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When the request is refused, its answer is lost, or it does not match the tape.
	 *
	 * @param OutboundRequest $request        The request.
	 * @param int             $timeoutSeconds The total timeout.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request, int $timeoutSeconds ): OutboundResponse {
		$number = count( $this->sent ) + 1;
		$host   = (string) wp_parse_url( $request->url, PHP_URL_HOST );

		if ( 0 !== $this->tx->depth() ) {
			$this->insideTransactions[] = sprintf( 'request %1$d of %2$s was sent at transaction depth %3$d', $number, $this->scenarioName(), $this->tx->depth() );
		}

		$lost   = $this->loseNext;
		$answer = null;

		$this->loseNext = false;

		if ( $this->replays() ) {
			$taped = $this->tapedRequest( $request, $number );

			if ( null === $taped ) {
				throw new TransportFailure( TransportFailure::CONNECT, $host );
			}

			$lost   = $taped->answerLost;
			$answer = self::answerOf( $taped->status, $taped->responseHeaders, $taped->responseBody );
		} elseif ( self::REFUSING === $this->mode ) {
			$answer = new \WP_Error( 'http_request_failed', 'cURL error 7: The conformance suite answers no request of a gateway that has no tape.' );
		}

		try {
			$response = $this->watch->expecting( $request->url, fn(): OutboundResponse => $this->sendThrough( $request, $timeoutSeconds, $answer ) );
		} catch ( TransportFailure $failed ) {
			$this->sent[] = self::exchangeOf( $request, null, $lost );

			if ( $this->records() ) {
				$this->violations[] = sprintf( 'request %1$d of %2$s got no answer while recording: %3$s', $number, $this->scenarioName(), $failed->getMessage() );
			}

			throw $failed;
		}

		$this->sent[]   = self::exchangeOf( $request, $response, $lost );
		$this->served[] = $response->body;

		if ( $lost ) {
			throw new TransportFailure( TransportFailure::TIMEOUT, $host );
		}

		return $response;
	}

	/**
	 * Ends the current scenario: a replay must have asked for every taped exchange; a recording is scrubbed, scanned and written.
	 *
	 * @since 0.2.0
	 */
	public function finish(): void {
		if ( $this->replays() && null !== $this->taped && count( $this->sent ) < count( $this->taped ) ) {
			$this->violations[] = sprintf( 'the tape of %1$s holds %2$d exchanges, and the gateway sent %3$d requests: record it again if the gateway sends fewer on purpose', $this->scenario, count( $this->taped ), count( $this->sent ) );
		}

		if ( ! $this->records() || null === $this->tape || '' === $this->scenario || ! $this->expectsRequests ) {
			if ( $this->records() && ! $this->expectsRequests && array() !== $this->sent ) {
				$this->violations[] = sprintf( 'the gateway sent %1$d requests in %2$s, which sends none', count( $this->sent ), $this->scenario );
			}

			return;
		}

		try {
			$scrubbed = array();

			foreach ( $this->sent as $index => $exchange ) {
				$clean = $this->scrubber->exchange( $exchange );
				$this->scrubber->scanExchange( $clean, sprintf( 'Exchange %1$d of %2$s', $index + 1, $this->scenario ) );
				$scrubbed[] = $clean;
			}

			$this->tape->write( $this->scenario, $scrubbed );
		} catch ( TapeRefused $refused ) {
			$this->violations[] = 'the recording was not written: ' . $refused->getMessage();
		}
	}

	/**
	 * Tells whether the current scenario sends requests.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True unless the scenario was begun as one that sends none.
	 */
	public function expectsRequests(): bool {
		return $this->expectsRequests;
	}

	/**
	 * Returns what the gateway sent in the current scenario, and what it was answered, unscrubbed.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Exchange> The exchanges, in order.
	 */
	public function exchanges(): array {
		return $this->sent;
	}

	/**
	 * Returns every response body served since the transport was built.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The bodies.
	 */
	public function servedBodies(): array {
		return $this->served;
	}

	/**
	 * Returns the requests sent inside a transaction.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One sentence each; none when every request was sent at depth 0.
	 */
	public function sentInsideTransactions(): array {
		return $this->insideTransactions;
	}

	/**
	 * Returns what went wrong.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One sentence each; none when nothing did.
	 */
	public function violations(): array {
		return $this->violations;
	}

	/**
	 * Finds the taped exchange a replayed request must equal, and notes why when there is none or it differs.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest $request The request.
	 * @param int             $number  Its number in the scenario, from 1.
	 * @return Exchange|null The taped exchange; null when the request does not match the tape.
	 */
	private function tapedRequest( OutboundRequest $request, int $number ): ?Exchange {
		if ( null === $this->taped ) {
			return null;
		}

		$taped = $this->taped[ $number - 1 ] ?? null;

		if ( null === $taped ) {
			$this->violations[] = sprintf( 'the gateway sent request %1$d of %2$s, and its tape holds %3$d: record it again if the gateway sends more on purpose', $number, $this->scenario, count( $this->taped ) );
			return null;
		}

		$sent = $this->scrubber->exchange( new Exchange( $request->method, $request->url, $request->headers, $request->body, 0, array(), '' ) );

		foreach ( array( 'method', 'host', 'path', 'body' ) as $part ) {
			$wanted = 'method' === $part ? $taped->method : ( 'body' === $part ? $taped->body : $taped->$part() );
			$got    = 'method' === $part ? $sent->method : ( 'body' === $part ? $sent->body : $sent->$part() );

			if ( $wanted !== $got ) {
				$this->violations[] = sprintf(
					'request %1$d of %2$s differs from its tape in its %3$s: the tape has %4$s, the gateway sent %5$s. Record it again with %6$s=%7$s if the provider call changed on purpose',
					$number,
					$this->scenario,
					$part,
					(string) wp_json_encode( $wanted ),
					(string) wp_json_encode( $got ),
					self::ENVIRONMENT,
					self::RECORD
				);
				return null;
			}
		}

		return $taped;
	}

	/**
	 * Sends a request through the plugin's own transport, answered from the given answer when there is one.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest                     $request        The request.
	 * @param int                                 $timeoutSeconds The total timeout.
	 * @param array<string, mixed>|\WP_Error|null $answer         What `pre_http_request` answers; null lets the request go on.
	 * @return OutboundResponse The answer.
	 */
	private function sendThrough( OutboundRequest $request, int $timeoutSeconds, array|\WP_Error|null $answer ): OutboundResponse {
		if ( null === $answer ) {
			return $this->inner->send( $request, $timeoutSeconds );
		}

		$answers = static function ( mixed $pre, mixed $args, mixed $url ) use ( $request, $answer ): mixed {
			unset( $args );

			return false === $pre && $url === $request->url ? $answer : $pre;
		};

		add_filter( 'pre_http_request', $answers, 0, 3 );

		try {
			return $this->inner->send( $request, $timeoutSeconds );
		} finally {
			remove_filter( 'pre_http_request', $answers, 0 );
		}
	}

	/**
	 * Builds the exchange of a request and its answer, as the gateway sent it.
	 *
	 * @since 0.2.0
	 *
	 * @param OutboundRequest       $request  The request.
	 * @param OutboundResponse|null $response The answer; null when there was none.
	 * @param bool                  $lost     Whether the answer was lost on its way to the gateway.
	 * @return Exchange The exchange.
	 */
	private static function exchangeOf( OutboundRequest $request, ?OutboundResponse $response, bool $lost ): Exchange {
		return new Exchange(
			$request->method,
			$request->url,
			$request->headers,
			$request->body,
			null === $response ? 0 : $response->status,
			null === $response ? array() : $response->headers,
			null === $response ? '' : $response->body,
			$lost
		);
	}

	/**
	 * Builds what `pre_http_request` answers for a taped response.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $status  The status.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The body.
	 * @return array<string, mixed> The answer, in the shape WordPress's HTTP API returns.
	 */
	private static function answerOf( int $status, array $headers, string $body ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Returns the current scenario's name, for a message.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name, or "the run" before the first scenario.
	 */
	public function scenarioName(): string {
		return '' === $this->scenario ? 'the run' : $this->scenario;
	}
}
