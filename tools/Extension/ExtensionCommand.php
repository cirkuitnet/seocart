<?php
/**
 * ExtensionCommand: the PHP half of the extension kit's shell scripts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension;

use InvalidArgumentException;
use RuntimeException;
use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Answers what SEOCart's extension scripts need from its PHP.
 *
 * The commands, which bin/dev/new-extension.sh, bin/ci/extension.sh and
 * bin/dev/provision-site.sh run:
 *
 *     php tools/extension.php new <SEOCart directory> <slug> --type=<type> --label=<label> [--namespace=<segment>] [--gateway-id=<id>] [--dir=<path>]
 *     php tools/extension.php conformance-script <type>
 *     php tools/extension.php check-version <extension root> <tag>
 *     php tools/extension.php check-extension <extension root>
 *
 * Exit codes: 0 done, 1 failed, 2 usage error.
 *
 * @since 0.2.0
 */
final class ExtensionCommand {

	/**
	 * Runs one command.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $core      SEOCart's root.
	 * @param string[] $arguments The command and its arguments, without the script name.
	 * @return int The exit code.
	 */
	public static function main( string $core, array $arguments ): int {
		$command   = array_shift( $arguments );
		$arguments = array_values( $arguments );

		try {
			switch ( $command ) {
				case 'new':
					return self::generate( $core, $arguments );
				case 'conformance-script':
					return self::conformanceScript( $arguments );
				case 'check-version':
					return self::checkVersion( $arguments );
				case 'check-extension':
					return self::checkExtension( $arguments );
			}
		} catch ( InvalidArgumentException $error ) {
			fwrite( STDERR, $error->getMessage() . "\n" );
			return 2;
		} catch ( RuntimeException $error ) {
			fwrite( STDERR, $error->getMessage() . "\n" );
			return 1;
		}

		fwrite( STDERR, "Usage: php tools/extension.php new|conformance-script|check-version|check-extension ...\n" );

		return 2;
	}

	/**
	 * Writes a new extension's repository and says what to do next.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $core      SEOCart's root.
	 * @param string[] $arguments `<SEOCart directory> <slug> --type=<type> --label=<label> [--namespace=<segment>] [--gateway-id=<id>] [--dir=<path>]`.
	 *                            The first is SEOCart's root as the caller reaches it, through any
	 *                            link, so that the extension is written beside that name.
	 * @return int The exit code.
	 *
	 * @throws InvalidArgumentException When the command line is wrong.
	 */
	private static function generate( string $core, array $arguments ): int {
		$beside  = (string) array_shift( $arguments );
		$options = array();
		$slugs   = array();

		if ( ! is_dir( $beside ) || realpath( $beside ) !== realpath( $core ) ) {
			throw new InvalidArgumentException( "new-extension: \"{$beside}\" is not SEOCart's directory {$core}." );
		}

		foreach ( $arguments as $argument ) {
			if ( 1 === preg_match( '/^--(type|label|namespace|gateway-id|dir)=(.+)$/s', $argument, $matches ) ) {
				$options[ $matches[1] ] = $matches[2];
			} elseif ( ! str_starts_with( $argument, '-' ) ) {
				$slugs[] = $argument;
			} else {
				throw new InvalidArgumentException( "new-extension: unrecognized argument \"{$argument}\"." );
			}
		}

		if ( 1 !== count( $slugs ) || ! isset( $options['type'], $options['label'] ) ) {
			throw new InvalidArgumentException( 'usage: new-extension.sh <slug> --type=<type> --label=<label> [--namespace=<segment>] [--gateway-id=<id>] [--dir=<path>]' );
		}

		$type = ExtensionType::tryFrom( $options['type'] );

		if ( null === $type ) {
			throw new InvalidArgumentException( "new-extension: \"{$options['type']}\" is not an extension type. Known: " . self::knownTypes() . '.' );
		}

		$skeleton = new Skeleton( $core, $slugs[0], $type, $options['label'], $options['namespace'] ?? null, $options['gateway-id'] ?? null );
		$target   = $options['dir'] ?? dirname( rtrim( $beside, '/' ) ) . '/' . $slugs[0];
		$files    = $skeleton->write( $target );
		$values   = $skeleton->values();

		fwrite( STDOUT, "new-extension: wrote {$target} (" . count( $files ) . " files, staged in a new git repository, nothing committed):\n" );
		fwrite( STDOUT, '  ' . implode( "\n  ", $files ) . "\n" );
		fwrite( STDOUT, "new-extension: pinned to SEOCart {$values['core_ref']} (seocart-core.env and .github/workflows).\n" );
		fwrite( STDOUT, "new-extension: {$values['slug']}.php hooks {$values['registration_action']} to register the gateway \"{$values['gateway_id']}\", written against contract {$values['contract_version']}; src/Gateway.php declares no capability yet.\n" );

		if ( $skeleton->coreHasChanges() ) {
			fwrite( STDOUT, "new-extension: note: {$core} has uncommitted changes. The files were written from them, but the pin is the commit, which does not hold them.\n" );
		}

		if ( ! self::isCoreBeside( $core, $target ) ) {
			fwrite( STDOUT, "new-extension: note: {$target}/../seocart is not this SEOCart checkout, and the extension's gates look for SEOCart there.\n" );
		}

		fwrite( STDOUT, "new-extension: next: commit the files, then run the gates: sh ../seocart/bin/ci/extension.sh all . (from {$target}).\n" );

		return 0;
	}

