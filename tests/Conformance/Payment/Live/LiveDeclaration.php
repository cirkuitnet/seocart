<?php
/**
 * LiveDeclaration: what a payment extension states about its live workflow, and what is derived from it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * The one statement a payment extension writes for its live workflow: what differs from one provider to the next, and nothing that can be derived.
 *
 * A payment extension's live workflow runs the extension against its provider's test environment
 * with the repository's secrets, started by hand or on a schedule. SEOCart holds everything such
 * a workflow shares: bin/ci/extension-live.sh and its lib, the composite action
 * extension-live-setup, and the classes beside this one. What is the provider's own is stated
 * once, by a subclass that the extension's tests/live.php returns:
 *
 * - the workflow's file name, whose basename also names the provider's script
 *   (bin/ci/<basename>.sh) and the concurrency group;
 * - the jobs, each with the choice of the `parts` input that selects it beside `all`, and for a
 *   part whose job runs `extension-live.sh live`, its PHPUnit filter and how many tests it runs;
 * - the proofs the verdict's table lists, and the facts a job reports beyond its counts and nonce;
 * - the conformance suite's run against the provider: how many tests the extension's
 *   conformance test holds, and the tests it skips, each by the scenario it cannot produce;
 * - the shapes of the provider's credentials, which the guard adds to its own;
 * - the harvest: which objects a recorded exchange created at the provider;
 * - optionally, the one part that runs nothing, says why and stays green while a secret is absent.
 *
 * Derived, never stated: the repository and the slug, from the `name` of the extension's
 * composer.json; the release zip's slug, the same slug; the live PHPUnit group, the slug without
 * `seocart-gateway-for-` and with `-live`; the concurrency group, the workflow's basename; the
 * summary's title, the workflow's `name:` as GitHub gives it to the step. The work directory is
 * the same for every extension, as one job is one run.
 *
 * load() reads tests/live.php by path, so a declaration works where no autoloader is, as in the
 * verdict's job, which installs nothing: whatever class of its own the declaration needs, the file
 * requires by path too.
 *
 * @since 0.2.0
 */
abstract class LiveDeclaration {

	/**
	 * The extension's file that returns its declaration, from its root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FILE = 'tests/live.php';

	/**
	 * How every payment extension's slug begins.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SLUG_PREFIX = 'seocart-gateway-for-';

	/**
	 * The choice of the `parts` input that selects the conformance suite's job.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SUITE_PART = 'conformance';

	/**
	 * The optional part's output when its secret is set.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRESENT = 'present';

	/**
	 * The optional part's output when its secret is absent: the part ran nothing, for the reason it states.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ABSENT = 'absent';

	/**
	 * The shape of a job's, a part's, a fact's or an output's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NAME = '/^[a-z][a-z0-9-]*$/D';

	/**
	 * The declarations loaded, by the extension's root.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, LiveDeclaration>
	 */
	private static array $loaded = array();

	/**
	 * The extension's root; empty for a declaration load() did not make.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * The extension's repository, the `name` of its composer.json; empty for a declaration load() did not make.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $repository = '';

	/**
	 * The guard built from this declaration, once asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var MessageGuard|null
	 */
	private ?MessageGuard $guard = null;

	/**
	 * Returns the live workflow's file name in .github/workflows, such as `example-sandbox.yml`.
	 *
	 * @since 0.2.0
	 *
	 * @return string The file name.
	 */
	abstract public function workflow(): string;

	/**
	 * Returns the jobs the verdict judges, each with the choice of the `parts` input that selects it beside `all`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each choice, by job, in the order the verdict's table lists them.
	 */
	abstract public function jobs(): array;

	/**
	 * Returns the parts whose job runs `extension-live.sh live <part>`, each with its live tests: the PHPUnit filter of their classes, and how many tests that filter runs.
	 *
	 * A run of fewer tests than the part holds proved less than it claims, so the count is checked.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{filter: string, tests: int}> Each part's tests, by its choice.
	 */
	abstract public function liveParts(): array;

	/**
	 * Returns what a run proves, each with the job that proves it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each job, by what it proves, in the order of the verdict's table.
	 */
	abstract public function proofs(): array;

	/**
	 * Returns the facts a job reports beyond its counts and its nonce, which the verdict's table shows.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The facts' names, in the order the table shows them.
	 */
	abstract public function facts(): array;

	/**
	 * Returns how many tests the extension's conformance test holds.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	abstract public function suiteTests(): int;

	/**
	 * Returns the tests the conformance suite skips against the provider, each by the scenario the provider's test environment cannot produce, which its skip names.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each scenario, by test method.
	 */
	abstract public function suiteSkips(): array;

	/**
	 * Returns the shapes of the provider's credentials, each a regular expression, by what it is; the guard applies its own shapes first.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The patterns, by what each matches.
	 */
	abstract public function shapes(): array;

