<?php
/**
 * CliOptions: parses the `--name=value` options of the directory check commands
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Parses command-line options strictly.
 *
 * These commands are gates. An option they do not know is an error, never ignored: a
 * mistyped `--tga=v1.2.3` must not quietly run the check without the tag.
 *
 * @since 0.1.0
 */
final class CliOptions {

	/**
	 * Parses `--name=value` arguments.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an argument is not a known `--name=value` option.
	 *
	 * @param string[] $args  Command-line arguments, without the script name.
	 * @param string[] $known The option names the command accepts, without the dashes.
	 * @return array<string, string> The given options, keyed by name.
	 */
	public static function parse( array $args, array $known ): array {
		$options = array();

		foreach ( $args as $arg ) {
			if ( 1 !== preg_match( '/^--([a-z][a-z-]*)=(.+)$/', $arg, $matches ) || ! in_array( $matches[1], $known, true ) ) {
				throw new \InvalidArgumentException( "Unknown argument \"{$arg}\". Accepted: " . implode( ', ', array_map( static fn ( string $name ): string => "--{$name}=<value>", $known ) ) . '.' );
			}

			$options[ $matches[1] ] = $matches[2];
		}

		return $options;
	}
}
