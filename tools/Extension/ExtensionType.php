<?php
/**
 * ExtensionType: the kinds of SEOCart extension the kit can generate and test
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension;

use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tools\Docs\GatewayMatrixPage;

/**
 * The one list of extension types, with what each type means to the kit.
 *
 * A type decides what bin/dev/new-extension.sh writes (bin/dev/extension-types/<type>/), how
 * the extension's plugin is named, which SEOCart action it registers on, which version of
 * SEOCart's contract it is written against, and which of SEOCart's conformance suites its CI
 * runs (bin/ci/extension.sh conformance). The value is what an extension's CI passes as
 * `extension-type`.
 *
 * Loading SEOCart's contract classes needs ABSPATH, which SEOCart's own files check for;
 * tools/extension.php defines a placeholder, as WordPress is not loaded.
 *
 * @since 0.2.0
 */
enum ExtensionType: string {

	case Payments = 'payments';

	/**
	 * Returns the plugin's display name, from the name of the service it integrates.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The service, for example `Stripe`.
	 * @return string For example `SEOCart Gateway for Stripe`.
	 */
	public function pluginName( string $label ): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => 'SEOCart Gateway for ' . $label,
		};
	}

	/**
	 * Returns the SEOCart action an extension of this type registers itself on.
	 *
	 * Read from the constant that declares it, so the generated main file, which names the
	 * action as a string because it loads before SEOCart, writes what SEOCart fires.
	 *
	 * @since 0.2.0
	 *
	 * @return string The action's name.
	 */
	public function registrationAction(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => GatewayRegistry::ACTION,
		};
	}

	/**
	 * Returns the version of SEOCart's contract that an extension of this type is generated against.
	 *
	 * The generated extension states it as a literal, which its own tests hold acceptable to the
	 * SEOCart they run against.
	 *
	 * @since 0.2.0
	 *
	 * @return string The version, `major.minor.patch`.
	 */
	public function contractVersion(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => PaymentGateway::CONTRACT_VERSION,
		};
	}

	/**
	 * Returns the Composer script of SEOCart that runs this type's conformance suite against an extension.
	 *
	 * Empty for a type SEOCart has no such suite for yet: the CI step then says so and passes.
	 * A script named here that SEOCart's composer.json does not define fails the step instead,
	 * so a rename can never turn into a silent skip.
	 *
	 * @since 0.2.0
	 *
	 * @return string The script's name in SEOCart's composer.json, or an empty string.
	 */
	public function conformanceScript(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => 'test:conformance:payment',
		};
	}

	/**
	 * Returns the placeholders this type's templates use beyond those every type's templates use.
	 *
	 * Each value is read from the declaration that owns it, never written into a template by
	 * hand: a payments gateway's void() states conformance rule 19 as SEOCart's suite words it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Placeholder name => value.
	 */
	public function templateValues(): array {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => array(
				'void_rule' => self::docblockText( sprintf( '%1$s Conformance rule %2$d (%3$s) holds the gateway to it.', Rule::VoidReportsTheAmount->asserts(), Rule::VoidReportsTheAmount->value, Rule::VoidReportsTheAmount->title() ) ),
			),
		};
	}

	/**
	 * Returns the documents written from the files the templates wrote, not from a template of their own.
	 *
	 * A payments extension's docs/capabilities.md is what SEOCart's page generator writes for the
	 * generated gateway (`bin/generate-docs.php --plugin`, GatewayMatrixPage::forPlugin()), run on
	 * the files in a directory of their own: the page is the gateway's declaration, never a copy
	 * typed by hand.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the generator cannot write the page.
	 *
	 * @param string                $core  SEOCart's root.
	 * @param array<string, string> $files The files the templates wrote, by path.
	 * @return array<string, string> The documents, by path.
	 */
	public function documents( string $core, array $files ): array {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => array( GatewayMatrixPage::PLUGIN_TARGET => self::generatedPage( $core, $files ) ),
		};
	}

	/**
	 * Runs the page generator on files written to a directory of their own and returns the page it wrote.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the generator fails.
	 *
	 * @param string                $core  SEOCart's root.
	 * @param array<string, string> $files The plugin's files, by path.
	 * @return string The page.
	 */
	private static function generatedPage( string $core, array $files ): string {
		$root = sys_get_temp_dir() . '/seocart-skeleton-' . bin2hex( random_bytes( 6 ) );

		try {
			foreach ( $files as $path => $content ) {
				if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
					mkdir( dirname( $root . '/' . $path ), 0700, true );
				}

				file_put_contents( $root . '/' . $path, $content );
			}

			// The page is written where the plugin keeps it, which no template has made yet.
			if ( ! is_dir( dirname( $root . '/' . GatewayMatrixPage::PLUGIN_TARGET ) ) ) {
				mkdir( dirname( $root . '/' . GatewayMatrixPage::PLUGIN_TARGET ), 0700, true );
			}

			$pipes   = array();
			$process = proc_open(
				array( PHP_BINARY, $core . '/bin/generate-docs.php', '--plugin=' . $root ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);

			if ( false === $process ) {
				throw new \RuntimeException( 'The page generator could not be started.' );
			}

			$said = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );

			if ( 0 !== proc_close( $process ) || ! is_file( $root . '/' . GatewayMatrixPage::PLUGIN_TARGET ) ) {
				throw new \RuntimeException( 'bin/generate-docs.php did not write the capabilities page of the generated gateway: ' . trim( $said ) );
			}

			return (string) file_get_contents( $root . '/' . GatewayMatrixPage::PLUGIN_TARGET );
		} finally {
			self::remove( $root );
		}
	}

	/**
	 * Removes a directory and everything in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The directory.
	 */
	private static function remove( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
			if ( $entry instanceof \SplFileInfo && $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} elseif ( $entry instanceof \SplFileInfo ) {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $directory );
	}

	/**
	 * Wraps text for a method's docblock in a template, each line after the first continued with the docblock's own prefix.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text, in one line.
	 * @return string The text, in lines of at most 95 characters.
	 */
	private static function docblockText( string $text ): string {
		return wordwrap( $text, 95, "\n\t * " );
	}
}
