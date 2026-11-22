<?php
/**
 * WorkflowFacts: what an extension's live workflow must be, read from its text
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Reads an extension's live workflow line by line, as SEOCart's check of workflows reads them, and says what it breaks.
 *
 * The workflow holds the provider's test credentials, and the repository is public, so its shape
 * is checked on every pull request: it starts only by hand or on its schedule, so it can never be
 * one of the checks a merge waits for; it reads the repository and nothing more; each job runs
 * only in the extension's repository, as a fork has no secrets; a secret reaches only a step that
 * needs it, through that step's `env:`; nothing traces the shell, prints the environment or
 * uploads a file; one run at a time uses the account, and none is cancelled midway; every job has
 * a time limit; the jobs and the choices of the `parts` input are the parts the declaration states,
 * no other; each part's job runs when its own part or `all` is chosen; and the verdict waits for
 * every part, runs whatever becomes of them, and checks SEOCart out before it judges, as its step
 * is SEOCart's. The workflow is plain YAML, so its lines are read by their indentation, each
 * relative to the line it belongs to, as YAML reads it, whatever width a level is written at;
 * nothing parses it. read() gives that reading of its jobs and steps to SettingsLast as well.
 *
 * copies() finds a class of SEOCart's live workflow that an extension's tests still declare: a
 * copy left behind, which would drift from SEOCart's.
 *
 * @since 0.2.0
 */
final class WorkflowFacts {

	/**
	 * The events that start the workflow.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const TRIGGERS = array( 'schedule', 'workflow_dispatch' );

	/**
	 * Text no line may hold: a trace of the shell, a dump of the environment, an upload.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const FORBIDDEN = array( 'upload-artifact', 'set -x', 'printenv' );

	/**
	 * The verdict's step, which runs SEOCart's script and so needs SEOCart checked out first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VERDICT_RUN = 'run: sh seocart/bin/ci/extension-live.sh verdict ext';

	/**
	 * Returns what the workflow breaks.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $lines       The workflow's lines.
	 * @return list<string> Each fact broken, with the line it is broken on where there is one; none when the workflow keeps them all.
	 * @phpstan-param list<string> $lines
	 */
	public static function violations( LiveDeclaration $declaration, array $lines ): array {
		$lines = array_map( static fn( string $line ): string => rtrim( $line, "\r\n" ), $lines );

		return array_merge(
			self::triggers( $lines ),
			self::jobs( $declaration, $lines ),
			self::parts( $declaration, $lines ),
			self::permissions( $lines ),
			self::secrets( $declaration, $lines ),
			self::forbidden( $lines ),
			self::concurrency( $declaration, $lines )
		);
	}

	/**
	 * Returns the workflow's structure, as SEOCart's checks of a live workflow read it: the keys at its top, and each job's keys and steps.
	 *
	 * Every line is read by its indentation relative to the line it belongs to, as YAML reads it, so
	 * any consistent indentation reads alike, and a list may stand at its key's own indentation. A
	 * step is read as its alias or anchor, its keys with their values on the key's own line (a plain
	 * value without its trailing comment), and the keys whose value goes on to deeper lines; a line of
	 * a step that does not line up with its keys is read as the key `?`.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @return array{top: array<string, int>, jobs: array<string, array{line: int, keys: array<string, int>, steps: list<array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int}>}>} Each top key's line, by key; each job's line, keys and steps, by job.
	 * @phpstan-param list<string> $lines
	 */
	public static function read( array $lines ): array {
		$lines = array_map( static fn( string $line ): string => rtrim( $line, "\r\n" ), $lines );
		$top   = array();
		$jobs  = array();

		foreach ( $lines as $at => $line ) {
			if ( ! self::isBlank( $line ) && 0 === self::indent( $line ) && 1 === preg_match( '/^["\']?([A-Za-z0-9_.\-]+)["\']?\s*:/', $line, $match ) ) {
				$top[ $match[1] ] ??= $at;
			}
		}

		foreach ( self::children( $lines, $top['jobs'] ?? null ) as $job => $at ) {
			$keys  = self::children( $lines, $at );
			$steps = array();

			foreach ( self::items( $lines, $keys['steps'] ?? null ) as list( $from, $to ) ) {
				$steps[] = self::step( $lines, $from, $to );
			}

			$jobs[ $job ] = array(
				'line'  => $at,
				'keys'  => $keys,
				'steps' => $steps,
			);
		}

		return array(
			'top'  => $top,
			'jobs' => $jobs,
		);
	}

