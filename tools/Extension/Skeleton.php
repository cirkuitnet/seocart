<?php
/**
 * Skeleton: the files of a new SEOCart extension's repository
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tools\Packaging\PluginPackage;
use SplFileInfo;

/**
 * Renders the templates under bin/dev/ into a new extension's repository.
 *
 * Every file comes from a template: bin/dev/extension-template/ for every extension, then
 * bin/dev/extension-types/<type>/ for its type. A template's name ends in `.tmpl`, which is
 * dropped; `plugin.php.tmpl` becomes the main file, `<slug>.php`. `{{name}}` placeholders are
 * replaced with the values below, each derived here and nowhere else. LICENSE is copied from
 * SEOCart as it is.
 *
 * What the extension takes from SEOCart is read from SEOCart at the commit it is generated
 * from: the commit itself (the extension's pin), the WordPress and PHP floors of SEOCart's
 * main file, and the WordPress version its readme was tested up to.
 *
 * @since 0.2.0
 */
final class Skeleton {

	/**
	 * What a slug looks like: `seocart-` and lower-case words joined by hyphens.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SLUG_PATTERN = '/^seocart-[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	/**
	 * What a label looks like: the name of a service, which every generated file can hold
	 * without escaping (no quote, ampersand, angle bracket or backslash).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .-]*$/D';

	/**
	 * What a namespace segment looks like, as `--namespace` takes it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9]*$/D';

	/**
	 * The templates every extension starts from, relative to SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const COMMON_TEMPLATES = 'bin/dev/extension-template';

	/**
	 * The directory of each type's templates, relative to SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TYPE_TEMPLATES = 'bin/dev/extension-types';

	/**
	 * A type's template that is not a file of its own but the main file's registration.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REGISTRATION_TEMPLATE = 'registration.tmpl';

	/**
	 * SEOCart's files an extension gets as they are.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const COPIED_FROM_CORE = array( 'LICENSE' );

	/**
	 * Files the generator marks executable.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const EXECUTABLE = array( 'bin/dev/bump-core.sh' );

	/**
	 * SEOCart's root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $core;

	/**
	 * The extension's type.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionType
	 */
	private ExtensionType $type;

	/**
	 * Placeholder name => value.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $values;

	/**
	 * Derives every value the templates use.
	 *
	 * @since 0.2.0
	 *
	 * SEOCart's commit and headers are read here too; when they cannot be, the RuntimeException
	 * of the method that reads them says why.
	 *
	 * @param string        $core    SEOCart's root, a git checkout.
	 * @param string        $slug    The extension's slug, for example `seocart-gateway-for-stripe`.
	 * @param ExtensionType $type    The extension's type.
	 * @param string        $label   The service it integrates, for example `Stripe`.
	 * @param string|null   $segment Optional. The namespace segment after `SEOCart\`. Default: the
	 *                               slug after `seocart-`, in StudlyCase.
	 * @param string|null   $gatewayId Optional. The id the gateway registers with, as
	 *                               GatewayDescriptor::ID_PATTERN has it. Default: the label, as
	 *                               gatewayIdOf() makes it.
	 *
	 * @throws InvalidArgumentException When a value is not of its form.
	 */
	public function __construct( string $core, string $slug, ExtensionType $type, string $label, ?string $segment = null, ?string $gatewayId = null ) {
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			throw new InvalidArgumentException( "\"{$slug}\" is not an extension slug: `seocart-` followed by lower-case words joined by hyphens, for example seocart-gateway-for-stripe." );
		}

		if ( 1 !== preg_match( self::LABEL_PATTERN, $label ) ) {
			throw new InvalidArgumentException( "\"{$label}\" is not a label: letters, digits, spaces, dots and hyphens, starting with a letter or digit." );
		}

		$name = $type->pluginName( $label );

		if ( self::slugOf( $name ) !== $slug ) {
			throw new InvalidArgumentException(
				"\"{$slug}\" is not the slug of the plugin's name, \"{$name}\": WordPress.org makes the slug from the name when the plugin is submitted, which gives \"" . self::slugOf( $name ) . '". Use that slug, or the label whose name gives yours.'
			);
		}

		$segment ??= str_replace( ' ', '', ucwords( str_replace( '-', ' ', substr( $slug, strlen( 'seocart-' ) ) ) ) );

		if ( 1 !== preg_match( self::NAMESPACE_PATTERN, $segment ) ) {
			throw new InvalidArgumentException( "\"{$segment}\" is not a namespace segment: a capital letter, then letters and digits, for example AuthorizeNet." );
		}

		$gateway_id = $gatewayId ?? self::gatewayIdOf( $label );

		if ( strlen( $gateway_id ) > GatewayDescriptor::ID_MAX_LENGTH || 1 !== preg_match( GatewayDescriptor::ID_PATTERN, $gateway_id ) ) {
			throw new InvalidArgumentException(
				( null === $gatewayId ? "The label \"{$label}\" gives the gateway id \"{$gateway_id}\", which is not one" : "\"{$gateway_id}\" is not a gateway id" )
				. ': lower-case letters and digits in words joined by underscores, starting with a letter, at most ' . GatewayDescriptor::ID_MAX_LENGTH . ' characters, for example authorize_net. Name it with --gateway-id=<id>.'
			);
		}

