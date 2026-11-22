<?php
/**
 * Tests the rule that a live workflow writes its settings file last and installs nothing after it, on SEOCart's scripts and the fixture extension
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\SettingsLast;

/**
 * Once a job's settings file is written, by the step that runs the provider script's settings step whatever it is named, the fixture's workflow runs only SEOCart's suite and live steps and the provider's named steps, through bin/ci/extension-live.sh and the fixture's script, which with SEOCart's lib run only commands that cannot install, download or build; none of those steps runs before the settings step. Each way to break that, in the workflow, in SEOCart's runner or lib, or in the provider's script, is planted in memory and found.
 *
 * Planted violations, each shown red and removed: in the fixture's workflow, `composer install`
 * after a job's settings step, the settings step moved before the site step, and the set-up action
 * after the settings step; `npm ci` in the runner's conformance(), and `npx --yes` in the lib's
 * nonce(); a second function in the lib that runs `"$@"`; the settings step known by its anchor's
 * name only: a settings step with no anchor followed by `npm ci`, and the settings step anchored
 * under another name followed by an action, pass; a part run with no settings step before it
 * passes.
 *
 * @since 0.2.0
 */
final class SettingsLastTest extends TestCase {

	use Plants;

	/**
	 * The fixture's provider steps a job may run after its settings step.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEPS = array( 'configure', 'checkout', 'cleanup' );

	/**
	 * The fixture script's calls allowed only as written, each naming its provider.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const EXACT = array(
		'site_wp seocart gateway configure fixture_pay --mode=test --from-file="$settings" --no-webhooks --user=admin',
		'site_wp db query "SELECT provider_intent_id, uuid FROM ${prefix}seocart_payment_intents WHERE gateway_id = \'fixture_pay\'" --skip-column-names',
	);

	/**
	 * Tests that the four texts are read from the fixture and from SEOCart.
	 *
	 * @since 0.2.0
	 */
	public function test_the_sources_are_the_workflow_the_runner_the_lib_and_the_script(): void {
		$sources = SettingsLast::sources( StatedDeclaration::fixture() );
		$core    = dirname( __DIR__, 5 );

		$this->assertSame( self::linesOf( StatedDeclaration::FIXTURE . '/.github/workflows/fixture-pay-sandbox.yml' ), $sources['workflow'] );
		$this->assertSame( self::linesOf( $core . '/bin/ci/extension-live.sh' ), $sources['runner'] );
		$this->assertSame( self::linesOf( $core . '/bin/ci/extension-live-lib.sh' ), $sources['lib'] );
		$this->assertSame( self::linesOf( StatedDeclaration::FIXTURE . '/bin/ci/fixture-pay-sandbox.sh' ), $sources['script'] );
	}

	/**
	 * Tests that, once a job's settings file is written, the job runs only the listed steps, and those steps only the commands on the lists.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_listed_steps_and_commands_run_once_the_settings_file_is_written(): void {
		$this->assertSame( array(), self::rule()->violations( SettingsLast::sources( StatedDeclaration::fixture() ) ) );
	}

	/**
	 * Tests that a step, an action or a command off the lists after a job's settings step is found, in the workflow, in SEOCart's runner or lib, or in the provider's script.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider planted
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the sources in, broken out.
	 */
	public function test_a_step_or_a_command_off_the_lists_after_the_settings_file_is_found( string $expected, \Closure $plant ): void {
		$found = self::rule()->violations( $plant( SettingsLast::sources( StatedDeclaration::fixture() ) ) );

		$this->assertStringContainsString( $expected, implode( "\n", $found ) );
	}

