<?php
/**
 * LicenseCheckCommand: the command behind `composer licenses:check`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Fails when a runtime dependency's licence is not on the GPLv3-compatible allow-list.
 *
 * It judges the two committed lockfiles, not the installed packages, so it judges exactly
 * what a release would ship and never needs node_modules/. It still needs `composer install`
 * to have run: bin/check-licenses.php loads this class through Composer's autoloader.
 *
 * @since 0.1.0
 */
final class LicenseCheckCommand {

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
	 * @param string[] $args Command-line arguments, without the script name:
	 *                       `--composer-lock=<path>` and `--package-lock=<path>`, both optional.
	 * @param string   $root Absolute path of the repository root.
	 * @return int 0 when every runtime licence is allowed, 1 when one is not, 2 when the command cannot run.
	 */
	public function run( array $args, string $root ): int {
		try {
			$options  = CliOptions::parse( $args, array( 'composer-lock', 'package-lock' ) );
			$composer = RuntimePackages::fromComposerLock( $this->readLock( $options['composer-lock'] ?? $root . '/composer.lock' ) );
			$npm      = RuntimePackages::fromPackageLock( $this->readLock( $options['package-lock'] ?? $root . '/package-lock.json' ) );
		} catch ( \InvalidArgumentException $exception ) {
			( $this->output )( 'licenses:check: ' . $exception->getMessage() );
			return 2;
		}

		$violations = array_merge( $this->check( 'Composer', $composer ), $this->check( 'npm', $npm ) );

		foreach ( $violations as $violation ) {
			( $this->output )( "FAIL  [{$violation->code}] {$violation->message}" );
		}

		if ( array() !== $violations ) {
			( $this->output )( 'licenses:check: ' . count( $violations ) . ' runtime package(s) are not GPLv3-compatible according to the allow-list in tools/WpOrg/LicenseAllowList.php.' );
			return 1;
		}

		( $this->output )( 'licenses:check: OK. ' . count( $composer ) . ' Composer and ' . count( $npm ) . ' npm runtime package(s), every licence on the allow-list.' );
		return 0;
	}

	/**
	 * Checks the licence of every package of one ecosystem.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $ecosystem 'Composer' or 'npm'.
	 * @param array[] $packages  The runtime packages, as RuntimePackages lists them.
	 * @return list<Violation> One violation per package that may not ship.
	 *
	 * @phpstan-param list<array{name: string, version: string, license: string|null}> $packages
	 */
	private function check( string $ecosystem, array $packages ): array {
		$violations = array();

		foreach ( $packages as $package ) {
			$label = "{$ecosystem} package {$package['name']} {$package['version']}";

			if ( null === $package['license'] ) {
				$violations[] = new Violation( 'license-missing', "{$label} declares no licence. A package without licence information may not ship." );
				continue;
			}

			try {
				$permitted = LicenseAllowList::permits( $package['license'] );
			} catch ( \InvalidArgumentException $exception ) {
				$violations[] = new Violation( 'license-unparsable', "{$label} declares \"{$package['license']}\", which is not a valid SPDX expression: " . $exception->getMessage() );
				continue;
			}

			if ( ! $permitted ) {
				$violations[] = new Violation( 'license-not-allowed', "{$label} is licensed \"{$package['license']}\", which is not on the allow-list." );
			}
		}

		return $violations;
	}

	/**
	 * Reads and decodes a lockfile.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the file is missing, is not JSON, or lists no packages.
	 *
	 * @param string $path Path of a composer.lock or a package-lock.json.
	 * @return array<string, mixed> The decoded lockfile.
	 */
	private function readLock( string $path ): array {
		if ( ! is_file( $path ) ) {
			throw new \InvalidArgumentException( "{$path} does not exist. Both lockfiles are committed; a missing one cannot be checked." );
		}

		$lock = json_decode( (string) file_get_contents( $path ), true );

		// Both formats keep their package list under `packages`. Without it nothing would be checked.
		if ( ! is_array( $lock ) || ! is_array( $lock['packages'] ?? null ) ) {
			throw new \InvalidArgumentException( "{$path} is not a lockfile with a \"packages\" list." );
		}

		return $lock;
	}
}