	/**
	 * Returns the objects recorded exchanges created at the provider, as the run list holds them, so the clean-up ends those the run left open.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<mixed>> $exchanges The exchanges, each in a tape's shape.
	 * @return array<string, string> Each store uuid, by the provider's id.
	 */
	abstract public function createdBy( array $exchanges ): array;

	/**
	 * Returns the one part that may run nothing: while the secret it names is absent, its job says why, its live tests do not run, and the run stays green.
	 *
	 * @since 0.2.0
	 *
	 * @return array{job: string, secret: string, output: string, reason: string}|null The part's job, the secret's name as the job's env gives it, the output that says whether it is set, and why the part runs nothing without it; null when every part always runs.
	 */
	public function optionalPart(): ?array {
		return null;
	}

	/**
	 * Returns an extension's declaration, read from its tests/live.php once per root.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the file is missing, returns anything but a declaration, or states what cannot be; or when composer.json names no payment extension. The message names the file.
	 *
	 * @param string $root The extension's root.
	 * @return LiveDeclaration The declaration.
	 */
	final public static function load( string $root ): self {
		$real = realpath( $root );
		$root = false === $real ? $root : $real;

		if ( isset( self::$loaded[ $root ] ) ) {
			return self::$loaded[ $root ];
		}

		$file = $root . '/' . self::FILE;

		if ( ! is_file( $file ) ) {
			throw new \UnexpectedValueException( sprintf( '%1$s is missing: a payment extension states its live workflow there, returning an instance of %2$s.', $file, self::class ) );
		}

		$declaration = ( static fn( string $path ): mixed => require $path )( $file );

		if ( ! $declaration instanceof self ) {
			throw new \UnexpectedValueException( sprintf( '%1$s returns %2$s, not an instance of %3$s.', $file, get_debug_type( $declaration ), self::class ) );
		}

		$declaration->root       = $root;
		$declaration->repository = self::repositoryOf( $root );
		$problems                = $declaration->problems();

		if ( array() !== $problems ) {
			throw new \UnexpectedValueException( sprintf( '%1$s states what cannot be: %2$s', $file, implode( ' ', $problems ) ) );
		}

		self::$loaded[ $root ] = $declaration;

		return $declaration;
	}

	/**
	 * Returns the extension's root.
	 *
	 * @since 0.2.0
	 *
	 * @return string The root.
	 */
	final public function root(): string {
		return $this->loaded( $this->root );
	}

	/**
	 * Returns the extension's repository, as its composer.json names it and as GitHub names the repository its jobs run in.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `cirkuitnet/seocart-gateway-for-example`.
	 */
	final public function repository(): string {
		return $this->loaded( $this->repository );
	}

	/**
	 * Returns the extension's slug, which its release zip is named by.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `seocart-gateway-for-example`.
	 */
	final public function slug(): string {
		return substr( $this->repository(), (int) strpos( $this->repository(), '/' ) + 1 );
	}

	/**
	 * Returns the PHPUnit group of the extension's live tests, which its other suites leave out.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `example-live`.
	 */
	final public function liveGroup(): string {
		return substr( $this->slug(), strlen( self::SLUG_PREFIX ) ) . '-live';
	}

	/**
	 * Returns the workflow's basename, which names the provider's script and the concurrency group.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `example-sandbox`.
	 */
	final public function basename(): string {
		return (string) preg_replace( '/\.ya?ml$/D', '', $this->workflow() );
	}

	/**
	 * Returns the extension's script of its provider's steps, from its root.
	 *
	 * @since 0.2.0
	 *
	 * @return string Such as `bin/ci/example-sandbox.sh`.
	 */
	final public function script(): string {
		return 'bin/ci/' . $this->basename() . '.sh';
	}

	/**
	 * Returns the job of the conformance suite: the one the `conformance` choice selects.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The job; null when the declaration has no such part.
	 */
	final public function suiteJob(): ?string {
		$job = array_search( self::SUITE_PART, $this->jobs(), true );

		return false === $job ? null : (string) $job;
	}

	/**
	 * Returns the guard of what the live tests and the workflow's steps print, built from this declaration's shapes.
	 *
	 * @since 0.2.0
	 *
	 * @return MessageGuard The guard.
	 */
	final public function guard(): MessageGuard {
		$this->guard ??= new MessageGuard( $this );

		return $this->guard;
	}