	/**
	 * Prints the Composer script that runs a type's conformance suite, or nothing when SEOCart has none yet.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $arguments `<type>`.
	 * @return int The exit code.
	 *
	 * @throws InvalidArgumentException When the type is unknown.
	 */
	private static function conformanceScript( array $arguments ): int {
		$type = 1 === count( $arguments ) ? ExtensionType::tryFrom( $arguments[0] ) : null;

		if ( null === $type ) {
			throw new InvalidArgumentException( 'conformance-script: name one extension type. Known: ' . self::knownTypes() . '.' );
		}

		fwrite( STDOUT, $type->conformanceScript() );

		return 0;
	}

	/**
	 * Checks that a release tag is the version the extension's main file and readme state.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $arguments `<extension root> <tag>`.
	 * @return int The exit code.
	 *
	 * @throws InvalidArgumentException When the command line or the root is wrong.
	 */
	private static function checkVersion( array $arguments ): int {
		if ( 2 !== count( $arguments ) || 1 !== preg_match( '/^v(\d+\.\d+\.\d+)$/D', $arguments[1], $matches ) ) {
			throw new InvalidArgumentException( 'check-version: expected <extension root> and a tag vX.Y.Z.' );
		}

		$root     = rtrim( $arguments[0], '/' );
		$package  = PluginPackage::at( $root );
		$version  = $matches[1];
		$found    = array(
			$package->mainFile . ' Version' => PluginPackage::header( (string) file_get_contents( $root . '/' . $package->mainFile ), 'Version' ),
			'readme.txt Stable tag'         => is_file( $root . '/readme.txt' ) ? PluginPackage::header( (string) file_get_contents( $root . '/readme.txt' ), 'Stable tag' ) : null,
		);
		$mismatch = array_filter( $found, static fn ( ?string $value ): bool => $value !== $version );

		foreach ( $mismatch as $where => $value ) {
			fwrite( STDERR, "check-version: {$where} is " . ( $value ?? 'missing' ) . ", not {$version} as the tag {$arguments[1]} says.\n" );
		}

		if ( array() !== $mismatch ) {
			return 1;
		}

		fwrite( STDOUT, "check-version: {$package->mainFile} and readme.txt state {$version}, as the tag does.\n" );

		return 0;
	}

	/**
	 * Prints the slug of the extension at a root, after checking that it is one: a plugin other
	 * than SEOCart whose header requires SEOCart.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $arguments `<extension root>`.
	 * @return int The exit code.
	 *
	 * @throws InvalidArgumentException When the command line is wrong.
	 * @throws RuntimeException         When the root holds no SEOCart extension.
	 */
	private static function checkExtension( array $arguments ): int {
		if ( 1 !== count( $arguments ) ) {
			throw new InvalidArgumentException( 'check-extension: expected <extension root>.' );
		}

		$root = rtrim( $arguments[0], '/' );

		try {
			$package = PluginPackage::at( $root );
		} catch ( InvalidArgumentException $error ) {
			throw new RuntimeException( 'check-extension: ' . $error->getMessage(), 0, $error );
		}

		$requires = (string) PluginPackage::header( (string) file_get_contents( $root . '/' . $package->mainFile ), 'Requires Plugins' );

		if ( $package->isCore || ! in_array( 'seocart', array_map( 'trim', explode( ',', $requires ) ), true ) ) {
			throw new RuntimeException( "check-extension: {$root} is not a SEOCart extension: its main file, {$package->mainFile}, must say \"Requires Plugins: seocart\"." );
		}

		fwrite( STDOUT, $package->slug . "\n" );

		return 0;
	}

	/**
	 * Lists the extension types, for a message.
	 *
	 * @since 0.2.0
	 *
	 * @return string The values, comma-separated.
	 */
	private static function knownTypes(): string {
		return implode( ', ', array_map( static fn ( ExtensionType $type ): string => $type->value, ExtensionType::cases() ) );
	}

	/**
	 * Tells whether the SEOCart checkout beside a directory is this one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $core   SEOCart's root.
	 * @param string $target The extension's root.
	 * @return bool True when `<target>/../seocart` is SEOCart's root.
	 */
	private static function isCoreBeside( string $core, string $target ): bool {
		$beside = realpath( $target . '/../seocart' );

		return false !== $beside && realpath( $core ) === $beside;
	}
}
