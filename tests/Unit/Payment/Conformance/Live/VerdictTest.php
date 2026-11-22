<?php
/**
 * Tests the closing verdict of a live workflow's run
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\Verdict;

/**
 * A run is green when every job its parts selected succeeded, the suite's job reported its counts and the replay's skips, and red otherwise; a part not selected is skipped and stays green; the optional part's job that ran nothing for its stated reason reads skipped with that reason and stays green, and only when it succeeded; the table names both heads and is titled by the workflow's name.
 *
 * Planted violations, each shown red and removed: in Verdict::judge(), a selected job's failure
 * taken as a success: the run whose selected job failed is green; a skipped job counted as a
 * success; the counts' check removed: the run without the suite's counts is green; the optional
 * part's reason shown for its job whatever the job's result: the job that failed reads skipped.
 *
 * @since 0.2.0
 */
final class VerdictTest extends TestCase {

	/**
	 * The extension's head the jobs report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXT = '7390d37aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * SEOCart's head the jobs report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CORE = 'f466a06912bab32012167a0e5173495def8c6b5c';

	/**
	 * Tests that a run whose every job succeeded is green, with its title, both heads and the counts in its table.
	 *
	 * @since 0.2.0
	 */
	public function test_every_job_succeeded_is_green(): void {
		$verdict = self::judge( self::needs(), 'all' );

		$this->assertTrue( $verdict['green'], implode( ' ', $verdict['problems'] ) );
		$this->assertStringStartsWith( "## Fixture Pay sandbox: green\n", $verdict['summary'] );
		$this->assertStringContainsString( '`' . self::EXT . '`', $verdict['summary'] );
		$this->assertStringContainsString( '`' . self::CORE . '`', $verdict['summary'] );
		$this->assertStringContainsString( '17 tests, 307 assertions, 2 skipped', $verdict['summary'] );
		$this->assertStringContainsString( '| live-conformance | `' . self::EXT . '` | `' . self::CORE . '` | nonce: r123456789.1 |', $verdict['summary'] );
		$this->assertStringContainsString( '| settled-refunds | `' . self::EXT . '` | `' . self::CORE . '` | settled: 2 |', $verdict['summary'] );
		$this->assertTrue( self::judge( self::needs(), '' )['green'], 'A scheduled run, which gives no parts, runs all.' );
	}

	/**
	 * Tests that a selected job that failed, skipped or is missing makes the run red.
	 *
	 * @since 0.2.0
	 */
	public function test_a_selected_job_failed_skipped_or_missing_is_red(): void {
		$failed = self::judge( self::needs( array( 'payments' => 'failure' ) ), 'all' );
		$this->assertFalse( $failed['green'] );
		$this->assertSame( array( 'The job payments was selected and ended failure.' ), $failed['problems'] );
		$this->assertStringStartsWith( "## Fixture Pay sandbox: red\n", $failed['summary'] );

		$skipped = self::judge( self::needs( array( 'settled-refunds' => 'skipped' ) ), 'all' );
		$this->assertSame( array( 'The job settled-refunds was selected and ended skipped.' ), $skipped['problems'] );

		$this->assertFalse( self::judge( self::needs( array( 'webhooks' => 'failure' ) ), 'webhooks' )['green'] );

		$needs = self::needs();
		unset( $needs['webhooks'] );
		$this->assertSame( array( 'The job webhooks was selected and ended missing.' ), self::judge( $needs, 'all' )['problems'] );
	}

	/**
	 * Tests that the parts not selected are skipped and the run stays green, and that a part the declaration does not state is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_part_not_selected_is_skipped_and_green(): void {
		$needs = self::needs(
			array(
				'live-conformance' => 'skipped',
				'payments'         => 'skipped',
				'settled-refunds'  => 'skipped',
				'checkout'         => 'skipped',
			)
		);

		$this->assertTrue( self::judge( $needs, 'webhooks' )['green'] );
		$this->assertFalse( self::judge( $needs, 'all' )['green'] );
		$this->assertFalse( self::judge( $needs, 'everything' )['green'], 'An unknown part is refused.' );
		$this->assertSame( array( 'The parts input `cli` names no part: all, conformance, payments, settled-refunds, webhooks, checkout.' ), self::judge( self::needs(), 'cli' )['problems'] );
	}

	/**
	 * Tests that the suite's job without its counts, or with other skips, makes the run red.
	 *
	 * @since 0.2.0
	 */
	public function test_the_suites_counts_missing_or_its_skips_differing_is_red(): void {
		$needs = self::needs();
		unset( $needs['live-conformance']['outputs']['tests'] );
		$this->assertSame( array( 'The job live-conformance reported no count of tests.' ), self::judge( $needs, 'all' )['problems'] );

		$needs = self::needs();
		$needs['live-conformance']['outputs']['skip-set'] = 'differs';
		$this->assertSame( array( 'The job live-conformance skipped other tests than those the replay skips.' ), self::judge( $needs, 'conformance' )['problems'] );
	}

