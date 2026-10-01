<?php
/**
 * Tests that an order's access key is compared with its hash in constant time, and nowhere else
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * A presented key meets the stored hash only in AccessKeys::verify(), which compares in constant time.
 *
 * Comparing a key with `===`, or hashing it and comparing the two hashes, still refuses a wrong
 * key, so every behavioural test stays green; what it loses is the constant time no behavioural
 * test can see. So this test reads the source:
 *
 * - every implementation of AccessKeys::verify() under src/ compares through hash_equals() or
 *   wp_verify_fast_hash(), and its body has no comparison operator and calls no string
 *   comparison function;
 * - in OrderAccessPolicy, the presented key and the stored hash are only declared as parameters,
 *   compared with null, passed whole to AccessKeys::verify(), or passed whole to one of the
 *   policy's own methods, whose parameters are read by the same rule. Nothing else reads them.
 *
 * Planted violations, each shown red and removed:
 * - in WordPressAccessKeys::verify(), return `wp_fast_hash( $raw ) === $hash`;
 * - in OrderAccessPolicy::keyOpens(), compare `$this->keys->hash( $key ) === $access->accessKeyHash`
 *   instead of calling verify().
 *
 * @since 0.1.0
 */
final class AccessKeyComparisonTest extends TestCase {

	/**
	 * The policy whose use of the key is read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const POLICY = 'src/Order/Application/OrderAccessPolicy.php';

	/**
	 * The functions that compare a key with its hash in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CONSTANT_TIME = array( 'hash_equals', 'wp_verify_fast_hash' );

	/**
	 * The string comparison functions, which stop at the first difference.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const STRING_COMPARISONS = array( 'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'strnatcmp', 'strnatcasecmp', 'substr_compare', 'strcoll' );

	/**
	 * The comparison operators.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int|string>
	 */
	private const OPERATORS = array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_SPACESHIP, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, '<', '>' );

	/**
	 * The names that carry the key or its hash in the policy: the presented key and the stored hash.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const KEY_NAMES = array( '$key', 'accessKeyHash' );

	/**
	 * Tests that every implementation of AccessKeys::verify() compares in constant time.
	 *
	 * @since 0.1.0
	 */
	public function test_every_access_key_check_compares_in_constant_time(): void {
		$bodies = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			$body = self::verifyBodyOfAccessKeys( $source );

			if ( null !== $body ) {
				$bodies[ $file ] = $body;
			}
		}

		$this->assertArrayHasKey( 'src/Order/Infrastructure/WordPressAccessKeys.php', $bodies, 'The scan did not find the production implementation, so a clean result would prove nothing.' );

		foreach ( $bodies as $file => $body ) {
			$this->assertSame( array(), self::nonConstantTime( $body ), "{$file}: AccessKeys::verify() must compare through hash_equals() or wp_verify_fast_hash(), and nothing else." );
		}
	}

	/**
	 * Tests that the policy hands the key and its hash to AccessKeys::verify() and reads them no other way.
	 *
	 * @since 0.1.0
	 */
	public function test_the_policy_reads_the_key_and_its_hash_only_to_verify_them(): void {
		$files = PhpSource::files( 'src/Order/Application' );

		$this->assertArrayHasKey( self::POLICY, $files, 'The scan did not find the policy, so a clean result would prove nothing.' );
		$this->assertSame( array(), self::misuses( $files[ self::POLICY ] ), 'OrderAccessPolicy reads the key or its hash outside AccessKeys::verify(): compare them only there.' );
	}

	/**
	 * Tests that the two scans find each shape they look for, so a clean result means something.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scans_find_what_they_are_shown(): void {
		$implementation = static fn( string $body ): string => '<?php use SEOCart\Order\Domain\AccessKeys; final class K implements AccessKeys { public function verify( string $raw, string $hash ): bool { ' . $body . ' } }';

		$this->assertSame( array(), self::nonConstantTime( (array) self::verifyBodyOfAccessKeys( $implementation( 'return wp_verify_fast_hash( $raw, $hash );' ) ) ) );
		$this->assertSame( array(), self::nonConstantTime( (array) self::verifyBodyOfAccessKeys( $implementation( 'return hash_equals( $hash, hash( \'sha256\', $raw ) );' ) ) ) );
		$this->assertNotSame( array(), self::nonConstantTime( (array) self::verifyBodyOfAccessKeys( $implementation( 'return wp_fast_hash( $raw ) === $hash;' ) ) ) );
		$this->assertNotSame( array(), self::nonConstantTime( (array) self::verifyBodyOfAccessKeys( $implementation( 'return 0 === strcmp( wp_fast_hash( $raw ), $hash );' ) ) ) );
		$this->assertNotSame( array(), self::nonConstantTime( (array) self::verifyBodyOfAccessKeys( $implementation( 'return hash_equals( $hash, wp_fast_hash( $raw ) ) || $raw === $hash;' ) ) ) );
		$this->assertNull( self::verifyBodyOfAccessKeys( '<?php final class K { public function verify( string $raw, string $hash ): bool { return $raw === $hash; } }' ), 'A class that is not an AccessKeys is not read.' );

		$policy = static fn( string $body ): string => '<?php final class P { private function keyOpens( OrderAccess $access, ?string $key ): bool { ' . $body . ' } }';

		$this->assertSame( array(), self::misuses( $policy( 'return null !== $key && null !== $access->accessKeyHash && $this->keys->verify( $key, $access->accessKeyHash ) && $this->other( $key );' ) ) );
		$this->assertSame( array( 'line 1: $key', 'line 1: accessKeyHash' ), self::misuses( $policy( 'return $this->keys->hash( $key ) === $access->accessKeyHash;' ) ) );
		$this->assertSame( array( 'line 1: $key' ), self::misuses( $policy( 'return $key === \'secret\';' ) ) );
		$this->assertSame( array( 'line 1: $key' ), self::misuses( $policy( 'return 0 === strcmp( $key, $other );' ) ) );
		$this->assertSame( array( 'line 1: $key' ), self::misuses( $policy( 'return $this->keys->verify( $key . \'\', $access->accessKeyHash );' ) ) );
		$this->assertSame( array( 'line 1: $key' ), self::misuses( $policy( '$copy = $key; return $this->keys->verify( $copy, $access->accessKeyHash );' ) ) );
	}

	/**
	 * Returns the body of verify() in a class that implements AccessKeys, as tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source.
	 * @return list<\PhpToken>|null The tokens between the method's braces, or null when the source declares no such method.
	 */
	private static function verifyBodyOfAccessKeys( string $source ): ?array {
		$tokens = PhpSource::tokens( $source );

		if ( ! self::implementsAccessKeys( $tokens ) ) {
			return null;
		}

		foreach ( $tokens as $index => $token ) {
			if ( ! $token->is( T_FUNCTION ) || 'verify' !== ( $tokens[ $index + 1 ]->text ?? null ) ) {
				continue;
			}

			$open = $index;

			while ( isset( $tokens[ $open ] ) && '{' !== $tokens[ $open ]->text ) {
				++$open;
			}

			return array_slice( $tokens, $open + 1, self::closing( $tokens, $open ) - $open - 1 );
		}

		return null;
	}

	/**
	 * Tells whether a file declares a class that implements AccessKeys.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @return bool True when an `implements` list names AccessKeys, as PHP resolves the name.
	 */
	private static function implementsAccessKeys( array $tokens ): bool {
		$namespace = PhpSource::namespaceOf( $tokens );
		$imports   = PhpSource::importsOf( $tokens );

		foreach ( $tokens as $index => $token ) {
			if ( ! $token->is( T_IMPLEMENTS ) ) {
				continue;
			}

			for ( $next = $index + 1; isset( $tokens[ $next ] ) && '{' !== $tokens[ $next ]->text; $next++ ) {
				if ( $tokens[ $next ]->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) ) && AccessKeys::class === PhpSource::resolve( $tokens[ $next ]->text, $namespace, $imports ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Lists what makes a body of verify() compare in other than constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $body The body's tokens.
	 * @return list<string> One entry per finding; empty when the body compares only in constant time.
	 */
	private static function nonConstantTime( array $body ): array {
		$found = array();
		$calls = array();

		foreach ( $body as $index => $token ) {
			if ( $token->is( self::OPERATORS ) ) {
				$found[] = "line {$token->line}: the operator {$token->text}";
			}

			if ( $token->is( T_STRING ) && '(' === ( $body[ $index + 1 ]->text ?? null ) ) {
				$calls[] = strtolower( $token->text );
			}
		}

		foreach ( array_intersect( $calls, self::STRING_COMPARISONS ) as $call ) {
			$found[] = "the string comparison {$call}()";
		}

		if ( array() === array_intersect( $calls, self::CONSTANT_TIME ) ) {
			$found[] = 'no call of hash_equals() or wp_verify_fast_hash()';
		}

		return $found;
	}

	/**
	 * Lists every place a source reads the presented key or the stored hash other than as the rule allows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source.
	 * @return list<string> One entry per misuse: its line and the name read.
	 */
	private static function misuses( string $source ): array {
		$tokens = PhpSource::tokens( $source );
		$found  = array();

		foreach ( $tokens as $index => $token ) {
			$start = self::operandStart( $tokens, $index );

			if ( null === $start ) {
				continue;
			}

			if ( self::isParameter( $tokens, $start ) || self::isComparedWithNull( $tokens, $start, $index ) || self::isWholeArgumentOfAllowedCall( $tokens, $start, $index ) ) {
				continue;
			}

			$found[] = "line {$token->line}: {$token->text}";
		}

		return $found;
	}

	/**
	 * Returns where the operand that reads the key or its hash starts, when a token is one.
	 *
	 * The presented key is the variable `$key`; the stored hash is a property `accessKeyHash`,
	 * whose operand starts at the variable it is read from.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $index  The token.
	 * @return int|null The index of the operand's first token, or null when the token reads neither.
	 */
	private static function operandStart( array $tokens, int $index ): ?int {
		$token = $tokens[ $index ];

		if ( $token->is( T_VARIABLE ) && in_array( $token->text, self::KEY_NAMES, true ) ) {
			return $index;
		}

		if ( ! $token->is( T_STRING ) || ! in_array( $token->text, self::KEY_NAMES, true ) ) {
			return null;
		}

		$operator = $tokens[ $index - 1 ] ?? null;
		$receiver = $tokens[ $index - 2 ] ?? null;

		if ( null === $operator || null === $receiver || ! $operator->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ) ) ) {
			return null;
		}

		return $receiver->is( T_VARIABLE ) ? $index - 2 : $index - 1;
	}

	/**
	 * Tells whether an operand is a parameter of the function or closure being declared.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $start  The operand's first token.
	 * @return bool True when its enclosing parenthesis opens a declaration's parameter list.
	 */
	private static function isParameter( array $tokens, int $start ): bool {
		$open = self::enclosingParenthesis( $tokens, $start );

		if ( null === $open ) {
			return false;
		}

		$before = $tokens[ $open - 1 ] ?? null;

		return null !== $before && ( $before->is( array( T_FUNCTION, T_FN ) ) || ( $before->is( T_STRING ) && ( $tokens[ $open - 2 ] ?? null )?->is( T_FUNCTION ) ) );
	}

	/**
	 * Tells whether an operand is compared with null, on either side of `===` or `!==`.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $start  The operand's first token.
	 * @param int         $end    The operand's last token.
	 * @return bool True for `null === operand`, `null !== operand`, `operand === null` or `operand !== null`.
	 */
	private static function isComparedWithNull( array $tokens, int $start, int $end ): bool {
		$identity = array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL );
		$isNull   = static fn( ?\PhpToken $token ): bool => null !== $token && 'null' === strtolower( $token->text );

		$before = $tokens[ $start - 1 ] ?? null;
		$after  = $tokens[ $end + 1 ] ?? null;

		return ( null !== $before && $before->is( $identity ) && $isNull( $tokens[ $start - 2 ] ?? null ) )
			|| ( null !== $after && $after->is( $identity ) && $isNull( $tokens[ $end + 2 ] ?? null ) );
	}

	/**
	 * Tells whether an operand is a whole argument of AccessKeys::verify() or of one of the policy's own methods.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $start  The operand's first token.
	 * @param int         $end    The operand's last token.
	 * @return bool True when the operand alone fills an argument of `->verify(`, or of `$this->name(`, `self::name(` or `static::name(`.
	 */
	private static function isWholeArgumentOfAllowedCall( array $tokens, int $start, int $end ): bool {
		$open = self::enclosingParenthesis( $tokens, $start );

		if ( null === $open || ! in_array( $tokens[ $start - 1 ]->text, array( '(', ',' ), true ) || ! in_array( $tokens[ $end + 1 ]->text ?? '', array( ')', ',' ), true ) ) {
			return false;
		}

		$name     = $tokens[ $open - 1 ] ?? null;
		$operator = $tokens[ $open - 2 ] ?? null;
		$receiver = $tokens[ $open - 3 ] ?? null;

		if ( null === $name || null === $operator || null === $receiver || ! $name->is( T_STRING ) || ! $operator->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ) ) ) {
			return false;
		}

		return 'verify' === $name->text || in_array( strtolower( $receiver->text ), array( '$this', 'self', 'static' ), true );
	}

	/**
	 * Returns the opening parenthesis an operand stands inside, when it stands inside one.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $index  The operand's first token.
	 * @return int|null The index of the nearest unmatched `(` before it, or null at the top level of a statement.
	 */
	private static function enclosingParenthesis( array $tokens, int $index ): ?int {
		$depth = 0;

		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$text = $tokens[ $i ]->text;

			if ( ';' === $text || '{' === $text || '}' === $text ) {
				return null;
			}

			if ( ')' === $text ) {
				++$depth;
			} elseif ( '(' === $text ) {
				if ( 0 === $depth ) {
					return $i;
				}

				--$depth;
			}
		}

		return null;
	}

	/**
	 * Returns the brace that closes the one at an index.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @param int         $open   The index of an opening brace.
	 * @return int The index of its closing brace, or the last index when it is never closed.
	 */
	private static function closing( array $tokens, int $open ): int {
		$depth = 0;

		foreach ( array_slice( $tokens, $open, null, true ) as $index => $token ) {
			if ( '{' === $token->text || $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				++$depth;
			} elseif ( '}' === $token->text && 0 === --$depth ) {
				return $index;
			}
		}

		return count( $tokens ) - 1;
	}
}
