<?php
/**
 * The fixture extension's live workflow, as a payment extension states it: the parts of a provider with a payments
 * part, a settled-refunds part, a webhooks part that waits for a secret, and a checkout in a browser
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;

return new class() extends LiveDeclaration {

	/**
	 * Returns the live workflow's file name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file name.
	 */
	public function workflow(): string {
		return 'fixture-pay-sandbox.yml';
	}

	/**
	 * Returns the jobs, each with its choice of the `parts` input.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each choice, by job.
	 */
	public function jobs(): array {
		return array(
			'live-conformance' => 'conformance',
			'payments'         => 'payments',
			'settled-refunds'  => 'settled-refunds',
			'webhooks'         => 'webhooks',
			'checkout'         => 'checkout',
		);
	}

	/**
	 * Returns the live parts' tests.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{filter: string, tests: int}> Each part's filter and count.
	 */
	public function liveParts(): array {
		return array(
			'payments'        => array(
				'filter' => '/Live\\\\PaymentsTest::/',
				'tests'  => 6,
			),
			'settled-refunds' => array(
				'filter' => '/Live\\\\SettledRefundsTest::/',
				'tests'  => 3,
			),
			'webhooks'        => array(
				'filter' => '/Live\\\\(Notifications|Webhooks)Test::/',
				'tests'  => 2,
			),
		);
	}

	/**
	 * Returns what a run proves, each with its job.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each job, by what it proves.
	 */
	public function proofs(): array {
		return array(
			'The conformance suite, every request sent to the sandbox'                        => 'live-conformance',
			'A decline; the same payment sent twice refused and found; a withheld id found' => 'payments',
			'Settled payments refunded in part twice, and more than is left declined'       => 'settled-refunds',
			'The account\'s webhook listed, and the run\'s notifications read back'         => 'webhooks',
			'The checkout\'s card form in a browser: an approval, a decline and a reload'   => 'checkout',
		);
	}

	/**
	 * Returns the facts a job reports beyond its counts and nonce.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The facts.
	 */
	public function facts(): array {
		return array( 'settled', 'notifications', 'signature-key', 'extra-presses' );
	}

	/**
	 * Returns how many tests the conformance test holds.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function suiteTests(): int {
		return 17;
	}

	/**
	 * Returns the tests the suite skips against the sandbox, by the scenario each cannot produce.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each scenario, by test.
	 */
	public function suiteSkips(): array {
		return array(
			'test_rule_09_expiry'                    => 'expires',
			'test_rule_17_void_answers_the_approval' => 'requires_action_completed',
		);
	}

	/**
	 * Returns the shape of the fixture provider's key.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The pattern, by what it matches.
	 */
	public function shapes(): array {
		return array( 'a fixture key' => '/(?<![A-Za-z0-9])fpk_(?:test|live)_[A-Za-z0-9]{16,}/' );
	}

	/**
	 * Returns the charges recorded exchanges created: an answer to `POST /v1/charges`, its id, and the reference its request sent.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges.
	 * @return array<string, string> Each store uuid, by the charge's id.
	 */
	public function createdBy( array $exchanges ): array {
		$created = array();

		foreach ( $exchanges as $exchange ) {
			$request  = is_array( $exchange['request'] ?? null ) ? $exchange['request'] : array();
			$response = is_array( $exchange['response'] ?? null ) ? $exchange['response'] : array();
			$sent     = json_decode( is_string( $request['body'] ?? null ) ? $request['body'] : '', true );
			$answer   = json_decode( is_string( $response['body'] ?? null ) ? $response['body'] : '', true );
			$id       = is_array( $answer ) ? ( $answer['id'] ?? null ) : null;
			$uuid     = is_array( $sent ) ? ( $sent['reference'] ?? null ) : null;

			if ( 'POST' === ( $request['method'] ?? null ) && '/v1/charges' === ( $request['path'] ?? null ) && is_string( $id ) && is_string( $uuid ) ) {
				$created[ $id ] = $uuid;
			}
		}

		return $created;
	}

	/**
	 * Returns the webhooks part, which runs nothing while the signature key is absent.
	 *
	 * @since 0.2.0
	 *
	 * @return array{job: string, secret: string, output: string, reason: string} The part.
	 */
	public function optionalPart(): array {
		return array(
			'job'    => 'webhooks',
			'secret' => 'FIXTURE_PAY_SIGNATURE_KEY',
			'output' => 'signature-key',
			'reason' => 'the repository has no FIXTURE_PAY_SIGNATURE_KEY secret yet, the key the sandbox signs its notifications with',
		);
	}
};
