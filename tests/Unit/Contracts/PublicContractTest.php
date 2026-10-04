<?php
/**
 * Tests that the public contract is all public, and names nothing internal
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every class, interface and enum under src/Contracts/ is tagged `@api` with its `@since`, and every type of the plugin its public members name, or its file imports, is public too.
 *
 * An extension is written against src/Contracts/ and the few types it names, which are tagged
 * `@api` where they live; everything else is internal and may change without notice. So a
 * contract that named an internal type in a signature would let an extension depend on it. The
 * types are read from each public or protected member's native signature (parameters, returns,
 * properties) and from the file's imports, which every type its docblocks name comes through.
 *
 * Planted violations, each shown red and removed: give PaymentQuery's constructor a parameter
 * typed IntentStatus; take `@api` off Mode; import SEOCart\Support\DigitArithmetic, which is
 * `@internal`, into a contract file.
 *
 * @group contract
 *
 * @since 0.2.0
 */
final class PublicContractTest extends TestCase {

	/**
	 * The namespace of the public contract.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CONTRACTS = 'SEOCart\\Contracts\\';

	/**
	 * Tests that every class of the contract is tagged public, with the version it became so.
	 *
	 * @since 0.2.0
	 */
	public function test_every_contract_class_is_tagged_api(): void {
		$untagged = array();
		$classes  = self::contractClasses();

		foreach ( array_keys( $classes ) as $class ) {
			$doc = (string) ( new \ReflectionClass( $class ) )->getDocComment();

			if ( ! self::tagged( $doc, '@api' ) || ! self::tagged( $doc, '@since' ) ) {
				$untagged[] = $class;
			}
		}

		$this->assertContains( PaymentGateway::class, array_keys( $classes ), 'The search must find the contract\'s classes, or it proves nothing.' );
		$this->assertContains( ExtensionContext::class, array_keys( $classes ) );
		$this->assertSame( array(), $untagged, 'Every class of the public contract is tagged @api and @since.' );
	}

	/**
	 * Tests that no contract class names a type of the plugin that is not public.
	 *
	 * @since 0.2.0
	 */
	public function test_the_contract_names_no_internal_type(): void {
		$internal = array();

		foreach ( self::contractClasses() as $class => $source ) {
			foreach ( array_unique( array_merge( self::signatureTypes( new \ReflectionClass( $class ) ), self::imports( $source ) ) ) as $type ) {
				if ( ! self::isPublic( $type ) ) {
					$internal[] = $class . ' names ' . $type;
				}
			}
		}

		$this->assertSame( array(), $internal, 'The public contract names only public types: under SEOCart\\Contracts, or tagged @api where they live.' );
	}

	/**
	 * Tests the reading of a signature on a class with a known answer, so a reader that finds nothing cannot pass for a clean contract.
	 *
	 * @since 0.2.0
	 */
	public function test_the_signature_reader_finds_every_kind_of_type(): void {
		$probe = new class() {

			/**
			 * A property.
			 *
			 * @var \SEOCart\Support\Money|null
			 */
			public ?\SEOCart\Support\Money $money = null;

			/**
			 * A method.
			 *
			 * @param \SEOCart\Payment\Domain\IntentStatus|\SEOCart\Support\Currency $status A union.
			 * @return \SEOCart\Support\Decimal A return.
			 */
			public function run( \SEOCart\Payment\Domain\IntentStatus|\SEOCart\Support\Currency $status ): \SEOCart\Support\Decimal {
				unset( $status );

				return $this->hidden( null );
			}

			/**
			 * A private method, which is no signature of the contract.
			 *
			 * @param \SEOCart\Support\DigitArithmetic|null $digits Ignored.
			 * @return \SEOCart\Support\Decimal One.
			 */
			private function hidden( ?\SEOCart\Support\DigitArithmetic $digits ): \SEOCart\Support\Decimal {
				unset( $digits );

				return \SEOCart\Support\Decimal::of( '1' );
			}
		};

		$types = self::signatureTypes( new \ReflectionClass( $probe ) );

		sort( $types );

		$this->assertSame( array( 'SEOCart\\Payment\\Domain\\IntentStatus', 'SEOCart\\Support\\Currency', 'SEOCart\\Support\\Decimal', 'SEOCart\\Support\\Money' ), $types );
		$this->assertFalse( self::isPublic( 'SEOCart\\Payment\\Domain\\IntentStatus' ), 'An untagged type of the plugin is internal.' );
		$this->assertFalse( self::isPublic( 'SEOCart\\Support\\DigitArithmetic' ), 'An @internal type is internal.' );
		$this->assertTrue( self::isPublic( 'SEOCart\\Support\\Money' ) );
		$this->assertTrue( self::isPublic( \DateTimeImmutable::class ), 'A type of PHP is not the plugin\'s to tag.' );
	}

