<?php
/**
 * FileProviderGateway: a provider whose memory of each payment is a file both processes of a race share
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The provider's memory is a local file both processes of a race read and write, under a lock.

/**
 * Wraps the stub and plays a provider that serializes what it is asked about one payment, as a real provider does: a capture after a cancel is declined, a cancel after a capture answers the authorization, and a capture asked again with the same key is answered with the capture it made.
 *
 * Owns one fact, for the tests of a capture and a void racing, of a capture asked twice, and of a
 * process killed after the provider answered: what the provider knows, which outlives either
 * process. Its memory is a JSON file, read and written under an exclusive lock: each payment's
 * state, the answer it gave each capture key, and how many captures it made. A test can also give
 * it a callable to run once a capture is made and remembered, as a crash right after the
 * provider's answer.
 *
 * @since 0.2.0
 */
final class FileProviderGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * The gateway whose answers it gives.
	 *
	 * @since 0.2.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $inner;

	/**
	 * The file the provider remembers in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * What runs once a capture is made and remembered; null for nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $afterCapture;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $inner        The gateway whose answers it gives.
	 * @param string         $file         The file the provider remembers in.
	 * @param \Closure|null  $afterCapture Optional. What runs once a capture is made and remembered. Default none.
	 *
	 * @phpstan-param (\Closure(): void)|null $afterCapture
	 */
	public function __construct( PaymentGateway $inner, string $file, ?\Closure $afterCapture = null ) {
		$this->inner        = $inner;
		$this->file         = $file;
		$this->afterCapture = $afterCapture;
	}

	/**
	 * Returns how many captures the provider made, under every key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file the provider remembers in.
	 * @return int The count.
	 */
	public static function capturesMade( string $file ): int {
		return self::counted( $file, 'made' );
	}

	/**
	 * Returns how many times the provider was asked to capture, a capture it answered from memory included.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file the provider remembers in.
	 * @return int The count.
	 */
	public static function capturesAsked( string $file ): int {
		return self::counted( $file, 'asked' );
	}

	/**
	 * Reads one of the provider's counts.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file  The file the provider remembers in.
	 * @param string $count The count's name.
	 * @return int The count; 0 for a provider never asked.
	 */
	private static function counted( string $file, string $count ): int {
		$memory = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A provider that was never asked has no file.

		return (int) ( $memory[ $count ] ?? 0 );
	}

	/**
	 * Authorizes through the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->inner->authorize( $request );
	}

	/**
	 * Captures, unless the payment was cancelled; a capture asked again with the same key is answered with the one made.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The capture, or its decline.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$answer = $this->remembering(
			function ( array $memory ) use ( $request ): array {
				$memory['asked'] = ( $memory['asked'] ?? 0 ) + 1;

				if ( 'cancelled' === ( $memory['states'][ $request->intentUuid ] ?? null ) ) {
					return array( $memory, new GatewayResult( $this->inner->describe()->id, Operation::Capture, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, 'intent_canceled' ) );
				}

				$made = $memory['captures'][ $request->idempotencyKey() ] ?? null;

				if ( null !== $made ) {
					return array( $memory, self::result( $made ) );
				}

				$answer = $this->inner->capture( $request );

				$memory['captures'][ $request->idempotencyKey() ] = self::stored( $answer );
				$memory['states'][ $request->intentUuid ]         = 'captured';
				$memory['made']                                   = ( $memory['made'] ?? 0 ) + 1;

				return array( $memory, $answer );
			}
		);

		if ( null !== $this->afterCapture && Outcome::Approved === $answer->outcome ) {
			( $this->afterCapture )();
		}

		return $answer;
	}

	/**
	 * Cancels the authorization, unless the payment was captured, which a provider answers with the authorization it keeps.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The void, or the authorization's approval.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return $this->remembering(
			function ( array $memory ) use ( $request ): array {
				if ( 'captured' === ( $memory['states'][ $request->intentUuid ] ?? null ) ) {
					return array( $memory, new GatewayResult( $this->inner->describe()->id, Operation::Authorize, Outcome::Approved, $request->intentUuid, $request->amount, 'stub-ch-' . $request->intentUuid, $request->providerIntentId ) );
				}

				$memory['states'][ $request->intentUuid ] = 'cancelled';

				return array( $memory, $this->inner->void( $request ) );
			}
		);
	}

	/**
	 * Refunds through the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->inner->refund( $request );
	}

	/**
	 * Asks the wrapped gateway where an intent stands.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null Its answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return $this->inner->query( $query );
	}

	/**
	 * Asks the wrapped gateway what became of a refund.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult|null Its answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return $this->inner->queryRefund( $request );
	}

	/**
	 * Runs one step of the provider under the file's exclusive lock: reads its memory, lets the step change it and answer, and writes it back.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the file cannot be opened or locked.
	 *
	 * @param \Closure $step Given the memory, returns the memory after and the answer.
	 * @return GatewayResult The answer.
	 *
	 * @phpstan-param \Closure(array<string, mixed>): array{0: array<string, mixed>, 1: GatewayResult} $step
	 */
	private function remembering( \Closure $step ): GatewayResult {
		$handle = fopen( $this->file, 'c+' );

		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			throw new \RuntimeException( 'The provider\'s memory could not be opened.' );
		}

		try {
			$memory = json_decode( (string) stream_get_contents( $handle ), true );

			list( $after, $answer ) = $step( is_array( $memory ) ? $memory : array() );

			ftruncate( $handle, 0 );
			rewind( $handle );
			fwrite( $handle, (string) wp_json_encode( $after ) );
			fflush( $handle );

			return $answer;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * Returns what the provider remembers of an answer.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The answer.
	 * @return array<string, mixed> Its fields.
	 */
	private static function stored( GatewayResult $answer ): array {
		return array(
			'provider'  => $answer->provider,
			'operation' => $answer->operation->value,
			'outcome'   => $answer->outcome->value,
			'intent'    => $answer->intentUuid,
			'minor'     => $answer->amount->minorUnits(),
			'currency'  => $answer->amount->currency()->code(),
			'object'    => $answer->providerObjectId,
			'reference' => $answer->providerIntentId,
		);
	}

	/**
	 * Gives a remembered answer again.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stored What the provider remembers of it.
	 * @return GatewayResult The answer.
	 */
	private static function result( array $stored ): GatewayResult {
		return new GatewayResult( (string) $stored['provider'], Operation::from( (string) $stored['operation'] ), Outcome::from( (string) $stored['outcome'] ), (string) $stored['intent'], Money::of( (int) $stored['minor'], Currency::of( (string) $stored['currency'] ) ), $stored['object'], $stored['reference'] );
	}
}