	/**
	 * Tests the optional part that ran nothing, as its secret is absent: its proofs read skipped with the declared reason, the run stays green, and every other part reads as it ended.
	 *
	 * @since 0.2.0
	 */
	public function test_the_optional_part_that_ran_nothing_reads_skipped_with_its_reason_and_stays_green(): void {
		$needs = self::needs();

		$needs['webhooks']['outputs']['signature-key'] = LiveDeclaration::ABSENT;

		$verdict = self::judge( $needs, 'all' );

		$this->assertTrue( $verdict['green'], implode( ' ', $verdict['problems'] ) );
		$this->assertStringContainsString( '| webhooks | skipped: the repository has no FIXTURE_PAY_SIGNATURE_KEY secret yet, the key the sandbox signs its notifications with |', $verdict['summary'] );
		$this->assertStringContainsString( '| payments | success |', $verdict['summary'], 'Only the optional part reads skipped.' );
		$this->assertStringContainsString( 'signature-key: absent', $verdict['summary'] );

		$needs['webhooks']['outputs']['signature-key'] = LiveDeclaration::PRESENT;
		$this->assertStringContainsString( '| webhooks | success |', self::judge( $needs, 'all' )['summary'], 'With its secret, the part reads as it ended.' );
	}

	/**
	 * Tests that the optional part's reason is never shown for its job that failed: the run is red and the table says failure.
	 *
	 * @since 0.2.0
	 */
	public function test_the_optional_parts_reason_is_not_shown_for_a_failure(): void {
		$needs = self::needs( array( 'webhooks' => 'failure' ) );

		$needs['webhooks']['outputs']['signature-key'] = LiveDeclaration::ABSENT;

		$verdict = self::judge( $needs, 'all' );

		$this->assertFalse( $verdict['green'] );
		$this->assertStringContainsString( '| webhooks | failure |', $verdict['summary'] );
		$this->assertStringNotContainsString( 'skipped: the repository has no', $verdict['summary'] );
	}

	/**
	 * Tests that a declaration without an optional part shows every result as it ended, an output named like another's included.
	 *
	 * @since 0.2.0
	 */
	public function test_without_an_optional_part_every_result_is_shown_as_it_ended(): void {
		$needs = self::needs();

		$needs['webhooks']['outputs']['signature-key'] = LiveDeclaration::ABSENT;

		$verdict = Verdict::judge( StatedDeclaration::with( array( 'optionalPart' => null ) ), $needs, 'all', 'Fixture Pay sandbox' );

		$this->assertStringContainsString( '| webhooks | success |', $verdict['summary'] );
	}

	/**
	 * Tests that a job's output shaped like a credential is blanked in the table, as is a parts input of that shape.
	 *
	 * @since 0.2.0
	 */
	public function test_an_output_shaped_like_a_credential_is_blanked(): void {
		$canary = 'fpk_test_' . bin2hex( random_bytes( 12 ) );
		$needs  = self::needs();

		$needs['settled-refunds']['outputs']['settled'] = $canary;

		$verdict = self::judge( $needs, $canary );

		$this->assertStringNotContainsString( $canary, $verdict['summary'] . implode( ' ', $verdict['problems'] ) );
		$this->assertStringContainsString( 'settled: [redacted: a fixture key]', $verdict['summary'] );
	}

	/**
	 * Judges a run of the fixture's workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $needs The jobs' results and outputs.
	 * @param string       $parts The parts input.
	 * @return array{green: bool, problems: list<string>, summary: string} The verdict.
	 */
	private static function judge( array $needs, string $parts ): array {
		return Verdict::judge( StatedDeclaration::fixture(), $needs, $parts, 'Fixture Pay sandbox' );
	}

	/**
	 * Returns the jobs' results and outputs, as `toJSON(needs)` gives them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $results Results that differ from success, by job.
	 * @return array<string, array{result: string, outputs: array<string, string>}> The jobs.
	 */
	private static function needs( array $results = array() ): array {
		$needs = array();

		foreach ( array_keys( StatedDeclaration::fixture()->jobs() ) as $job ) {
			$needs[ $job ] = array(
				'result'  => $results[ $job ] ?? 'success',
				'outputs' => array(
					'ext-head'  => self::EXT,
					'core-head' => self::CORE,
				),
			);
		}

		$needs['live-conformance']['outputs'] += array(
			'tests'      => '17',
			'assertions' => '307',
			'skipped'    => '2',
			'skip-set'   => 'expected',
			'nonce'      => 'r123456789.1',
		);
		$needs['settled-refunds']['outputs']  += array( 'settled' => '2' );

		return $needs;
	}
}