	/**
	 * Returns what the declaration states that cannot be: a name of the wrong shape, a part or proof of no job, a job nothing proves, a shape that is no pattern.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> Each, as a sentence; none when the declaration can serve.
	 */
	final public function problems(): array {
		$problems = array();
		$jobs     = $this->jobs();

		if ( '' !== $this->repository && 1 !== preg_match( '/^[a-z0-9][a-z0-9_.-]*\/' . preg_quote( self::SLUG_PREFIX, '/' ) . '[a-z0-9][a-z0-9-]*$/D', $this->repository ) ) {
			$problems[] = sprintf( 'The name of composer.json, `%1$s`, is not `<vendor>/%2$s<provider>`.', $this->repository, self::SLUG_PREFIX );
		}

		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]*\.ya?ml$/D', $this->workflow() ) ) {
			$problems[] = sprintf( 'The workflow `%s` is not a workflow\'s file name.', $this->workflow() );
		}

		if ( array() === $jobs ) {
			$problems[] = 'It states no job.';
		}

		foreach ( $jobs as $job => $choice ) {
			if ( 1 !== preg_match( self::NAME, (string) $job ) || in_array( $job, array( 'ci', 'verdict' ), true ) || 1 !== preg_match( self::NAME, $choice ) || 'all' === $choice ) {
				$problems[] = sprintf( 'The job `%1$s` chosen by `%2$s` is not a job and a choice of their own: lower-case words, not `ci`, `verdict` or `all`.', $job, $choice );
			}
		}

		if ( count( array_unique( $jobs ) ) !== count( $jobs ) ) {
			$problems[] = 'Two jobs share a choice of the parts input.';
		}

		foreach ( $this->liveParts() as $part => $tests ) {
			if ( ! in_array( $part, $jobs, true ) || '' === $tests['filter'] || $tests['tests'] < 1 ) {
				$problems[] = sprintf( 'The live part `%s` is not a choice of a job with a filter and at least one test.', $part );
			}
		}

		foreach ( $this->proofs() as $proof => $job ) {
			if ( ! isset( $jobs[ $job ] ) ) {
				$problems[] = sprintf( 'The proof `%1$s` names no job: `%2$s`.', $proof, $job );
			}
		}

		foreach ( array_diff( array_keys( $jobs ), $this->proofs() ) as $job ) {
			$problems[] = sprintf( 'The job `%s` proves nothing the verdict lists.', $job );
		}

		foreach ( $this->facts() as $fact ) {
			if ( 1 !== preg_match( self::NAME, $fact ) ) {
				$problems[] = sprintf( 'The fact `%s` is not a name of lower-case words.', $fact );
			}
		}

		if ( $this->suiteTests() < 1 ) {
			$problems[] = 'The conformance test holds no test.';
		}

		foreach ( $this->suiteSkips() as $test => $scenario ) {
			if ( 1 !== preg_match( '/^test_\w+$/D', $test ) || 1 !== preg_match( '/^[a-z][a-z_]*$/D', $scenario ) ) {
				$problems[] = sprintf( 'The skip `%1$s` for `%2$s` is not a test method and a scenario.', $test, $scenario );
			}
		}

		try {
			new MessageGuard( $this );
		} catch ( \LogicException $refused ) {
			$problems[] = $refused->getMessage();
		}

		return array_merge( $problems, $this->optionalProblems() );
	}

	/**
	 * Returns what the optional part states that cannot be.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> Each, as a sentence.
	 */
	private function optionalProblems(): array {
		$optional = $this->optionalPart();

		if ( null === $optional ) {
			return array();
		}

		$problems = array();

		if ( ! isset( $this->jobs()[ $optional['job'] ] ) ) {
			$problems[] = sprintf( 'The optional part names no job: `%s`.', $optional['job'] );
		}

		if ( 1 !== preg_match( '/^[A-Z][A-Z0-9_]*$/D', $optional['secret'] ) ) {
			$problems[] = sprintf( 'The optional part\'s secret `%s` is not a secret\'s name.', $optional['secret'] );
		}

		if ( 1 !== preg_match( self::NAME, $optional['output'] ) ) {
			$problems[] = sprintf( 'The optional part\'s output `%s` is not an output\'s name.', $optional['output'] );
		}

		if ( '' === trim( $optional['reason'] ) ) {
			$problems[] = 'The optional part gives no reason.';
		}

		return $problems;
	}

	/**
	 * Returns a value derived from the extension, failing for a declaration load() did not make.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the declaration was not loaded from an extension.
	 *
	 * @param string $value The value.
	 * @return string The value.
	 */
	private function loaded( string $value ): string {
		if ( '' === $value ) {
			throw new \LogicException( sprintf( 'A declaration that %s::load() did not read from an extension has no repository, slug or root.', self::class ) );
		}

		return $value;
	}

	/**
	 * Reads an extension's repository from its composer.json.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the file is missing or names nothing.
	 *
	 * @param string $root The extension's root.
	 * @return string The `name`.
	 */
	private static function repositoryOf( string $root ): string {
		$composer = is_file( $root . '/composer.json' ) ? json_decode( (string) file_get_contents( $root . '/composer.json' ), true ) : null;
		$name     = is_array( $composer ) ? ( $composer['name'] ?? null ) : null;

		if ( ! is_string( $name ) || '' === $name ) {
			throw new \UnexpectedValueException( sprintf( '%s names no repository: the live workflow\'s repository, slug and group are derived from its `name`.', $root . '/composer.json' ) );
		}

		return $name;
	}
}