	/**
	 * Returns one plant per way something off the lists could run after a settings file is written.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function planted(): array {
		$run     = static fn( string $command ): string => '/^              run: ' . preg_quote( $command, '/' ) . '$/';
		$step    = 'The job %1$s runs, after its settings step, a command that is not one of the script\'s steps: `run: %2$s`.';
		$runner  = 'The step extension-live.sh %1$s, which the job %2$s runs after its settings step, runs in %3$s() %4$s.';
		$script  = 'The step fixture-pay-sandbox.sh %1$s, which the job %2$s runs after its settings step, runs in %3$s() %4$s.';
		$lib     = 'The lib extension-live-lib.sh, which every step sources, runs %s.';
		$top     = 'The top level of extension-live.sh, which every step runs, runs a command not on the list: `%s`.';
		$command = static fn( string $words ): string => sprintf( 'a command not on the list: `%s`', $words );

		return array(
			'composer install after the settings'        => array( sprintf( $step, 'webhooks', 'composer install --working-dir=seocart' ), self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext webhooks' ), '            - run: composer install --working-dir=seocart' ) ),
			'the settings before the site is built'      => array( sprintf( $step, 'checkout', 'sh seocart/bin/ci/extension-live.sh site ext' ), self::insert( 'workflow', "/^                  browser: 'true'$/", '            - *settings' ) ),
			'the set-up action by its anchor'            => array( 'The job payments uses an action after its settings step: `uses: ./seocart/.github/actions/extension-live-setup`.', self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext payments' ), '            - *setup' ) ),
			'the set-up action written out'              => array( 'The job checkout uses an action after its settings step: `uses: ./seocart/.github/actions/extension-live-setup`.', self::insert( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh configure' ), '            - uses: ./seocart/.github/actions/extension-live-setup' ) ),
			'an action of a third party'                 => array( 'The job checkout uses an action after its settings step: `uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567`.', self::insert( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh checkout' ), '            - uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567' ) ),
			'npm i under its alias'                      => array( sprintf( $step, 'live-conformance', 'npm i --prefix ext/tests/browser' ), self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh conformance ext' ), '            - run: npm i --prefix ext/tests/browser' ) ),
			'pip install'                                => array( sprintf( $step, 'settled-refunds', 'pip install requests' ), self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext settled-refunds' ), '            - run: pip install requests' ) ),
			'WordPress downloaded'                       => array( sprintf( $step, 'checkout', 'sh seocart/bin/ci/prepare-integration.sh "$WORDPRESS_VERSION"' ), self::insert( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh checkout' ), '            - run: sh seocart/bin/ci/prepare-integration.sh "$WORDPRESS_VERSION"' ) ),
			'a part the declaration lacks'               => array( sprintf( $step, 'checkout', 'sh seocart/bin/ci/extension-live.sh live ext checkout' ), self::swap( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh checkout' ), '              run: sh seocart/bin/ci/extension-live.sh live ext checkout' ) ),
			'a provider step the caller lacks'           => array( sprintf( $step, 'payments', 'sh ext/bin/ci/fixture-pay-sandbox.sh check-secrets' ), self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext payments' ), '            - run: sh ext/bin/ci/fixture-pay-sandbox.sh check-secrets' ) ),
			'a settings step of no anchor, then npm ci'  => array( sprintf( $step, 'payments', 'npm ci' ), static fn( array $sources ): array => self::insert( 'workflow', '/^            - name: Write the settings file$/', '              run: sh ext/bin/ci/fixture-pay-sandbox.sh settings', '            - run: npm ci' )( self::swap( 'workflow', '/^            - \*settings$/', '            - name: Write the settings file' )( $sources ) ) ),
			'the settings step anchored otherwise'       => array( 'The job checkout uses an action after its settings step: `uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567`.', static fn( array $sources ): array => self::insert( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh configure' ), '            - uses: some-org/some-action@0123456789abcdef0123456789abcdef01234567' )( self::renamed( $sources, 'settings', 'write-file' ) ) ),
			'a part with no settings step'               => array( 'The job payments runs `run: sh seocart/bin/ci/extension-live.sh live ext payments` with no settings step before it: the step reads the settings file, so the rule has lost track of where the file is written.', self::drop( 'workflow', '/^            - \*settings$/' ) ),
			'an alias of no anchor'                      => array( 'The job payments runs `- *nothing` after its settings step, the alias of no step the workflow anchors.', self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext payments' ), '            - *nothing' ) ),
			'an env after the settings'                  => array( 'The job payments gives a step after its settings step the key env: only name, id, if, run.', self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext payments' ), '              env:', '                  SEOCART_SOAK_SIZE: 12' ) ),
			'a run continued on a deeper line'           => array( sprintf( $step, 'payments', 'sh seocart/bin/ci/extension-live.sh live ext payments' ), self::insert( 'workflow', $run( 'sh seocart/bin/ci/extension-live.sh live ext payments' ), '                  && npm ci' ) ),
			'npm ci in the runner\'s conformance()'      => array( sprintf( $runner, 'conformance', 'live-conformance', 'conformance', $command( 'npm ci' ) ), self::insert( 'runner', '/^conformance\(\) \{$/', "\tnpm ci" ) ),
			'npx --yes in a lib function reached'        => array( sprintf( $runner, 'conformance', 'live-conformance', 'nonce', $command( 'npx --yes cowsay hello' ) ), self::insert( 'lib', '/^nonce\(\) \{$/', "\tnpx --yes cowsay hello" ) ),
			'npx --yes in the lib, named there'          => array( sprintf( $lib, 'in nonce() ' . $command( 'npx --yes cowsay hello' ) ), self::insert( 'lib', '/^nonce\(\) \{$/', "\tnpx --yes cowsay hello" ) ),
			'a second pass-through in the lib'           => array( sprintf( $lib, 'in quietly() the command its caller gives it (`"$@"`), though it is no wrapper whose calls are checked' ), self::insert( 'lib', '/^outputs=/', 'quietly() {', "\t\"\$@\" >/dev/null", '}' ) ),
			'a command at the lib\'s top level'          => array( sprintf( $lib, $command( 'npx --yes cowsay hello' ) ), self::insert( 'lib', '/^outputs=/', 'npx --yes cowsay hello' ) ),
			'sed running a command in the lib'           => array( sprintf( $lib, 'in count() ' . $command( 'sed -n \'1e npm ci\' "$1"' ) ), self::insert( 'lib', '/^count\(\) \{$/', "\tsed -n '1e npm ci' \"\$1\"" ) ),
			'site() run through filtered()'              => array( sprintf( $runner, 'live payments', 'payments', 'site', $command( 'npm ci' ) ), self::insert( 'runner', '/^live\(\) \{$/', "\tfiltered \"\$work/x.out\" site" ) ),
			'a pass-through of its own'                  => array( sprintf( $runner, 'live payments', 'payments', 'live', $command( 'heads npm ci' ) ), static fn( array $sources ): array => self::insert( 'runner', '/^heads\(\) \{$/', "\t\"\$@\"" )( self::insert( 'runner', '/^live\(\) \{$/', "\theads npm ci" )( $sources ) ) ),
			'a branch running more than its step'        => array( 'The dispatch of extension-live.sh runs `conformance) conformance; npm ci ;;`: each of its branches is exactly `<step>) <function> ;;`.', self::swap( 'runner', '/^\tconformance\) conformance ;;$/', "\tconformance) conformance; npm ci ;;" ) ),
			'a trap at the top level'                    => array( sprintf( $top, 'trap \'npm ci\' EXIT' ), self::insert( 'runner', '/^set -eu$/', 'trap \'npm ci\' EXIT' ) ),
			'a } at the start of a line'                 => array( sprintf( $top, 'npm ci' ), self::insert( 'runner', '/^conformance\(\) \{$/', "\t{", "\t\ttrue", '}', "\tnpm ci" ) ),
			'backticks in double quotes'                 => array( sprintf( $runner, 'live payments', 'payments', 'live', 'a command substitution in backticks, which this rule does not read' ), self::insert( 'runner', '/^live\(\) \{$/', "\techo \"`npm ci`\"" ) ),
			'backticks in an assignment'                 => array( sprintf( $runner, 'live payments', 'payments', 'live', 'a command substitution in backticks, which this rule does not read' ), self::insert( 'runner', '/^live\(\) \{$/', "\tdone=`npm ci`" ) ),
			'a substitution in an expansion'             => array( sprintf( $runner, 'live payments', 'payments', 'live', $command( 'npm ci' ) ), self::insert( 'runner', '/^live\(\) \{$/', "\tdone=\${done:-\$(npm ci)}" ) ),
			'npm ci in configure()'                      => array( sprintf( $script, 'configure', 'checkout', 'configure', $command( 'npm ci' ) ), self::insert( 'script', '/^configure\(\) \{$/', "\t(cd \"\$core\" && npm ci)" ) ),
			'npx fetching a package in configure()'      => array( sprintf( $script, 'configure', 'checkout', 'configure', $command( 'npx --yes cowsay hello' ) ), self::insert( 'script', '/^configure\(\) \{$/', "\tnpx --yes cowsay hello" ) ),
			'the zip built in harvest_site()'            => array( sprintf( $script, 'cleanup', 'checkout', 'harvest_site', $command( 'php "$core/bin/build-zip.php" --plugin="$ext"' ) ), self::insert( 'script', '/^harvest_site\(\) \{$/', "\tphp \"\$core/bin/build-zip.php\" --plugin=\"\$ext\"" ) ),
			'WP-CLI told to run code by an option'       => array( sprintf( $script, 'cleanup', 'checkout', 'harvest_site', $command( 'site_wp db prefix --exec=\'passthru("npm ci");\'' ) ), self::swap( 'script', '/^\tprefix=\$\(site_wp db prefix /', "\tprefix=\$(site_wp db prefix --exec='passthru(\"npm ci\");' </dev/null) || return 1" ) ),
			'WP-CLI told to load a file'                 => array( sprintf( $script, 'configure', 'checkout', 'configure', $command( self::EXACT[0] . ' --require=/tmp/x.php' ) ), self::swap( 'script', '/^\tsite_wp seocart gateway configure /', "\t" . self::EXACT[0] . ' --require=/tmp/x.php </dev/null' ) ),
			'sort running a program'                     => array( sprintf( $script, 'cleanup', 'checkout', 'harvest_site', $command( 'sort --compress-program=sh -u -o "$run_list" "$run_list"' ) ), self::swap( 'script', '/^\tsort -u -o /', "\tsort --compress-program=sh -u -o \"\$run_list\" \"\$run_list\" || return 1" ) ),
			'a copy of a lib function'                   => array( 'fixture-pay-sandbox.sh defines filtered(), which the lib defines: its copy would run in the lib\'s place.', self::insert( 'script', '/^\[ "\$\{1:-\}" = check-secrets \]/', '', 'filtered() {', "\t\"\$@\"", '}' ) ),
			'the lib not sourced'                        => array( sprintf( $script, 'checkout', 'checkout', 'checkout', $command( 'count "$work/checkout.out" passed' ) ), self::drop( 'script', '/^\[ "\$\{1:-\}" = check-secrets \]/' ) ),
			'two spaces a level, then an install'        => array( sprintf( $step, 'payments', 'npm ci --prefix ext/tests/browser' ), static fn( array $sources ): array => self::insert( 'workflow', '/^      - \*settings$/', '      - run: npm ci --prefix ext/tests/browser' )( self::workflowAs( $sources, self::twoSpaces( $sources['workflow'] ) ) ) ),
			'a job\'s steps at its key, then an install' => array( sprintf( $step, 'payments', 'npm ci --prefix ext/tests/browser' ), static fn( array $sources ): array => self::insert( 'workflow', '/^        - \*settings$/', '        - run: npm ci --prefix ext/tests/browser' )( self::workflowAs( $sources, self::indentless( $sources['workflow'], 'payments' ) ) ) ),
			'a commented settings step, then an install' => array( sprintf( $step, 'payments', 'npm ci' ), static fn( array $sources ): array => self::insert( 'workflow', '/^            - \*settings$/', '            - run: npm ci' )( self::swap( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh settings' ), '              run: sh ext/bin/ci/fixture-pay-sandbox.sh settings # write the file' )( $sources ) ) ),
			'no job read'                                => array( 'No job was read from the workflow, so nothing it runs is checked.', self::swap( 'workflow', '/^jobs:$/', 'steps-of-the-jobs:' ) ),
			'a job of the declaration not read'          => array( 'The job settled-refunds was not read from the workflow, or no step of it was: what it runs after its settings step is not checked.', self::swap( 'workflow', '/^    settled-refunds:$/', '    settled-refunds-later:' ) ),
			'a settings step it cannot read'             => array( 'The job checkout has no step this rule reads as its settings step, `run: sh ext/bin/ci/fixture-pay-sandbox.sh settings`: what it runs after the settings file is written is not checked.', static fn( array $sources ): array => self::insert( 'workflow', '/^              run: >-$/', '                  sh ext/bin/ci/fixture-pay-sandbox.sh settings' )( self::swap( 'workflow', $run( 'sh ext/bin/ci/fixture-pay-sandbox.sh settings' ), '              run: >-' )( $sources ) ) ),
			'defaults for the whole workflow'            => array( 'The workflow sets defaults:, which every step after a settings step would follow: a live workflow sets none.', self::insert( 'workflow', '/^    cancel-in-progress: false$/', '', 'defaults:', '    run:', '        shell: npx --yes some-shell {0}' ) ),
			'defaults for a job'                         => array( 'The job payments sets defaults: for all its steps, those after its settings step included: a live workflow gives env: only to a step, and sets no defaults:.', self::insert( 'workflow', '/^    payments:$/', '        defaults:', '            run:', '                working-directory: ext' ) ),
			'an env for a job'                           => array( 'The job payments sets env: for all its steps, those after its settings step included: a live workflow gives env: only to a step, and sets no defaults:.', self::insert( 'workflow', '/^    payments:$/', '        env:', "            NPM_CONFIG_IGNORE_SCRIPTS: 'false'" ) ),
			'a here-document, then npm ci'               => array( sprintf( $script, 'configure', 'checkout', 'configure', 'a here-document, which this rule does not read' ), self::insert( 'script', '/^configure\(\) \{$/', "\tcat >/dev/null <<'END'", "\tsay \"hello", 'END', "\tnpm ci", "\techo \"done\"" ) ),
			'a quote that never closes, then npm ci'     => array( sprintf( $script, 'configure', 'checkout', 'configure', 'a quote that never closes, so what follows it is not read' ), self::insert( 'script', '/^\techo \'The gateway takes payments in test mode, its webhooks left out\.\'$/', "\techo \"it's done", "\tnpm ci" ) ),
			'npm ci in a case branch with alternatives'  => array( sprintf( $script, 'cleanup', 'checkout', 'cleanup', $command( 'npm ci' ) ), self::insert( 'script', '/^cleanup\(\) \{$/', "\tcase \${1:-} in", "\t\t'' | endpoints) npm ci ;;", "\t\t*) fail \"cleanup: the argument is endpoints or nothing, not \$1.\" ;;", "\tesac" ) ),
			'a lib variable assigned in a function'      => array( 'fixture-pay-sandbox.sh assigns step_php in configure(): the lib sets it, and what its functions run and read depends on it.', self::insert( 'script', '/^configure\(\) \{$/', "\tstep_php=\$ext/tests/Integration/Live/soak.php" ) ),
			'a lib variable assigned after the lib'      => array( 'fixture-pay-sandbox.sh assigns work in its top level, after it sources the lib: the lib sets it, and what its functions run and read depends on it.', self::insert( 'script', '/^\[ "\$\{1:-\}" = check-secrets \]/', 'work=/tmp/elsewhere' ) ),
			'a lib variable exported in a function'      => array( 'fixture-pay-sandbox.sh assigns settings in checkout(): the lib sets it, and what its functions run and read depends on it.', self::insert( 'script', '/^checkout\(\) \{$/', "\texport settings=/tmp/other.json" ) ),
		);
	}

	/**
	 * Tests that the workflow read at any consistent indentation, and a settings step whose run line carries a comment, keep the rule as the fixture does.
	 *
	 * @since 0.2.0
	 */
	public function test_the_rule_reads_the_workflow_at_any_indentation_and_a_commented_settings_step(): void {
		$sources = SettingsLast::sources( StatedDeclaration::fixture() );

		$this->assertSame( array(), self::rule()->violations( self::workflowAs( $sources, self::twoSpaces( $sources['workflow'] ) ) ) );
		$this->assertSame( array(), self::rule()->violations( self::workflowAs( $sources, self::indentless( $sources['workflow'], 'payments' ) ) ) );
		$this->assertSame( array(), self::rule()->violations( self::swap( 'workflow', '/^              run: sh ext\/bin\/ci\/fixture-pay-sandbox\.sh settings$/', '              run: sh ext/bin/ci/fixture-pay-sandbox.sh settings # write the file' )( $sources ) ) );
	}

