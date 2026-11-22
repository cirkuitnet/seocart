<?php
/**
 * SettingsLast: the rule that a live workflow writes its settings file after everything that installs, and runs nothing that installs after it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Finds every way a live workflow's job could install, download or build once its settings file, which holds the provider's key, is written.
 *
 * The rule: the settings file that holds a key is written after everything that installs,
 * downloads or builds, and nothing after it installs, downloads or builds; otherwise a package's
 * install script, a build, a downloaded browser or an action would run with the file readable.
 * The lists are closed. A job's settings step is the step that runs the provider script's settings
 * step, `sh ext/bin/ci/<basename>.sh settings`, known by what it runs whatever it is named or
 * anchored as; an alias is read as the step its anchor marks. After it, the job may run only:
 *
 * - SEOCart's steps that read the file: `sh seocart/bin/ci/extension-live.sh conformance ext`, and
 *   `… live ext <part>` for each live part the declaration states;
 * - the provider's steps the caller names, as `sh ext/bin/ci/<basename>.sh <step>`, such as the
 *   gateway's configuration, the checkout's browser tests and the clean-up;
 *
 * each with the keys `name`, `id`, `if` and `run` alone (no environment, shell or working
 * directory of its own) and no action at all. Those steps read the file, so none can run before it
 * is written: a job that runs one with no settings step before it is refused, as the rule has then
 * lost track of where the file is written (a settings step written in a form it does not read, such
 * as a block of lines, included). The workflow is read as WorkflowFacts::read() reads it, by
 * relative indentation, a plain `run:` value without its trailing comment. The rule fails closed
 * on what it could not read: a workflow from which no job was read, a job of the declaration that
 * was not read or has no settings step it recognises, and `defaults:` at the top or in a job, or a
 * job's own `env:`, which every step after the settings step would follow, are each refused.
 *
 * The shell those steps run is read as two programs: SEOCart's bin/ci/extension-live.sh, and the
 * extension's bin/ci/<basename>.sh, each with SEOCart's bin/ci/extension-live-lib.sh where its top
 * level sources it (`. "$core/bin/ci/extension-live-lib.sh"`). In each, each branch of the
 * dispatch runs one function and nothing else, and the top level, which every step runs, and the
 * functions those steps reach, followed through every call and every function named as an
 * argument, may run only the commands COMMANDS allows and the calls EXACT and the caller list,
 * a wrapper's command checked at its call (WRAPPERS). Every function of the lib is held to the
 * same lists whether a step reaches it or not, as any step of any extension may. A script that
 * defines a function of the lib again is refused: its copy would run in the lib's place; so is a
 * script that, once it has sourced the lib, assigns one of the variables the lib's functions read.
 * A line the functions do not hold, such as one after a `}` that ends a function early, is the
 * top level's; what the reader does not read is refused: a command substitution in backticks, a
 * here-document, and a quote that never closes.
 *
 * @since 0.2.0
 */
final class SettingsLast {