	/**
	 * Reads one step: the list item from its dash to the line after its last.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int      $from  The item's first line, its dash's.
	 * @param int      $to    The line after its last.
	 * @return array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int} The step.
	 * @phpstan-param list<string> $lines
	 */
	private static function step( array $lines, int $from, int $to ): array {
		$step = array(
			'alias' => null,
			'keys'  => array(),
			'deep'  => array(),
			'from'  => $from,
			'to'    => $to,
		);

		preg_match( '/^\s*-\s*(?:([*&][A-Za-z0-9_-]+)(?:\s+|$))?(.*)$/D', $lines[ $from ], $match );

		$step['alias'] = '' === ( $match[1] ?? '' ) ? null : $match[1];
		$rest          = $match[2] ?? '';
		$column        = null;
		$key           = null;

		if ( '' !== trim( $rest ) && ! str_starts_with( ltrim( $rest ), '#' ) ) {
			$column = strlen( $lines[ $from ] ) - strlen( $rest );
			$key    = self::stepKey( $step, $rest );
		}

		for ( $next = $from + 1; $next < $to; $next++ ) {
			if ( self::isBlank( $lines[ $next ] ) ) {
				continue;
			}

			$width    = self::indent( $lines[ $next ] );
			$column ??= $width;

			if ( $width === $column ) {
				$key = self::stepKey( $step, ltrim( $lines[ $next ] ) );
			} elseif ( $width > $column && null !== $key ) {
				$step['deep'][ $key ] = true;
			} else {
				$step['keys']['?'] = trim( $lines[ $next ] );
				$key               = '?';
			}
		}

		return $step;
	}

	/**
	 * Reads one `key: value` line of a step into it, and returns the key.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $step The step, read into.
	 * @param string $text The line, without its indentation.
	 * @return string The key; `?` for a line that is no key.
	 * @phpstan-param array{alias: ?string, keys: array<string, string>, deep: array<string, bool>, from: int, to: int} $step
	 */
	private static function stepKey( array &$step, string $text ): string {
		if ( 1 !== preg_match( '/^([A-Za-z0-9_-]+):(?:\s+(.*))?$/D', $text, $match ) ) {
			$step['keys']['?'] = $text;

			return '?';
		}

		$value = $match[2] ?? '';

		// A plain value ends where a comment begins; a quoted one is kept whole, quotes and all.
		$step['keys'][ $match[1] ] = '' !== $value && ( "'" === $value[0] || '"' === $value[0] ) ? $value : rtrim( (string) preg_replace( '/(?:^|\s+)#.*$/', '', $value ) );

		return $match[1];
	}