	/**
	 * Tests that a case branch whose pattern has alternatives is read as a pattern, as the clean-up's argument is read, and its body as commands.
	 *
	 * @since 0.2.0
	 */
	public function test_a_case_pattern_with_alternatives_is_no_command(): void {
		$sources = self::insert( 'script', '/^cleanup\(\) \{$/', "\tcase \${1:-} in", "\t\t'' | endpoints) ;;", "\t\t*) fail \"cleanup: the argument is endpoints or nothing, not \$1.\" ;;", "\tesac" )( SettingsLast::sources( StatedDeclaration::fixture() ) );

		$this->assertSame( array(), self::rule()->violations( $sources ) );
	}

	/**
	 * Returns the sources with the workflow's lines replaced.
	 *
	 * @since 0.2.0
	 *
	 * @param array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} $sources  The sources.
	 * @param string[]                                                                                     $workflow The workflow's lines.
	 * @return array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} The sources.
	 * @phpstan-param list<string> $workflow
	 */
	private static function workflowAs( array $sources, array $workflow ): array {
		$sources['workflow'] = $workflow;

		return $sources;
	}

	/**
	 * Tests that a script with no dispatch, or a lib with one, is found: its steps cannot be read, or it would run as a step.
	 *
	 * @since 0.2.0
	 */
	public function test_a_script_without_a_dispatch_or_a_lib_with_one_is_found(): void {
		$sources = SettingsLast::sources( StatedDeclaration::fixture() );

		$sources['script'] = self::replaced( $sources['script'], '/^case \$\{1:-\} in$/', 'case $1 in' );
		$sources['lib'][]  = 'case ${1:-} in';
		$sources['lib'][]  = 'esac';

		$found = self::rule()->violations( $sources );

		$this->assertContains( 'fixture-pay-sandbox.sh has no dispatch, `case ${1:-} in` to `esac`: its steps cannot be read.', $found );
		$this->assertContains( 'The lib extension-live-lib.sh has a dispatch of its own: it is sourced, and runs no step.', $found );
	}

