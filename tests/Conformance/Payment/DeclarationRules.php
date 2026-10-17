<?php
/**
 * DeclarationRules: the conformance rules that read a gateway's declaration without WordPress
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use Brain\Monkey\Actions;
use Brain\Monkey\Container;
use Brain\Monkey\Functions;
use Brain\Monkey\Hook\HookStorage;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Rules 4 and 15 of the suite, as checks of a gateway in a process where WordPress is not loaded, and the loading of an extension's gateways there.
 *
 * Owns one fact: how a gateway's declaration is judged without WordPress. Rule 4 reflects the
 * contract's classes, the gateway's class with its parents and traits, and every class declared
 * under the gateway's source directories, and finds each property or parameter whose name reads
 * as card or bank account data. Rule 15 calls describe() under Brain Monkey (set up by the caller)
 * and finds any WordPress function it calls: one that is undefined here fails with its name, a
 * translation call or a hook is counted. The pure helpers Brain Monkey defines (absint() and the
 * like) are not counted.
 *
 * @since 0.2.0
 */
final class DeclarationRules {

	/**
	 * The words of a name that read as card or bank account data, after camelCase is split into snake_case words.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARD_NAME = '/(?:^|_)(?:pan|card_number|cvv|cvc|csc|expir[a-z]*|exp_month|exp_year|account_number|routing)(?:_|$)/';

	/**
	 * The translation functions describe() may not call: a label is a closure, translated when it is shown.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const TRANSLATIONS = array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x', 'translate' );

	/**
	 * The listeners each extension's main file added to the registration action, kept from its one inclusion.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<callable>>
	 */
	private static array $listeners = array();

	/**
	 * Finds the properties and parameters named like card data.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway     The gateway.
	 * @param string[]       $sourceRoots The directories of the gateway's own source.
	 * @return list<string> Each finding as "Class::$name" or "Class::method( $name )"; none when there is none.
	 */
	private static function cardFields( PaymentGateway $gateway, array $sourceRoots ): array {
		$classes = array_merge( self::classesIn( dirname( __DIR__, 3 ) . '/src/Contracts/Payment' ), self::hierarchyOf( get_class( $gateway ) ) );

		foreach ( $sourceRoots as $root ) {
			$classes = array_merge( $classes, self::classesIn( $root ) );
		}

		$findings = array();

		foreach ( array_values( array_unique( $classes ) ) as $class ) {
			$findings = array_merge( $findings, self::cardFieldsOf( new \ReflectionClass( $class ) ) );
		}

		return $findings;
	}

	/**
	 * Returns rule 4's failure for a gateway: the names that read as card data, or null when none does.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway     The gateway.
	 * @param string[]       $sourceRoots The directories of the gateway's own source.
	 * @return string|null The failure, beginning with the rule's message; null when the gateway passes.
	 */
	public static function cardFieldsFailure( PaymentGateway $gateway, array $sourceRoots ): ?string {
		$findings = self::cardFields( $gateway, $sourceRoots );

		return array() === $findings ? null : Rule::NoCardFields->message() . 'these are named like card or bank account data, which never reaches the plugin: ' . implode( ', ', $findings ) . '.';
	}

	/**
	 * Returns rule 15's failure for a gateway: what describe() did that a declaration may not, or null; Brain Monkey must be set up.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 * @return string|null The failure, beginning with the rule's message; null when the gateway passes.
	 */
	public static function describeFailure( PaymentGateway $gateway ): ?string {
		$why = self::describeIsData( $gateway );

		return null === $why ? null : Rule::DescribeIsData->message() . $why;
	}

	/**
	 * Calls describe() and says what WordPress function it called, if any; Brain Monkey must be set up.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 * @return string|null Why describe() is not data; null when it is.
	 */
	private static function describeIsData( PaymentGateway $gateway ): ?string {
		foreach ( self::TRANSLATIONS as $function ) {
			Functions\when( $function )->alias(
				static function () use ( $function ): never {
					throw new \LogicException( sprintf( 'describe() called %s(), a WordPress function: a label is a closure, translated only when it is shown.', $function ) );
				}
			);
		}

		$hooks = self::hookCount();

		try {
			$gateway->describe();
		} catch ( \Error $error ) {
			// PHP names an unqualified call by the caller's namespace first: the function's own name is the last part.
			return 1 === preg_match( '/^Call to undefined function (?:[^(]*\\\\)?([^(\\\\]+)\(\)/', $error->getMessage(), $match )
				? sprintf( 'describe() called %s(), a WordPress function: a declaration reads nothing and calls nothing.', $match[1] )
				: sprintf( 'describe() failed outside WordPress: %1$s: %2$s', get_class( $error ), $error->getMessage() );
		} catch ( \Throwable $failure ) {
			return $failure instanceof \LogicException && str_starts_with( $failure->getMessage(), 'describe() called' )
				? $failure->getMessage()
				: sprintf( 'describe() threw %1$s: %2$s', get_class( $failure ), $failure->getMessage() );
		}

		return self::hookCount() === $hooks ? null : 'describe() added, removed or ran a WordPress hook: a declaration reads nothing and calls nothing.';
	}

