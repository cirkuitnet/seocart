<?php
/**
 * Tests that the money sniff's method lists equal the real money API (DRY rule 11)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\phpcs\SEOCart\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tools\phpcs\SEOCart\Sniffs\DRY\MoneyArithmeticInInterfacesSniff;

/**
 * The companion set-equality test of SEOCart.DRY.MoneyArithmeticInInterfaces.
 *
 * The sniff reports calls by method name, so its two lists are a hand-maintained copy of the
 * money API. This test derives both sets from the classes themselves, by reflection, and
 * compares them with the lists as sets, without regard to case:
 *
 * - `$arithmeticMethods` must equal the public methods, of any class under src/Support
 *   (Schema/ aside), that compute an amount: a method whose declared return type is an amount
 *   type — Money, TaxedMoney or Decimal, or `array` on one of those (allocate()) — except a
 *   static method none of whose parameters is an amount (it builds an amount from scalars:
 *   Money::of(), Decimal::of()) and a component getter (an instance method with no parameters
 *   that returns the property of the same name: TaxedMoney::net(), ConversionContext::rate()).
 * - `$accessors` must equal the public instance methods of an amount type that take no
 *   parameter and return a bare number, `int` or `string`: Money::minorUnits(),
 *   Decimal::toString() and their kind.
 *
 * TaxedMoney and Decimal are in scope, not just Money: the sniff matches names, not types, and
 * DRY rule 7 is about recomputing amounts in an adapter, which a TaxedMoney or a Decimal does
 * as surely as a Money. Classes that are not amounts but compute one (Percentage::toFactor(),
 * the ConversionContext conversions) are covered through their return type.
 *
 * @since 0.1.0
 */
final class MoneyApiListsTest extends TestCase {

	/**
	 * The amount types: the classes whose values are amounts or amounts on their way to rounding.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string>
	 */
	private const AMOUNT_TYPES = array( Money::class, TaxedMoney::class, Decimal::class );

