<?php
/**
 * Tests that each schema dialect is compiled at exactly one place in the code base (DRY rule 3)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Schemas are compiled, never copied: every dialect has one compiler function and that function
 * has one call site.
 *
 * The code base is everything that ships or generates a shipped document: src/, tools/ and bin/.
 * Tests are left out, because the compiler's own tests call each function directly. A call is
 * found in every spelling PHP resolves to the compiler class — imported, aliased, fully qualified
 * — and so is any `JsonSchemaCompiler::class` outside the compiler, since a callable built from it
 * could call a dialect without a visible call site, and any `new JsonSchemaCompiler`, since an
 * instance could call one through `->`. The compiler's constructor is private, which a test pins,
 * so such an instance cannot exist at run time either.
 *
 * The dialect list below is hand-kept, so it has a companion check: it must equal the compiler's
 * public static functions.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class CompilerCallSitesTest extends TestCase {

	/**
	 * Each dialect's compiler function, with the one file allowed to call it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const DIALECTS = array(
		'restArguments'      => 'src/Application/Operations/CompiledOperation.php',
		'wordPressSchema'    => 'src/Application/Operations/CompiledOperation.php',
		'cliSynopsis'        => 'src/Application/Operations/CompiledOperation.php',
		'openApiSchema'      => 'tools/Docs/OpenApiDocument.php',
		// The one core-shaped REST resource with a property of the plugin's: the product post type's `seocart`.
		'restObjectProperty' => 'src/Catalog/Interfaces/Rest/ProductCommerceSchema.php',
	);

	/**
	 * The directories of the code base that are tests or fixtures, not code.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const NOT_CODE = array(
		'tools/Docs/Tests',
		'tools/Packaging/Tests',
		'tools/WpOrg/Tests',
		'tools/phpcs/SEOCart/Tests',
	);

	/**
	 * The file that declares the compiler, whose own calls to itself do not count.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const COMPILER_FILE = 'src/Support/Schema/JsonSchemaCompiler.php';

	/**
	 * Tests that the dialect list is exactly the compiler's public static functions.
	 *
	 * @since 0.1.0
	 */
	public function test_the_dialect_list_is_the_compilers_public_functions(): void {
		$functions = array();

		foreach ( ( new \ReflectionClass( JsonSchemaCompiler::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( $method->isStatic() ) {
				$functions[] = $method->getName();
			}
		}

		$listed = array_keys( self::DIALECTS );

		sort( $functions );
		sort( $listed );

		$this->assertSame( $functions, $listed, 'A dialect was added to or removed from the compiler without updating this test\'s list, so its call sites would go unchecked.' );
	}

	/**
	 * Tests that each dialect is called at exactly one site, in the file allowed to call it.
	 *
	 * @since 0.1.0
	 */
	public function test_each_dialect_has_exactly_one_call_site(): void {
		$sites = array();

		foreach ( self::codeBase() as $file => $source ) {
			if ( self::COMPILER_FILE === $file ) {
				continue;
			}

			foreach ( self::compilerUses( $source ) as $use ) {
				$sites[ $use['member'] ][] = $file . ':' . $use['line'];
			}
		}

		$problems = array();

		foreach ( self::DIALECTS as $dialect => $allowed_file ) {
			$found = $sites[ $dialect ] ?? array();

			if ( 1 !== count( $found ) || ! str_starts_with( $found[0], $allowed_file . ':' ) ) {
				$problems[] = $dialect . '() must be called exactly once, in ' . $allowed_file . '; it is called at [' . implode( ', ', $found ) . '].';
			}
		}

		foreach ( $sites['class'] ?? array() as $site ) {
			$problems[] = 'JsonSchemaCompiler::class is named at ' . $site . ', which could call a dialect without a visible call site.';
		}

		foreach ( $sites['new'] ?? array() as $site ) {
			$problems[] = 'JsonSchemaCompiler is instantiated at ' . $site . ', and an instance could call a dialect without a visible call site.';
		}

		$this->assertSame( array(), $problems, "Schemas must be compiled at one place per dialect:\n  " . implode( "\n  ", $problems ) . "\n" );
	}

	/**
	 * Tests that the compiler cannot be instantiated, so no dialect can be called through an instance.
	 *
	 * @since 0.1.0
	 */
	public function test_the_compiler_cannot_be_instantiated(): void {
		$constructor = ( new \ReflectionClass( JsonSchemaCompiler::class ) )->getConstructor();

		$this->assertNotNull( $constructor, 'The compiler declares no constructor, so anyone can instantiate it.' );
		$this->assertTrue( $constructor->isPrivate(), 'The compiler\'s constructor is not private.' );
	}

	/**
	 * Tests the reader on source with a known answer, so a reader that finds nothing cannot pass for a clean code base.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reader_finds_every_spelling_of_a_call(): void {
		$source = <<<'PHP'
<?php
namespace Shop\Adapter;

use SEOCart\Support\Schema\JsonSchemaCompiler;
use SEOCart\Support\Schema\JsonSchemaCompiler as Compiler;
use SEOCart\Support\Schema;

final class Planted {
	public function run(): void {
		JsonSchemaCompiler::restArguments( array() );
		Compiler::openApiSchema( array() );
		\SEOCart\Support\Schema\JsonSchemaCompiler::cliSynopsis( array(), array() );
		Schema\JsonSchemaCompiler::wordPressSchema( 'x', array() );
		$callable = array( JsonSchemaCompiler::class, 'restArguments' );
		$constant = JsonSchemaCompiler::CLI_FORMATS;
		$text     = 'JsonSchemaCompiler::restArguments( array() )';
		Other::restArguments( array() );
		( new JsonSchemaCompiler() )->restArguments( array() );
	}
}
PHP;

		$this->assertSame(
			array(
				array( 'restArguments', 10 ),
				array( 'openApiSchema', 11 ),
				array( 'cliSynopsis', 12 ),
				array( 'wordPressSchema', 13 ),
				array( 'class', 14 ),
				array( 'new', 18 ),
			),
			array_map( static fn( array $found ): array => array( $found['member'], $found['line'] ), self::compilerUses( $source ) )
		);
	}

	/**
	 * Reads the code base: src/, tools/ and bin/, without tests and fixtures.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Source text, keyed by path relative to the root.
	 */
	private static function codeBase(): array {
		return PhpSource::files( 'src' ) + PhpSource::files( 'tools', self::NOT_CODE ) + PhpSource::files( 'bin' );
	}

	/**
	 * Finds every call to a compiler function, every naming of the compiler class and every instantiation of it, in a file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<array{member: string, line: int}> The function called, `class` or `new`, with its line.
	 */
	private static function compilerUses( string $source ): array {
		$tokens    = PhpSource::tokens( $source );
		$namespace = PhpSource::namespaceOf( $tokens );
		$imports   = PhpSource::importsOf( $tokens );
		$uses      = array();

		foreach ( $tokens as $index => $token ) {
			$next = $tokens[ $index + 1 ] ?? null;

			if ( $token->is( T_NEW ) && null !== $next && $next->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) ) && JsonSchemaCompiler::class === PhpSource::resolve( $next->text, $namespace, $imports ) ) {
				$uses[] = array(
					'member' => 'new',
					'line'   => $next->line,
				);

				continue;
			}

			if ( ! $token->is( T_DOUBLE_COLON ) ) {
				continue;
			}

			$class  = $tokens[ $index - 1 ] ?? null;
			$member = $tokens[ $index + 1 ] ?? null;
			$after  = $tokens[ $index + 2 ] ?? null;

			if ( null === $class || null === $member || ! $class->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) ) ) {
				continue;
			}

			if ( JsonSchemaCompiler::class !== PhpSource::resolve( $class->text, $namespace, $imports ) ) {
				continue;
			}

			if ( $member->is( T_CLASS ) ) {
				$uses[] = array(
					'member' => 'class',
					'line'   => $member->line,
				);
			} elseif ( $member->is( T_STRING ) && null !== $after && '(' === $after->text ) {
				$uses[] = array(
					'member' => $member->text,
					'line'   => $member->line,
				);
			}
		}

		return $uses;
	}
}