	/**
	 * Returns the gateways an extension registers, read outside WordPress; Brain Monkey must be set up.
	 *
	 * Its main file is included once in the process, as WordPress would include it; the listeners
	 * it adds to the registration action are kept and run against a DeclarationRegistry.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The extension's root directory.
	 * @return list<PaymentGateway> The gateways.
	 */
	public static function extensionGateways( string $root ): array {
		$root = rtrim( $root, '/' );

		if ( ! isset( self::$listeners[ $root ] ) ) {
			$listeners = array();

			Actions\expectAdded( GatewayRegistry::ACTION )->zeroOrMoreTimes()->whenHappen(
				static function ( callable $listener ) use ( &$listeners ): void {
					$listeners[] = $listener;
				}
			);

			require_once $root . '/' . PluginPackage::at( $root )->mainFile;

			self::$listeners[ $root ] = $listeners;
		}

		return DeclarationRegistry::registeredBy( ...self::$listeners[ $root ] );
	}

	/**
	 * Lists the classes, interfaces, traits and enums declared in the PHP files under a directory, loading each.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The directory.
	 * @return list<class-string> The declared names.
	 */
	private static function classesIn( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$classes = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( self::declaredIn( (string) file_get_contents( $file->getPathname() ) ) as $class ) {
				if ( class_exists( $class ) || interface_exists( $class ) || trait_exists( $class ) || enum_exists( $class ) ) {
					$classes[] = $class;
				}
			}
		}

		sort( $classes, SORT_STRING );

		return $classes;
	}

	/**
	 * Reads the names a PHP source declares.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source The source.
	 * @return list<string> The fully qualified names.
	 */
	private static function declaredIn( string $source ): array {
		$tokens    = token_get_all( $source );
		$namespace = '';
		$names     = array();
		$count     = count( $tokens );

		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_NAMESPACE === $token[0] ) {
				$namespace = self::nextName( $tokens, $index, array( T_NAME_QUALIFIED, T_STRING ) );
			} elseif ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) && ! self::isClassConstant( $tokens, $index ) ) {
				$name = self::nextName( $tokens, $index, array( T_STRING ) );

				if ( '' !== $name ) {
					$names[] = ( '' === $namespace ? '' : $namespace . '\\' ) . $name;
				}
			}
		}

		return $names;
	}

	/**
	 * Returns the first token's text of the given kinds after an index, skipping white space.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $tokens The tokens.
	 * @param int          $index  The index to start after.
	 * @param array        $kinds  The token kinds sought.
	 * @return string The text; empty when the next meaningful token is another kind, as after `new class`.
	 *
	 * @phpstan-param list<int|string> $kinds
	 */
	private static function nextName( array $tokens, int $index, array $kinds ): string {
		$count = count( $tokens );

		for ( $next = $index + 1; $next < $count; $next++ ) {
			$token = $tokens[ $next ];

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			return is_array( $token ) && in_array( $token[0], $kinds, true ) ? $token[1] : '';
		}

		return '';
	}

	/**
	 * Tells whether a `class` token is the `::class` of a name rather than a declaration.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $tokens The tokens.
	 * @param int          $index  The token's index.
	 * @return bool True after `::`.
	 */
	private static function isClassConstant( array $tokens, int $index ): bool {
		for ( $previous = $index - 1; $previous >= 0; $previous-- ) {
			$token = $tokens[ $previous ];

			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}

			return is_array( $token ) && T_DOUBLE_COLON === $token[0];
		}

		return false;
	}

	/**
	 * Returns a class with its parents and the traits of each.
	 *
	 * @since 0.2.0
	 *
	 * @param class-string $name The class.
	 * @return list<class-string> The class, its parents and their traits.
	 */
	private static function hierarchyOf( string $name ): array {
		$classes = array();

		for ( $reflection = new \ReflectionClass( $name ); false !== $reflection; $reflection = $reflection->getParentClass() ) {
			$classes[] = $reflection->getName();

			foreach ( $reflection->getTraitNames() as $trait ) {
				$classes[] = $trait;
			}
		}

		return $classes;
	}

	/**
	 * Finds the card-like names among a class's own properties and its own methods' parameters.
	 *
	 * @since 0.2.0
	 *
	 * @param \ReflectionClass $reflection The class.
	 * @return list<string> The findings.
	 *
	 * @phpstan-param \ReflectionClass<object> $reflection
	 */
	private static function cardFieldsOf( \ReflectionClass $reflection ): array {
		$findings = array();

		foreach ( $reflection->getProperties() as $property ) {
			if ( $property->getDeclaringClass()->getName() === $reflection->getName() && self::readsAsCardData( $property->getName() ) ) {
				$findings[] = sprintf( '%1$s::$%2$s', $reflection->getName(), $property->getName() );
			}
		}

		foreach ( $reflection->getMethods() as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
				continue;
			}

			foreach ( $method->getParameters() as $parameter ) {
				if ( self::readsAsCardData( $parameter->getName() ) ) {
					$findings[] = sprintf( '%1$s::%2$s( $%3$s )', $reflection->getName(), $method->getName(), $parameter->getName() );
				}
			}
		}

		return $findings;
	}

	/**
	 * Tells whether a name reads as card or bank account data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name A property's or parameter's name, snake_case or camelCase.
	 * @return bool True when one of its words does.
	 */
	private static function readsAsCardData( string $name ): bool {
		return 1 === preg_match( self::CARD_NAME, strtolower( (string) preg_replace( '/(?<=[a-z0-9])[A-Z]/', '_$0', $name ) ) );
	}

	/**
	 * Counts the hooks Brain Monkey saw added or run so far.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private static function hookCount(): int {
		$storage = Container::instance()->hookStorage();
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- The closure is bound to the storage, whose own $this it reads.
		$read = \Closure::bind( fn(): array => $this->storage, $storage, HookStorage::class );

		return count( $read(), COUNT_RECURSIVE );
	}
}
