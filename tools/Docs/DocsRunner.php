<?php
/**
 * DocsRunner: writes or drift-checks every generated, committed document
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

/**
 * Runs every generator, in write mode or in check mode.
 *
 * This class owns one fact: what it means for a generated document to be in order.
 * The committed file equals what its generator produces now, and the generator left
 * out exactly the source items it declares it leaves out. Either condition failing is
 * a non-zero exit, and a drift failure prints the command that repairs it. A run with
 * no generators fails as well: a gate that checks nothing must not pass.
 *
 * @since 0.1.0
 */
final class DocsRunner {

	/**
	 * The command that rewrites every generated document. Printed when a check finds drift.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REGENERATE_COMMAND = 'composer docs:generate';

	/**
	 * The generators to run, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Generator>
	 */
	private array $generators;

	/**
	 * Absolute path of the repository root, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Receives each line of output, without a line ending.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Generator[]            $generators The generators to run, in order.
	 * @param string                 $root       Absolute path of the repository root.
	 * @param callable(string): void $output     Receives each line of output.
	 */
	public function __construct( array $generators, string $root, callable $output ) {
		$this->generators = $generators;
		$this->root       = rtrim( $root, '/' );
		$this->output     = $output;
	}

	/**
	 * Runs the command line: no argument writes, `--check` compares.
	 *
	 * An unknown argument is an error rather than being ignored: a mistyped `--check`
	 * must not turn the drift gate into a silent rewrite that exits zero.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Command-line arguments, without the script name.
	 * @return int The process exit code.
	 */
	public function main( array $args ): int {
		if ( array() !== array_diff( $args, array( '--check' ) ) ) {
			( $this->output )( 'Usage: php bin/generate-docs.php [--check]' );
			return 2;
		}

		return $this->run( in_array( '--check', $args, true ) );
	}

	/**
	 * Runs every generator.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $check True to compare the committed files and write nothing.
	 * @return int 0 when every document is in order, 1 otherwise, including when there is no generator to run.
	 */
	public function run( bool $check ): int {
		// A run without generators would check nothing and still exit zero.
		if ( array() === $this->generators ) {
			( $this->output )( 'FAIL   no generators are registered, so no document would be written or checked.' );
			return 1;
		}

		$failed  = false;
		$drifted = false;

		foreach ( $this->generators as $generator ) {
			$label   = $generator->target() . ' (' . $generator->id() . ')';
			$path    = $this->root . '/' . $generator->target();
			$current = is_file( $path ) ? (string) file_get_contents( $path ) : '';

			try {
				$result = $generator->generate( $current );
			} catch ( \RuntimeException $exception ) {
				( $this->output )( "FAIL   {$label}: " . $exception->getMessage() );
				$failed = true;
				continue;
			}

			$skipErrors = $this->skipErrors( $generator, $result );

			// A wrong skip set fails the run before anything is written: the output is not trustworthy.
			if ( array() !== $skipErrors ) {
				foreach ( $skipErrors as $error ) {
					( $this->output )( "FAIL   {$label}: {$error}" );
				}
				$failed = true;
				continue;
			}

			if ( $result->content === $current ) {
				( $this->output )( "ok     {$label}" );
				continue;
			}

			if ( $check ) {
				( $this->output )( "DRIFT  {$label}: " . $this->firstDifference( $current, $result->content ) );
				$drifted = true;
				continue;
			}

			if ( false === file_put_contents( $path, $result->content ) ) {
				( $this->output )( "FAIL   {$label}: the file could not be written." );
				$failed = true;
				continue;
			}

			( $this->output )( "wrote  {$label}" );
		}

		if ( $drifted ) {
			( $this->output )( '' );
			( $this->output )( 'A generated document no longer matches its source. Do not edit it by hand; regenerate it with:' );
			( $this->output )( '' );
			( $this->output )( '    ' . self::REGENERATE_COMMAND );
			( $this->output )( '' );
		}

		return ( $failed || $drifted ) ? 1 : 0;
	}

	/**
	 * Compares the items a generator skipped with the set it declares it skips.
	 *
	 * @since 0.1.0
	 *
	 * @param Generator        $generator The generator that ran.
	 * @param GenerationResult $result    What it produced.
	 * @return list<string> One message per discrepancy; empty when the two sets are equal.
	 */
	private function skipErrors( Generator $generator, GenerationResult $result ): array {
		$errors     = array();
		$unexpected = array_diff( $result->skipped, $generator->expectedSkips() );
		$stale      = array_diff( $generator->expectedSkips(), $result->skipped );

		if ( array() !== $unexpected ) {
			$errors[] = 'unexpected skip: ' . implode( ', ', $unexpected ) . '. A generator that skips must fail. Fix the source item, or add it to expectedSkips() with the reason.';
		}

		if ( array() !== $stale ) {
			$errors[] = 'stale expected skip: ' . implode( ', ', $stale ) . '. The item is no longer skipped; remove it from expectedSkips().';
		}

		return $errors;
	}

	/**
	 * Describes the first line on which the committed and the generated content differ.
	 *
	 * @since 0.1.0
	 *
	 * @param string $committed The content of the committed file.
	 * @param string $generated The content the generator produces now.
	 * @return string A one-line description.
	 */
	private function firstDifference( string $committed, string $generated ): string {
		$committedLines = explode( "\n", $committed );
		$generatedLines = explode( "\n", $generated );
		$lineCount      = max( count( $committedLines ), count( $generatedLines ) );

		for ( $index = 0; $index < $lineCount; $index++ ) {
			$committedLine = $committedLines[ $index ] ?? '<end of file>';
			$generatedLine = $generatedLines[ $index ] ?? '<end of file>';

			if ( $committedLine !== $generatedLine ) {
				return 'line ' . ( $index + 1 ) . " is \"{$committedLine}\" but its source generates \"{$generatedLine}\".";
			}
		}

		return 'the content differs.';
	}
}