	/**
	 * Returns the rule for the fixture extension.
	 *
	 * @since 0.2.0
	 *
	 * @return SettingsLast The rule.
	 */
	private static function rule(): SettingsLast {
		return new SettingsLast( StatedDeclaration::fixture(), self::STEPS, self::EXACT );
	}

	/**
	 * Returns a plant that inserts lines, in their order, after the first line of a source that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source  The source: workflow, runner, lib or script.
	 * @param string $pattern The line after which they go.
	 * @param string ...$lines The lines.
	 * @return \Closure The plant: the sources in, broken out.
	 */
	private static function insert( string $source, string $pattern, string ...$lines ): \Closure {
		return static function ( array $sources ) use ( $source, $pattern, $lines ): array {
			foreach ( array_reverse( $lines ) as $line ) {
				$sources[ $source ] = self::after( $sources[ $source ], $pattern, $line );
			}

			return $sources;
		};
	}

	/**
	 * Returns a plant that replaces the first line of a source that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source  The source.
	 * @param string $pattern The line replaced.
	 * @param string $line    The line put in its place.
	 * @return \Closure The plant.
	 */
	private static function swap( string $source, string $pattern, string $line ): \Closure {
		return static function ( array $sources ) use ( $source, $pattern, $line ): array {
			$sources[ $source ] = self::replaced( $sources[ $source ], $pattern, $line );

			return $sources;
		};
	}

	/**
	 * Renames an anchor of the workflow's steps and every alias of it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} $sources The sources.
	 * @param string                                                                                       $from    The anchor's name.
	 * @param string                                                                                       $to      Its new name.
	 * @return array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} The sources, planted.
	 */
	private static function renamed( array $sources, string $from, string $to ): array {
		$renamed = array_map( static fn( string $line ): string => (string) preg_replace( '/^(            - [&*])' . preg_quote( $from, '/' ) . '$/D', '${1}' . $to, $line ), $sources['workflow'] );

		self::assertNotSame( $sources['workflow'], $renamed, 'The plant found no anchor to rename.' );

		$sources['workflow'] = $renamed;

		return $sources;
	}

	/**
	 * Returns a plant that removes the first line of a source that matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source  The source.
	 * @param string $pattern The line removed.
	 * @return \Closure The plant.
	 */
	private static function drop( string $source, string $pattern ): \Closure {
		return static function ( array $sources ) use ( $source, $pattern ): array {
			$sources[ $source ] = self::without( $sources[ $source ], $pattern );

			return $sources;
		};
	}
}
