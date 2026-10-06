<?php
/**
 * Tests for the generator of a new extension's repository
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tools\Extension\ExtensionType;
use SEOCart\Tools\Extension\Skeleton;
use SEOCart\Tools\Packaging\PluginPackage;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * Renders the payments skeleton from this checkout's templates and inspects it.
 *
 * Whether a generated skeleton passes its own gates is proved by running them: bin/dev/selftest.sh
 * on a developer machine and the extension-kit job of ci.yml.
 *
 * @since 0.2.0
 */
final class SkeletonTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The files of a payments extension labelled Example, in the order files() returns them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const FILES = array(
		'.distignore',
		'.editorconfig',
		'.gitattributes',
		'.github/workflows/ci.yml',
		'.github/workflows/nightly.yml',
		'.github/workflows/release.yml',
		'.gitignore',
		'CHANGELOG.md',
		'LICENSE',
		'SECURITY.md',
		'bin/dev/bump-core.sh',
		'composer.json',
		'phpcs.xml.dist',
		'phpstan.neon.dist',
		'phpunit.xml.dist',
		'readme.txt',
		'seocart-core.env',
		'seocart-gateway-for-example.php',
		'src/Gateway.php',
		'tests/Integration/LoadsBesideSEOCartTest.php',
		'tests/Integration/RegistersWithSEOCartTest.php',
		'tests/Unit/PrivateReferencesTest.php',
		'tests/Unit/RegistrationTest.php',
		'tests/bootstrap.php',
	);

	/**
	 * A script that stands in for WordPress and runs a main file in a process without SEOCart.
	 *
	 * It defines the one function the main file calls at file scope, add_action(), and prints
	 * the names of the actions hooked, as JSON.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RUNNER = <<<'PHP'
		<?php
		define( 'ABSPATH', __DIR__ . '/' );
		$hooks = array();
		function add_action( $hook ) {
			global $hooks;
			$hooks[] = $hook;
		}
		require __DIR__ . '/seocart-gateway-for-example.php';
		echo json_encode( $hooks );
		PHP;

	/**
	 * Returns this checkout's root, the SEOCart the skeleton is generated from.
	 *
	 * @since 0.2.0
	 *
	 * @return string The root.
	 */
	private static function core(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Returns the payments skeleton labelled Example.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $segment Optional. The namespace segment to ask for.
	 * @return Skeleton The skeleton.
	 */
	private static function skeleton( ?string $segment = null ): Skeleton {
		return new Skeleton( self::core(), 'seocart-gateway-for-example', ExtensionType::Payments, 'Example', $segment );
	}

	/**
	 * Tests that the skeleton holds exactly the expected files, every placeholder filled.
	 *
	 * @since 0.2.0
	 */
	public function test_renders_every_file_and_fills_every_placeholder(): void {
		$files = self::skeleton()->files();

		$this->assertSame( self::FILES, array_keys( $files ) );

		foreach ( $files as $path => $contents ) {
			$this->assertDoesNotMatchRegularExpression( '/\{\{[a-z_]+\}\}/', $contents, "{$path} keeps a placeholder." );
		}

		$this->assertSame( (string) file_get_contents( self::core() . '/LICENSE' ), $files['LICENSE'] );
		$this->assertJson( $files['composer.json'] );
	}

	/**
	 * Tests the values the skeleton derives from the slug, the label and SEOCart.
	 *
	 * @since 0.2.0
	 */
	public function test_derives_its_values_from_the_slug_the_label_and_seocart(): void {
		$values    = self::skeleton()->values();
		$core_main = (string) file_get_contents( self::core() . '/' . PluginPackage::MAIN_FILE );

		$this->assertSame( 'SEOCart Gateway for Example', $values['name'] );
		$this->assertSame( 'SEOCart\\GatewayForExample', $values['namespace'] );
		$this->assertSame( 'seocart_gateway_for_example', $values['prefix'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/D', $values['core_ref'] );
		$this->assertSame( PluginPackage::header( $core_main, 'Requires PHP' ), $values['requires_php'] );
		$this->assertSame( PluginPackage::header( $core_main, 'Requires at least' ), $values['requires_wp'] );

		$this->assertSame( 'SEOCart\\GatewayForStripe', ( new Skeleton( self::core(), 'seocart-gateway-for-stripe', ExtensionType::Payments, 'Stripe' ) )->values()['namespace'] );
		$this->assertSame( 'example', $values['gateway_id'] );
		$this->assertSame( 'SEOCart\\AuthorizeNet', ( new Skeleton( self::core(), 'seocart-gateway-for-authorize-net', ExtensionType::Payments, 'Authorize.Net', 'AuthorizeNet' ) )->values()['namespace'] );
	}

	/**
	 * Tests that a plugin name becomes the slug WordPress makes of it.
	 *
	 * The integration test tests/Integration/Extension/ExtensionSlugTest.php compares names like
	 * these with WordPress's own sanitize_title().
	 *
	 * @since 0.2.0
	 */
	public function test_the_slug_is_the_one_wordpress_makes_of_the_name(): void {
		$this->assertSame( 'seocart-gateway-for-stripe', Skeleton::slugOf( 'SEOCart Gateway for Stripe' ) );
		$this->assertSame( 'seocart-gateway-for-authorize-net', Skeleton::slugOf( 'SEOCart Gateway for Authorize.Net' ) );
		$this->assertSame( 'seocart-gateway-for-pay-later', Skeleton::slugOf( 'SEOCart Gateway for  Pay - Later' ) );
		$this->assertSame( 'seocart-gateway-for-2checkout', Skeleton::slugOf( 'SEOCart Gateway for 2Checkout.' ) );
	}

	/**
	 * Tests that the main file declares an extension of SEOCart that the packaging tools recognise,
	 * and that every place that names SEOCart's commit names the same one.
	 *
	 * @since 0.2.0
	 */
	public function test_the_main_file_and_the_pins_agree(): void {
		$skeleton = self::skeleton();
		$files    = $skeleton->files();
		$pin      = $skeleton->values()['core_ref'];

		$this->assertSame( 'seocart', PluginPackage::header( $files['seocart-gateway-for-example.php'], 'Requires Plugins' ) );
		$this->assertSame( 'seocart-gateway-for-example', PluginPackage::header( $files['seocart-gateway-for-example.php'], 'Text Domain' ) );
		$this->assertSame( '0.1.0', PluginPackage::header( $files['seocart-gateway-for-example.php'], 'Version' ) );
		$this->assertSame( 'SEOCart Gateway for Example', PluginPackage::header( $files['seocart-gateway-for-example.php'], 'Plugin Name' ) );
		$this->assertSame( 'SEOCart', PluginPackage::header( $files['seocart-gateway-for-example.php'], 'Author' ) );
		// The WordPress.org account the installer compares an extension's author with.
		$this->assertSame( 'cirkuitnet', PluginPackage::header( $files['readme.txt'], 'Contributors' ) );
		$this->assertSame( '0.1.0', PluginPackage::header( $files['readme.txt'], 'Stable tag' ) );
		$this->assertStringContainsString( "\nSEOCART_CORE_REF={$pin}\n", $files['seocart-core.env'] );

		foreach ( array( 'ci', 'nightly', 'release' ) as $workflow ) {
			preg_match_all( '#cirkuitnet/seocart/\.github/workflows/[a-z-]+\.yml@(\S+)#', $files[ ".github/workflows/{$workflow}.yml" ], $calls );

			$this->assertSame( array( $pin ), array_values( array_unique( $calls[1] ) ), "{$workflow}.yml must call SEOCart's workflows at the pin." );
		}
	}

	/**
	 * Tests that the main file hooks the action SEOCart declares, named by a string written from the constant.
	 *
	 * @since 0.2.0
	 */
	public function test_the_main_file_hooks_the_action_seocart_declares(): void {
		$skeleton = self::skeleton();
		$main     = $skeleton->files()['seocart-gateway-for-example.php'];

		$this->assertSame( GatewayRegistry::ACTION, ExtensionType::Payments->registrationAction() );
		$this->assertSame( GatewayRegistry::ACTION, $skeleton->values()['registration_action'] );
		$this->assertSame( 1, preg_match_all( "/^add_action\(\n\t'" . preg_quote( GatewayRegistry::ACTION, '/' ) . "',\n/m", $main ), 'The main file hooks the action once, by its string.' );
		$this->assertStringNotContainsString( 'GatewayRegistry::ACTION', $main, 'The main file loads before SEOCart: it cannot name its constant.' );
	}

	/**
	 * Tests the main file in a process where SEOCart does not exist: it hooks the one action and uses nothing of SEOCart.
	 *
	 * WordPress loads the extension before SEOCart, so a class, function or constant of SEOCart at
	 * file scope is a fatal error on every request. Only a process without SEOCart can tell.
	 *
	 * @since 0.2.0
	 */
	public function test_the_main_file_runs_where_seocart_is_not_loaded(): void {
		$this->writeFile( 'main/seocart-gateway-for-example.php', self::skeleton()->files()['seocart-gateway-for-example.php'] );
		$this->writeFile( 'main/run.php', self::RUNNER );

		$process = proc_open(
			array( PHP_BINARY, '-n', $this->directory . '/main/run.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process );

		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $errors . $output );
		$this->assertSame( (string) json_encode( array( GatewayRegistry::ACTION ) ), $output );
	}

	/**
	 * Tests the gateway the skeleton writes: its id, its contract version as a literal, and no operation it implements.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_states_its_id_and_the_contract_version_it_is_written_against(): void {
		$gateway = self::skeleton()->files()['src/Gateway.php'];

		$this->assertStringContainsString( "public const ID = 'example';", $gateway );
		$this->assertStringContainsString( "\t\t\t'" . PaymentGateway::CONTRACT_VERSION . "',\n", $gateway );
		$this->assertStringContainsString( 'final class Gateway implements PaymentGateway', $gateway );
		$this->assertStringNotContainsString( 'CONTRACT_VERSION', (string) preg_replace( '#/\*\*.*?\*/#s', '', $gateway ), 'The version is a literal, written when the extension is generated.' );
		$this->assertSame( ExtensionType::Payments->contractVersion(), PaymentGateway::CONTRACT_VERSION );
	}

	/**
	 * Tests the id the gateway registers with, which the label gives and an option overrides.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_id_comes_from_the_label_or_the_option(): void {
		$this->assertSame( 'stripe', Skeleton::gatewayIdOf( 'Stripe' ) );
		$this->assertSame( 'authorize_net', Skeleton::gatewayIdOf( 'Authorize.Net' ) );
		$this->assertSame( 'pay_later', Skeleton::gatewayIdOf( ' Pay - Later ' ) );

		$this->assertSame( 'authorize_net', ( new Skeleton( self::core(), 'seocart-gateway-for-authorize-net', ExtensionType::Payments, 'Authorize.Net' ) )->values()['gateway_id'] );

		$asked = new Skeleton( self::core(), 'seocart-gateway-for-2checkout', ExtensionType::Payments, '2Checkout', null, 'checkout_two' );

		$this->assertSame( 'checkout_two', $asked->values()['gateway_id'] );
		$this->assertStringContainsString( "public const ID = 'checkout_two';", $asked->files()['src/Gateway.php'] );
	}

	/**
	 * Tests that an id the contract refuses is refused before anything is written.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider invalidGatewayIds
	 *
	 * @param string      $label   The label.
	 * @param string|null $id      The id asked for.
	 * @param string      $message A fragment of the refusal.
	 */
	public function test_refuses_a_gateway_id_the_contract_refuses( string $label, ?string $id, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		new Skeleton( self::core(), Skeleton::slugOf( ExtensionType::Payments->pluginName( $label ) ), ExtensionType::Payments, $label, null, $id );
	}

	/**
	 * Provides gateway ids the contract refuses: the label's own, and one asked for.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string|null, string}>
	 */
	public function invalidGatewayIds(): array {
		$too_long = str_repeat( 'a', GatewayDescriptor::ID_MAX_LENGTH + 1 );

		return array(
			'label that starts with a digit'      => array( '2Checkout', null, 'The label "2Checkout" gives the gateway id "2checkout", which is not one' ),
			'label longer than an id may be'      => array( 'Some Very Long Payment Provider Name', null, 'at most ' . GatewayDescriptor::ID_MAX_LENGTH . ' characters' ),
			'id in capitals'                      => array( 'Example', 'Example', '"Example" is not a gateway id' ),
			'id with a hyphen'                    => array( 'Example', 'my-gateway', '"my-gateway" is not a gateway id' ),
			'id with a double underscore'         => array( 'Example', 'my__gateway', '"my__gateway" is not a gateway id' ),
			'id that starts with a digit'         => array( 'Example', '2example', '"2example" is not a gateway id' ),
			'id of 33 characters'                 => array( 'Example', $too_long, "\"{$too_long}\" is not a gateway id" ),
			'empty id'                            => array( 'Example', '', '"" is not a gateway id' ),
			'id with a trailing newline'          => array( 'Example', "example\n", 'is not a gateway id' ),
			'label that gives the stand-in\'s id' => array( 'Stub', null, 'The label "Stub" gives the gateway id "' . StubGateway::ID . '", which is the stand-in gateway\'s id' ),
			'the stand-in\'s id asked for'        => array( 'Example', StubGateway::ID, 'The gateway id "' . StubGateway::ID . '", which is the stand-in gateway\'s id' ),
		);
	}

	/**
	 * Tests that a slug, a label or a namespace of another form is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider invalidArguments
	 *
	 * @param string      $slug    The slug.
	 * @param string      $label   The label.
	 * @param string|null $segment The namespace segment.
	 * @param string      $message A fragment of the refusal.
	 */
	public function test_refuses_arguments_of_another_form( string $slug, string $label, ?string $segment, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		new Skeleton( self::core(), $slug, ExtensionType::Payments, $label, $segment );
	}

	/**
	 * Provides arguments the generator refuses.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string|null, string}>
	 */
	public function invalidArguments(): array {
		return array(
			'slug without the prefix'   => array( 'stripe', 'Stripe', null, 'is not an extension slug' ),
			'slug in capitals'          => array( 'seocart-Stripe', 'Stripe', null, 'is not an extension slug' ),
			'slug with a double hyphen' => array( 'seocart--stripe', 'Stripe', null, 'is not an extension slug' ),
			'label with a quote'        => array( 'seocart-gateway-for-example', 'Ex"ample', null, 'is not a label' ),
			'label with an ampersand'   => array( 'seocart-gateway-for-example', 'A & B', null, 'is not a label' ),
			'slug not the name\'s'      => array( 'seocart-example', 'Example', null, 'is not the slug of the plugin\'s name, "SEOCart Gateway for Example"' ),
			'slug of another label'     => array( 'seocart-gateway-for-stripe', 'Example', null, 'which gives "seocart-gateway-for-example"' ),
			'namespace in lower case'   => array( 'seocart-gateway-for-example', 'Example', 'example', 'is not a namespace segment' ),
			'namespace with a slash'    => array( 'seocart-gateway-for-example', 'Example', 'Ex\\Ample', 'is not a namespace segment' ),
		);
	}

	/**
	 * Tests that write() creates a git repository with every file staged and the bump script executable.
	 *
	 * @since 0.2.0
	 */
	public function test_writes_a_repository_with_every_file_staged(): void {
		$target  = $this->directory . '/seocart-gateway-for-example';
		$written = self::skeleton()->write( $target );

		$this->assertSame( self::FILES, $written );
		$this->assertSame( self::FILES, $this->stagedFiles( $target ) );
		$this->assertSame( 0755, fileperms( $target . '/bin/dev/bump-core.sh' ) & 0777 );
		$this->assertSame( 0644, fileperms( $target . '/seocart-gateway-for-example.php' ) & 0777 );
	}

	/**
	 * Tests that write() never writes into a directory that exists.
	 *
	 * @since 0.2.0
	 */
	public function test_refuses_to_write_into_an_existing_directory(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'exists already' );

		self::skeleton()->write( $this->directory );
	}

	/**
	 * Lists the files git has staged in a repository.
	 *
	 * @since 0.2.0
	 *
	 * @param string $repository The repository.
	 * @return list<string> Paths, sorted by byte value.
	 */
	private function stagedFiles( string $repository ): array {
		$process = proc_open( array( 'git', '-C', $repository, 'ls-files', '-z' ), array( 1 => array( 'pipe', 'w' ) ), $pipes );

		$this->assertIsResource( $process );

		$output = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		proc_close( $process );

		$files = array_values( array_filter( explode( "\0", $output ) ) );
		sort( $files, SORT_STRING );

		return $files;
	}
}
