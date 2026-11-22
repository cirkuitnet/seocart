<?php
/**
 * Tests the PHP steps of a live workflow: the harvest, the suite's and the parts' checks, the optional part, the verdict and the filter
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\WorkflowStep;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * The harvest puts on the run list what the declaration says the run's own tapes created, and nothing from an earlier recording; each step writes its outputs and messages through the guard; the optional part's step says whether its secret is set and never what it is.
 *
 * Planted violation, shown red and removed: the harvest ignoring the declaration (an empty map in
 * place of LiveDeclaration::createdBy()): the run list of the fixture's tapes is empty.
 *
 * @since 0.2.0
 */
final class WorkflowStepTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The step's outputs.
	 *
	 * @since 0.2.0
	 *
	 * @var resource
	 */
	private $outputs;

	/**
	 * The step's messages.
	 *
	 * @since 0.2.0
	 *
	 * @var resource
	 */
	private $messages;

	/**
	 * Opens the streams.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->outputs  = self::stream();
		$this->messages = self::stream();
	}

	/**
	 * Tests that the harvest puts the charges the fixture's tapes created on the run list, as the declaration reads them, leaving out what an earlier recording's tape created and what was no creation.
	 *
	 * @since 0.2.0
	 */
	public function test_the_harvest_lists_what_the_runs_tapes_created(): void {
		$mark    = $this->writeFile( 'conformance.mark', '' );
		$runList = $this->writeFile( 'run-list', "fp_ch_9999Z 0199c0de-0000-7000-8000-00000000a999\n" );
		$then    = time() - 60;

		touch( $mark, $then );

		foreach ( (array) glob( StatedDeclaration::FIXTURE . '/tests/tapes/*/*.json' ) as $tape ) {
			touch( $this->writeFile( 'tapes/' . basename( dirname( (string) $tape ) ) . '/' . basename( (string) $tape ), (string) file_get_contents( (string) $tape ) ), $then + 5 );
		}

		$earlier = str_replace( array( 'fp_ch_0001A', '00000000a001' ), array( 'fp_ch_0000E', '00000000a000' ), (string) file_get_contents( StatedDeclaration::FIXTURE . '/tests/tapes/charge-approved/1.json' ) );
		touch( $this->writeFile( 'tapes/earlier/1.json', $earlier ), $then );

		$this->assertSame( 0, WorkflowStep::harvest( StatedDeclaration::fixture(), $this->directory . '/tapes', $mark, $runList, $this->messages ) );
		$this->assertSame( "fp_ch_9999Z 0199c0de-0000-7000-8000-00000000a999\nfp_ch_0001A 0199c0de-0000-7000-8000-00000000a001\nfp_ch_0002B 0199c0de-0000-7000-8000-00000000a002\n", file_get_contents( $runList ) );
		$this->assertSame( "The run list: 2 objects the suite created at the provider, read from the 4 tape files the run wrote.\n", self::read( $this->messages ) );
	}

	/**
	 * Tests that the harvest without its mark or its run list fails, saying so, and writes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_harvest_without_its_mark_fails(): void {
		$runList = $this->writeFile( 'run-list', '' );

		$this->assertSame( 1, WorkflowStep::harvest( StatedDeclaration::fixture(), StatedDeclaration::FIXTURE . '/tests/tapes', $this->directory . '/no-mark', $runList, $this->messages ) );
		$this->assertStringStartsWith( '::error::The objects the suite created cannot be listed', self::read( $this->messages ) );
		$this->assertSame( '', file_get_contents( $runList ) );
	}

	/**
	 * Tests that the suite's step gives its counts and whether the skips are the declared ones.
	 *
	 * @since 0.2.0
	 */
	public function test_the_suite_gives_its_counts_and_its_skip_set(): void {
		$output = "OK (40 tests, 120 assertions)\nOK, but incomplete, skipped, or risky tests!\nTests: 17, Assertions: 307, Skipped: 1.\n";

		$this->assertSame( 1, WorkflowStep::suite( StatedDeclaration::fixture(), $output, $this->outputs, $this->messages ) );
		$this->assertSame( "tests=17\nassertions=307\nskipped=1\nskip-set=differs\n", self::read( $this->outputs ) );
		$this->assertStringContainsString( '::error::The skipped tests are none, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.', self::read( $this->messages ) );
	}

	/**
	 * Tests that a part's step gives its counts and only the declared facts of its report as outputs, and prints every fact through the guard.
	 *
	 * @since 0.2.0
	 */
	public function test_a_part_gives_its_counts_and_its_declared_facts(): void {
		$canary = 'fpk_live_' . bin2hex( random_bytes( 12 ) );
		$report = "settled=2\nunlisted=x\nsettled=3\nkey={$canary}\n";

		$this->assertSame( 0, WorkflowStep::live( StatedDeclaration::fixture(), 'settled-refunds', "OK (3 tests, 30 assertions)\n", $report, $this->outputs, $this->messages ) );
		$this->assertSame( "tests=3\nassertions=30\nskipped=0\nsettled=3\n", self::read( $this->outputs ) );
		$this->assertSame( "settled: 3\nunlisted: x\nkey: [redacted: a fixture key]\n", self::read( $this->messages ) );
	}

	/**
	 * Tests that the optional part's step says whether the secret is set, never its value, and says why the part runs nothing without it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_optional_part_says_whether_its_secret_is_set_and_never_the_secret(): void {
		$secret = 'whsk_' . bin2hex( random_bytes( 16 ) );

		$this->assertSame( 0, WorkflowStep::optional( StatedDeclaration::fixture(), 'webhooks', '', $this->outputs, $this->messages ) );
		$this->assertSame( 0, WorkflowStep::optional( StatedDeclaration::fixture(), 'webhooks', $secret, $this->outputs, $this->messages ) );

		$outputs  = self::read( $this->outputs );
		$messages = self::read( $this->messages );

		$this->assertSame( "signature-key=absent\nsignature-key=present\n", $outputs );
		$this->assertSame( "::notice title=The webhooks part::The webhooks part is skipped: the repository has no FIXTURE_PAY_SIGNATURE_KEY secret yet, the key the sandbox signs its notifications with. The other parts run.\n", $messages );
		$this->assertStringNotContainsString( $secret, $outputs . $messages );
	}

	/**
	 * Tests that the optional step in another job, or for a declaration without an optional part, fails, saying so.
	 *
	 * @since 0.2.0
	 */
	public function test_the_optional_step_of_another_job_fails(): void {
		$this->assertSame( 1, WorkflowStep::optional( StatedDeclaration::fixture(), 'payments', '', $this->outputs, $this->messages ) );
		$this->assertSame( 1, WorkflowStep::optional( StatedDeclaration::with( array( 'optionalPart' => null ) ), 'webhooks', '', $this->outputs, $this->messages ) );
		$this->assertSame( '', self::read( $this->outputs ) );
		$this->assertSame( "::error::The job payments is not the optional part of tests/live.php, which is webhooks.\n::error::The job webhooks is not the optional part of tests/live.php, which is none.\n", self::read( $this->messages ) );
	}

	/**
	 * Tests what the shell steps read of the declaration: the slug, the group and a part's filter, each on a line of its own; anything else fails.
	 *
	 * @since 0.2.0
	 */
	public function test_describe_gives_the_slug_the_group_and_a_parts_filter(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( 0, WorkflowStep::describe( $declaration, 'slug', '', $this->outputs, $this->messages ) );
		$this->assertSame( 0, WorkflowStep::describe( $declaration, 'group', '', $this->outputs, $this->messages ) );
		$this->assertSame( 0, WorkflowStep::describe( $declaration, 'filter', 'payments', $this->outputs, $this->messages ) );
		$this->assertSame( "seocart-gateway-for-fixture-pay\nfixture-pay-live\n/Live\\\\PaymentsTest::/\n", self::read( $this->outputs ) );

		$this->assertSame( 1, WorkflowStep::describe( $declaration, 'filter', 'conformance', $this->outputs, $this->messages ) );
		$this->assertSame( 1, WorkflowStep::describe( $declaration, 'repository', '', $this->outputs, $this->messages ) );
		$this->assertSame( "The declaration says nothing of filter conformance: slug, group, or the filter of a live part (payments, settled-refunds, webhooks).\nThe declaration says nothing of repository: slug, group, or the filter of a live part (payments, settled-refunds, webhooks).\n", self::read( $this->messages ) );
	}

	/**
	 * Tests that the verdict's step writes the table under the workflow's name, or its basename when the name is not given, and fails a red run.
	 *
	 * @since 0.2.0
	 */
	public function test_the_verdict_writes_its_table_and_fails_a_red_run(): void {
		$declaration = StatedDeclaration::fixture();
		$needs       = (string) json_encode( array_map( static fn(): array => array( 'result' => 'skipped' ), $declaration->jobs() ) );

		$this->assertSame( 1, WorkflowStep::verdict( $declaration, $needs, 'nothing-but', 'Fixture Pay sandbox', $this->outputs, self::stream() ) );
		$this->assertStringStartsWith( "## Fixture Pay sandbox: red\n", self::read( $this->outputs ) );

		$summary = self::stream();

		$this->assertSame( 1, WorkflowStep::verdict( $declaration, $needs, 'all', '', $summary, $this->messages ) );
		$this->assertStringStartsWith( "## fixture-pay-sandbox: red\n", self::read( $summary ) );
		$this->assertStringContainsString( '::error::The job payments was selected and ended skipped.', self::read( $this->messages ) );
	}

	/**
	 * Tests that the filter of the scripts' output blanks what the guard blanks, line by line.
	 *
	 * @since 0.2.0
	 */
	public function test_redact_copies_a_text_through_the_guard(): void {
		$in = self::stream();

		fwrite( $in, "ok 1\nBearer UnitTestAccessTokenOfTheFixturesOnly\ncard 4012888888881881\n" );
		rewind( $in );

		$this->assertSame( 0, WorkflowStep::redact( StatedDeclaration::fixture(), $in, $this->outputs ) );
		$this->assertSame( "ok 1\n[redacted: an authorization header]\ncard [redacted: a card number]\n", self::read( $this->outputs ) );
	}

	/**
	 * Tests that the optional part's output is the one the declaration names, with the values SEOCart's constants give.
	 *
	 * @since 0.2.0
	 */
	public function test_the_optional_parts_values_are_present_and_absent(): void {
		$this->assertSame( array( 'present', 'absent' ), array( LiveDeclaration::PRESENT, LiveDeclaration::ABSENT ) );
	}

	/**
	 * Returns a stream in memory.
	 *
	 * @since 0.2.0
	 *
	 * @return resource The stream.
	 */
	private static function stream() {
		$stream = fopen( 'php://memory', 'w+b' );

		self::assertIsResource( $stream );

		return $stream;
	}

	/**
	 * Returns what a stream holds, from its start.
	 *
	 * @since 0.2.0
	 *
	 * @param resource $stream The stream.
	 * @return string Its text.
	 */
	private static function read( $stream ): string {
		rewind( $stream );

		return (string) stream_get_contents( $stream );
	}
}