		if ( StubGateway::ID === $gateway_id ) {
			throw new InvalidArgumentException(
				( null === $gatewayId ? "The label \"{$label}\" gives the gateway id \"{$gateway_id}\"" : "The gateway id \"{$gateway_id}\"" )
				. ', which is the stand-in gateway\'s id: SEOCart registers that gateway itself, and a second one of the id would be refused. Name another with --gateway-id=<id>.'
			);
		}

		$this->core = rtrim( $core, '/' );
		$this->type = $type;

		$core_main   = (string) file_get_contents( $this->core . '/' . PluginPackage::MAIN_FILE );
		$core_readme = (string) file_get_contents( $this->core . '/readme.txt' );

		$this->values = array(
			'slug'                => $slug,
			'name'                => $name,
			'label'               => $label,
			'type'                => $type->value,
			'namespace'           => 'SEOCart\\' . $segment,
			'prefix'              => str_replace( '-', '_', $slug ),
			'gateway_id'          => $gateway_id,
			'core_ref'            => self::coreCommit( $this->core ),
			'requires_wp'         => self::required( PluginPackage::header( $core_main, 'Requires at least' ), 'Requires at least', PluginPackage::MAIN_FILE ),
			'requires_php'        => self::required( PluginPackage::header( $core_main, 'Requires PHP' ), 'Requires PHP', PluginPackage::MAIN_FILE ),
			'tested_wp'           => self::required( PluginPackage::header( $core_readme, 'Tested up to' ), 'Tested up to', 'readme.txt' ),
			'registration_action' => $type->registrationAction(),
			'contract_version'    => $type->contractVersion(),
		);
	}

	/**
	 * Returns the slug WordPress makes of a plugin name.
	 *
	 * WordPress.org takes a plugin's slug from its name when the plugin is submitted, with
	 * sanitize_title(), so a generated plugin's slug must be exactly that or the directory
	 * would list it under another one. This is sanitize_title() for the characters a
	 * generated name can hold (LABEL_PATTERN): lower case, a dot becomes a hyphen, a run of
	 * spaces becomes one hyphen, hyphens are collapsed and trimmed.
	 * tests/Integration/Extension/ExtensionSlugTest.php holds it equal to WordPress's own.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name A plugin name, for example `SEOCart Gateway for Authorize.Net`.
	 * @return string For example `seocart-gateway-for-authorize-net`.
	 */
	public static function slugOf( string $name ): string {
		$slug = str_replace( '.', '-', strtolower( $name ) );
		$slug = (string) preg_replace( '/[^a-z0-9 _-]/', '', $slug );
		$slug = (string) preg_replace( '/\s+/', '-', $slug );
		$slug = (string) preg_replace( '/-+/', '-', $slug );

		return trim( $slug, '-' );
	}

	/**
	 * Returns the id a gateway gets from the name of the service it integrates.
	 *
	 * The label in lower case, every run of characters that are not letters or digits made one
	 * underscore, and none at either end: `Stripe` gives `stripe` and `Authorize.Net` gives
	 * `authorize_net`. The result is not checked: a label that starts with a digit gives an id the
	 * contract refuses, and the constructor says so.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The service, for example `Authorize.Net`.
	 * @return string The id, for example `authorize_net`.
	 */
	public static function gatewayIdOf( string $label ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $label ) ), '_' );
	}

	/**
	 * Returns the values the placeholders are replaced with.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Placeholder name => value.
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Tells whether SEOCart's checkout has changes its commit does not hold.
	 *
	 * The templates are read from the working tree, but the pin is the commit, so a skeleton
	 * generated from a changed checkout may not match what the pinned commit would write.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when a tracked file differs from the commit.
	 *
	 * @throws RuntimeException When git cannot say.
	 */
	public function coreHasChanges(): bool {
		return '' !== trim( self::git( $this->core, 'status', '--porcelain', '--untracked-files=no' ) );
	}

	/**
	 * Renders every file of the skeleton.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Path relative to the extension's root => contents, sorted by path.
	 *
	 * @throws RuntimeException When a template cannot be read or keeps a placeholder nothing fills.
	 */
	public function files(): array {
		$type_directory = $this->core . '/' . self::TYPE_TEMPLATES . '/' . $this->type->value;
		$values         = $this->values;

		$values['registration'] = rtrim( $this->render( self::REGISTRATION_TEMPLATE, (string) file_get_contents( $type_directory . '/' . self::REGISTRATION_TEMPLATE ), $values ) );

		$files = array();

		foreach ( array( $this->core . '/' . self::COMMON_TEMPLATES, $type_directory ) as $directory ) {
			foreach ( self::templatesIn( $directory ) as $relative ) {
				if ( self::REGISTRATION_TEMPLATE === $relative ) {
					continue;
				}

				$target = substr( $relative, 0, -strlen( '.tmpl' ) );
				$target = 'plugin.php' === $target ? $this->values['slug'] . '.php' : $target;

				$files[ $target ] = $this->render( $relative, (string) file_get_contents( $directory . '/' . $relative ), $values );
			}
		}

		foreach ( self::COPIED_FROM_CORE as $file ) {
			$files[ $file ] = (string) file_get_contents( $this->core . '/' . $file );
		}

		ksort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Writes the skeleton into a new directory, makes it a git repository and stages every file.
	 *
	 * Nothing is committed: the first commit, with its author, is the maintainer's. The files
	 * are staged because the extension's private-reference check reads what git tracks.
	 *
	 * @since 0.2.0
	 *
	 * @param string $target The directory to create. It must not exist.
	 * @return list<string> The paths written, relative to the target.
	 *
	 * @throws RuntimeException When the target exists, or a file or git cannot be written.
	 */
	public function write( string $target ): array {
		if ( file_exists( $target ) || is_link( $target ) ) {
			throw new RuntimeException( "{$target} exists already. Choose another directory, or remove it first." );
		}

		$files = $this->files();

		foreach ( $files as $relative => $contents ) {
			$path = $target . '/' . $relative;

			if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0755, true ) ) {
				throw new RuntimeException( dirname( $path ) . ' could not be created.' );
			}

			if ( false === file_put_contents( $path, $contents ) || ! chmod( $path, in_array( $relative, self::EXECUTABLE, true ) ? 0755 : 0644 ) ) {
				throw new RuntimeException( "{$path} could not be written." );
			}
		}

		self::git( $target, 'init', '--quiet', '--initial-branch=main' );
		self::git( $target, 'add', '--', ...array_keys( $files ) );

		return array_keys( $files );
	}

	/**
	 * Replaces the placeholders of one template.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $name     The template's path, for the error message.
	 * @param string                $template The template.
	 * @param array<string, string> $values   Placeholder name => value.
	 * @return string The rendered file.
	 *
	 * @throws RuntimeException When a placeholder is left that nothing fills.
	 */
	private function render( string $name, string $template, array $values ): string {
		$pairs = array();

		foreach ( $values as $key => $value ) {
			$pairs[ '{{' . $key . '}}' ] = $value;
		}

		$rendered = strtr( $template, $pairs );

		if ( 1 === preg_match( '/\{\{[a-z_]+\}\}/', $rendered, $left ) ) {
			throw new RuntimeException( "{$name} uses {$left[0]}, which nothing fills for a {$this->type->value} extension at this SEOCart commit." );
		}

		return $rendered;
	}

	/**
	 * Lists the templates beneath a directory, hidden ones included.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory An absolute path.
	 * @return list<string> Paths relative to the directory, each ending in `.tmpl`.
	 *
	 * @throws RuntimeException When the directory is missing or holds a file that is not a template.
	 */
	private static function templatesIn( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			throw new RuntimeException( "{$directory} does not exist." );
		}

		$templates = array();
		$entries   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ) );

		/**
		 * One file beneath the directory.
		 *
		 * @var SplFileInfo $entry
		 */
		foreach ( $entries as $entry ) {
			$relative = substr( str_replace( '\\', '/', $entry->getPathname() ), strlen( $directory ) + 1 );

			if ( ! str_ends_with( $relative, '.tmpl' ) ) {
				throw new RuntimeException( "{$directory}/{$relative} is not a template: every file there ends in .tmpl." );
			}

			$templates[] = $relative;
		}

		sort( $templates, SORT_STRING );

		return $templates;
	}

	/**
	 * Returns a header value SEOCart must have.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value  The value read.
	 * @param string      $header The header's name.
	 * @param string      $file   The file it is read from.
	 * @return string The value.
	 *
	 * @throws RuntimeException When the value is missing.
	 */
	private static function required( ?string $value, string $header, string $file ): string {
		if ( null === $value ) {
			throw new RuntimeException( "SEOCart's {$file} has no \"{$header}\" header, and the extension takes it from there." );
		}

		return $value;
	}

	/**
	 * Returns the commit SEOCart's checkout is at, which becomes the extension's pin.
	 *
	 * @since 0.2.0
	 *
	 * @param string $core SEOCart's root.
	 * @return string 40 hexadecimal digits.
	 *
	 * @throws RuntimeException When git cannot say.
	 */
	private static function coreCommit( string $core ): string {
		$commit = trim( self::git( $core, 'rev-parse', '--verify', 'HEAD^{commit}' ) );

		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/D', $commit ) ) {
			throw new RuntimeException( "git did not name SEOCart's commit in {$core}." );
		}

		return $commit;
	}

	/**
	 * Runs git in a directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The working directory.
	 * @param string ...$arguments The git command and its arguments.
	 * @return string What git printed.
	 *
	 * @throws RuntimeException When git fails.
	 */
	private static function git( string $directory, string ...$arguments ): string {
		$process = proc_open(
			array_merge( array( 'git', '-C', $directory ), $arguments ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'git could not be started.' );
		}

		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'git ' . implode( ' ', $arguments ) . " failed in {$directory}: " . trim( $errors ) );
		}

		return $output;
	}
}
