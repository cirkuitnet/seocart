<?php
/**
 * Sniff: no hand-written JSON-Schema outside the schema module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\phpcs\SEOCart\Sniffs\DRY;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use SEOCart\Tools\phpcs\SEOCart\Helpers\PathScope;

/**
 * A syntax tripwire for the obvious array-literal spelling of DRY rule 2.
 *
 * A field is declared as a `FieldSpec` and compiled into each dialect (REST arguments, REST
 * response, Ability, OpenAPI, WP-CLI synopsis, admin form). A schema array typed by hand is
 * a second declaration of the same field, so it is an error everywhere except in the
 * directories listed in `$allowedPaths`.
 *
 * Two shapes are reported, both only as items of an array literal with a plain string key:
 *
 * - `Type`: the key `type` whose value is a JSON-Schema type name, or a list made only of
 *   them. `'type' => 'error'` (an admin notice) and `'type' => 'NUMERIC'` (a meta query) are
 *   ordinary WordPress arrays and are not reported.
 * - `Keyword`: a key that belongs to the JSON-Schema vocabulary and means nothing else in
 *   WordPress, whatever its value.
 *
 * `properties`, `items`, `required`, `default`, `format`, `pattern`, `minimum` and `maximum`
 * are schema keywords too, but they are also everyday array keys (line items, menu items,
 * form fields), so they are not reported on their own. A schema that uses them is still
 * caught, because every node beneath them carries a `type`.
 *
 * Out of reach, because the sniff reads array literals and resolves nothing: a schema built
 * by offset assignment (`$schema['type'] = 'string';`) and a type given as a constant
 * (`'type' => self::TYPE_STRING`).
 *
 * @since 0.1.0
 */
final class SchemaLiteralSniff implements Sniff {

	/**
	 * The values of the JSON-Schema `type` keyword.
	 *
	 * @since 0.1.0
	 * @var array<string, true>
	 */
	private const TYPE_NAMES = array(
		'string'  => true,
		'integer' => true,
		'number'  => true,
		'boolean' => true,
		'object'  => true,
		'array'   => true,
		'null'    => true,
	);

	/**
	 * JSON-Schema keywords that have no other meaning as a WordPress array key.
	 *
	 * @since 0.1.0
	 * @var array<string, true>
	 */
	private const KEYWORDS = array(
		'$schema'              => true,
		'$ref'                 => true,
		'enum'                 => true,
		'oneOf'                => true,
		'anyOf'                => true,
		'additionalProperties' => true,
		'patternProperties'    => true,
		'minProperties'        => true,
		'maxProperties'        => true,
		'minItems'             => true,
		'maxItems'             => true,
		'uniqueItems'          => true,
		'minLength'            => true,
		'maxLength'            => true,
		'multipleOf'           => true,
		'exclusiveMinimum'     => true,
		'exclusiveMaximum'     => true,
	);

	/**
	 * The shipped code this rule governs. Set once for every DRY sniff, in ruleset.xml.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $shippedPaths = array();

	/**
	 * Directories whose files may spell out schema arrays. Set once, in ruleset.xml.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $allowedPaths = array();

	/**
	 * Returns the tokens this sniff listens for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_ARRAY, T_OPEN_SHORT_ARRAY );
	}

	/**
	 * Examines the direct items of one array literal. Nested arrays arrive as their own tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the array token.
	 * @return int|void The position to resume at, when the rest of the file can be skipped.
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		if ( ! PathScope::isUnder( $phpcsFile, $this->shippedPaths ) || PathScope::isUnder( $phpcsFile, $this->allowedPaths ) ) {
			return $phpcsFile->numTokens;
		}

		$tokens = $phpcsFile->getTokens();
		$bounds = $this->arrayBounds( $tokens, $stackPtr );

		if ( null === $bounds || $this->isDestructuring( $phpcsFile, $stackPtr, $bounds[1] ) ) {
			return;
		}

		list( $opener, $closer ) = $bounds;

		for ( $i = $opener + 1; $i < $closer; $i++ ) {
			// Step over anything bracketed: nested arrays, call arguments, closure and match bodies.
			if ( isset( $tokens[ $i ]['bracket_closer'] ) && $tokens[ $i ]['bracket_opener'] === $i ) {
				$i = $tokens[ $i ]['bracket_closer'];
			} elseif ( isset( $tokens[ $i ]['parenthesis_closer'] ) && $tokens[ $i ]['parenthesis_opener'] === $i ) {
				$i = $tokens[ $i ]['parenthesis_closer'];
			} elseif ( T_DOUBLE_ARROW === $tokens[ $i ]['code'] ) {
				$this->processItem( $phpcsFile, $i, $opener, $closer );
			}
		}
	}

	/**
	 * Reports one `key => value` item when it is a schema literal.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $arrow     Position of the item's double arrow.
	 * @param int  $opener    Position of the bracket that opens the array.
	 * @param int  $closer    Position of the bracket that closes the array.
	 */
	private function processItem( File $phpcsFile, int $arrow, int $opener, int $closer ): void {
		$tokens = $phpcsFile->getTokens();
		$keyPtr = $phpcsFile->findPrevious( Tokens::$emptyTokens, $arrow - 1, $opener, true );

		if ( false === $keyPtr || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $keyPtr ]['code'] ) {
			return;
		}

		// The key must be the whole string literal, not the tail of an expression.
		$beforeKey = $phpcsFile->findPrevious( Tokens::$emptyTokens, $keyPtr - 1, $opener, true );

		if ( false === $beforeKey || ( $beforeKey !== $opener && T_COMMA !== $tokens[ $beforeKey ]['code'] ) ) {
			return;
		}

		$key = substr( $tokens[ $keyPtr ]['content'], 1, -1 );