	/**
	 * Returns every class, interface and enum declared under src/Contracts/, with its file's source.
	 *
	 * @since 0.2.0
	 *
	 * @return array<class-string, string> The source of each class's file, keyed by the class.
	 */
	private static function contractClasses(): array {
		$classes = array();

		foreach ( PhpSource::files( 'src/Contracts' ) as $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				/**
				 * A class the search found declared in the contract's files.
				 *
				 * @var class-string $class
				 */
				$classes[ $class ] = $source;
			}
		}

		return $classes;
	}

	/**
	 * Returns the plugin's types a class's public and protected members name in their native signatures.
	 *
	 * @since 0.2.0
	 *
	 * @param \ReflectionClass $reflection The class.
	 * @return list<string> The types' names, the plugin's only, each once.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function signatureTypes( \ReflectionClass $reflection ): array {
		$types = array();

		foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED ) as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			foreach ( $method->getParameters() as $parameter ) {
				$types = array_merge( $types, self::names( $parameter->getType() ) );
			}

			$types = array_merge( $types, self::names( $method->getReturnType() ) );
		}

		foreach ( $reflection->getProperties( \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED ) as $property ) {
			$types = array_merge( $types, self::names( $property->getType() ) );
		}

		return array_values( array_unique( array_filter( $types, static fn( string $type ): bool => str_starts_with( $type, 'SEOCart\\' ) ) ) );
	}

	/**
	 * Returns the class names a native type is made of.
	 *
	 * @since 0.2.0
	 *
	 * @param \ReflectionType|null $type The type, or null for none.
	 * @return list<string> The class and interface names; none for a built-in type.
	 */
	private static function names( ?\ReflectionType $type ): array {
		if ( $type instanceof \ReflectionNamedType ) {
			return $type->isBuiltin() ? array() : array( $type->getName() );
		}

		if ( $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ) {
			return array_merge( ...array_map( array( self::class, 'names' ), $type->getTypes() ) );
		}

		return array();
	}

	/**
	 * Returns the plugin's types a file imports.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source The file's source.
	 * @return list<string> The imported names, the plugin's only.
	 */
	private static function imports( string $source ): array {
		return array_values( array_filter( PhpSource::importsOf( PhpSource::tokens( $source ) ), static fn( string $type ): bool => str_starts_with( $type, 'SEOCart\\' ) ) );
	}

	/**
	 * Tells whether a type may be named by the public contract.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type A class, interface or enum name.
	 * @return bool True for a type of PHP, one of the contract, or one of the plugin tagged `@api` and not `@internal`.
	 */
	private static function isPublic( string $type ): bool {
		if ( ! str_starts_with( $type, 'SEOCart\\' ) || str_starts_with( $type, self::CONTRACTS ) ) {
			return true;
		}

		if ( ! class_exists( $type ) && ! interface_exists( $type ) && ! enum_exists( $type ) ) {
			return false;
		}

		$doc = (string) ( new \ReflectionClass( $type ) )->getDocComment();

		return self::tagged( $doc, '@api' ) && ! self::tagged( $doc, '@internal' );
	}

	/**
	 * Tells whether a docblock carries a tag.
	 *
	 * @since 0.2.0
	 *
	 * @param string $doc The docblock.
	 * @param string $tag The tag, such as `@api`.
	 * @return bool True when a line of it starts with the tag.
	 */
	private static function tagged( string $doc, string $tag ): bool {
		return 1 === preg_match( '/^\s*\*\s*' . preg_quote( $tag, '/' ) . '\b/m', $doc );
	}
}
