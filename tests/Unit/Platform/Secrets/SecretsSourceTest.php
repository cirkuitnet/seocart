<?php
/**
 * SecretsSourceTest: the rules the secrets code keeps, read from its source
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Secrets;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Four rules, checked over the tokens of the source:
 *
 * - SEOCART_ENCRYPTION_KEY is read in exactly one place in the plugin, EncryptionKey::fromEnvironment():
 *   nowhere else is the constant used as a constant, in any spelling (`SEOCART_ENCRYPTION_KEY`,
 *   `\SEOCART_ENCRYPTION_KEY`), written as a string on its own, or named through
 *   EncryptionKey::CONSTANT, however the class is written (imported, aliased or fully qualified)
 *   and whether or not the reference goes straight into constant(), \constant(), defined() or
 *   getenv(): a reference kept in a variable can reach a reader later. The search covers src/,
 *   seocart.php and uninstall.php.
 * - The secrets code derives nothing from WordPress's salts: no salt or key constant of
 *   wp-config.php, and no wp_salt() or wp_hash().
 * - It serializes nothing: no serialize(), unserialize(), maybe_serialize() or maybe_unserialize().
 * - It holds no floating-point number: no float literal, cast, conversion or type.
 *
 * A check that finds nothing to check fails, so an empty search cannot pass.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class SecretsSourceTest extends TestCase {

	/**
	 * The names that stand for WordPress's salts.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const SALTS = array( 'wp_salt', 'wp_hash', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/**
	 * The functions that serialize.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const SERIALIZERS = array( 'serialize', 'unserialize', 'maybe_serialize', 'maybe_unserialize' );

	/**
	 * Tests that SEOCART_ENCRYPTION_KEY is read in exactly one place.
	 *
	 * Planted violations, each in SecretsStatus::report(): add
	 * `$raw = defined( 'SEOCART_ENCRYPTION_KEY' ) ? constant( 'SEOCART_ENCRYPTION_KEY' ) : null;`; or add
	 * `$raw = \constant( EncryptionKey::CONSTANT );`. The search then finds a second place.
	 *
	 * @since 0.1.0
	 */
	public function test_the_encryption_key_is_read_in_exactly_one_place(): void {
		$sources = PhpSource::files( 'src' );

		foreach ( array( 'seocart.php', 'uninstall.php' ) as $file ) {
			$sources[ $file ] = (string) file_get_contents( PhpSource::root() . '/' . $file );
		}

		$reads = array();

		foreach ( $sources as $path => $source ) {
			foreach ( self::encryptionKeyReads( $path, PhpSource::tokens( $source ) ) as $read ) {
				$reads[] = $read;
			}
		}

		$this->assertSame( array( 'src/Platform/Secrets/EncryptionKey.php: fromEnvironment()' ), array_values( array_unique( $reads ) ), 'SEOCART_ENCRYPTION_KEY is read somewhere else than EncryptionKey::fromEnvironment().' );
		$this->assertSame( 'SEOCART_ENCRYPTION_KEY', EncryptionKey::CONSTANT );
	}

	/**
	 * Tests that the secrets code uses no salt, serializes nothing and holds no float.
	 *
	 * Planted violations: in Envelope::associatedData(), append `. wp_salt()`; in KeyMaterial::write(),
	 * wrap the material in `maybe_serialize()`; in Cipher, declare `private const RATIO = 0.5;`. Each
	 * is then named.
	 *
	 * @since 0.1.0
	 */
	public function test_the_secrets_code_uses_no_salt_no_serialization_and_no_float(): void {
		$sources = PhpSource::files( 'src/Platform/Secrets' );

		$sources['src/Platform/Settings/SecretSealer.php'] = (string) file_get_contents( PhpSource::root() . '/src/Platform/Settings/SecretSealer.php' );

		$this->assertGreaterThan( 10, count( $sources ), 'Too few files were read for the search to prove anything.' );

		$found = array();

		foreach ( $sources as $path => $source ) {
			foreach ( self::forbidden( PhpSource::tokens( $source ) ) as $what ) {
				$found[] = $path . ': ' . $what;
			}
		}

		$this->assertSame( array(), $found );
	}

	/**
	 * Lists the places a file reads SEOCART_ENCRYPTION_KEY, or names it, by function.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $path   The file's path.
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @return list<string> One entry per read: the path and the function it is in.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function encryptionKeyReads( string $path, array $tokens ): array {
		$reads     = array();
		$function  = '(file scope)';
		$is_class  = str_ends_with( $path, 'Secrets/EncryptionKey.php' );
		$namespace = PhpSource::namespaceOf( $tokens );
		$imports   = PhpSource::importsOf( $tokens );

		foreach ( $tokens as $index => $token ) {
			$previous = $tokens[ $index - 1 ] ?? null;
			$class    = $tokens[ $index - 2 ] ?? null;

			if ( $token->is( T_FUNCTION ) && isset( $tokens[ $index + 1 ] ) && $tokens[ $index + 1 ]->is( T_STRING ) ) {
				$function = $tokens[ $index + 1 ]->text . '()';
			}

			if ( $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) && EncryptionKey::CONSTANT === ltrim( $token->text, '\\' ) && ! ( null !== $previous && $previous->is( array( T_CONST, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ) ) ) ) {
				$reads[] = $path . ': ' . $function . ' uses the constant directly';
			} elseif ( $token->is( T_CONSTANT_ENCAPSED_STRING ) && EncryptionKey::CONSTANT === trim( $token->text, '\'"' ) && ! ( $is_class && null !== $class && 'CONSTANT' === $class->text ) ) {
				$reads[] = $path . ': ' . $function . ' names the constant in a string';
			} elseif ( $token->is( T_STRING ) && 'CONSTANT' === $token->text && null !== $previous && $previous->is( T_DOUBLE_COLON ) && null !== $class && self::isEncryptionKey( $class, $is_class, $namespace, $imports ) ) {
				$reads[] = $path . ': ' . $function;
			}
		}

		return $reads;
	}

	/**
	 * Tells whether the class a `::CONSTANT` reference is made on is EncryptionKey.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken             $name         The token before `::`.
	 * @param bool                  $is_class     Whether the file is EncryptionKey's own.
	 * @param string                $in_namespace The file's namespace.
	 * @param array<string, string> $imports      The file's class imports.
	 * @return bool True when the reference names EncryptionKey, as written or through self or static.
	 */
	private static function isEncryptionKey( \PhpToken $name, bool $is_class, string $in_namespace, array $imports ): bool {
		if ( in_array( strtolower( $name->text ), array( 'self', 'static' ), true ) ) {
			return $is_class;
		}

		return $name->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) ) && EncryptionKey::class === PhpSource::resolve( $name->text, $in_namespace, $imports );
	}

	/**
	 * Lists the salts, serializers and floats a file's tokens hold.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The tokens.
	 * @return list<string> What was found, one entry each.
	 *
	 * @phpstan-param list<\PhpToken> $tokens
	 */
	private static function forbidden( array $tokens ): array {
		$found = array();

		foreach ( $tokens as $index => $token ) {
			$called = $token->is( T_STRING ) && isset( $tokens[ $index + 1 ] ) && '(' === $tokens[ $index + 1 ]->text;
			$name   = ltrim( $token->text, '\\' );

			if ( $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) && in_array( $name, self::SALTS, true ) ) {
				$found[] = 'the salt ' . $name;
			} elseif ( ( $called || $token->is( T_NAME_FULLY_QUALIFIED ) ) && in_array( strtolower( $name ), self::SERIALIZERS, true ) ) {
				$found[] = 'the serializer ' . $name . '()';
			} elseif ( $token->is( array( T_DNUMBER, T_DOUBLE_CAST ) ) ) {
				$found[] = 'the float ' . $token->text;
			} elseif ( $token->is( T_STRING ) && in_array( strtolower( $token->text ), array( 'float', 'floatval', 'doubleval' ), true ) ) {
				$found[] = 'the float ' . $token->text;
			}
		}

		return $found;
	}
}