	/**
	 * Loads PHP_CodeSniffer's autoloader, which Composer does not register, for the sniff's interface.
	 *
	 * @since 0.1.0
	 */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 4 ) . '/vendor/squizlabs/php_codesniffer/autoload.php';
	}

	/**
	 * Tests that the sniff's arithmetic list equals the methods that compute an amount.
	 *
	 * @since 0.1.0
	 */
	public function test_arithmetic_methods_equal_the_methods_that_compute_an_amount(): void {
		$this->assertSame(
			self::lowerSorted( array_keys( self::classify()['arithmetic'] ) ),
			self::lowerSorted( ( new MoneyArithmeticInInterfacesSniff() )->arithmeticMethods ),
			'MoneyArithmeticInInterfacesSniff::$arithmeticMethods must list exactly the money API methods that compute an amount. Found where: ' . self::where( self::classify()['arithmetic'] )
		);
	}

	/**
	 * Tests that the sniff's accessor list equals the methods that expose a raw number.
	 *
	 * @since 0.1.0
	 */
	public function test_accessors_equal_the_methods_that_expose_a_raw_number(): void {
		$this->assertSame(
			self::lowerSorted( array_keys( self::classify()['accessors'] ) ),
			self::lowerSorted( ( new MoneyArithmeticInInterfacesSniff() )->accessors ),
			'MoneyArithmeticInInterfacesSniff::$accessors must list exactly the money API methods that expose a raw number. Found where: ' . self::where( self::classify()['accessors'] )
		);
	}

	/**
	 * Tests that no ruleset overrides the two lists, which would make the defaults compared above irrelevant.
	 *
	 * @since 0.1.0
	 */
	public function test_no_ruleset_overrides_the_lists(): void {
		$root = dirname( __DIR__, 4 );

		foreach ( array( 'tools/phpcs/SEOCart/ruleset.xml', 'phpcs.xml.dist' ) as $ruleset ) {
			$this->assertDoesNotMatchRegularExpression(
				'/<property\s+name="(?:arithmeticMethods|accessors)"/',
				(string) file_get_contents( $root . '/' . $ruleset ),
				$ruleset . ' sets a money list; the set-equality test reads the sniff\'s defaults.'
			);
		}
	}

	/**
	 * Tests the classification on methods whose kind is known, so a classifier that finds nothing cannot pass.
	 *
	 * @since 0.1.0
	 */
	public function test_the_classification_reads_the_real_api(): void {
		$classified = self::classify();

		$this->assertContains( Money::class . '::add', $classified['arithmetic']['add'], 'Money::add() computes an amount.' );
		$this->assertContains( Money::class . '::allocate', $classified['arithmetic']['allocate'], 'An array of amounts counts.' );
		$this->assertContains( Money::class . '::ofDecimal', $classified['arithmetic']['ofdecimal'], 'A static method that rounds a Decimal counts.' );
		$this->assertContains( Money::class . '::minorUnits', $classified['accessors']['minorunits'] );
		$this->assertArrayNotHasKey( 'of', $classified['arithmetic'], 'Money::of() builds an amount from scalars.' );
		$this->assertArrayNotHasKey( 'net', $classified['arithmetic'], 'TaxedMoney::net() returns a component, it computes nothing.' );
		$this->assertArrayNotHasKey( 'compare', $classified['accessors'], 'A comparison takes an operand.' );
	}

	/**
	 * Classifies the public methods of every class under src/Support.
	 *
	 * @since 0.1.0
	 *
	 * @return array{arithmetic: array<string, list<string>>, accessors: array<string, list<string>>} Lower-cased method names, each with the Class::method pairs that define it.
	 */
	private static function classify(): array {
		$classified = array(
			'arithmetic' => array(),
			'accessors'  => array(),
		);

		foreach ( self::supportClasses() as $reflection ) {
			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
				if ( $method->getDeclaringClass()->getName() !== $reflection->getName() || str_starts_with( $method->getName(), '__' ) ) {
					continue;
				}

				$where = $reflection->getName() . '::' . $method->getName();
				$name  = strtolower( $method->getName() );

				if ( self::computesAnAmount( $reflection, $method ) ) {
					$classified['arithmetic'][ $name ][] = $where;
				} elseif ( self::exposesARawNumber( $reflection, $method ) ) {
					$classified['accessors'][ $name ][] = $where;
				}
			}
		}

		return $classified;
	}

	/**
	 * Tells whether a method computes an amount.
	 *
	 * @since 0.1.0
	 *
	 * @param \ReflectionClass  $reflection The class.
	 * @param \ReflectionMethod $method     The method.
	 * @return bool True for a method that returns a new amount.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function computesAnAmount( \ReflectionClass $reflection, \ReflectionMethod $method ): bool {
		$returns        = self::typeNames( $method->getReturnType(), $reflection );
		$is_amount_type = in_array( $reflection->getName(), self::AMOUNT_TYPES, true );

		if ( array() === array_intersect( $returns, self::AMOUNT_TYPES ) && ! ( $is_amount_type && in_array( 'array', $returns, true ) ) ) {
			return false;
		}

		if ( $method->isStatic() && ! self::takesAnAmount( $reflection, $method ) ) {
			return false;
		}

		return ! self::isComponentGetter( $reflection, $method );
	}

	/**
	 * Tells whether a method hands out a raw number of an amount.
	 *
	 * @since 0.1.0
	 *
	 * @param \ReflectionClass  $reflection The class.
	 * @param \ReflectionMethod $method     The method.
	 * @return bool True for a parameterless instance method of an amount type returning int or string.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function exposesARawNumber( \ReflectionClass $reflection, \ReflectionMethod $method ): bool {
		return in_array( $reflection->getName(), self::AMOUNT_TYPES, true )
			&& ! $method->isStatic()
			&& 0 === $method->getNumberOfParameters()
			&& array() !== array_intersect( self::typeNames( $method->getReturnType(), $reflection ), array( 'int', 'string' ) );
	}

	/**
	 * Tells whether any parameter of a method is an amount.
	 *
	 * @since 0.1.0
	 *
	 * @param \ReflectionClass  $reflection The class.
	 * @param \ReflectionMethod $method     The method.
	 * @return bool True when a parameter's type includes an amount type.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function takesAnAmount( \ReflectionClass $reflection, \ReflectionMethod $method ): bool {
		foreach ( $method->getParameters() as $parameter ) {
			if ( array() !== array_intersect( self::typeNames( $parameter->getType(), $reflection ), self::AMOUNT_TYPES ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a method only returns the property of the same name.
	 *
	 * @since 0.1.0
	 *
	 * @param \ReflectionClass  $reflection The class.
	 * @param \ReflectionMethod $method     The method.
	 * @return bool True for a parameterless instance method named after a property of its return type.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function isComponentGetter( \ReflectionClass $reflection, \ReflectionMethod $method ): bool {
		if ( $method->isStatic() || 0 !== $method->getNumberOfParameters() || ! $reflection->hasProperty( $method->getName() ) ) {
			return false;
		}

		return self::typeNames( $reflection->getProperty( $method->getName() )->getType(), $reflection ) === self::typeNames( $method->getReturnType(), $reflection );
	}

	/**
	 * Returns the class names in a type, with self and static resolved.
	 *
	 * @since 0.1.0
	 *
	 * @param \ReflectionType|null $type       The type.
	 * @param \ReflectionClass     $reflection The class the type is declared in.
	 * @return list<string> The names, sorted.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function typeNames( ?\ReflectionType $type, \ReflectionClass $reflection ): array {
		$types = $type instanceof \ReflectionUnionType ? $type->getTypes() : array( $type );
		$names = array();

		foreach ( $types as $member ) {
			if ( $member instanceof \ReflectionNamedType ) {
				$names[] = in_array( $member->getName(), array( 'self', 'static' ), true ) ? $reflection->getName() : $member->getName();
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Loads every class, interface and enum under src/Support, Schema/ aside, by its PSR-4 name.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\ReflectionClass<object>> The classes.
	 */
	private static function supportClasses(): array {
		$source  = dirname( __DIR__, 4 ) . '/src';
		$classes = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source . '/Support', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			$relative = str_replace( '\\', '/', substr( (string) $file, strlen( $source ) + 1 ) );

			if ( ! str_ends_with( $relative, '.php' ) || str_starts_with( $relative, 'Support/Schema/' ) ) {
				continue;
			}

			$name = 'SEOCart\\' . str_replace( '/', '\\', substr( $relative, 0, -4 ) );

			self::assertTrue( class_exists( $name ) || interface_exists( $name ) || enum_exists( $name ), $relative . ' does not declare ' . $name . '.' );

			$classes[] = new \ReflectionClass( $name );
		}

		self::assertNotSame( array(), $classes, 'No class was found under src/Support.' );

		return $classes;
	}

	/**
	 * Lower-cases and sorts a list of names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, string> $names The names.
	 * @return list<string> The lower-cased names, sorted.
	 */
	private static function lowerSorted( array $names ): array {
		$names = array_values( array_unique( array_map( 'strtolower', $names ) ) );
		sort( $names );

		return $names;
	}

	/**
	 * Writes where each classified name is defined, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $classified Class::method pairs, keyed by lower-cased name.
	 * @return string The pairs.
	 */
	private static function where( array $classified ): string {
		$pairs = array();

		foreach ( $classified as $definitions ) {
			$pairs = array_merge( $pairs, $definitions );
		}

		sort( $pairs );

		return implode( ', ', $pairs );
	}
}
