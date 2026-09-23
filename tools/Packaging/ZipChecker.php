<?php
/**
 * ZipChecker: fail-closed validation of a built release zip
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

use ZipArchive;

/**
 * Decides whether a release zip may be published.
 *
 * The check reads the zip and the injected repository composer.lock. It fails closed: the
 * top of the plugin folder is compared with an allow-list, so a new file at the repository
 * root fails the release until someone decides whether it ships, instead of shipping until
 * someone notices. Every locked runtime package and the generated autoloader must be in the
 * archive too.
 *
 * Source maps are rejected. `wp-scripts build` writes none unless it is asked to, so a
 * `.map` file means a development build (`wp-scripts start`) was packaged; it would also
 * spend the size budget on something no site loads. WordPress.org guideline 4 asks for
 * readable source, and readme.txt meets that by linking the public repository and the
 * build steps.
 *
 * @since 0.1.0
 */
final class ZipChecker {

	/**
	 * The project's budget for the zip: 5 MiB.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_BUDGET_BYTES = 5242880;

	/**
	 * The WordPress.org directory's limit for a submitted zip: 10 MiB.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT_BYTES = 10485760;

	/**
	 * Path segments that may not appear at any depth, as a regular expression mapped to the reason.
	 *
	 * One rule for every depth and every letter case, beneath src/ exactly as beneath
	 * vendor-scoped/. Libraries call the directory `tests` or `Tests`, the zip is also
	 * unpacked on file systems that do not tell the two apart, and an exception for
	 * first-party code would be the one place where co-located tests could ship unseen.
	 * The price is that `Tests`, `Vendor` and `node_modules` are reserved names: a
	 * first-party namespace for suppliers is called something else, and a library that
	 * keeps runtime code under such a name needs a reviewed change to this table.
	 *
	 * The rule is wider than .distignore on purpose. The anchored `/tests`, `/vendor` and
	 * `/.*` there strip the root only, so that .distignore never removes a nested
	 * directory by accident; whatever it leaves in, this refuses, and the violation names
	 * the pattern that excludes it.
	 *
	 * `docs/` is refused at the top of the plugin folder only, by the allow-list: a library
	 * beneath vendor-scoped/ may keep runtime files in a directory of that name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const FORBIDDEN_SEGMENTS = array(
		'/^\./'                                          => 'hidden files and version-control metadata never ship',
		'/^(tests|node_modules|vendor)$/i'               => 'tests and unscoped dependency folders never ship (WordPress.org plugin FAQ)',
		'/^(phpunit.*|strauss(\.phar)?|brianhenryie)$/i' => 'development tooling never ships',
		'/\.map$/i'                                      => 'source maps never ship; they mean a development build was packaged',
	);

	/**
	 * Where Strauss copies Action Scheduler, relative to the plugin folder.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ACTION_SCHEDULER_DIRECTORY = 'vendor-scoped/woocommerce/action-scheduler/';

	/**
	 * Action Scheduler files, relative to its directory, mapped to the global class each must declare.
	 *
	 * These two classes carry the version negotiation: every plugin that bundles Action
	 * Scheduler registers its copy with `ActionScheduler_Versions`, and the newest copy
	 * then defines `ActionScheduler`. A prefixed copy would negotiate with nobody.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ACTION_SCHEDULER_CLASSES = array(
		'classes/ActionScheduler_Versions.php'  => 'ActionScheduler_Versions',
		'classes/abstracts/ActionScheduler.php' => 'ActionScheduler',
	);

	/**
	 * Why a finding about Action Scheduler fails the release.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ACTION_SCHEDULER_RULE = 'Action Scheduler ships unprefixed and outside the generated autoloader, because plugins negotiate which bundled copy runs by its real class names.';

	/**
	 * Generated Composer autoloader files needed to load scoped runtime packages.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const RUNTIME_AUTOLOADER_FILES = array(
		'vendor-scoped/autoload.php',
		'vendor-scoped/composer/autoload_real.php',
	);

	/**
	 * Checks a release zip.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $zip_path           Path of the zip.
	 * @param int         $budget_bytes       The project's size budget.
	 * @param int         $limit_bytes        The directory's hard size limit.
	 * @param string|null $composer_lock_path Path of the repository's composer.lock. Defaults to
	 *                                        the lock beside this tools directory.
	 * @return list<string> One message per violation. An empty list means the zip may be published.
	 */
	public static function check( string $zip_path, int $budget_bytes = self::DEFAULT_BUDGET_BYTES, int $limit_bytes = self::DEFAULT_LIMIT_BYTES, ?string $composer_lock_path = null ): array {
		$composer_lock_path ??= dirname( __DIR__, 2 ) . '/composer.lock';

		if ( ! is_file( $zip_path ) ) {
			return array( "{$zip_path} does not exist." );
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::RDONLY );

		if ( true !== $opened ) {
			return array( "{$zip_path} is not a readable zip archive (ZipArchive error {$opened})." );
		}

		$names = array();

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$names[] = (string) $zip->getNameIndex( $index );
		}