	/**
	 * Returns each file of an extension's tests that declares a class with the short name of one of SEOCart's live workflow classes: a copy left behind.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The extension's root.
	 * @return list<string> Each copy, as a sentence; none when the extension holds none.
	 */
	public static function copies( string $root ): array {
		$files  = glob( __DIR__ . '/[A-Z]*.php' );
		$ours   = array_map( static fn( string $file ): string => basename( $file, '.php' ), false === $files ? array() : $files );
		$copies = array();

		if ( ! is_dir( $root . '/tests' ) ) {
			return array();
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ),
				static fn( \SplFileInfo $file ): bool => ! $file->isDir() || ! in_array( $file->getFilename(), array( 'node_modules', 'vendor' ), true )
			)
		);

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			preg_match_all( '/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)\b/m', (string) file_get_contents( $file->getPathname() ), $classes );

			foreach ( array_intersect( $classes[1], $ours ) as $class ) {
				$copies[] = sprintf( '%1$s declares the class %2$s, which SEOCart holds in tests/Conformance/Payment/Live: a copy left behind drifts from it.', substr( $file->getPathname(), strlen( $root ) + 1 ), $class );
			}
		}

		sort( $copies );

		return $copies;
	}

	/**
	 * Checks that the workflow starts on its schedule and by hand, and on nothing else.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function triggers( array $lines ): array {
		$events = array_keys( self::children( $lines, self::topLevel( $lines, 'on' ) ) );

		sort( $events );

		return self::TRIGGERS === $events ? array() : array( sprintf( 'The workflow starts on %1$s, not on exactly %2$s.', array() === $events ? 'nothing it lists as a block' : implode( ', ', $events ), implode( ', ', self::TRIGGERS ) ) );
	}

	/**
	 * Checks each job: not named `ci`, guarded by the repository, with a time limit.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $lines       The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function jobs( LiveDeclaration $declaration, array $lines ): array {
		$problems = array();
		$jobs     = self::children( $lines, self::topLevel( $lines, 'jobs' ) );

		if ( array() === $jobs ) {
			return array( 'The workflow has no jobs.' );
		}

		foreach ( $jobs as $job => $at ) {
			$keys = self::children( $lines, $at );

			if ( 'ci' === $job ) {
				$problems[] = sprintf( 'Line %d: a job is named ci, the name the required checks begin with.', $at + 1 );
			}

			if ( ! isset( $keys['if'] ) || ! str_contains( $lines[ $keys['if'] ], sprintf( "github.repository == '%s'", $declaration->repository() ) ) ) {
				$problems[] = sprintf( 'Line %1$d: the job %2$s does not run only in %3$s.', $at + 1, $job, $declaration->repository() );
			}

			if ( ! isset( $keys['timeout-minutes'] ) ) {
				$problems[] = sprintf( 'Line %1$d: the job %2$s has no timeout-minutes.', $at + 1, $job );
			}
		}

		return $problems;
	}

	/**
	 * Checks that the jobs are the parts the declaration states and the verdict, that each part is chosen by its own condition, that the verdict waits for them all and checks SEOCart out, and that the `parts` input offers `all` and each of them.
	 *
	 * A job or a choice the declaration does not state is a part the verdict would not judge; a
	 * part whose condition names another part, or not `all`, is a part the weekly run skips; a part
	 * the verdict does not wait for is judged before it ends.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $lines       The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function parts( LiveDeclaration $declaration, array $lines ): array {
		$problems = array();
		$jobs     = self::children( $lines, self::topLevel( $lines, 'jobs' ) );
		$names    = array_keys( $jobs );
		$expected = array_merge( array_keys( $declaration->jobs() ), array( 'verdict' ) );
		$choices  = self::choices( $lines );
		$offered  = array_merge( array( 'all' ), array_values( $declaration->jobs() ) );

		sort( $names );
		sort( $expected );
		sort( $choices );
		sort( $offered );

		if ( $expected !== $names ) {
			$problems[] = sprintf( 'The jobs are %1$s, not exactly %2$s.', array() === $names ? 'none' : implode( ', ', $names ), implode( ', ', $expected ) );
		}

		if ( $offered !== $choices ) {
			$problems[] = sprintf( 'The parts input offers %1$s, not exactly %2$s.', array() === $choices ? 'nothing it lists' : implode( ', ', $choices ), implode( ', ', $offered ) );
		}

		return array_merge( $problems, self::conditions( $declaration, $lines, $jobs ), self::verdict( $declaration, $lines, $jobs ) );
	}

	/**
	 * Checks that each part's job runs when its own part, or `all`, is chosen, by a condition compared whole, so no term can stop it from running.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration    $declaration The extension's declaration.
	 * @param string[]           $lines       The lines.
	 * @param array<string, int> $jobs        Each job's line, by job.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function conditions( LiveDeclaration $declaration, array $lines, array $jobs ): array {
		$problems = array();

		foreach ( $declaration->jobs() as $job => $part ) {
			if ( ! isset( $jobs[ $job ] ) ) {
				continue;
			}

			$keys   = self::children( $lines, $jobs[ $job ] );
			$chosen = sprintf( 'fromJSON(\'["all", "%s"]\')', $part );

			$condition = sprintf( "if: github.repository == '%s' && contains(%s, inputs.parts || 'all')", $declaration->repository(), $chosen );

			if ( ! isset( $keys['if'] ) || ! self::isExactly( $lines, $keys['if'], $condition ) ) {
				$problems[] = sprintf( 'Line %1$d: the job %2$s has no condition %3$s, written exactly `%4$s` on a line of its own.', $jobs[ $job ] + 1, $job, $chosen, $condition );
			}
		}

		return $problems;
	}

	/**
	 * Checks that the verdict waits for exactly the parts, runs whatever becomes of them, by a condition compared whole, and checks SEOCart out before its step.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration    $declaration The extension's declaration.
	 * @param string[]           $lines       The lines.
	 * @param array<string, int> $jobs        Each job's line, by job.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function verdict( LiveDeclaration $declaration, array $lines, array $jobs ): array {
		if ( ! isset( $jobs['verdict'] ) ) {
			return array();
		}

		$problems = array();
		$keys     = self::children( $lines, $jobs['verdict'] );
		$needs    = isset( $keys['needs'] ) && 1 === preg_match( '/^\s*needs:\s*\[(.*)\]\s*$/D', $lines[ $keys['needs'] ], $match )
			? array_map( 'trim', explode( ',', $match[1] ) )
			: array();
		$parts    = array_keys( $declaration->jobs() );

		sort( $needs );
		sort( $parts );

		if ( $parts !== $needs ) {
			$problems[] = sprintf( 'Line %1$d: the verdict needs %2$s, not exactly %3$s.', $jobs['verdict'] + 1, array() === $needs ? 'nothing it lists' : implode( ', ', $needs ), implode( ', ', $parts ) );
		}

		$condition = sprintf( "if: always() && github.repository == '%s'", $declaration->repository() );

		if ( ! isset( $keys['if'] ) || ! self::isExactly( $lines, $keys['if'], $condition ) ) {
			$problems[] = sprintf( 'Line %1$d: the verdict does not run after a part fails: its if must hold always(), written exactly `%2$s` on a line of its own.', $jobs['verdict'] + 1, $condition );
		}

		if ( ! self::checksOutCoreFirst( $lines, $keys['steps'] ?? null ) ) {
			$problems[] = sprintf( 'Line %1$d: the verdict does not check SEOCart (cirkuitnet/seocart) out before its step `%2$s`.', $jobs['verdict'] + 1, self::VERDICT_RUN );
		}

		return $problems;
	}

	/**
	 * Tells whether, among a job's steps, one that checks SEOCart out comes before the one that runs the verdict; an alias is read as the step its anchor marks.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int|null $steps The line of the job's `steps:`; null for none.
	 * @return bool True when SEOCart is checked out before the verdict's step.
	 * @phpstan-param list<string> $lines
	 */
	private static function checksOutCoreFirst( array $lines, ?int $steps ): bool {
		$core = false;

		foreach ( self::items( $lines, $steps ) as list( $from, $to ) ) {
			if ( 1 === preg_match( '/^\s*-\s+\*([A-Za-z0-9_-]+)\s*$/D', $lines[ $from ], $alias ) ) {
				list( $from, $to ) = self::anchored( $lines, $alias[1] ) ?? array( $from, $to );
			}

			$step = array_map( 'trim', array_slice( $lines, $from, $to - $from ) );
			$step = array_map( static fn( string $line ): string => (string) preg_replace( '/^-\s+(?:&[A-Za-z0-9_-]+\s*)?/', '', $line ), $step );

			if ( in_array( self::VERDICT_RUN, $step, true ) ) {
				return $core;
			}

			$core = $core || ( array() !== preg_grep( '/^uses: actions\/checkout@/', $step ) && in_array( 'repository: cirkuitnet/seocart', $step, true ) );
		}

		return false;
	}

	/**
	 * Returns the items of a list under a key, each as the line it starts on and the line after its last.
	 *
	 * The items stand at the first indentation after the key, deeper than the key or, as YAML allows,
	 * at the key's own, their dashes counting as indentation; a line at that indentation that is no
	 * item, or a line less indented, ends the list.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int|null $at    The key's line; null for none, which has no items.
	 * @return list<array{int, int}> The items.
	 * @phpstan-param list<string> $lines
	 */
	private static function items( array $lines, ?int $at ): array {
		if ( null === $at ) {
			return array();
		}

		$depth = self::indent( $lines[ $at ] );
		$total = count( $lines );
		$items = array();
		$first = null;
		$end   = $total;

		for ( $next = $at + 1; $next < $total; $next++ ) {
			if ( self::isBlank( $lines[ $next ] ) ) {
				continue;
			}

			$width = self::indent( $lines[ $next ] );
			$item  = 1 === preg_match( '/^\s*-(?:\s|$)/', $lines[ $next ] );

			$first ??= $width > $depth || ( $width === $depth && $item ) ? $width : -1;

			if ( -1 === $first || $width < $first || ( $width === $first && ! $item ) ) {
				$end = $next;
				break;
			}

			if ( $width === $first ) {
				$items[] = $next;
			}
		}

		$ranges = array();

		foreach ( $items as $index => $from ) {
			$ranges[] = array( $from, $items[ $index + 1 ] ?? $end );
		}

		return $ranges;
	}

	/**
	 * Finds the list item an anchor marks: the line `- &<name>` and the lines deeper than its dash after it.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param string   $name  The anchor's name.
	 * @return array{int, int}|null The item's first line and the line after its last; null when no item has the anchor.
	 * @phpstan-param list<string> $lines
	 */
	private static function anchored( array $lines, string $name ): ?array {
		foreach ( $lines as $at => $line ) {
			if ( 1 !== preg_match( '/^(\s*)-\s+&' . preg_quote( $name, '/' ) . '\s*$/D', $line, $match ) ) {
				continue;
			}

			$total = count( $lines );

			for ( $next = $at + 1; $next < $total; $next++ ) {
				if ( ! self::isBlank( $lines[ $next ] ) && self::indent( $lines[ $next ] ) <= strlen( $match[1] ) ) {
					return array( $at, $next );
				}
			}

			return array( $at, $total );
		}

		return null;
	}

	/**
	 * Returns the choices of the `parts` input: the items listed under `on.workflow_dispatch.inputs.parts.options`.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> The choices; none when the input lists none.
	 * @phpstan-param list<string> $lines
	 */
	private static function choices( array $lines ): array {
		$at = self::topLevel( $lines, 'on' );

		foreach ( array( 'workflow_dispatch', 'inputs', 'parts', 'options' ) as $key ) {
			$at = self::children( $lines, $at )[ $key ] ?? null;
		}

		if ( null === $at ) {
			return array();
		}

		$choices = array();
		$depth   = self::indent( $lines[ $at ] );
		$total   = count( $lines );

		for ( $next = $at + 1; $next < $total; $next++ ) {
			if ( self::isBlank( $lines[ $next ] ) ) {
				continue;
			}

			if ( self::indent( $lines[ $next ] ) <= $depth ) {
				break;
			}

			// A line that is not one choice is kept as it is, so it reads as a choice the verdict does not judge.
			$choices[] = 1 === preg_match( '/^\s*-\s+["\']?([^"\'\s]+)["\']?(?:\s+#.*)?\s*$/', $lines[ $next ], $match ) ? $match[1] : trim( $lines[ $next ] );
		}

		return $choices;
	}

	/**
	 * Checks that every permission the workflow asks for is to read the repository's contents, and that it asks for that at the top.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function permissions( array $lines ): array {
		$problems = array();

		foreach ( $lines as $at => $line ) {
			if ( 1 !== preg_match( '/^(\s*)permissions:\s*(.*)$/', $line, $match ) || self::isComment( $line ) ) {
				continue;
			}

			$granted = self::children( $lines, $at );

			if ( '' !== $match[2] || array( 'contents' ) !== array_keys( $granted ) || 'contents: read' !== trim( $lines[ $granted['contents'] ] ) ) {
				$problems[] = sprintf( 'Line %d: the permissions are not exactly `contents: read`.', $at + 1 );
			}
		}

		if ( null === self::topLevel( $lines, 'permissions' ) ) {
			$problems[] = 'The workflow states no permissions at the top.';
		}

		return $problems;
	}

	/**
	 * Checks that a secret is only ever the value of an entry of the `env:` of a step that needs one, written `NAME: ${{ secrets.NAME }}`.
	 *
	 * Every expression that names the `secrets` context counts, however it reads it: `secrets.NAME`,
	 * `secrets['NAME']`, the whole context in `toJSON(secrets)`, in any letter case, and an
	 * expression spread over lines. A line that looks like a comment counts too: inside a `run:`
	 * script it is the shell's comment, and GitHub expands the expression in it all the same. An
	 * expression ends, as GitHub reads it, at the first `}}` outside a quoted string, so a `}}`
	 * inside a string cannot end it early and leave the rest unread.
	 *
	 * The steps that need a secret are those that run one of the extension's script's steps (its
	 * secrets' check, its settings step) and SEOCart's `optional` step, which says whether the
	 * optional part's secret is set: never a step that uses an action, and never a step that runs
	 * anything else, such as one that installs or builds, nor a job or the workflow as a whole.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $lines       The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function secrets( LiveDeclaration $declaration, array $lines ): array {
		$problems = array();
		$text     = implode( "\n", $lines );
		$steps    = array_merge( ...array_values( array_map( static fn( array $job ): array => $job['steps'], self::read( $lines )['jobs'] ) ) );
		$needs    = '/^sh (?:ext\/' . preg_quote( $declaration->script(), '/' ) . ' [a-z][a-z0-9-]*|seocart\/bin\/ci\/extension-live\.sh optional ext [a-z][a-z0-9-]*)$/D';

		// An expression runs to the first two closing braces outside a single-quoted string. A
		// quote doubled inside a string, its escape, reads as two strings back to back.
		if ( false === preg_match_all( '/\$\{\{(?:\'[^\']*+\'|[^\'}]++|\}(?!\}))*+\}\}/', $text, $expressions, PREG_OFFSET_CAPTURE ) ) {
			return array( 'The expressions of the workflow could not be read, so no secret in them is checked.' );
		}

		foreach ( $expressions[0] as list( $expression, $offset ) ) {
			if ( 1 !== preg_match( '/\bsecrets\b/i', $expression ) ) {
				continue;
			}

			$at   = substr_count( $text, "\n", 0, $offset );
			$env  = self::parent( $lines, $at );
			$step = null === $env ? null : self::parent( $lines, $env );

			if (
				1 !== preg_match( '/^\s+[A-Z][A-Z0-9_]*: \$\{\{ secrets\.[A-Z][A-Z0-9_]* \}\}$/D', $lines[ $at ] )
				|| null === $env || 'env:' !== trim( $lines[ $env ] )
				|| null === $step || ! str_starts_with( ltrim( $lines[ $step ] ), '- ' )
			) {
				$problems[ $at ] = sprintf( 'Line %d: a secret is used other than as the value of an entry of a step\'s env.', $at + 1 );
				continue;
			}

			$holder = array_values( array_filter( $steps, static fn( array $item ): bool => $item['from'] <= $at && $at < $item['to'] ) )[0] ?? null;

			if ( null === $holder || isset( $holder['keys']['uses'] ) || isset( $holder['deep']['run'] ) || 1 !== preg_match( $needs, $holder['keys']['run'] ?? '' ) ) {
				$problems[ $at ] = sprintf( 'Line %d: a secret is given to a step that does not need it: only a step that runs the extension\'s script, or extension-live.sh optional, may hold one, never an action.', $at + 1 );
			}
		}

		return array_values( $problems );
	}

	/**
	 * Checks that no line traces the shell, prints the environment or uploads a file.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function forbidden( array $lines ): array {
		$problems = array();

		foreach ( $lines as $at => $line ) {
			foreach ( self::FORBIDDEN as $text ) {
				if ( str_contains( $line, $text ) ) {
					$problems[] = sprintf( 'Line %1$d holds `%2$s`.', $at + 1, $text );
				}
			}
		}

		return $problems;
	}

	/**
	 * Checks that every run shares one concurrency group, the workflow's basename, and none is cancelled by the next.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string[]        $lines       The lines.
	 * @return list<string> What is wrong.
	 * @phpstan-param list<string> $lines
	 */
	private static function concurrency( LiveDeclaration $declaration, array $lines ): array {
		$keys  = self::children( $lines, self::topLevel( $lines, 'concurrency' ) );
		$value = static fn( string $key ): string => isset( $keys[ $key ] ) ? trim( (string) preg_replace( '/^[^:]*:/', '', $lines[ $keys[ $key ] ] ) ) : '';

		return $declaration->basename() === $value( 'group' ) && 'false' === $value( 'cancel-in-progress' )
			? array()
			: array( sprintf( 'The workflow\'s concurrency is not the group %s with cancel-in-progress: false.', $declaration->basename() ) );
	}

	/**
	 * Tells whether a line is exactly a condition: the line, its spaces apart, and no continuation after it.
	 *
	 * A line after it that is deeper than it would continue it (`&& false`), so it is refused.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines     The lines.
	 * @param int      $at        The condition's line.
	 * @param string   $condition The condition, as `if: <expression>`.
	 * @return bool True when the line is the condition alone.
	 * @phpstan-param list<string> $lines
	 */
	private static function isExactly( array $lines, int $at, string $condition ): bool {
		return trim( $lines[ $at ] ) === $condition && self::indent( $lines[ $at + 1 ] ?? '' ) <= self::indent( $lines[ $at ] );
	}

	/**
	 * Finds a key at the top of the workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param string   $key   The key.
	 * @return int|null The key's line; null when there is none.
	 * @phpstan-param list<string> $lines
	 */
	private static function topLevel( array $lines, string $key ): ?int {
		foreach ( $lines as $at => $line ) {
			if ( 1 === preg_match( '/^["\']?' . preg_quote( $key, '/' ) . '["\']?:/', $line ) ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Returns the keys directly under a line: those of the first indentation deeper than it, up to the next line no deeper than it.
	 *
	 * A list item's key counts as its own (`- name:` is the key `name`).
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int|null $at    The line; null for none, which has no keys.
	 * @return array<string, int> Each key's line, by key.
	 * @phpstan-param list<string> $lines
	 */
	private static function children( array $lines, ?int $at ): array {
		if ( null === $at ) {
			return array();
		}

		$depth = self::indent( $lines[ $at ] );
		$first = null;
		$keys  = array();

		$total = count( $lines );

		for ( $next = $at + 1; $next < $total; $next++ ) {
			if ( self::isBlank( $lines[ $next ] ) ) {
				continue;
			}

			$width = self::indent( $lines[ $next ] );

			if ( $width <= $depth ) {
				break;
			}

			$first ??= $width;

			if ( $width === $first && 1 === preg_match( '/^\s*(?:-\s+)?["\']?([A-Za-z0-9_.\-]+)["\']?:/', $lines[ $next ], $match ) ) {
				$keys[ $match[1] ] ??= $next;
			}
		}

		return $keys;
	}

	/**
	 * Returns the line a line belongs to: the nearest before it that is less indented.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The lines.
	 * @param int      $at    The line.
	 * @return int|null The parent's line; null at the top.
	 * @phpstan-param list<string> $lines
	 */
	private static function parent( array $lines, int $at ): ?int {
		$width = self::indent( $lines[ $at ] );

		for ( $before = $at - 1; $before >= 0; $before-- ) {
			if ( ! self::isBlank( $lines[ $before ] ) && self::indent( $lines[ $before ] ) < $width ) {
				return $before;
			}
		}

		return null;
	}

	/**
	 * Returns a line's indentation: its leading spaces, so a list item's keys after its dash sit deeper than the dash.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return int The width.
	 */
	private static function indent( string $line ): int {
		return strlen( $line ) - strlen( ltrim( $line, ' ' ) );
	}

	/**
	 * Tells whether a line says nothing: empty, or a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return bool True for a blank line or a comment.
	 */
	private static function isBlank( string $line ): bool {
		return '' === trim( $line ) || self::isComment( $line );
	}

	/**
	 * Tells whether a line is a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The line.
	 * @return bool True when its first character other than a space is `#`.
	 */
	private static function isComment( string $line ): bool {
		return str_starts_with( ltrim( $line ), '#' );
	}
}
