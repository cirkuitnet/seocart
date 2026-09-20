<?php
/**
 * ReadmeCheckCommand: the command behind `composer wporg:check`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Validates readme.txt against the directory rules, the plugin header and the git tag.
 *
 * It reads the three files that hold the declarations: readme.txt, the plugin's main file
 * and composer.json, whose `support.source` is the public source repository the readme
 * must link.
 *
 * Every pull request runs it without a tag. The release workflow runs it with
 * `--tag=<the pushed tag>`, which is how one command proves that the plugin header's
 * Version, readme.txt's Stable tag and the git tag all agree.
 *
 * @since 0.1.0
 */
final class ReadmeCheckCommand {

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
	 * @param callable(string): void $output Receives each line of output.
	 */
	public function __construct( callable $output ) {
		$this->output = $output;
	}

	/**
	 * Runs the command.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Command-line arguments, without the script name: `--tag=vX.Y.Z`,
	 *                       `--readme=<path>`, `--plugin-file=<path>` and `--composer-json=<path>`,
	 *                       all optional.
	 * @param string   $root Absolute path of the repository root.
	 * @return int 0 when the readme is valid, 1 when a rule is broken, 2 when the command cannot run.
	 */
	public function run( array $args, string $root ): int {
		try {
			$options = CliOptions::parse( $args, array( 'tag', 'readme', 'plugin-file', 'composer-json' ) );
		} catch ( \InvalidArgumentException $exception ) {
			( $this->output )( 'wporg:check: ' . $exception->getMessage() );
			return 2;
		}

		$readmePath   = $options['readme'] ?? $root . '/readme.txt';
		$pluginPath   = $options['plugin-file'] ?? $root . '/seocart.php';
		$composerPath = $options['composer-json'] ?? $root . '/composer.json';
		$shown        = $options['readme'] ?? 'readme.txt';

		foreach ( array( $readmePath, $pluginPath, $composerPath ) as $path ) {
			if ( ! is_file( $path ) ) {
				( $this->output )( "wporg:check: {$path} does not exist." );
				return 2;
			}
		}

		// The public source repository is declared once, as composer.json's `support.source`.
		$manifest  = json_decode( (string) file_get_contents( $composerPath ), true );
		$support   = is_array( $manifest ) ? ( $manifest['support'] ?? null ) : null;
		$sourceUrl = is_array( $support ) ? ( $support['source'] ?? null ) : null;

		if ( ! is_string( $sourceUrl ) || '' === $sourceUrl ) {
			( $this->output )( "wporg:check: {$composerPath} does not declare the public source repository as \"support.source\"." );
			return 2;
		}

		$violations = ( new ReadmeValidator() )->validate(
			Readme::parse( (string) file_get_contents( $readmePath ) ),
			PluginHeaders::read( (string) file_get_contents( $pluginPath ) ),
			$sourceUrl,
			$options['tag'] ?? null
		);

		foreach ( $violations as $violation ) {
			( $this->output )( "FAIL  [{$violation->code}] {$violation->message}" );
		}

		if ( array() !== $violations ) {
			( $this->output )( 'wporg:check: ' . count( $violations ) . " violation(s) in {$shown}, checked against the plugin header." );
			return 1;
		}

		$checked = isset( $options['tag'] ) ? "plugin header, stable tag and git tag {$options['tag']} agree" : 'plugin header and stable tag agree';

		( $this->output )( "wporg:check: OK. {$shown} is valid; {$checked}." );
		return 0;
	}
}