		$violations = array_merge(
			self::sizeViolations( (int) filesize( $zip_path ), $budget_bytes, $limit_bytes ),
			self::layoutViolations( $names ),
			self::forbiddenPathViolations( $names ),
			self::symbolicLinkViolations( $zip ),
			self::runtimeDependencyViolations( $names, $composer_lock_path ),
			self::actionSchedulerViolations( $zip, $names ),
			self::versionViolations( $zip, basename( $zip_path ) )
		);

		$zip->close();

		return $violations;
	}

	/**
	 * Runs the checker for bin/check-zip.php.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $arguments          The command line, script name included.
	 * @param string   $composer_lock_path Path of the repository's composer.lock.
	 * @return int The process exit code: 0 when the zip passes, 1 when it does not, 2 for a usage error.
	 */
	public static function main( array $arguments, string $composer_lock_path ): int {
		$usage = sprintf(
			"Usage: php bin/check-zip.php <zip> [--budget-bytes=%d] [--limit-bytes=%d]\n",
			self::DEFAULT_BUDGET_BYTES,
			self::DEFAULT_LIMIT_BYTES
		);

		$options = array(
			'budget-bytes' => self::DEFAULT_BUDGET_BYTES,
			'limit-bytes'  => self::DEFAULT_LIMIT_BYTES,
		);
		$paths   = array();

		foreach ( array_slice( $arguments, 1 ) as $argument ) {
			if ( '--help' === $argument ) {
				fwrite( STDOUT, $usage );
				return 0;
			}

			if ( ! str_starts_with( $argument, '--' ) ) {
				$paths[] = $argument;
				continue;
			}

			if ( 1 !== preg_match( '/^--([a-z-]+)=([0-9]+)$/', $argument, $matches ) || ! isset( $options[ $matches[1] ] ) ) {
				fwrite( STDERR, "check-zip: unrecognized argument \"{$argument}\".\n\n{$usage}" );
				return 2;
			}

			$options[ $matches[1] ] = (int) $matches[2];
		}

		if ( 1 !== count( $paths ) ) {
			fwrite( STDERR, 'check-zip: expected exactly one zip, got ' . count( $paths ) . ".\n\n{$usage}" );
			return 2;
		}

		if ( $options['budget-bytes'] > $options['limit-bytes'] ) {
			fwrite( STDERR, "check-zip: the budget cannot be larger than the limit.\n\n{$usage}" );
			return 2;
		}

		$violations = self::check( $paths[0], $options['budget-bytes'], $options['limit-bytes'], $composer_lock_path );

		if ( array() === $violations ) {
			fwrite(
				STDOUT,
				sprintf(
					"check-zip: OK %s, %d bytes (budget %d, limit %d)\n",
					$paths[0],
					(int) filesize( $paths[0] ),
					$options['budget-bytes'],
					$options['limit-bytes']
				)
			);
			return 0;
		}

		foreach ( $violations as $violation ) {
			fwrite( STDERR, "check-zip: FAIL {$violation}\n" );
		}

		fwrite( STDERR, sprintf( "check-zip: %d violation(s) in %s. This zip must not be published.\n", count( $violations ), $paths[0] ) );

		return 1;
	}

	/**
	 * Checks the size of the zip against the budget and the limit.
	 *
	 * @since 0.1.0
	 *
	 * @param int $bytes        Size of the zip.
	 * @param int $budget_bytes The project's size budget.
	 * @param int $limit_bytes  The directory's hard size limit.
	 * @return list<string> Violations.
	 */
	private static function sizeViolations( int $bytes, int $budget_bytes, int $limit_bytes ): array {
		if ( $bytes > $limit_bytes ) {
			return array( "size: {$bytes} bytes is over the WordPress.org hard limit of {$limit_bytes} bytes. The directory will not accept this zip." );
		}

		if ( $bytes > $budget_bytes ) {
			return array( "size: {$bytes} bytes is over the project budget of {$budget_bytes} bytes (the hard limit is {$limit_bytes}). Find what grew; raising the budget is a reviewed change." );
		}

		return array();
	}

	/**
	 * Checks that everything sits in one plugin folder whose top level matches the allow-list.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $names Entry names.
	 * @return list<string> Violations.
	 */
	private static function layoutViolations( array $names ): array {
		$folder  = PluginPackage::SLUG . '/';
		$found   = array();
		$outside = array();

		foreach ( $names as $name ) {
			if ( ! str_starts_with( $name, $folder ) || 1 === preg_match( '~(?:^|/)\.\.(?:/|$)|\\\\~', $name ) ) {
				$outside[] = $name;
				continue;
			}

			$inside = substr( $name, strlen( $folder ) );

			if ( '' === $inside ) {
				continue;
			}

			$slash = strpos( $inside, '/' );

			$found[ false === $slash ? $inside : substr( $inside, 0, $slash + 1 ) ] = true;
		}

		$violations = array();

		if ( array() !== $outside ) {
			$violations[] = sprintf(
				'layout: every entry must sit inside the single top-level folder %s, with no `..` and no backslash. %d do not, starting with "%s".',
				$folder,
				count( $outside ),
				$outside[0]
			);
		}

		foreach ( array_diff( array_keys( $found ), array_keys( PluginPackage::TOP_LEVEL ) ) as $unexpected ) {
			$violations[] = "layout: unexpected top-level entry {$folder}{$unexpected}. Only these may ship: "
				. implode( ', ', array_keys( PluginPackage::TOP_LEVEL ) )
				. '. Exclude it in .distignore; if it has to ship, add it to PluginPackage::TOP_LEVEL and to the header of .distignore.';
		}

		foreach ( PluginPackage::TOP_LEVEL as $entry => $required ) {
			if ( $required && ! isset( $found[ $entry ] ) ) {
				$violations[] = "layout: required entry {$folder}{$entry} is missing.";
			}
		}

		return $violations;
	}

	/**
	 * Checks that no entry has a forbidden path segment.
	 *
	 * Each violation ends with its remedy: the .distignore line that excludes the path,
	 * anchored to the plugin root so that it strips this path and no other.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $names Entry names.
	 * @return list<string> Violations, one per offending file or directory rather than one per entry.
	 */
	private static function forbiddenPathViolations( array $names ): array {
		$offenders = array();

		foreach ( $names as $name ) {
			$segments = explode( '/', trim( $name, '/' ) );
			$deepest  = count( $segments );

			// The first segment is the plugin folder, which the layout check owns.
			for ( $depth = 1; $depth < $deepest; $depth++ ) {
				$reason = self::forbiddenSegmentReason( $segments[ $depth ] );

				if ( null === $reason ) {
					continue;
				}

				$offender = implode( '/', array_slice( $segments, 0, $depth + 1 ) );

				$offenders[ $offender ] = array(
					'reason'  => $reason,
					'pattern' => '/' . implode( '/', array_slice( $segments, 1, $depth ) ),
					'entries' => ( $offenders[ $offender ]['entries'] ?? 0 ) + 1,
				);

				break;
			}
		}

		$violations = array();

		foreach ( $offenders as $offender => $finding ) {
			$violations[] = "forbidden path: {$offender} ({$finding['entries']} entries): {$finding['reason']}. "
				. "Exclude it with the line `{$finding['pattern']}` in .distignore; if the plugin needs it at runtime, rename it, because the name is refused at every depth.";
		}

		return $violations;
	}

	/**
	 * Explains why a path segment is forbidden.
	 *
	 * @since 0.1.0
	 *
	 * @param string $segment One segment of an entry name.
	 * @return string|null The reason, or null when the segment is allowed.
	 */
	private static function forbiddenSegmentReason( string $segment ): ?string {
		foreach ( self::FORBIDDEN_SEGMENTS as $expression => $reason ) {
			if ( 1 === preg_match( $expression, $segment ) ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Checks that no entry is a symbolic link.
	 *
	 * @since 0.1.0
	 *
	 * @param ZipArchive $zip The open zip.
	 * @return list<string> Violations.
	 */
	private static function symbolicLinkViolations( ZipArchive $zip ): array {
		$violations = array();

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$system     = 0;
			$attributes = 0;

			if (
				$zip->getExternalAttributesIndex( $index, $system, $attributes )
				&& ZipArchive::OPSYS_UNIX === $system
				&& 0120000 === ( ( $attributes >> 16 ) & 0170000 )
			) {
				$violations[] = 'symbolic link: ' . $zip->getNameIndex( $index ) . ' is stored as a link. A release is made of regular files.';
			}
		}

		return $violations;
	}

	/**
	 * Checks that the generated autoloader and every locked runtime package ship.
	 *
	 * The composer.lock file itself remains a development file. The checker reads the
	 * repository copy injected by bin/check-zip.php, which also lets self-tests use a small
	 * fixture lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $names              Entry names.
	 * @param string   $composer_lock_path Path of the repository's composer.lock.
	 * @return list<string> Violations.
	 */
	private static function runtimeDependencyViolations( array $names, string $composer_lock_path ): array {
		$folder     = PluginPackage::SLUG . '/';
		$violations = array();

		foreach ( self::RUNTIME_AUTOLOADER_FILES as $file ) {
			$entry = $folder . $file;

			if ( ! in_array( $entry, $names, true ) ) {
				$violations[] = "runtime dependencies: {$entry} is missing. Run `composer install` so Strauss generates the scoped autoloader before building the zip.";
			}
		}

		if ( ! is_readable( $composer_lock_path ) ) {
			$violations[] = "runtime dependencies: {$composer_lock_path} is not a readable composer.lock, so the zip cannot be reconciled with its runtime packages.";

			return $violations;
		}

		try {
			$lock = json_decode( (string) file_get_contents( $composer_lock_path ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			$violations[] = "runtime dependencies: {$composer_lock_path} is not valid JSON, so the zip cannot be reconciled with its runtime packages: " . $error->getMessage();

			return $violations;
		}

		if ( ! is_array( $lock ) || ! isset( $lock['packages'] ) || ! is_array( $lock['packages'] ) ) {
			$violations[] = "runtime dependencies: {$composer_lock_path} has no packages array, so it is not a usable composer.lock.";

			return $violations;
		}

		foreach ( $lock['packages'] as $package ) {
			$name = is_array( $package ) ? ( $package['name'] ?? null ) : null;

			if ( ! is_string( $name ) || 1 !== preg_match( '~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name ) ) {
				$violations[] = "runtime dependencies: {$composer_lock_path} contains a runtime package without a valid vendor/name, so its directory cannot be checked.";
				continue;
			}

			$directory = $folder . 'vendor-scoped/' . $name . '/';
			$present   = false;

			foreach ( $names as $entry ) {
				if ( str_starts_with( $entry, $directory ) ) {
					$present = true;
					break;
				}
			}

			if ( ! $present ) {
				$violations[] = "runtime dependencies: package {$name} from composer.lock is missing from {$directory}. Run `composer install` before building the zip.";
			}
		}

		return $violations;
	}

	/**
	 * Checks that Action Scheduler is present, unprefixed and outside the generated autoloader.
	 *
	 * @since 0.1.0
	 *
	 * @param ZipArchive $zip   The open zip.
	 * @param string[]   $names Entry names.
	 * @return list<string> Violations.
	 */
	private static function actionSchedulerViolations( ZipArchive $zip, array $names ): array {
		$directory  = PluginPackage::SLUG . '/' . self::ACTION_SCHEDULER_DIRECTORY;
		$entry_file = $directory . 'action-scheduler.php';
		$source     = $zip->getFromName( $entry_file );

		if ( false === $source ) {
			return array( "action scheduler: {$entry_file} is missing. Run `composer install` so that Strauss copies it into vendor-scoped/. " . self::ACTION_SCHEDULER_RULE );
		}

		$violations = array();

		if ( 1 !== preg_match( "/class_exists\(\s*'ActionScheduler_Versions'/", $source ) ) {
			$violations[] = "action scheduler: {$entry_file} does not look for the class ActionScheduler_Versions under its real name. " . self::ACTION_SCHEDULER_RULE;
		}

		foreach ( self::ACTION_SCHEDULER_CLASSES as $file => $class_name ) {
			$source = $zip->getFromName( $directory . $file );

			if ( false === $source || 1 !== preg_match( '/^\s*(?:abstract\s+|final\s+)*class\s+' . $class_name . '\b/m', $source ) ) {
				$violations[] = "action scheduler: {$directory}{$file} does not declare the class {$class_name}; it has been prefixed or is missing. " . self::ACTION_SCHEDULER_RULE;
			}
		}

		foreach ( $names as $name ) {
			if ( ! str_ends_with( $name, '.php' ) ) {
				continue;
			}

			if ( str_starts_with( $name, $directory ) ) {
				preg_match_all( '/^\s*namespace\s+([^\s;{]+)/m', (string) $zip->getFromName( $name ), $matches );

				foreach ( $matches[1] as $declared ) {
					if ( 'Action_Scheduler' !== $declared && ! str_starts_with( $declared, 'Action_Scheduler\\' ) ) {
						$violations[] = "action scheduler: {$name} declares the namespace {$declared}, so it has been prefixed. " . self::ACTION_SCHEDULER_RULE;
					}
				}
			}

			if (
				1 === preg_match( '~^' . PluginPackage::SLUG . '/vendor-scoped/composer/autoload_[^/]+\.php$~', $name )
				&& str_contains( (string) $zip->getFromName( $name ), '/woocommerce/action-scheduler/' )
			) {
				$violations[] = "action scheduler: {$name} lists Action Scheduler files, so the generated autoloader could load this copy of a class after another plugin's copy won. " . self::ACTION_SCHEDULER_RULE;
			}
		}

		return $violations;
	}

	/**
	 * Checks that the version in the file name is the version in the plugin header.
	 *
	 * @since 0.1.0
	 *
	 * @param ZipArchive $zip       The open zip.
	 * @param string     $file_name File name of the zip, without a directory.
	 * @return list<string> Violations.
	 */
	private static function versionViolations( ZipArchive $zip, string $file_name ): array {
		$claimed = PluginPackage::versionFromZipFileName( $file_name );

		if ( null === $claimed ) {
			return array( "version: the file name {$file_name} is not of the form " . PluginPackage::zipFileName( '<version>' ) . ', so it states no version to compare with the plugin header.' );
		}

		$main_file = PluginPackage::SLUG . '/' . PluginPackage::MAIN_FILE;
		$source    = $zip->getFromName( $main_file );

		if ( false === $source ) {
			// The layout check reports the missing main file.
			return array();
		}

		$header = PluginPackage::header( $source, 'Version' );

		if ( null === $header ) {
			return array( "version: {$main_file} has no Version header." );
		}

		if ( $header !== $claimed ) {
			return array( "version: the file name says {$claimed} but the Version header of {$main_file} inside the zip says {$header}." );
		}

		return array();
	}
}
