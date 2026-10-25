<?php
/**
 * ScriptedQueries: what the stand-in answers the reconciliation run when it asks where a payment stands, as a test scripts it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;

/**
 * Has the recording gateway answer the reconciliation run's queries about some intents as a test gives, and about every other as the stand-in does, and records the intents asked about.
 *
 * Owns one fact: what the reconciliation run is told, in a test, when it asks where a payment
 * stands. An answer is a result, or a closure that makes it when the run asks, so a test can land
 * something else first, such as the provider's delivery, while the run waits for its answer. The
 * run asks only about intents unchanged for a while: answerQueries() makes them so, every intent or
 * only those it answers.
 *
 * @since 0.2.0
 */
trait ScriptedQueries {

	/**
	 * The intents the gateway was asked about, in order, since answerQueries().
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	protected array $asked = array();

	/**
	 * Has the gateway answer the queries about some intents as given, and every other one as the stand-in does; makes intents old enough for the run to ask about them, and forgets the calls so far.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, GatewayResult|\Closure> $answers   The answer about each intent, by its uuid, or what makes it.
	 * @param bool                                  $onlyThese Optional. Whether only the intents answered are made old
	 *                                                         enough, so the run asks about them alone. Default false:
	 *                                                         every intent.
	 *
	 * @phpstan-param array<string, GatewayResult|(\Closure(): GatewayResult)> $answers
	 */
	protected function answerQueries( array $answers, bool $onlyThese = false ): void {
		if ( $onlyThese ) {
			foreach ( array_keys( $answers ) as $uuid ) {
				$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $uuid );
			}
		} else {
			$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );
		}

		$this->asked            = array();
		$this->gateway->calls   = array();
		$this->gateway->queries = function ( PaymentQuery $query ) use ( $answers ): ?GatewayResult {
			$this->asked[] = $query->intentUuid;
			$answer        = $answers[ $query->intentUuid ] ?? null;

			if ( $answer instanceof \Closure ) {
				return $answer();
			}

			return $answer ?? ( new StubGateway() )->query( $query );
		};
	}
}