		if ( isset( self::KEYWORDS[ $key ] ) ) {
			$phpcsFile->addError(
				'Hand-written JSON-Schema: the "%s" keyword. Declare the field once as a FieldSpec and compile it. Schema arrays may be spelled out only under: %s',
				$keyPtr,
				'Keyword',
				array( $key, PathScope::describe( $this->allowedPaths ) )
			);

			return;
		}

		if ( 'type' !== $key ) {
			return;
		}

		$valuePtr = $phpcsFile->findNext( Tokens::$emptyTokens, $arrow + 1, $closer, true );

		if ( false === $valuePtr || ! $this->isSchemaType( $phpcsFile, $valuePtr, $closer ) ) {
			return;
		}

		$phpcsFile->addError(
			'Hand-written JSON-Schema: "type" set to a JSON-Schema type name. Declare the field once as a FieldSpec and compile it. Schema arrays may be spelled out only under: %s',
			$keyPtr,
			'Type',
			array( PathScope::describe( $this->allowedPaths ) )
		);
	}

	/**
	 * Determines whether an item's value is a JSON-Schema type name or a list made only of them.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $valuePtr  Position of the first token of the value.
	 * @param int  $closer    Position of the bracket that closes the array the item belongs to.
	 * @return bool True when the value is nothing but schema type names.
	 */
	private function isSchemaType( File $phpcsFile, int $valuePtr, int $closer ): bool {
		$tokens = $phpcsFile->getTokens();

		if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $valuePtr ]['code'] ) {
			// The literal must be the whole value: followed by the next item or the end of the array.
			$after = $phpcsFile->findNext( Tokens::$emptyTokens, $valuePtr + 1, $closer + 1, true );

			return $this->isTypeName( $tokens[ $valuePtr ]['content'] )
				&& false !== $after
				&& ( $after === $closer || T_COMMA === $tokens[ $after ]['code'] );
		}

		$bounds = $this->arrayBounds( $tokens, $valuePtr );

		if ( null === $bounds ) {
			return false;
		}

		$names = 0;

		for ( $i = $bounds[0] + 1; $i < $bounds[1]; $i++ ) {
			$code = $tokens[ $i ]['code'];

			if ( isset( Tokens::$emptyTokens[ $code ] ) || T_COMMA === $code ) {
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING !== $code || ! $this->isTypeName( $tokens[ $i ]['content'] ) ) {
				return false;
			}

			++$names;
		}

		return $names > 0;
	}

	/**
	 * Determines whether a quoted string literal is a JSON-Schema type name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $literal The token content, including its quotes.
	 * @return bool True for 'string', 'integer', 'number', 'boolean', 'object', 'array' and 'null'.
	 */
	private function isTypeName( string $literal ): bool {
		return isset( self::TYPE_NAMES[ substr( $literal, 1, -1 ) ] );
	}

	/**
	 * Finds the brackets of an array literal.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $tokens The file's token stack.
	 * @param int                              $ptr    Position of a possible array token.
	 * @return array{0: int, 1: int}|null The opener and closer positions, or null when the token
	 *                                    is not an array literal or the file is still being typed.
	 */
	private function arrayBounds( array $tokens, int $ptr ): ?array {
		if ( T_ARRAY === $tokens[ $ptr ]['code'] && isset( $tokens[ $ptr ]['parenthesis_opener'], $tokens[ $ptr ]['parenthesis_closer'] ) ) {
			return array( $tokens[ $ptr ]['parenthesis_opener'], $tokens[ $ptr ]['parenthesis_closer'] );
		}

		if ( T_OPEN_SHORT_ARRAY === $tokens[ $ptr ]['code'] && isset( $tokens[ $ptr ]['bracket_closer'] ) ) {
			return array( $ptr, $tokens[ $ptr ]['bracket_closer'] );
		}

		return null;
	}

	/**
	 * Determines whether an array literal is a destructuring target, which reads a schema
	 * instead of declaring one: `[ 'enum' => $allowed ] = $schema`, whatever follows `as` in
	 * a `foreach`, and every array nested in either of them.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the array token.
	 * @param int  $closer    Position of the bracket that closes the array.
	 * @return bool True when values are assigned into the array's items.
	 */
	private function isDestructuring( File $phpcsFile, int $stackPtr, int $closer ): bool {
		$tokens = $phpcsFile->getTokens();
		$after  = $phpcsFile->findNext( Tokens::$emptyTokens, $closer + 1, null, true );

		if ( false !== $after && T_EQUAL === $tokens[ $after ]['code'] ) {
			return true;
		}

		// Walk back to whatever encloses the array, stepping over anything bracketed on the way.
		$followsAs = false;

		for ( $i = $stackPtr - 1; $i > 0; $i-- ) {
			$token = $tokens[ $i ];

			if ( isset( $token['bracket_opener'] ) && $token['bracket_closer'] === $i ) {
				$i = $token['bracket_opener'];
			} elseif ( isset( $token['parenthesis_opener'] ) && $token['parenthesis_closer'] === $i ) {
				$i = $token['parenthesis_opener'];
			} elseif ( T_AS === $token['code'] ) {
				$followsAs = true;
			} elseif ( T_OPEN_SHORT_ARRAY === $token['code'] ) {
				return $this->isDestructuring( $phpcsFile, $i, $token['bracket_closer'] );
			} elseif ( T_OPEN_PARENTHESIS === $token['code'] ) {
				return $followsAs && isset( $token['parenthesis_owner'] ) && T_FOREACH === $tokens[ $token['parenthesis_owner'] ]['code'];
			} elseif ( in_array( $token['code'], array( T_SEMICOLON, T_OPEN_CURLY_BRACKET, T_OPEN_SQUARE_BRACKET, T_OPEN_TAG ), true ) ) {
				return false;
			}
		}

		return false;
	}
}