	/**
	 * SEOCart's script of the live steps, from SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RUNNER = 'bin/ci/extension-live.sh';

	/**
	 * SEOCart's lib of the helpers and paths both scripts share, from SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIB = 'bin/ci/extension-live-lib.sh';

	/**
	 * The keys a step after the settings step may have: none that runs an action, or gives a shell, a working directory or an environment.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const STEP_KEYS = array( 'name', 'id', 'if', 'run' );

	/**
	 * The only commands the scripts' top levels and the functions those steps reach may run, as patterns of a simple
	 * command once its assignments, its `env` and its redirections are put aside, beside the calls EXACT lists. None can
	 * install, download or build anything:
	 *
	 * - the shell's own words and the POSIX tools for text, files and paths, which run no code they are not given;
	 * - PHP running SEOCart's workflow steps, the extension's clean-up, or the PHPUnit SEOCart's set-up installed before
	 *   the settings step;
	 * - SEOCart's conformance suite, which runs that PHPUnit;
	 * - WP-CLI on the site, as site_wp() runs it for the calls EXACT and the caller list;
	 * - jq, which rewrites the settings file;
	 * - the Playwright the set-up installed, running the tests: never npx, which fetches a package it lacks;
	 * - the site's file of its address and administrator, which the site step wrote, and SEOCart's lib, read into
	 *   the shell;
	 * - a wrapper's own run of the command its caller gave it, which is checked at the call (WRAPPERS).
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const COMMANDS = array(
		'/^(\[|test|true|false|printf|echo|cat|grep|tail|tee|tr|cut|od|mkdir|touch|sleep|rm|mv|chmod|kill|date|umask|cd|pwd|dirname|set|export|shift|return|exit)( |$)/',
		'/^php "\$(step_php|ext\/tests\/Integration\/Live\/cleanup\.php|core\/vendor\/bin\/phpunit)"( |$)/',
		'/^sh "\$core\/bin\/ci\/extension\.sh" conformance( |$)/',
		'/^wp --path="\$site_dir" "\$@"$/',
		'/^jq( |$)/',
		'/^node_modules\/\.bin\/playwright test( |$)/',
		'/^\. "\$site_env"$/',
		'/^\. "\$core\/bin\/ci\/extension-live-lib\.sh"$/',
	);

	/**
	 * The calls allowed only as written, whole, of the commands an option can turn to running code, that are no provider's:
	 * sed, whose `e` runs a command, for the live summary's lines and Playwright's counts; sort, whose
	 * `--compress-program` runs one, for the run list's; and WP-CLI, whose `--exec` and `--require` run PHP, for the
	 * database's prefix. The caller adds its own calls that name its provider.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const EXACT = array(
		'sed \'s/^/- /\'',
		'sed -n "s/^ *\([0-9][0-9]*\) $2.*/\1/p" "$1"',
		'sort -u -o "$run_list" "$run_list"',
		'site_wp db prefix',
	);

	/**
	 * The functions that run the command their caller gives them, by how many arguments of their own come first: a call of
	 * one is checked as the command those arguments are followed by. Any other function whose command is `"$@"` is a
	 * violation, as its callers' commands would go unchecked.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const WRAPPERS = array( 'filtered' => 1 );

	/**
	 * The words that open a compound command or join its parts, put aside before a command is read.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const KEYWORDS = array( 'if', 'then', 'else', 'elif', 'fi', 'do', 'done', 'while', 'until', 'esac', '!', '{', '}' );

	/**
	 * What the shell reader does not read, each refused where it stands, by the token the reader puts in its place: a command substitution in backticks, a here-document (whose body would be read as commands), and a quote that never closes (what follows it would be read as one word).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const UNREAD = array(
		'`'  => 'a command substitution in backticks, which this rule does not read',
		'<<' => 'a here-document, which this rule does not read',
		"'"  => 'a quote that never closes, so what follows it is not read',
		'"'  => 'a quote that never closes, so what follows it is not read',
	);

	/**
	 * The extension's declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var LiveDeclaration
	 */
	private LiveDeclaration $declaration;

	/**
	 * The provider's steps a job may run after its settings step, as a step's `run:` names them after the script.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $steps;

	/**
	 * The calls allowed only as written: EXACT, and the caller's.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $exact;

	/**
	 * Builds the rule for an extension.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $steps       The provider's steps a job may run after its settings step, such as `configure` or `cleanup endpoints`.
	 * @param string[]        $exact       The calls of the provider's script allowed only as written, each naming its provider, such as its gateway's configuration by WP-CLI.
	 * @phpstan-param list<string> $steps
	 * @phpstan-param list<string> $exact
	 */
	public function __construct( LiveDeclaration $declaration, array $steps, array $exact ) {
		$this->declaration = $declaration;
		$this->steps       = $steps;
		$this->exact       = array_merge( self::EXACT, $exact );
	}

	/**
	 * Returns the four texts the rule reads, from the extension's root and from SEOCart's beside it.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @return array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} The lines of each.
	 */
	public static function sources( LiveDeclaration $declaration ): array {
		$core = dirname( __DIR__, 4 );

		return array(
			'workflow' => self::read( $declaration->root() . '/.github/workflows/' . $declaration->workflow() ),
			'runner'   => self::read( $core . '/' . self::RUNNER ),
			'lib'      => self::read( $core . '/' . self::LIB ),
			'script'   => self::read( $declaration->root() . '/' . $declaration->script() ),
		);
	}

	/**
	 * Returns every way a job runs anything but the listed steps once its settings file is written, and every command those steps, the scripts' top levels and the lib run that is not on the lists.
	 *
	 * @since 0.2.0
	 *
	 * @param array{workflow: list<string>, runner: list<string>, lib: list<string>, script: list<string>} $sources The workflow's lines, SEOCart's script's and lib's, and the provider's script's.
	 * @return list<string> The violations; none when nothing but the allowed runs then.
	 */
	public function violations( array $sources ): array {
		$violations = array();
		$used       = array(
			'runner' => array(),
			'script' => array(),
		);
		$workflow   = WorkflowFacts::read( $sources['workflow'] );
		$jobs       = array_map( static fn( array $job ): array => $job['steps'], $workflow['jobs'] );
		$anchors    = array();
		$written    = array();

		if ( array() === $jobs ) {
			$violations[] = 'No job was read from the workflow, so nothing it runs is checked.';
		}

		if ( isset( $workflow['top']['defaults'] ) ) {
			$violations[] = 'The workflow sets defaults:, which every step after a settings step would follow: a live workflow sets none.';
		}

		foreach ( $workflow['jobs'] as $job => $read ) {
			foreach ( array_intersect( array( 'defaults', 'env' ), array_keys( $read['keys'] ) ) as $key ) {
				$violations[] = sprintf( 'The job %1$s sets %2$s: for all its steps, those after its settings step included: a live workflow gives env: only to a step, and sets no defaults:.', $job, $key );
			}
		}

		foreach ( $jobs as $items ) {
			foreach ( $items as $item ) {
				if ( null !== $item['alias'] && str_starts_with( $item['alias'], '&' ) ) {
					$anchors[ substr( $item['alias'], 1 ) ] ??= $item;
				}
			}
		}

		$settings = 'sh ext/' . $this->declaration->script() . ' settings';

		foreach ( $jobs as $job => $items ) {
			$after = false;

			foreach ( $items as $item ) {
				$resolved = self::resolved( $item, $anchors );
				$run      = null === $resolved || isset( $resolved['deep']['run'] ) ? null : ( $resolved['keys']['run'] ?? null );

				if ( $settings === $run ) {
					$after           = true;
					$written[ $job ] = true;
					continue;
				}

				if ( ! $after ) {
					if ( null !== $resolved && null !== $this->listed( $resolved ) ) {
						$violations[] = sprintf( 'The job %1$s runs `run: %2$s` with no settings step before it: the step reads the settings file, so the rule has lost track of where the file is written.', $job, $resolved['keys']['run'] ?? '' );
					}

					continue;
				}

				$step = $this->scriptStep( $job, $item, $anchors, $violations );

				if ( null !== $step ) {
					$used[ $step[0] ][ $step[1] ][ $job ] = true;
				}
			}
		}

		// Every part's job writes the settings file: one whose steps were not read, or whose settings
		// step was not recognised, is a job this rule could not follow, never one it passes.
		foreach ( array_keys( $this->declaration->jobs() ) as $job ) {
			if ( array() === ( $jobs[ $job ] ?? array() ) ) {
				$violations[] = sprintf( 'The job %1$s was not read from the workflow, or no step of it was: what it runs after its settings step is not checked.', $job );
			} elseif ( ! isset( $written[ $job ] ) ) {
				$violations[] = sprintf( 'The job %1$s has no step this rule reads as its settings step, `run: %2$s`: what it runs after the settings file is written is not checked.', $job, $settings );
			}
		}

		$lib = self::parts( $sources['lib'] );

		return array_values(
			array_unique(
				array_merge(
					$violations,
					$this->libViolations( $lib ),
					$this->programViolations( basename( self::RUNNER ), $sources['runner'], $lib, $used['runner'] ),
					$this->programViolations( basename( $this->declaration->script() ), $sources['script'], $lib, $used['script'] ),
					self::libAssignments( basename( $this->declaration->script() ), $sources['script'], $lib )
				)
			)
		);
	}

	/**
	 * Returns each assignment, in the provider's script, of a variable the lib's functions read, once the script has sourced the lib.
	 *
	 * The lib's paths (the variables its top level assigns), and core and ext, which the script sets
	 * before it sources the lib, decide what the lib's functions run and read: `filtered` runs
	 * `$step_php`, every step writes under `$work`. A script that assigns one afterwards, at its top
	 * level or in any function, changes what SEOCart's helpers do, so it is refused, whether the
	 * assignment stands alone, before a command, or after `export`, `readonly`, `local`, `unset` or
	 * `read`.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                                                        $name  The script's file name.
	 * @param string[]                                                                                      $lines The script's lines.
	 * @param array{functions: array<string, list<string>>, dispatch: list<string>|null, top: list<string>} $lib   The lib's parts.
	 * @return list<string> The violations.
	 * @phpstan-param list<string> $lines
	 */
	private static function libAssignments( string $name, array $lines, array $lib ): array {
		$parts     = self::parts( $lines );
		$variables = array( 'core', 'ext' );
		$texts     = array();
		$sourced   = false;

		foreach ( self::commands( $lib['top'] ) as $words ) {
			$variables = array_merge( $variables, self::assigned( $words ) );
		}

		foreach ( $parts['top'] as $line ) {
			if ( $sourced ) {
				$texts['its top level, after it sources the lib'][] = $line;
			}

			foreach ( self::commands( array( $line ) ) as $words ) {
				$sourced = $sourced || array( '.', '"$core/' . self::LIB . '"' ) === self::bare( $words );
			}
		}

		if ( ! $sourced ) {
			return array();
		}

		foreach ( $parts['functions'] as $function => $body ) {
			$texts[ $function . '()' ] = $body;
		}

		$violations = array();

		foreach ( $texts as $where => $text ) {
			foreach ( self::commands( $text ) as $words ) {
				foreach ( array_intersect( self::assigned( $words ), $variables ) as $variable ) {
					$violations[] = sprintf( '%1$s assigns %2$s in %3$s: the lib sets it, and what its functions run and read depends on it.', $name, $variable, $where );
				}
			}
		}

		return $violations;
	}

	/**
	 * Returns the variables a simple command assigns: by its assignments before the command's name, or as the arguments of `export`, `readonly`, `local`, `unset` or `read`.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words The command's words.
	 * @return list<string> The variables' names.
	 * @phpstan-param list<string> $words
	 */
	private static function assigned( array $words ): array {
		$names = array();
		$words = array_values( array_filter( $words, static fn( string $word ): bool => ! in_array( $word, self::KEYWORDS, true ) ) );

		foreach ( $words as $at => $word ) {
			if ( 1 === preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)=/', $word, $match ) ) {
				$names[] = $match[1];
				continue;
			}

			if ( in_array( $word, array( 'export', 'readonly', 'local', 'unset', 'read' ), true ) ) {
				foreach ( array_slice( $words, $at + 1 ) as $argument ) {
					if ( 1 === preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)(?:=|$)/', $argument, $match ) ) {
						$names[] = $match[1];
					}
				}
			}

			break;
		}

		return $names;
	}

	/**
	 * Returns why the lib breaks the lists: a dispatch of its own, or a command of its top level or of any of its functions that is not on them.
	 *
	 * @since 0.2.0
	 *
	 * @param array{functions: array<string, list<string>>, dispatch: list<string>|null, top: list<string>} $lib The lib's parts.
	 * @return list<string> The violations.
	 */
	private function libViolations( array $lib ): array {
		$violations = array();
		$name       = basename( self::LIB );
		$functions  = $lib['functions'] + array( '' => $lib['top'] );

		if ( null !== $lib['dispatch'] ) {
			$violations[] = sprintf( 'The lib %s has a dispatch of its own: it is sourced, and runs no step.', $name );
		}

		foreach ( array_keys( $functions ) as $function ) {
			foreach ( $this->refusals( (string) $function, $functions ) as $problem ) {
				$violations[] = sprintf( 'The lib %1$s, which every step sources, runs %2$s.', $name, $problem );
			}
		}

		return $violations;
	}

	/**
	 * Returns why a script breaks the lists: its top level, its dispatch, its copies of the lib's functions, and the commands the steps run after a settings step reach.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                                                        $name  The script's file name.
	 * @param string[]                                                                                      $lines The script's lines.
	 * @param array{functions: array<string, list<string>>, dispatch: list<string>|null, top: list<string>} $lib   The lib's parts.
	 * @param array<string, array<string, bool>>                                                            $used  The jobs that run each of its steps after their settings step, by the step.
	 * @return list<string> The violations.
	 * @phpstan-param list<string> $lines
	 */
	private function programViolations( string $name, array $lines, array $lib, array $used ): array {
		$violations = array();
		$parts      = self::parts( $lines );
		$functions  = $parts['functions'];
		$dispatch   = array();

		if ( $this->sourcesLib( $parts['top'] ) ) {
			foreach ( $lib['functions'] as $function => $body ) {
				if ( isset( $functions[ $function ] ) ) {
					$violations[] = sprintf( '%1$s defines %2$s(), which the lib defines: its copy would run in the lib\'s place.', $name, $function );
				}

				$functions[ $function ] = $body;
			}
		}

		$functions[''] = $parts['top'];

		foreach ( $this->refusals( '', $functions ) as $problem ) {
			$violations[] = sprintf( 'The top level of %1$s, which every step runs, runs %2$s.', $name, $problem );
		}

		if ( null === $parts['dispatch'] ) {
			$violations[] = sprintf( '%s has no dispatch, `case ${1:-} in` to `esac`: its steps cannot be read.', $name );
		}

		foreach ( $parts['dispatch'] ?? array() as $line ) {
			if ( 1 === preg_match( '/^\t([a-z-]+)\) ([a-z_]+)(?: "\$\{[23]:-\}")? ;;$/D', $line, $match ) ) {
				$dispatch[ $match[1] ] ??= $match[2];
			} elseif ( '' !== $line && 1 !== preg_match( '/^\t\*\) (?:fail \'[^\']*\'|usage) ;;$/D', $line ) ) {
				$violations[] = sprintf( 'The dispatch of %1$s runs `%2$s`: each of its branches is exactly `<step>) <function> ;;`.', $name, trim( $line ) );
			}
		}

		foreach ( $used as $step => $jobs ) {
			foreach ( $this->refusals( $dispatch[ (string) strtok( $step, ' ' ) ] ?? '-', $functions ) as $problem ) {
				foreach ( array_keys( $jobs ) as $job ) {
					$violations[] = sprintf( 'The step %1$s %2$s, which the job %3$s runs after its settings step, runs %4$s.', $name, $step, $job, $problem );
				}
			}
		}

		return $violations;
	}

	/**
	 * Returns which script a job's step after its settings step runs, and which of its steps, or null after noting why it is not one the lists allow.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                                                                           $job        The job.
	 * @param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}                $item       The step: its alias or anchor, its keys and their first lines, its keys whose value goes on.
	 * @param array<string, array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}> $anchors    The steps the workflow anchors, by the anchor's name.
	 * @param string[]                                                                                                         $violations The violations, noted into.
	 * @return array{string, string}|null `runner` or `script`, and the step, such as `live payments` or `configure`.
	 * @phpstan-param list<string> $violations
	 */
	private function scriptStep( string $job, array $item, array $anchors, array &$violations ): ?array {
		if ( null !== $item['alias'] && str_starts_with( $item['alias'], '*' ) ) {
			if ( ! isset( $anchors[ substr( $item['alias'], 1 ) ] ) ) {
				$violations[] = sprintf( 'The job %1$s runs `- %2$s` after its settings step, the alias of no step the workflow anchors.', $job, $item['alias'] );

				return null;
			}

			$item = $anchors[ substr( $item['alias'], 1 ) ];
		}

		if ( isset( $item['keys']['uses'] ) ) {
			$violations[] = sprintf( 'The job %1$s uses an action after its settings step: `uses: %2$s`.', $job, $item['keys']['uses'] );

			return null;
		}

		foreach ( array_diff( array_keys( $item['keys'] ), self::STEP_KEYS ) as $key ) {
			$violations[] = sprintf( 'The job %1$s gives a step after its settings step the key %2$s: only %3$s.', $job, $key, implode( ', ', self::STEP_KEYS ) );
		}

		$listed = $this->listed( $item );

		if ( null === $listed ) {
			$violations[] = sprintf( 'The job %1$s runs, after its settings step, a command that is not one of the script\'s steps: `run: %2$s`.', $job, $item['keys']['run'] ?? '' );
		}

		return $listed;
	}

	/**
	 * Returns which script a step runs, and which of its steps, when it is one the lists allow after a settings step: each of these reads the settings file.
	 *
	 * @since 0.2.0
	 *
	 * @param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int} $item The step.
	 * @return array{string, string}|null `runner` or `script`, and the step, such as `live payments` or `configure`; null for any other step.
	 */
	private function listed( array $item ): ?array {
		if ( isset( $item['deep']['run'] ) ) {
			return null;
		}

		$run    = $item['keys']['run'] ?? '';
		$runner = array( 'sh seocart/' . self::RUNNER . ' conformance ext' => 'conformance' );
		$script = 'sh ext/' . $this->declaration->script() . ' ';

		foreach ( array_keys( $this->declaration->liveParts() ) as $part ) {
			$runner[ 'sh seocart/' . self::RUNNER . ' live ext ' . $part ] = 'live ' . $part;
		}

		if ( isset( $runner[ $run ] ) ) {
			return array( 'runner', $runner[ $run ] );
		}

		if ( str_starts_with( $run, $script ) && in_array( substr( $run, strlen( $script ) ), $this->steps, true ) ) {
			return array( 'script', substr( $run, strlen( $script ) ) );
		}

		return null;
	}

	/**
	 * Returns a step as the workflow runs it: the step itself, or, for an alias, the step its anchor marks.
	 *
	 * @since 0.2.0
	 *
	 * @param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}                $item    The step.
	 * @param array<string, array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}> $anchors The steps the workflow anchors, by the anchor's name.
	 * @return array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}|null The step; null for the alias of no anchor.
	 */
	private static function resolved( array $item, array $anchors ): ?array {
		if ( null === $item['alias'] || ! str_starts_with( $item['alias'], '*' ) ) {
			return $item;
		}

		return $anchors[ substr( $item['alias'], 1 ) ] ?? null;
	}

	/**
	 * Tells whether a script's top level sources SEOCart's lib.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $top The top level's lines.
	 * @return bool True when one of its commands is the lib's `.`.
	 * @phpstan-param list<string> $top
	 */
	private function sourcesLib( array $top ): bool {
		foreach ( self::commands( $top ) as $words ) {
			if ( array( '.', '"$core/' . self::LIB . '"' ) === self::bare( $words ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns why each command of a function, and of every function it reaches, is not allowed.
	 *
	 * @since 0.2.0
	 *
	 * @param string                      $first     The function's name; `''` for the script's top level.
	 * @param array<string, list<string>> $functions The script's functions, and its top level by `''`.
	 * @return list<string> Each why, as `in <function>() <why>`, or the top level's own as `<why>`.
	 */
	private function refusals( string $first, array $functions ): array {
		$found = array();

		foreach ( self::reached( $first, $functions ) as $function ) {
			foreach ( self::commands( $functions[ $function ] ) as $words ) {
				$problem = $this->refusal( $words, $function, $functions );

				if ( null !== $problem ) {
					$found[] = '' === $function ? $problem : sprintf( 'in %1$s() %2$s', $function, $problem );
				}
			}
		}

		return $found;
	}

	/**
	 * Returns why a command of a function the steps reach is not allowed, or null when it is.
	 *
	 * A call of a wrapper is the command it is given (WRAPPERS); a call of another function of the
	 * script that runs `"$@"`, such as site_wp(), must itself be on the list; a call of any other
	 * function is allowed, as reached() follows it and its own commands are read.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                    $words     The command's words.
	 * @param string                      $within    The function it is in; `''` for the script's top level.
	 * @param array<string, list<string>> $functions The script's functions, and its top level by `''`.
	 * @return string|null Why not; null when allowed.
	 * @phpstan-param list<string> $words
	 */
	private function refusal( array $words, string $within, array $functions ): ?string {
		if ( 1 === count( $words ) && isset( self::UNREAD[ $words[0] ] ) ) {
			return self::UNREAD[ $words[0] ];
		}

		$words = self::bare( $words );

		if ( array() === $words ) {
			return null;
		}

		$name    = $words[0];
		$command = implode( ' ', $words );

		if ( isset( self::WRAPPERS[ $name ] ) ) {
			return $this->refusal( array_slice( $words, 1 + self::WRAPPERS[ $name ] ), $within, $functions );
		}

		if ( '"$@"' === $name ) {
			return isset( self::WRAPPERS[ $within ] ) ? null : sprintf( 'the command its caller gives it (`%s`), though it is no wrapper whose calls are checked', $command );
		}

		if ( ( isset( $functions[ $name ] ) && ! self::passesThrough( $functions[ $name ] ) ) || in_array( $command, $this->exact, true ) ) {
			return null;
		}

		foreach ( self::COMMANDS as $pattern ) {
			if ( 1 === preg_match( $pattern, $command ) ) {
				return null;
			}
		}

		return sprintf( 'a command not on the list: `%s`', $command );
	}

	/**
	 * Tells whether a function runs the command its caller gives it, `"$@"`.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The function's lines.
	 * @return bool True when it does.
	 * @phpstan-param list<string> $lines
	 */
	private static function passesThrough( array $lines ): bool {
		foreach ( self::commands( $lines ) as $words ) {
			if ( in_array( '"$@"', $words, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a command's words without what does not name the command: the words that open or join a compound command, assignments, `env`, and redirections.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words The words.
	 * @return list<string> The words from the command's name on.
	 * @phpstan-param list<string> $words
	 */
	private static function bare( array $words ): array {
		$words = array_values( array_filter( $words, static fn( string $word ): bool => 1 !== preg_match( '/^\d*[<>]/', $word ) ) );

		while ( array() !== $words && ( in_array( $words[0], self::KEYWORDS, true ) || 'env' === $words[0] || 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0] ) ) ) {
			array_shift( $words );
		}

		return $words;
	}

	/**
	 * Returns the simple commands of a function's lines, each as its words, quotes kept: a command
	 * substitution `$( … )`, in double quotes or in a parameter's expansion `${ … }` as much as
	 * outside them, is a command of its own, an arithmetic expansion a number, a case's
	 * patterns (alternatives joined by `|` included) and the heads of `case` and `for` no command;
	 * a backtick outside single quotes, a here-document's `<<` and a quote that never closes are
	 * each a command of their own token (UNREAD), which is refused.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<list<string>> The commands.
	 * @phpstan-param list<string> $lines
	 */
	private static function commands( array $lines ): array {
		$text = (string) preg_replace( '/\$\(\([^()]*\)\)/', '0', (string) preg_replace( '/\\\\\n/', ' ', implode( "\n", $lines ) ) );

		return self::lex( $text );
	}

	/**
	 * Splits shell text into simple commands: words are split on blanks, commands on newlines, `;`, `&`, `|`, `(` and `)`, outside quotes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return list<list<string>> The commands.
	 */
	private static function lex( string $text ): array {
		$commands = array();
		$words    = array();
		$word     = null;
		$cases    = 0;
		$pattern  = false;
		$length   = strlen( $text );

		for ( $at = 0; $at <= $length; $at++ ) {
			$char = $text[ $at ] ?? "\n";
			$two  = substr( $text, $at, 2 );

			if ( '$(' === $two ) {
				$close    = self::closing( $text, $at + 2 );
				$commands = array_merge( $commands, self::lex( substr( $text, $at + 2, $close - $at - 2 ) ) );
				$word    .= '$(…)';
				$at       = $close;
			} elseif ( "'" === $char ) {
				$close = strpos( $text, "'", $at + 1 );

				if ( false === $close ) {
					$commands[] = array( "'" );
					$close      = $length;
				}

				$word .= substr( $text, $at, $close - $at + 1 );
				$at    = $close;
			} elseif ( '"' === $char ) {
				[ $quoted, $inner, $at ] = self::doubleQuoted( $text, $at );
				$word                   .= $quoted;
				$commands                = array_merge( $commands, $inner, $at >= $length ? array( array( '"' ) ) : array() );
			} elseif ( '\\' === $char ) {
				$word .= substr( $text, $at++, 2 );
			} elseif ( '`' === $char ) {
				$commands[] = array( '`' );
				$word      .= $char;
			} elseif ( '#' === $char && null === $word ) {
				$close = strpos( $text, "\n", $at );
				$at    = ( false === $close ? $length : $close ) - 1;
			} elseif ( '<<' === $two ) {
				$commands[] = array( '<<' );
				$word      .= '<<';
				++$at;
			} elseif ( '>' === $char || '<' === $char ) {
				$word .= $char . ( '&' === ( $text[ $at + 1 ] ?? '' ) ? $text[ ++$at ] : '' );
			} elseif ( ' ' === $char || "\t" === $char ) {
				self::endWord( $word, $words );
			} elseif ( in_array( $char, array( "\n", ';', '&', '|', '(', ')' ), true ) ) {
				self::endWord( $word, $words );
				self::separate( $char, ';' === $char && ';' === ( $text[ $at + 1 ] ?? '' ), $words, $commands, $cases, $pattern );
				$at += ';' === $char && ';' === ( $text[ $at + 1 ] ?? '' ) ? 1 : 0;
			} else {
				$word .= $char;
			}
		}

		// A quote or a substitution that runs to the end of the text leaves words no separator ended.
		self::endWord( $word, $words );
		self::endCommand( $words, $commands, $cases, $pattern, false );

		return $commands;
	}

	/**
	 * Reads one separator: in a case's pattern, `|` joins its alternatives and `)` ends it, so the pattern is no command; `;;` ends a branch, and what comes next is a pattern, or `esac`; any other separator ends the command being read.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $char     The separator.
	 * @param bool     $branch   Whether it is the `;;` that ends a case's branch.
	 * @param string[] $words    The command's words.
	 * @param array    $commands The commands, added to.
	 * @param int      $cases    How many `case` commands are open.
	 * @param bool     $pattern  Whether a case's pattern is being read.
	 * @phpstan-param list<string> $words
	 * @phpstan-param list<list<string>> $commands
	 */
	private static function separate( string $char, bool $branch, array &$words, array &$commands, int &$cases, bool &$pattern ): void {
		if ( $pattern && ')' === $char ) {
			$words   = array();
			$pattern = false;
		} elseif ( $pattern && in_array( $char, array( '|', '(' ), true ) ) {
			return;
		} elseif ( $pattern && array() === $words ) {
			return;
		} else {
			// In a pattern, anything but `esac` before its `)` is no pattern: it is read as a command, so nothing goes unread.
			$pattern = $pattern && array( 'esac' ) !== $words ? false : $pattern;
			self::endCommand( $words, $commands, $cases, $pattern, ')' === $char );
		}

		if ( $branch && $cases > 0 ) {
			$pattern = true;
		}
	}

	/**
	 * Reads a double-quoted text from its opening quote: the text, the commands of the substitutions in it, and where it ends.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @param int    $at   Where its opening quote is.
	 * @return array{string, list<list<string>>, int} The quoted text, its commands, its closing quote's place.
	 */
	private static function doubleQuoted( string $text, int $at ): array {
		$quoted   = '"';
		$commands = array();
		$length   = strlen( $text );

		for ( ++$at; $at < $length && '"' !== $text[ $at ]; $at++ ) {
			if ( '\\' === $text[ $at ] ) {
				$quoted .= substr( $text, $at++, 2 );
			} elseif ( '$(' === substr( $text, $at, 2 ) ) {
				$close    = self::closing( $text, $at + 2 );
				$commands = array_merge( $commands, self::lex( substr( $text, $at + 2, $close - $at - 2 ) ) );
				$quoted  .= '$(…)';
				$at       = $close;
			} elseif ( '`' === $text[ $at ] ) {
				$commands[] = array( '`' );
				$quoted    .= '`';
			} else {
				$quoted .= $text[ $at ];
			}
		}

		return array( $quoted . '"', $commands, $at );
	}

	/**
	 * Returns where the `)` that closes a command substitution is, from the text just inside it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @param int    $at   Where the substitution's text begins.
	 * @return int Its closing parenthesis's place; the text's end when it has none.
	 */
	private static function closing( string $text, int $at ): int {
		$length = strlen( $text );

		for ( $depth = 1; $at < $length; $at++ ) {
			$char = $text[ $at ];

			if ( "'" === $char ) {
				$close = strpos( $text, "'", $at + 1 );
				$at    = false === $close ? $length : $close;
			} elseif ( '\\' === $char ) {
				++$at;
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && 0 === --$depth ) {
				return $at;
			}
		}

		return $length;
	}

	/**
	 * Ends the word being read, when there is one.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $word  The word; null between words.
	 * @param string[]    $words The command's words, added to.
	 * @phpstan-param list<string> $words
	 * @phpstan-param-out null $word
	 */
	private static function endWord( ?string &$word, array &$words ): void {
		if ( null !== $word ) {
			$words[] = $word;
			$word    = null;
		}
	}

	/**
	 * Ends the command being read: the heads of `case` and `for` are dropped, and after a `case`'s head its first pattern is read, unless a `)` ended the head with the pattern in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words    The command's words, emptied.
	 * @param array    $commands The commands, added to.
	 * @param int      $cases    How many `case` commands are open.
	 * @param bool     $pattern  Whether a case's pattern is being read.
	 * @param bool     $closing  Whether a `)` ends it.
	 * @phpstan-param list<string> $words
	 * @phpstan-param list<list<string>> $commands
	 */
	private static function endCommand( array &$words, array &$commands, int &$cases, bool &$pattern, bool $closing ): void {
		$head = self::bare( $words )[0] ?? null;

		if ( 'case' === $head ) {
			++$cases;
			$pattern = ! $closing;
		} elseif ( 'esac' === ( $words[0] ?? null ) ) {
			--$cases;
			$pattern = false;
		} elseif ( 'for' !== $head && array() !== $words ) {
			$commands[] = $words;
		}

		$words = array();
	}

	/**
	 * Returns a script's parts: its functions, each one's lines from its `name() {` to the first `}` that starts a
	 * line, by its name; its dispatch's branches, from the first `case ${1:-} in` that starts a line to the `esac` that
	 * ends it, or null without one; and every other line, its top level.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $script The script's lines.
	 * @return array{functions: array<string, list<string>>, dispatch: list<string>|null, top: list<string>} The parts.
	 * @phpstan-param list<string> $script
	 */
	private static function parts( array $script ): array {
		$parts = array(
			'functions' => array(),
			'dispatch'  => null,
			'top'       => array(),
		);
		$name  = null;
		$case  = false;

		foreach ( $script as $line ) {
			if ( null !== $name ) {
				if ( '}' === $line ) {
					$name = null;
				} else {
					$parts['functions'][ $name ][] = $line;
				}
			} elseif ( $case ) {
				if ( 'esac' === $line ) {
					$case = false;
				} else {
					$parts['dispatch'][] = $line;
				}
			} elseif ( 1 === preg_match( '/^([a-z_]+)\(\) \{$/', $line, $match ) ) {
				$name                        = $match[1];
				$parts['functions'][ $name ] = array();
			} elseif ( 'case ${1:-} in' === $line && null === $parts['dispatch'] ) {
				$case              = true;
				$parts['dispatch'] = array();
			} else {
				$parts['top'][] = $line;
			}
		}

		return $parts;
	}

	/**
	 * Returns a function of the script and every function of the script it reaches, at any depth: one
	 * it calls, and one it names as an unquoted word anywhere in a command, as a wrapper's argument
	 * or otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param string                      $first     The function's name; `''` for the script's top level.
	 * @param array<string, list<string>> $functions The script's functions, and its top level by `''`.
	 * @return list<string> The names.
	 */
	private static function reached( string $first, array $functions ): array {
		$reached = array();
		$queue   = isset( $functions[ $first ] ) ? array( $first ) : array();

		while ( array() !== $queue ) {
			$name      = array_shift( $queue );
			$reached[] = $name;

			foreach ( self::commands( $functions[ $name ] ) as $words ) {
				foreach ( $words as $word ) {
					if ( isset( $functions[ $word ] ) && ! in_array( $word, $reached, true ) && ! in_array( $word, $queue, true ) ) {
						$queue[] = $word;
					}
				}
			}
		}

		return $reached;
	}

	/**
	 * Returns a file's lines.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the file cannot be read.
	 *
	 * @param string $file The file.
	 * @return list<string> The lines.
	 */
	private static function read( string $file ): array {
		$lines = is_file( $file ) ? file( $file, FILE_IGNORE_NEW_LINES ) : false;

		if ( false === $lines ) {
			throw new \RuntimeException( sprintf( '%s cannot be read, so the rule of the settings file cannot be checked.', $file ) );
		}

		return $lines;
	}
}
