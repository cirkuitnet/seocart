<?php
/**
 * ReadInventory: the SELECT statements a module's source writes, found without running it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * The reads a module can send, so that the query-plan run can prove it sent, and judged, each one.
 *
 * Owns one fact: which reads a module's source holds. A class constant under the module's
 * directory whose value begins with SELECT, or an array constant holding such values at any
 * depth, is one read per value, and the value is read from the class itself, evaluated, so a
 * statement built from other constants is read whole; the SELECT an INSERT ... SELECT constant ends
 * in is the read, and a constant that ends at IN takes a placeholder list. Any other run of string literals joined
 * by dots, folded into one text, that begins with SELECT is one read, and the statement is
 * followed to its end. A read is matched by its complete shape: nothing may follow it, so a
 * read whose last part is chosen at run time cannot hide behind the part that is written. Its
 * table tokens, `%i` or a `{name}` the statement expands, stand for any table.
 *
 * The only parts chosen at run time that a statement may have are named in completions(): the
 * placeholder list of an IN clause, which matches whatever its length; a variable or a ternary
 * that holds one of two literals or constants, and a parameter whose values the class's own calls
 * pass as literals or constants, which give the read one shape for each, every one of which the
 * run must send. Anything else joined to a SELECT, or a SELECT handed to sprintf() or
 * written after something that is not a literal, is refused by name, whether or not it names
 * its table, because a read it does not see is a read nobody judges. A class whose constants
 * cannot be evaluated, a SELECT that starts an interpolated string or a heredoc, and a statement
 * written as a WITH or as a UNION of SELECTs, are refused for the same reason. An
 * INSERT ... SELECT is a read through its SELECT, written in a method or in a constant.
 *
 * Only the PHP files of the directory are read, and a file that includes another, or a file that
 * is not PHP, is refused, so that every read the module can send is in a file that was read. A
 * parent class or a trait written outside the directory would carry reads the inventory never
 * sees; ClassDependencies finds them, and a test pins the ones there are.
 *
 * A variable a statement is joined to is read from its one assignment, directly in the function's
 * body; assigned in a block, or more than once, it is refused.
 *
 * A statement assigned to a variable that its function, or its file when it is written outside
 * any function, goes on to give a value again (a keyset page extended with `.=`, a clause added by
 * an `if`, the variable assigned in both branches of an `if`) is built in steps that no reading of
 * the source can follow whole, so it is refused by name, in any class: write it as complete
 * statements, constants or literals. The limits below say what this does not see.
 *
 * A constant that holds a SELECT is a read of its own, sent whole. A method that joins it to
 * anything with a dot, or hands it to implode() or sprintf(), builds a statement no constant holds,
 * and is refused by name as well. The constant may be the class's own (`self::`, `static::`) or
 * another class's of the module, written in full, imported, or with a prefix; one that stops at IN
 * may be followed by the placeholder list of its IN clause. Passing it whole, to a call, is its use.
 *
 * Known limits of the reader: a call that the scan of a method does not recognise (one from a
 * trait the class uses, `$that = $this` then `$that->m()`, `call_user_func( array( $this, 'm' ) )`)
 * adds no shape to the parameter it passes; `static::CONSTANT` in a non-final base class is
 * evaluated against the base, so a subclass's constant adds no shape; the constants of an
 * anonymous class are not read; and a SELECT written as a parenthesised literal, or starting with
 * a comment or a parenthesis, is neither inventoried nor refused, because the refusal of a
 * compound statement is narrowed to a WITH and a parenthesised UNION: the fragment constants
 * UNAPPLIED_RESULT, DECLINED_REFUNDS and OPEN_CLAIM legitimately hold a SELECT. A SELECT constant
 * gathered in a variable or an array first (`$parts = array( self::A, self::B )` and later
 * `implode( '', $parts )`), one named through a group `use`, and one inherited from a class that is
 * not loaded are not seen assembled. A constant reached through a variable class (`$class::HEAD`)
 * or through `constant()` is not seen. A constant gathered into a variable before it is joined is
 * not seen. A constant passed through `str_replace()`, `strtr()` or `preg_replace()` is not seen,
 * and only its head is inventoried. A statement at the top of a file that a function extends through
 * `$GLOBALS['sql']` is not seen. A SELECT assigned to a variable that a closure of its function
 * also assigns is counted as built in steps though the closure's variable is its own: that fails
 * closed, and no module has such a case. Four ways of completing a statement in steps are not seen
 * as such: a helper that takes the variable by reference (`self::cursor( $sql, $after )` with
 * `string &$sql`), a property (`$this->sql .= …`), the variable joined where it is sent
 * (`fetchAll( $sql . ' WHERE id > %d' )`), and a destructuring assignment
 * (`[ $sql ] = array( $sql . … )`). The query-plan run catches each of them, unless the bare head
 * is also sent and the completed statement reads a table outside the module.
 *
 * Compared with the run both ways: a head no statement matches is a read the run did not send;
 * a statement of the run that names one of the module's tables and matches no head is a read
 * of those tables from somewhere the inventory does not look.
 *
 * @since 0.1.0
 */
final class ReadInventory {

	/**
	 * What stands for a table in a head's shape.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TABLE = '{table}';

	/**
	 * The most texts one statement may take, so that a few ternaries are read and a generator of statements is not.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MAXIMUM_TEXTS = 32;

	/**
	 * The call that writes the placeholders of an IN list: `implode( ', ', array_fill( 0, $count, '%d' ) )`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PLACEHOLDER_LIST = '/^implode\(\s*[\'"], [\'"]\s*,\s*array_fill\(\s*0\s*,.*,\s*[\'"]%[ds][\'"]\s*\)\s*\)$/s';

	/**
	 * What a SELECT that cannot be read is joined to, for the message that refuses it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const JOINED = array(
		'after'        => 'joined to a constant, a variable or a call',
		'before'       => 'joined after a constant, a variable or a call',
		'sprintf'      => 'handed to sprintf()',

		'interpolated' => 'written in an interpolated string or a heredoc',
		'compound'     => 'written as a WITH or a UNION',
		'steps'        => 'built in steps, by `.=` or by assigning to its variable again',
		'assembled'    => 'built in steps, by joining a constant that holds a SELECT to something, or by handing it to implode() or sprintf()',
		'visibility'   => 'completed by a parameter of a method that is not private, or protected in a final class, so that its callers may be outside the class',
	);

	/**
	 * Finds the heads of the reads under a directory.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A class constant is read from its class, evaluated; a source that cannot be read is refused.
	 *
	 * @param string $directory The module's source directory.
	 * @return array<string, list<string>> Each read's shapes, one for each text it can take, keyed by where it is written: `path/File.php:line` relative to the directory, or `Full\Class\Name::CONSTANT`.
	 *
	 * @throws \UnexpectedValueException When a class's constants cannot be evaluated, a SELECT cannot be read from the source, two reads have one name, a source cannot be read, or the directory holds a file that is not PHP or one that includes another.
	 */
	public static function of( string $directory ): array {
		$heads   = array();
		$sources = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo ) {
				continue;
			}

			$name = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $directory ) ) ), '/' );

			if ( 'php' !== $file->getExtension() ) {
				throw new \UnexpectedValueException( sprintf( '%s is not a PHP file, and the inventory reads only PHP files, so a read written in it would never be seen. The source directory of a module holds PHP files only.', $name ) );
			}

			$tokens  = token_get_all( (string) file_get_contents( $file->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source files of the plugin.
			$classes = self::classesIn( $tokens );

			self::refuseIncludes( $name, $tokens );

			$heads     = self::merged( $heads, self::literalHeads( $name, $tokens, array() !== $classes, $classes[0] ?? null ), self::constantHeads( self::constantsMayHoldReads( $tokens ) ? $classes : array() ) );
			$sources[] = array( $name, $tokens, $classes[0] ?? null );
		}

		$constants = self::constantNames( $heads );

		foreach ( $sources as list( $name, $tokens, $owner ) ) {
			self::refuseAssembledConstants( $name, $tokens, $owner, $constants );
		}

		ksort( $heads );

		return $heads;
	}

	/**
	 * Names the constants that hold a read, in lower case, each as `class::constant`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>> $heads The heads of the module.
	 * @return array<string, bool> Each name, with whether the constant stops at IN, to take a placeholder list.
	 */
	private static function constantNames( array $heads ): array {
		$names = array();

		foreach ( $heads as $key => $shapes ) {
			if ( ! str_contains( $key, '::' ) ) {
				continue;
			}

			$name  = strtolower( (string) preg_replace( '/\[.*$/s', '', $key ) );
			$stops = array() !== $shapes && array() === array_filter( $shapes, static fn( string $shape ): bool => ! str_ends_with( $shape, ' IN ( ?+ )' ) );

			$names[ $name ] = $stops && ( $names[ $name ] ?? true );
		}

		return $names;
	}

	/**
	 * Refuses a method that joins a constant holding a SELECT to anything, or hands it to implode() or sprintf().
	 *
	 * A constant that holds a SELECT is a read of its own, sent whole. A method that completes it,
	 * `self::HEAD . self::TAIL` or `implode( '', array( self::HEAD, self::TAIL ) )`, sends a statement
	 * no constant holds, and none of the reads the inventory has describes it. The constant may be the
	 * class's own (`self::`, `static::`), or another class's, written with its name as it is imported.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $name      The file's name.
	 * @param array               $tokens    The file's tokens.
	 * @param string|null         $owner     The file's class.
	 * @param array<string, bool> $constants The constants that hold a read, as constantNames() lists them.
	 *
	 * @throws \UnexpectedValueException When a method does.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function refuseAssembledConstants( string $name, array $tokens, ?string $owner, array $constants ): void {
		if ( array() === $constants ) {
			return;
		}

		$names = self::importsOf( $tokens );

		foreach ( $tokens as $at => $token ) {
			if ( ! is_array( $token ) || T_DOUBLE_COLON !== $token[0] ) {
				continue;
			}

			$subject  = self::significantBefore( $tokens, $at );
			$member   = self::significantAfter( $tokens, $at );
			$fetched  = self::classNamed( $tokens[ $subject ] ?? '', $owner, $names );
			$constant = $tokens[ $member ] ?? '';
			$stops    = null !== $fetched && is_array( $constant ) && T_STRING === $constant[0] && '(' !== ( $tokens[ self::significantAfter( $tokens, $member ) ] ?? '' ) ? self::stopsAtIn( $fetched, $constant[1], $constants ) : null;

			if ( null === $stops ) {
				continue;
			}

			$end = $member;

			while ( '[' === ( $tokens[ self::significantAfter( $tokens, $end ) ] ?? '' ) ) {
				$end = self::closerOf( $tokens, self::significantAfter( $tokens, $end ) ) ?? $end + 1;
			}

			// A constant that stops at IN is completed by the placeholder list of its IN clause, which the inventory reads: the constant and its list are one operand, which may itself be joined.
			$completed = $stops ? self::takesPlaceholderList( $tokens, $end ) : null;

			if ( null !== self::bodyAround( $tokens, $subject ) && self::isJoined( $tokens, $subject, $completed ?? $end ) ) {
				throw self::refusal( $name, (int) $tokens[ $subject ][2], 'assembled' );
			}
		}
	}

	/**
	 * Names the class a static member is fetched from, in full.
	 *
	 * @since 0.2.0
	 *
	 * @param array|string $token The token before the `::`.
	 * @param string|null  $owner The file's class, which `self` and `static` name.
	 * @param array        $names The file's namespace and imports, as importsOf() returns them.
	 * @return string|null The class's full name; null when it is not a name that can be resolved here.
	 *
	 * @phpstan-param array{0: int, 1: string, 2: int}|string $token
	 * @phpstan-param array{namespace: string, uses: array<string, string>} $names
	 */
	private static function classNamed( $token, ?string $owner, array $names ): ?string {
		if ( ! is_array( $token ) ) {
			return null;
		}

		if ( T_STATIC === $token[0] || ( T_STRING === $token[0] && 1 === preg_match( '/^self$/i', $token[1] ) ) ) {
			return $owner;
		}

		$kind = token_name( $token[0] );

		if ( 'T_NAME_FULLY_QUALIFIED' === $kind ) {
			return ltrim( $token[1], '\\' );
		}

		if ( 'T_NAME_QUALIFIED' === $kind ) {
			$parts = explode( '\\', $token[1], 2 );

			return ( $names['uses'][ strtolower( $parts[0] ) ] ?? $names['namespace'] . $parts[0] ) . '\\' . $parts[1];
		}

		return T_STRING === $token[0] && 'parent' !== strtolower( $token[1] ) ? ( $names['uses'][ strtolower( $token[1] ) ] ?? $names['namespace'] . $token[1] ) : null;
	}

	/**
	 * Reads the namespace a file declares and the classes it imports.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @return array{namespace: string, uses: array<string, string>} The namespace, with its trailing backslash, and each imported class by its lower-case alias.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function importsOf( array $tokens ): array {
		$namespace = '';
		$uses      = array();
		$depth     = 0;

		foreach ( $tokens as $at => $token ) {
			$depth += self::opensBlock( $token ) ? 1 : ( '}' === $token ? -1 : 0 );

			if ( ! is_array( $token ) || ( T_NAMESPACE !== $token[0] && ( T_USE !== $token[0] || 0 !== $depth ) ) ) {
				continue;
			}

			$next = $tokens[ self::significantAfter( $tokens, $at ) ] ?? '';

			if ( ! is_array( $next ) || ! in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
				continue;
			}

			if ( T_NAMESPACE === $token[0] ) {
				$namespace = $next[1] . '\\';

				continue;
			}

			$named = self::significantAfter( $tokens, $at );
			$then  = self::significantAfter( $tokens, $named );
			$alias = is_array( $tokens[ $then ] ?? null ) && T_AS === $tokens[ $then ][0] ? ( $tokens[ self::significantAfter( $tokens, $then ) ] ?? '' ) : '';
			$full  = ltrim( $next[1], '\\' );
			$last  = (string) substr( strrchr( '\\' . $full, '\\' ), 1 );

			$uses[ strtolower( is_array( $alias ) ? $alias[1] : $last ) ] = $full;
		}

		return array(
			'namespace' => $namespace,
			'uses'      => $uses,
		);
	}

	/**
	 * Finds the constant of a class, or of a class it extends that is already loaded, that holds a read.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $fetched   The class's full name.
	 * @param string              $constant  The constant's name.
	 * @param array<string, bool> $constants  The constants that hold a read, as constantNames() lists them.
	 * @return bool|null Whether the constant stops at IN; null when no constant of the class or its parents holds a read.
	 */
	private static function stopsAtIn( string $fetched, string $constant, array $constants ): ?bool {
		$current = $fetched;

		while ( '' !== $current ) {
			if ( isset( $constants[ strtolower( $current . '::' . $constant ) ] ) ) {
				return $constants[ strtolower( $current . '::' . $constant ) ];
			}

			$parent  = class_exists( $current, false ) ? get_parent_class( $current ) : false;
			$current = false === $parent ? '' : $parent;
		}

		return null;
	}

	/**
	 * Finds where the placeholder list of an IN clause ends, when the operand that ends at a position is followed by it: `. '( ' . implode( ', ', array_fill( … ) ) . ' )'`.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $end    Where the operand ends.
	 * @return int|null Where the closing literal is; null when the list is not there, or is closed otherwise.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function takesPlaceholderList( array $tokens, int $end ): ?int {
		$dot    = self::significantAfter( $tokens, $end );
		$open   = self::significantAfter( $tokens, $dot );
		$second = self::significantAfter( $tokens, $open );
		$call   = self::significantAfter( $tokens, $second );
		$text   = $tokens[ $open ] ?? '';
		$then   = $tokens[ $call ] ?? '';
		$list   = is_array( $then ) && T_STRING === $then[0] && 'implode' === strtolower( $then[1] ) ? self::placeholderList( $tokens, $call, array( 'IN ( ' ) ) : null;

		if ( '.' !== ( $tokens[ $dot ] ?? '' ) || ! is_array( $text ) || T_CONSTANT_ENCAPSED_STRING !== $text[0] || 1 !== preg_match( '/^[\'"]\(\s*[\'"]$/', $text[1] ) || '.' !== ( $tokens[ $second ] ?? '' ) || null === $list ) {
			return null;
		}

		$tail   = self::significantAfter( $tokens, self::significantAfter( $tokens, $list['end'] ) );
		$closes = $tokens[ $tail ] ?? '';

		// The list closes with a parenthesis of its own, and the statement ends there.
		$ends = '.' === ( $tokens[ self::significantAfter( $tokens, $list['end'] ) ] ?? '' ) && is_array( $closes ) && T_CONSTANT_ENCAPSED_STRING === $closes[0] && 1 === preg_match( '/^[\'"]\s*\)\s*[\'"]$/', $closes[1] ) && '.' !== ( $tokens[ self::significantAfter( $tokens, $tail ) ] ?? '' );

		return $ends ? $tail : null;
	}

	/**
	 * Tells whether an operand is joined to something by a dot, or handed to implode() or sprintf().
	 *
	 * The operand may sit in a group, an array or a ternary that is itself joined or handed on, so
	 * the groups around it are followed outwards until a call that is none of those, or the end of the
	 * statement.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $start  Where the operand starts.
	 * @param int   $end    Where it ends.
	 * @return bool True when it is.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function isJoined( array $tokens, int $start, int $end ): bool {
		while ( true ) {
			$before = $tokens[ self::significantBefore( $tokens, $start ) ] ?? '';
			$after  = $tokens[ self::significantAfter( $tokens, $end ) ] ?? '';

			if ( '.' === $before || '.' === $after || ( is_array( $before ) && T_CONCAT_EQUAL === $before[0] ) ) {
				return true;
			}

			$open  = self::enclosingOpen( $tokens, $start );
			$close = null === $open ? null : self::closerOf( $tokens, $open );

			if ( null === $open || null === $close ) {
				return false;
			}

			$callee = self::significantBefore( $tokens, $open );
			$name   = $tokens[ $callee ] ?? '';

			if ( '(' === $tokens[ $open ] && is_array( $name ) && in_array( $name[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$on = $tokens[ self::significantBefore( $tokens, $callee ) ] ?? '';

				return ! ( is_array( $on ) && in_array( $on[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) && 1 === preg_match( '/(?:^|\\\\)(?:implode|join|sprintf|vsprintf)$/i', $name[1] );
			}

			$start = $open;
			$end   = $close;
		}
	}

	/**
	 * Finds the body of the function that a `function` token begins: a method, a function or a closure.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens   The file's tokens.
	 * @param int   $keyword  Where the `function` token is.
	 * @return array{0: int, 1: int}|null Where the body opens and closes; null when it has none.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function bodyOfFunction( array $tokens, int $keyword ): ?array {
		$open = self::significantAfter( $tokens, $keyword );

		while ( ( is_array( $tokens[ $open ] ?? null ) && T_STRING === $tokens[ $open ][0] ) || '&' === ( $tokens[ $open ] ?? '' ) ) {
			$open = self::significantAfter( $tokens, $open );
		}

		$close = '(' === ( $tokens[ $open ] ?? '' ) ? self::closingParenthesis( $tokens, $open ) : null;

		return null === $close ? null : self::bodyOf( $tokens, $close );
	}

	/**
	 * Finds the parenthesis or bracket that opens around a position and is not closed before it.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The position.
	 * @return int|null Where it is; null when the statement ends first.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function enclosingOpen( array $tokens, int $at ): ?int {
		$depth = 0;

		for ( $back = $at - 1; $back >= 0; $back-- ) {
			$token = $tokens[ $back ];

			if ( ')' === $token || ']' === $token ) {
				++$depth;
			} elseif ( '(' === $token || '[' === $token ) {
				if ( 0 === $depth ) {
					return $back;
				}

				--$depth;
			} elseif ( 0 === $depth && ( ';' === $token || '{' === $token || '}' === $token ) ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Finds the parenthesis or bracket that closes the one at a position.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $open   Where the `(` or `[` is.
	 * @return int|null Where its closing character is; null when there is none.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function closerOf( array $tokens, int $open ): ?int {
		$opening = $tokens[ $open ] ?? '';
		$closing = '(' === $opening ? ')' : ']';
		$depth   = 0;
		$count   = count( $tokens );

		for ( $at = $open; $at < $count; $at++ ) {
			$depth += $opening === $tokens[ $at ] ? 1 : ( $closing === $tokens[ $at ] ? -1 : 0 );

			if ( 0 === $depth ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Refuses a file that includes another, since a read in the included file would never be seen.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name   The file's name.
	 * @param array  $tokens The file's tokens.
	 *
	 * @throws \UnexpectedValueException When the file holds an include or a require.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function refuseIncludes( string $name, array $tokens ): void {
		foreach ( $tokens as $at => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				continue;
			}

			$before = $tokens[ self::significantBefore( $tokens, $at ) ] ?? '';

			// A method may be named require: `->require(` and `function require(` are not includes.
			if ( is_array( $before ) && in_array( $before[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
				continue;
			}

			throw new \UnexpectedValueException( sprintf( '%s:%d includes a file, and the inventory reads only the files of the directory it is given, so a read in an included file would never be seen. The source of a module does not include files.', $name, $token[2] ) );
		}
	}

	/**
	 * Lists the shapes of the reads that no statement of the run matches: reads the run did not send.
	 *
	 * A read that can take several texts, a ternary or a parameter several calls pass, has one shape
	 * for each, and each one must be sent: the others do not stand for it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every shape of a read must be sent.
	 *
	 * @param array<string, list<string>> $heads      The heads, as of() returns them.
	 * @param Statement[]                 $statements The plugin SELECTs the run sent.
	 * @return list<string> One line per shape: where its read is written, which shape of how many when there are several, and the shape.
	 *
	 * @phpstan-param list<Statement> $statements
	 */
	public static function unsent( array $heads, array $statements ): array {
		$unsent = array();

		foreach ( $heads as $where => $head ) {
			foreach ( $head as $index => $shape ) {
				$matched = array_filter( $statements, static fn( Statement $statement ): bool => self::matches( array( $shape ), $statement ) );

				if ( array() === $matched ) {
					$unsent[] = $where . ( count( $head ) > 1 ? sprintf( ' [shape %d of %d]', $index + 1, count( $head ) ) : '' ) . ' ' . $shape;
				}
			}
		}

		return $unsent;
	}

	/**
	 * Lists the statements of the run that name one of the tables and match no head: reads from outside the inventory.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $heads      The heads, as of() returns them.
	 * @param Statement[]                 $statements The plugin SELECTs the run sent.
	 * @param string[]                    $tables     The full names of the module's tables.
	 * @return list<string> The shapes of those statements.
	 *
	 * @phpstan-param list<Statement> $statements
	 * @phpstan-param list<string>    $tables
	 */
	public static function unknown( array $heads, array $statements, array $tables ): array {
		$unknown = array();

		foreach ( $statements as $statement ) {
			$named   = array() !== array_intersect( array_values( $statement->tables() ), $tables );
			$matched = array_filter( $heads, static fn( array $head ): bool => self::matches( $head, $statement ) );

			if ( $named && array() === $matched ) {
				$unknown[] = $statement->shape();
			}
		}

		return array_values( array_unique( $unknown ) );
	}

	/**
	 * Tells whether a statement's shape is, in full, one of a head's shapes.
	 *
	 * A table token of the head stands for any one table name, and an IN list is the same shape
	 * whatever its length, so the whole text is compared and nothing may follow the head.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The whole shape must match, not its beginning.
	 *
	 * @param string[]  $head      A head's shapes.
	 * @param Statement $statement A statement of the run.
	 * @return bool True when it is one of them.
	 */
	private static function matches( array $head, Statement $statement ): bool {
		foreach ( $head as $shape ) {
			if ( 1 === preg_match( '/^' . str_replace( preg_quote( self::TABLE, '/' ), '\S+', preg_quote( $shape, '/' ) ) . '$/D', $statement->shape() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds the heads written as string literals.
	 *
	 * Literals joined by dots with nothing but literals between them are folded into one text first,
	 * so a statement split over several literals is read whole. The statement is then followed to
	 * its end, through the operands joined to it by dots, and every text it can take is one of its
	 * alternatives. A string literal inside a class constant is left to constantHeads(), which reads
	 * the constant from its class. A SELECT that starts an interpolated string or a heredoc is refused.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $name      The file's name.
	 * @param array       $tokens    The file's tokens.
	 * @param bool        $inClasses Whether the file declares a class, so that its constants are class constants.
	 * @param string|null $owner     The file's class, whose constants a ternary may name.
	 * @return array<string, list<string>> Each read's alternatives, as shapes, keyed by `File.php:line`.
	 *
	 * @throws \UnexpectedValueException When a SELECT is joined to, or handed to, something that cannot be read.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function literalHeads( string $name, array $tokens, bool $inClasses, ?string $owner ): array {
		$heads    = array();
		$constant = false;
		$count    = count( $tokens );

		for ( $at = 0; $at < $count; $at++ ) {
			$token = $tokens[ $at ];

			if ( ';' === $token ) {
				$constant = false;
			} elseif ( is_array( $token ) && T_CONST === $token[0] ) {
				$constant = $inClasses;
			} elseif ( is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] && 1 === preg_match( '/^\s*SELECT\b/i', $token[1] ) && self::opensString( $tokens, $at ) ) {
				throw self::refusal( $name, $token[2], 'interpolated' );
			} elseif ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$run = self::literalRun( $tokens, $at );

				// A class constant is read from its class, whole; see constantHeads().
				$read  = $constant ? array(
					'heads' => array(),
					'end'   => $run['end'],
				) : self::statementRead( $name, $tokens, $run, $owner );
				$heads = self::merged( $heads, $read['heads'] );
				$at    = $read['end'];
			}
		}

		return $heads;
	}

	/**
	 * Reads the string literals that start at a position and are joined by dots with nothing between them but literals.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The first literal's position.
	 * @return array{start: int, end: int, pieces: list<array{0: string, 1: int}>} Where the run starts and ends, and its literals' texts with their lines.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function literalRun( array $tokens, int $at ): array {
		$pieces = array();
		$end    = $at;

		while ( true ) {
			$pieces[] = array( stripcslashes( substr( $tokens[ $end ][1], 1, -1 ) ), $tokens[ $end ][2] );
			$dot      = self::significantAfter( $tokens, $end );
			$next     = self::significantAfter( $tokens, $dot );

			if ( '.' !== ( $tokens[ $dot ] ?? '' ) || ! is_array( $tokens[ $next ] ?? null ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $next ][0] ) {
				break;
			}

			$end = $next;
		}

		return array(
			'start'  => $at,
			'end'    => $end,
			'pieces' => $pieces,
		);
	}

	/**
	 * Reads a statement in a method: the SELECT a run of literals is, or ends in, followed to its end.
	 *
	 * The run is read folded. One that begins with SELECT is a read. One that begins with INSERT and
	 * holds a SELECT, written in one literal or in several, is a read once it is complete: its SELECT
	 * tail is the read, unless it selects from DUAL or a derived table of values. One that begins
	 * with WITH, or with a parenthesis and holds a UNION, cannot be read and is refused. Whatever
	 * is joined to the run by a dot must be one of the dynamic parts the inventory knows
	 * (completions()), and a SELECT written after something that is not a literal, or handed to
	 * sprintf(), is refused. So is a statement assigned to a variable that the function goes on to
	 * give a value again, with `.=` or any other assignment: it is built in steps the source does not show whole.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $name   The file's name.
	 * @param array       $tokens The file's tokens.
	 * @param array       $run    The run, as literalRun() returns it.
	 * @param string|null $owner  The file's class.
	 * @return array{heads: array<string, list<string>>, end: int} The read, and where the statement ends.
	 *
	 * @throws \UnexpectedValueException When the statement cannot be read from the source.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param array{start: int, end: int, pieces: list<array{0: string, 1: int}>} $run
	 */
	private static function statementRead( string $name, array $tokens, array $run, ?string $owner ): array {
		$folded = implode( '', array_column( $run['pieces'], 0 ) );
		$line   = $run['pieces'][0][1];
		$none   = array(
			'heads' => array(),
			'end'   => $run['end'],
		);

		if ( 1 === preg_match( '/^\s*SELECT\b/i', $folded ) ) {
			$preceded = self::precededBy( $tokens, $run );

			if ( null !== $preceded ) {
				throw self::refusal( $name, $line, $preceded );
			}

			if ( self::isExtendedLater( $tokens, $run ) ) {
				throw self::refusal( $name, $line, 'steps' );
			}

			$statement = self::completions( $name, $tokens, $run, $folded, $line, $owner );
			$shapes    = array_values( array_unique( array_map( array( self::class, 'shapeOf' ), $statement['texts'] ) ) );

			return array(
				'heads' => array( $name . ':' . $line => $shapes ),
				'end'   => $statement['end'],
			);
		}

		if ( 1 === preg_match( '/^\s*INSERT\b.*\bSELECT\b/is', $folded ) ) {
			$statement = self::completions( $name, $tokens, $run, $folded, $line, $owner );
			$selects   = array_values( array_filter( array_map( array( self::class, 'selectIn' ), $statement['texts'] ) ) );
			$shapes    = array_values( array_unique( array_map( array( self::class, 'shapeOf' ), $selects ) ) );

			return array(
				'heads' => array() === $shapes ? array() : array( $name . ':' . $line => $shapes ),
				'end'   => $statement['end'],
			);
		}

		if ( self::isCompound( $folded ) ) {
			throw self::refusal( $name, $line, 'compound' );
		}

		return $none;
	}

	/**
	 * Tells whether a text is a statement that holds a SELECT but does not begin with one: a WITH, or a UNION of parenthesised SELECTs.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return bool True when it is.
	 */
	private static function isCompound( string $text ): bool {
		return 1 === preg_match( '/\bSELECT\b/i', $text )
			&& ( 1 === preg_match( '/^\s*WITH\s+(?:RECURSIVE\s+)?\w+\s+AS\s*\(/i', $text ) || ( 1 === preg_match( '/^\s*\(/', $text ) && 1 === preg_match( '/\)\s*UNION\b/i', $text ) ) );
	}

	/**
	 * Tells whether a statement is assigned to a variable that the same function gives a value again afterwards, with `.=` or any other assignment.
	 *
	 * The whole rest of the function's body is read, closures and arrow functions in it included. A
	 * statement outside any function, at the top of a file or as a property's default, is followed to
	 * the end of the file, past the bodies of the functions declared after it.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param array $run    The run the statement begins in.
	 * @return bool True when it is.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param array{start: int, end: int, pieces: list<array{0: string, 1: int}>} $run
	 */
	private static function isExtendedLater( array $tokens, array $run ): bool {
		$equals   = self::significantBefore( $tokens, $run['start'] );
		$variable = $tokens[ self::significantBefore( $tokens, $equals ) ] ?? '';
		$body     = self::bodyAround( $tokens, $run['start'] );

		if ( '=' !== ( $tokens[ $equals ] ?? '' ) || ! is_array( $variable ) || T_VARIABLE !== $variable[0] ) {
			return false;
		}

		$until = null === $body ? count( $tokens ) : $body[1];

		for ( $at = $run['end'] + 1; $at < $until; $at++ ) {
			$token = $tokens[ $at ];

			// Outside a function the variable is the file's, or a property's default: the bodies of the functions that follow have variables of their own, unless they take it as global.
			if ( null === $body && is_array( $token ) && T_FUNCTION === $token[0] ) {
				$skipped = self::bodyOfFunction( $tokens, $at );

				if ( null !== $skipped && self::assignsGlobal( $tokens, $skipped, $variable[1] ) ) {
					return true;
				}

				$at = $skipped[1] ?? $at;

				continue;
			}

			if ( is_array( $token ) && T_VARIABLE === $token[0] && $token[1] === $variable[1] && self::assignsTo( $tokens, $at ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a function body takes a variable as global and then assigns it.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $tokens The file's tokens.
	 * @param array  $body   Where the body opens and closes.
	 * @param string $name  The variable, with its `$`.
	 * @return bool True when it does.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param array{0: int, 1: int} $body
	 */
	private static function assignsGlobal( array $tokens, array $body, string $name ): bool {
		$naming   = false;
		$declared = false;

		for ( $at = $body[0]; $at < $body[1]; $at++ ) {
			$token = $tokens[ $at ];

			if ( ';' === $token ) {
				$naming = false;
			} elseif ( is_array( $token ) && T_GLOBAL === $token[0] ) {
				$naming = true;
			} elseif ( is_array( $token ) && T_VARIABLE === $token[0] && $name === $token[1] ) {
				$declared = $declared || $naming;

				if ( $declared && ! $naming && self::assignsTo( $tokens, $at ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Finds the body of the function, method or closure that a position is in: the innermost.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The position.
	 * @return array{0: int, 1: int}|null Where the body opens and closes; null outside one.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function bodyAround( array $tokens, int $at ): ?array {
		for ( $back = $at; $back >= 0; $back-- ) {
			if ( ! is_array( $tokens[ $back ] ) || T_FUNCTION !== $tokens[ $back ][0] ) {
				continue;
			}

			$body = self::bodyOfFunction( $tokens, $back );

			if ( null !== $body && $body[0] < $at && $at < $body[1] ) {
				return $body;
			}
		}

		return null;
	}

	/**
	 * Tells whether a token is the first text of a double-quoted string or a heredoc.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The text's position.
	 * @return bool True when the string opens just before it.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function opensString( array $tokens, int $at ): bool {
		$before = $tokens[ $at - 1 ] ?? '';

		return '"' === $before || ( is_array( $before ) && T_START_HEREDOC === $before[0] );
	}

	/**
	 * Merges sets of reads, refusing two reads that have one name.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>> ...$sets The sets.
	 * @return array<string, list<string>> The reads of all of them.
	 *
	 * @throws \UnexpectedValueException When two reads have one name.
	 */
	private static function merged( array ...$sets ): array {
		$all = array();

		foreach ( $sets as $set ) {
			foreach ( $set as $name => $shapes ) {
				if ( isset( $all[ $name ] ) ) {
					throw new \UnexpectedValueException( sprintf( 'Two reads are called %s, so one of them would hide the other.', $name ) );
				}

				$all[ $name ] = $shapes;
			}
		}

		return $all;
	}

	/**
	 * Follows a statement through the operands joined to it by dots, and lists every text it can take.
	 *
	 * The dynamic parts accepted are named here, and nothing else is:
	 *
	 * - the placeholder list of an IN clause, `IN ( ' . implode( ', ', array_fill( 0, $n, '%d' ) ) . ' )`,
	 *   which stands for any number of placeholders and is matched in the shape every IN list has;
	 * - a variable, or a parenthesised ternary, whose value is one of two literals or constants of the
	 *   class named in the statement's own function: the statement then has one text for each;
	 * - a parameter of a private method, or of a protected one in a final class, whose callers are
	 *   therefore all in the class: the literals and constants, and the readable variables, that
	 *   the calls pass for it, by position or by name, or its default; one text for each call, the
	 *   arguments of a call kept together;
	 *
	 * and a variable that is assigned something the inventory cannot read, or a parameter some
	 * call passes something it cannot read, is refused. Every text of a statement is a read the
	 * run must send, which unsent() reports.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $name   The file's name.
	 * @param array       $tokens The file's tokens.
	 * @param array       $run     The run the statement begins in.
	 * @param string      $initial The run, folded.
	 * @param int         $line    The line the statement starts on.
	 * @param string|null $owner   The file's class.
	 * @return array{texts: list<string>, end: int} Every text the statement can take, and where it ends.
	 *
	 * @throws \UnexpectedValueException When an operand is none of those.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param array{start: int, end: int, pieces: list<array{0: string, 1: int}>} $run
	 */
	private static function completions( string $name, array $tokens, array $run, string $initial, int $line, ?string $owner ): array {
		$parts = array(
			array(
				'text' => $initial,
				'call' => null,
			),
		);
		$end   = $run['end'];

		while ( '.' === ( $tokens[ self::significantAfter( $tokens, $end ) ] ?? '' ) ) {
			$operand = self::operandAt( $tokens, self::significantAfter( $tokens, self::significantAfter( $tokens, $end ) ), $owner, array_column( $parts, 'text' ) );

			if ( null === $operand ) {
				throw self::refusal( $name, $line, self::reasonFor( $tokens, self::significantAfter( $tokens, self::significantAfter( $tokens, $end ) ) ) );
			}

			$parts = self::appended( $parts, $operand['texts'], $operand['calls'] );
			$end   = $operand['end'];

			if ( count( $parts ) > self::MAXIMUM_TEXTS ) {
				throw self::refusal( $name, $line, 'after' );
			}
		}

		return array(
			'texts' => array_values( array_unique( array_column( $parts, 'text' ) ) ),
			'end'   => $end,
		);
	}

	/**
	 * Says why an operand cannot be read: its variable is a parameter of a method whose callers may be anywhere, or none of the dynamic parts the inventory knows.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     Where the operand starts.
	 * @return string A key of JOINED.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function reasonFor( array $tokens, int $at ): string {
		$token    = $tokens[ $at ] ?? '';
		$function = is_array( $token ) && T_VARIABLE === $token[0] ? self::functionAround( $tokens, $at ) : null;

		if ( null === $function || self::continuesAsExpression( $tokens, $at ) || 'unassigned' !== self::assigned( $tokens, $at, null )['kind'] || self::calledOnlyHere( $tokens, $function['at'] ) ) {
			return 'after';
		}

		foreach ( self::splitAtCommas( $tokens, $function['open'] + 1, $function['close'] - 1 ) as $parameter ) {
			for ( $cursor = $parameter[0]; $cursor <= $parameter[1]; $cursor++ ) {
				if ( is_array( $tokens[ $cursor ] ) && T_VARIABLE === $tokens[ $cursor ][0] && $tokens[ $cursor ][1] === $token[1] ) {
					return 'visibility';
				}
			}
		}

		return 'after';
	}

	/**
	 * Reads the operand that starts at a position, after a dot.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $at     Where the operand starts.
	 * @param string|null $owner  The file's class.
	 * @param string[]    $before The texts the statement can take so far.
	 * @return array{texts: list<string>, calls: list<int|null>, end: int}|null The texts it can take, the call each belongs to when a parameter gave it, and where it ends; null when it is none of the operands the inventory knows.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function operandAt( array $tokens, int $at, ?string $owner, array $before ): ?array {
		$token = $tokens[ $at ] ?? '';

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$run = self::literalRun( $tokens, $at );

			return array(
				'texts' => array( implode( '', array_column( $run['pieces'], 0 ) ) ),
				'calls' => array( null ),
				'end'   => $run['end'],
			);
		}

		if ( is_array( $token ) && T_STRING === $token[0] && 'implode' === strtolower( $token[1] ) ) {
			return self::placeholderList( $tokens, $at, $before );
		}

		if ( '(' === $token ) {
			$close = self::closingParenthesis( $tokens, $at );
			$texts = null === $close ? null : self::choices( $tokens, $at + 1, $close - 1, $owner );

			return null === $texts ? null : array(
				'texts' => $texts,
				'calls' => array_fill( 0, count( $texts ), null ),
				'end'   => $close,
			);
		}

		if ( is_array( $token ) && T_VARIABLE === $token[0] && ! self::continuesAsExpression( $tokens, $at ) ) {
			$found  = self::assigned( $tokens, $at, $owner );
			$passed = 'unassigned' === $found['kind'] ? self::passed( $tokens, $at, $owner ) : null;
			$texts  = 'unassigned' === $found['kind'] ? ( $passed['texts'] ?? null ) : $found['texts'];

			return null === $texts ? null : array(
				'texts' => $texts,
				'calls' => $passed['calls'] ?? array_fill( 0, count( $texts ), null ),
				'end'   => $at,
			);
		}

		return null;
	}

	/**
	 * Reads the placeholder list of an IN clause: `implode( ', ', array_fill( 0, $n, '%d' ) )` between `IN ( ` and ` )`.
	 *
	 * This is the only dynamic completion of a statement's text the inventory takes as a template:
	 * the list is written `{list}`, which shapeOf() turns into the one shape every IN list has, so
	 * a statement sent with one placeholder and one sent with three both match it by their full text.
	 *
	 * @since 0.2.0
	 *
	 * @param array    $tokens The file's tokens.
	 * @param int      $at     Where `implode` is.
	 * @param string[] $before The texts the statement can take so far.
	 * @return array{texts: list<string>, calls: list<int|null>, end: int}|null The list, or null when this is not one.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function placeholderList( array $tokens, int $at, array $before ): ?array {
		$open  = self::significantAfter( $tokens, $at );
		$close = '(' === ( $tokens[ $open ] ?? '' ) ? self::closingParenthesis( $tokens, $open ) : null;

		if ( null === $close ) {
			return null;
		}

		$call = '';

		for ( $position = $at; $position <= $close; $position++ ) {
			$call .= is_array( $tokens[ $position ] ) ? $tokens[ $position ][1] : $tokens[ $position ];
		}

		$dot   = self::significantAfter( $tokens, $close );
		$after = $tokens[ self::significantAfter( $tokens, $dot ) ] ?? '';
		$ends  = '.' === ( $tokens[ $dot ] ?? '' ) && is_array( $after ) && T_CONSTANT_ENCAPSED_STRING === $after[0] && 1 === preg_match( '/^[\'"]\s*\)/', $after[1] );
		$opens = array() === array_filter( $before, static fn( string $text ): bool => 1 !== preg_match( '/\bIN\s*\(\s*$/i', $text ) );

		return 1 === preg_match( self::PLACEHOLDER_LIST, $call ) && $ends && $opens ? array(
			'texts' => array( '{list}' ),
			'calls' => array( null ),
			'end'   => $close,
		) : null;
	}

	/**
	 * Lists the texts a variable can hold, from its one assignment in the body of its function.
	 *
	 * The variable must be assigned once, directly in the function's body and before it is used. One
	 * that is assigned in a block, or more than once, may hold what the source of the other
	 * assignments says, and is refused.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $at     Where the variable is used.
	 * @param string|null $owner  The file's class.
	 * @return array{kind: string, texts: list<string>|null} 'texts' with the texts it can hold; 'unreadable' when it is assigned something the inventory cannot read, or in a block, or more than once; 'unassigned' when the function never assigns it, as a parameter is not.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-return array{kind: 'texts'|'unreadable'|'unassigned', texts: list<string>|null}
	 */
	private static function assigned( array $tokens, int $at, ?string $owner ): array {
		$unreadable = array(
			'kind'  => 'unreadable',
			'texts' => null,
		);
		$function   = self::functionAround( $tokens, $at );
		$body       = null === $function ? null : self::bodyOf( $tokens, $function['close'] );

		if ( null === $body ) {
			return $unreadable;
		}

		$assignments = array();
		$depth       = 0;

		for ( $cursor = $body[0] + 1; $cursor < $body[1]; $cursor++ ) {
			$token  = $tokens[ $cursor ];
			$depth += self::opensBlock( $token ) ? 1 : ( '}' === $token ? -1 : 0 );

			if ( is_array( $token ) && T_VARIABLE === $token[0] && $token[1] === $tokens[ $at ][1] && self::assignsTo( $tokens, $cursor ) ) {
				$assignments[] = array( $cursor, $depth );
			}
		}

		if ( array() === $assignments ) {
			return array(
				'kind'  => 'unassigned',
				'texts' => null,
			);
		}

		$equals = self::significantAfter( $tokens, $assignments[0][0] );

		if ( count( $assignments ) > 1 || 0 !== $assignments[0][1] || $assignments[0][0] > $at || '=' !== ( $tokens[ $equals ] ?? '' ) ) {
			return $unreadable;
		}

		$from = self::significantAfter( $tokens, $equals );
		$to   = $from;

		while ( isset( $tokens[ $to ] ) && ';' !== $tokens[ $to ] ) {
			++$to;
		}

		$texts = self::choices( $tokens, $from, $to - 1, $owner );

		return array(
			'kind'  => null === $texts ? 'unreadable' : 'texts',
			'texts' => $texts,
		);
	}

	/**
	 * Finds the body of a function: the braces that follow its parameter list.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $close  Where the parameter list closes.
	 * @return array{0: int, 1: int}|null Where the body opens and closes; null when there is none.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function bodyOf( array $tokens, int $close ): ?array {
		$count = count( $tokens );
		$open  = $close + 1;

		while ( $open < $count && '{' !== $tokens[ $open ] && ';' !== $tokens[ $open ] ) {
			++$open;
		}

		if ( $open >= $count || '{' !== $tokens[ $open ] ) {
			return null;
		}

		$depth = 0;

		for ( $at = $open; $at < $count; $at++ ) {
			$depth += self::opensBlock( $tokens[ $at ] ) ? 1 : ( '}' === $tokens[ $at ] ? -1 : 0 );

			if ( 0 === $depth ) {
				return array( $open, $at );
			}
		}

		return null;
	}

	/**
	 * Tells whether a token opens a block: a brace, or the brace of an interpolation.
	 *
	 * @since 0.2.0
	 *
	 * @param array|string $token The token.
	 * @return bool True when it does.
	 *
	 * @phpstan-param array{0: int, 1: string, 2: int}|string $token
	 */
	private static function opensBlock( $token ): bool {
		return '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) );
	}

	/**
	 * Tells whether a variable at a position is being given a value: by `=`, by a compound assignment, or as the value of a foreach.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The variable's position.
	 * @return bool True when it is.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function assignsTo( array $tokens, int $at ): bool {
		$next   = $tokens[ self::significantAfter( $tokens, $at ) ] ?? '';
		$before = $tokens[ self::significantBefore( $tokens, $at ) ] ?? '';
		$ops    = array( T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL );

		return '=' === $next || ( is_array( $next ) && in_array( $next[0], $ops, true ) ) || '&' === $before || ( is_array( $before ) && T_AS === $before[0] );
	}

	/**
	 * Lists the texts a parameter takes, from the calls of its function in the same class.
	 *
	 * Only a private method is read, or a protected one of a final class: its callers are all in the
	 * class, so the calls found are the calls there are. A public method may be called from
	 * anywhere, and a read it completes by a parameter is refused. Each call passes a literal, a
	 * constant of the class, a ternary of those, or a variable that its own function assigns one of
	 * those to, by position or by name; a call that leaves the argument out passes the parameter's
	 * default, which may be an expression of literals and class constants. The function must be
	 * called at least once, and every call must be read. Each text is returned with the number of
	 * the call it came from.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $at     Where the parameter is used.
	 * @param string|null $owner  The file's class.
	 * @return array{texts: list<string>, calls: list<int>}|null The texts the calls pass; null when this is no parameter, the method may be called from outside the class, nothing calls it, or a call passes what cannot be read.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function passed( array $tokens, int $at, ?string $owner ): ?array {
		$function = self::functionAround( $tokens, $at );

		if ( null === $function || ! self::calledOnlyHere( $tokens, $function['at'] ) ) {
			return null;
		}

		$position = null;
		$default  = null;
		$variable = $tokens[ $at ][1];

		foreach ( self::splitAtCommas( $tokens, $function['open'] + 1, $function['close'] - 1 ) as $index => $parameter ) {
			for ( $cursor = $parameter[0]; $cursor <= $parameter[1]; $cursor++ ) {
				if ( is_array( $tokens[ $cursor ] ) && T_VARIABLE === $tokens[ $cursor ][0] && $tokens[ $cursor ][1] === $variable ) {
					$position = $index;
					$equals   = self::significantAfter( $tokens, $cursor );
					$default  = '=' === ( $tokens[ $equals ] ?? '' ) ? array( self::significantAfter( $tokens, $equals ), $parameter[1] ) : null;
					break 2;
				}
			}
		}

		if ( null === $position ) {
			return null;
		}

		$texts  = array();
		$calls  = array();
		$number = 0;
		$count  = count( $tokens );

		for ( $call = 0; $call < $count; $call++ ) {
			$open = self::callOf( $tokens, $call, $function['name'] );

			if ( null === $open ) {
				continue;
			}

			$close     = self::closingParenthesis( $tokens, $open );
			$arguments = null === $close ? null : self::sortedArguments( $tokens, self::splitAtCommas( $tokens, $open + 1, $close - 1 ) );
			$argument  = null === $arguments ? null : ( $arguments['named'][ substr( $variable, 1 ) ] ?? $arguments['positional'][ $position ] ?? $default );
			$passed    = null === $argument ? null : self::argumentTexts( $tokens, $argument[0], $argument[1], $owner );

			if ( null === $passed ) {
				return null;
			}

			foreach ( $passed as $text ) {
				$texts[] = $text;
				$calls[] = $number;
			}

			++$number;
		}

		return 0 === $number ? null : array(
			'texts' => $texts,
			'calls' => $calls,
		);
	}

	/**
	 * Tells whether every call of a method is in its own class: it is private, or protected in a final class.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     Where `function` is.
	 * @return bool True when it is.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function calledOnlyHere( array $tokens, int $at ): bool {
		$private   = false;
		$protected = false;

		for ( $back = $at - 1; $back >= 0; $back-- ) {
			$token = $tokens[ $back ];

			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT ), true ) ) {
				break;
			}

			$private   = $private || T_PRIVATE === $token[0];
			$protected = $protected || T_PROTECTED === $token[0];
		}

		return $private || ( $protected && self::declaresFinalClass( $tokens ) );
	}

	/**
	 * Tells whether the file declares a final class.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @return bool True when it does.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function declaresFinalClass( array $tokens ): bool {
		foreach ( $tokens as $at => $token ) {
			if ( ! is_array( $token ) || T_CLASS !== $token[0] ) {
				continue;
			}

			$before = $tokens[ self::significantBefore( $tokens, $at ) ] ?? '';
			$before = is_array( $before ) && 'readonly' === strtolower( $before[1] ) ? ( $tokens[ self::significantBefore( $tokens, self::significantBefore( $tokens, $at ) ) ] ?? '' ) : $before;

			if ( is_array( $before ) && T_FINAL === $before[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sorts the arguments of a call into those given by position and those given by name.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens    The file's tokens.
	 * @param array $arguments The arguments, as splitAtCommas() returns them.
	 * @return array{positional: list<array{0: int, 1: int}>, named: array<string, array{0: int, 1: int}>}|null The value of each, or null when one is unpacked with `...`, so that its position is unknown.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param list<array{0: int, 1: int}> $arguments
	 */
	private static function sortedArguments( array $tokens, array $arguments ): ?array {
		$positional = array();
		$named      = array();

		foreach ( $arguments as $argument ) {
			$first  = self::significantAfter( $tokens, $argument[0] - 1 );
			$colon  = self::significantAfter( $tokens, $first );
			$leader = $tokens[ $first ] ?? '';

			if ( is_array( $leader ) && '...' === $leader[1] ) {
				return null;
			}

			if ( is_array( $leader ) && T_STRING === $leader[0] && ':' === ( $tokens[ $colon ] ?? '' ) ) {
				$named[ $leader[1] ] = array( self::significantAfter( $tokens, $colon ), $argument[1] );
			} else {
				$positional[] = $argument;
			}
		}

		return array(
			'positional' => $positional,
			'named'      => $named,
		);
	}

	/**
	 * Reads what an argument can be: a literal, a constant, a ternary of those, or a variable assigned one.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $from   The argument's first token.
	 * @param int         $to     Its last.
	 * @param string|null $owner  The file's class.
	 * @return list<string>|null The texts, or null when it is anything else.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function argumentTexts( array $tokens, int $from, int $to, ?string $owner ): ?array {
		$texts = self::choices( $tokens, $from, $to, $owner );

		if ( null !== $texts ) {
			return $texts;
		}

		$first = self::significantAfter( $tokens, $from - 1 );

		if ( $first !== $to && self::significantAfter( $tokens, $first ) <= $to ) {
			return null;
		}

		if ( ! is_array( $tokens[ $first ] ?? null ) || T_VARIABLE !== $tokens[ $first ][0] || self::continuesAsExpression( $tokens, $first ) ) {
			return null;
		}

		$found = self::assigned( $tokens, $first, $owner );

		return 'texts' === $found['kind'] ? $found['texts'] : null;
	}

	/**
	 * Finds the function a position is in: its name, and its parameter list.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The position.
	 * @return array{at: int, name: string, open: int, close: int}|null The function, or null outside one.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function functionAround( array $tokens, int $at ): ?array {
		for ( $back = $at; $back >= 0; $back-- ) {
			if ( is_array( $tokens[ $back ] ) && T_FUNCTION === $tokens[ $back ][0] ) {
				$name = self::significantAfter( $tokens, $back );
				$name = '&' === $tokens[ $name ] ? self::significantAfter( $tokens, $name ) : $name;
				$open = self::significantAfter( $tokens, $name );
				$end  = '(' === ( $tokens[ $open ] ?? '' ) ? self::closingParenthesis( $tokens, $open ) : null;

				return is_array( $tokens[ $name ] ?? null ) && T_STRING === $tokens[ $name ][0] && null !== $end ? array(
					'at'    => $back,
					'name'  => $tokens[ $name ][1],
					'open'  => $open,
					'close' => $end,
				) : null;
			}
		}

		return null;
	}

	/**
	 * Tells whether a position is the call of a method of the class, and where its parenthesis opens.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $tokens The file's tokens.
	 * @param int    $at     The position of the name.
	 * @param string $name   The method.
	 * @return int|null Where the parenthesis of `$this->name(`, `self::name(` or `static::name(` opens; null when this is not such a call.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function callOf( array $tokens, int $at, string $name ): ?int {
		if ( ! is_array( $tokens[ $at ] ) || T_STRING !== $tokens[ $at ][0] || $tokens[ $at ][1] !== $name ) {
			return null;
		}

		$open     = self::significantAfter( $tokens, $at );
		$operator = $tokens[ self::significantBefore( $tokens, $at ) ] ?? '';
		$subject  = $tokens[ self::significantBefore( $tokens, self::significantBefore( $tokens, $at ) ) ] ?? '';

		if ( '(' !== ( $tokens[ $open ] ?? '' ) || ! is_array( $operator ) || ! is_array( $subject ) ) {
			return null;
		}

		$on_this = T_OBJECT_OPERATOR === $operator[0] && T_VARIABLE === $subject[0] && '$this' === $subject[1];
		$on_self = T_DOUBLE_COLON === $operator[0] && in_array( $subject[0], array( T_STRING, T_STATIC ), true ) && in_array( strtolower( $subject[1] ), array( 'self', 'static' ), true );

		return $on_this || $on_self ? $open : null;
	}

	/**
	 * Splits the tokens between two positions at the commas that are not inside brackets.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $from   The first token.
	 * @param int   $to     The last.
	 * @return list<array{0: int, 1: int}> The first and last token of each part; none for an empty list.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function splitAtCommas( array $tokens, int $from, int $to ): array {
		$parts = array();
		$depth = 0;
		$start = $from;

		for ( $at = $from; $at <= $to; $at++ ) {
			$depth += in_array( $tokens[ $at ], array( '(', '[', '{' ), true ) ? 1 : ( in_array( $tokens[ $at ], array( ')', ']', '}' ), true ) ? -1 : 0 );

			if ( 0 === $depth && ',' === $tokens[ $at ] ) {
				$parts[] = array( $start, $at - 1 );
				$start   = $at + 1;
			}
		}

		if ( $start <= $to && self::significantAfter( $tokens, $start - 1 ) <= $to ) {
			$parts[] = array( $start, $to );
		}

		return $parts;
	}

	/**
	 * Lists the texts of an expression that is a literal, a constant of the class, or a ternary of two of those.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $from   The first token of the expression.
	 * @param int         $to     The last.
	 * @param string|null $owner  The file's class.
	 * @return list<string>|null The texts, or null when it is anything else.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function choices( array $tokens, int $from, int $to, ?string $owner ): ?array {
		$depth    = 0;
		$question = null;
		$colon    = null;

		for ( $at = $from; $at <= $to; $at++ ) {
			$token  = $tokens[ $at ];
			$depth += in_array( $token, array( '(', '[', '{' ), true ) ? 1 : ( in_array( $token, array( ')', ']', '}' ), true ) ? -1 : 0 );

			if ( 0 === $depth && '?' === $token ) {
				if ( null !== $question ) {
					return null;
				}

				$question = $at;
			} elseif ( 0 === $depth && ':' === $token && null !== $question && null === $colon ) {
				$colon = $at;
			}
		}

		if ( null === $question ) {
			$only = self::branch( $tokens, $from, $to, $owner );

			return null === $only ? null : array( $only );
		}

		$yes = null === $colon ? null : self::branch( $tokens, $question + 1, $colon - 1, $owner );
		$no  = null === $colon ? null : self::branch( $tokens, $colon + 1, $to, $owner );

		return null === $yes || null === $no ? null : array( $yes, $no );
	}

	/**
	 * Reads one branch of a ternary: string literals joined by dots, or `self::NAME` naming a string constant of the class.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens The file's tokens.
	 * @param int         $from   The first token.
	 * @param int         $to     The last.
	 * @param string|null $owner  The file's class.
	 * @return string|null Its text, or null when it is anything else.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function branch( array $tokens, int $from, int $to, ?string $owner ): ?string {
		$significant = array();

		for ( $at = $from; $at <= $to; $at++ ) {
			if ( ! is_array( $tokens[ $at ] ) || ! in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$significant[] = $tokens[ $at ];
			}
		}

		$text  = '';
		$at    = 0;
		$count = count( $significant );

		while ( $at < $count ) {
			$token = $significant[ $at ];

			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$text .= stripcslashes( substr( $token[1], 1, -1 ) );
				++$at;
			} else {
				$value = self::classConstant( array_slice( $significant, $at, 3 ), $owner );

				if ( null === $value ) {
					return null;
				}

				$text .= $value;
				$at   += 3;
			}

			if ( $at < $count && ( '.' !== $significant[ $at ] || ++$at >= $count ) ) {
				return null;
			}
		}

		return $count > 0 ? $text : null;
	}

	/**
	 * Reads `self::NAME` or `static::NAME`: the value of a string constant of the class.
	 *
	 * @since 0.2.0
	 *
	 * @param array       $tokens Three significant tokens.
	 * @param string|null $owner  The file's class.
	 * @return string|null The value, or null when the tokens are anything else or the constant is no string.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function classConstant( array $tokens, ?string $owner ): ?string {
		if ( null === $owner || 3 !== count( $tokens ) || ! is_array( $tokens[0] ) || ! is_array( $tokens[1] ) || ! is_array( $tokens[2] ) ) {
			return null;
		}

		if ( ! in_array( $tokens[0][0], array( T_STRING, T_STATIC ), true ) || ! in_array( strtolower( $tokens[0][1] ), array( 'self', 'static' ), true ) || T_DOUBLE_COLON !== $tokens[1][0] || T_STRING !== $tokens[2][0] ) {
			return null;
		}

		try {
			$value = ( new \ReflectionClassConstant( $owner, $tokens[2][1] ) )->getValue();
		} catch ( \ReflectionException $missing ) {
			unset( $missing );

			return null;
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Finds the parenthesis that closes the one at a position.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $open   Where the opening parenthesis is.
	 * @return int|null Where the closing one is, or null when the file ends first.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function closingParenthesis( array $tokens, int $open ): ?int {
		$depth = 0;
		$count = count( $tokens );

		for ( $at = $open; $at < $count; $at++ ) {
			$depth += '(' === $tokens[ $at ] ? 1 : ( ')' === $tokens[ $at ] ? -1 : 0 );

			if ( 0 === $depth ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Tells whether a variable is the start of a longer expression: a property, an element, a call or a static member.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     The variable's position.
	 * @return bool True when it is.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function continuesAsExpression( array $tokens, int $at ): bool {
		$next = $tokens[ self::significantAfter( $tokens, $at ) ] ?? '';

		return in_array( $next, array( '[', '(' ), true ) || ( is_array( $next ) && in_array( $next[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) );
	}

	/**
	 * Appends each of some texts to each of the texts a statement can take so far.
	 *
	 * A text that a parameter gave belongs to one call of the function, and combines only with the
	 * texts of the same call: the arguments of a call go together, so a statement that ends in two
	 * parameters has one shape for each call, not one for each pair of values.
	 *
	 * @since 0.2.0
	 *
	 * @param array                $parts  The texts so far, each with the call it is bound to, if any.
	 * @param string[]             $append The texts to add.
	 * @param array<int, int|null> $calls  The call each text to add belongs to, or null.
	 * @return list<array{text: string, call: int|null}> Every combination, once.
	 *
	 * @phpstan-param list<array{text: string, call: int|null}> $parts
	 */
	private static function appended( array $parts, array $append, array $calls ): array {
		$all = array();

		foreach ( $parts as $part ) {
			foreach ( $append as $index => $addition ) {
				$call = $calls[ $index ] ?? null;

				if ( null !== $part['call'] && null !== $call && $part['call'] !== $call ) {
					continue;
				}

				$bound = $part['call'] ?? $call;

				$all[ $part['text'] . $addition . "\0" . ( $bound ?? '' ) ] = array(
					'text' => $part['text'] . $addition,
					'call' => $bound,
				);
			}
		}

		return array_values( $all );
	}

	/**
	 * Tells what the run of literals that begins a statement follows, when it is not a statement's start.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param array $run    The run, as literalRun() returns it.
	 * @return string|null 'before' when a dot joins it to what precedes, 'sprintf' when it opens a call of sprintf(); null when neither.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-param array{start: int, end: int, pieces: list<array{0: string, 1: int}>} $run
	 * @phpstan-return 'before'|'sprintf'|null
	 */
	private static function precededBy( array $tokens, array $run ): ?string {
		$before = self::significantBefore( $tokens, $run['start'] );

		if ( '.' === ( $tokens[ $before ] ?? '' ) ) {
			return 'before';
		}

		$caller = $tokens[ self::significantBefore( $tokens, $before ) ] ?? '';

		return '(' === ( $tokens[ $before ] ?? '' ) && is_array( $caller ) && 1 === preg_match( '/^v?sprintf$/i', $caller[1] ) ? 'sprintf' : null;
	}

	/**
	 * Builds the refusal of a statement that cannot be read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name   The file's name.
	 * @param int    $line   The line the statement starts on.
	 * @param string $joined What it is joined to: a key of JOINED.
	 * @return \UnexpectedValueException The exception to throw.
	 */
	private static function refusal( string $name, int $line, string $joined ): \UnexpectedValueException {
		return new \UnexpectedValueException( sprintf( '%s:%d starts a SELECT that is %s, so its statement cannot be read from the source. Write the statement as one class constant, or as string literals.', $name, $line, self::JOINED[ $joined ] ) );
	}

	/**
	 * Finds the reads written as constants of the given classes, each with its evaluated value.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $classes The classes, loaded by their autoloader.
	 * @return array<string, list<string>> Each read's shape, keyed by `Class::CONSTANT`.
	 *
	 * @throws \UnexpectedValueException When a class cannot be loaded or a constant cannot be evaluated.
	 *
	 * @phpstan-param list<class-string> $classes
	 */
	private static function constantHeads( array $classes ): array {
		$heads = array();

		foreach ( $classes as $owner ) {
			try {
				$reflection = new \ReflectionClass( $owner );

				foreach ( $reflection->getReflectionConstants() as $constant ) {
					$value = $constant->isEnumCase() || $constant->getDeclaringClass()->getName() !== $reflection->getName() ? null : $constant->getValue();

					$heads = self::merged( $heads, self::readsIn( $value, $reflection->getName() . '::' . $constant->getName() ) );
				}
			} catch ( \Throwable $failure ) {
				throw new \UnexpectedValueException( sprintf( '%s has a constant the read inventory cannot evaluate (%s), so the reads it holds cannot be judged.', $owner, $failure->getMessage() ), 0, $failure );
			}
		}

		return $heads;
	}

	/**
	 * Tells whether a class constant of a file could be a read: its declaration holds a SELECT literal, or takes another constant's value.
	 *
	 * Only such a file's classes are loaded, so a class that extends WordPress is never asked for
	 * its constants unless one of them could be a statement.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens, inside a class when it declares one.
	 * @return bool True when a constant could be a read.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function constantsMayHoldReads( array $tokens ): bool {
		$constant = false;

		foreach ( $tokens as $at => $token ) {
			if ( ';' === $token ) {
				$constant = false;
			} elseif ( is_array( $token ) && T_CONST === $token[0] ) {
				$constant = true;
			} elseif ( $constant && is_array( $token ) ) {
				$fetches = T_DOUBLE_COLON === $token[0] && is_array( $tokens[ self::significantAfter( $tokens, $at ) ] ?? null ) && T_STRING === $tokens[ self::significantAfter( $tokens, $at ) ][0];

				$selects = T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( '/\bSELECT\b/i', implode( '', array_column( self::literalRun( $tokens, $at )['pieces'], 0 ) ) );

				if ( $fetches || $selects ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Names the classes, interfaces, traits and enums a file declares.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @return string[] Their full names.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 * @phpstan-return list<class-string>
	 */
	private static function classesIn( array $tokens ): array {
		$namespace = '';
		$classes   = array();

		foreach ( $tokens as $at => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}

			$name = $tokens[ self::significantAfter( $tokens, $at ) ] ?? '';

			if ( T_NAMESPACE === $token[0] && is_array( $name ) && in_array( $name[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
				$namespace = $name[1] . '\\';
			} elseif ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) && is_array( $name ) && T_STRING === $name[0] ) {
				$classes[] = $namespace . $name[1];
			}
		}

		return $classes;
	}

	/**
	 * Finds the SELECT a constant's value is: the value itself, or the SELECT an INSERT ... SELECT ends in.
	 *
	 * The SELECT of an INSERT ... SELECT is a read when it reads a table in its own FROM clause. One
	 * that selects from DUAL or from a derived table of values is a way of writing VALUES, and reads
	 * nothing a plan could judge.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string|null The SELECT, or null when the value is none.
	 */
	private static function selectIn( string $value ): ?string {
		if ( 1 === preg_match( '/^\s*SELECT\b/i', $value ) ) {
			return $value;
		}

		$found = array();

		if ( 1 !== preg_match( '/^\s*INSERT\b.*?\b(SELECT\b.*)$/is', $value, $found ) || 1 !== preg_match( '/^SELECT\b(?:(?!\bFROM\b).)*\bFROM\s+(?:%i|\{[a-z_]+\})/is', $found[1] ) ) {
			return null;
		}

		return $found[1];
	}

	/**
	 * Finds the reads in a constant's value: the value itself, or the values of an array, at any depth.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $value The evaluated value.
	 * @param string $name  What the read is called: `Class::CONSTANT`, with `[key]` for each array level.
	 * @return array<string, list<string>> Each read's shape, keyed by its name.
	 *
	 * @throws \UnexpectedValueException When a value is a WITH or a UNION of SELECTs, or two reads have one name.
	 */
	private static function readsIn( mixed $value, string $name ): array {
		if ( is_string( $value ) ) {
			if ( self::isCompound( $value ) ) {
				throw new \UnexpectedValueException( sprintf( '%s holds a SELECT written as a WITH or a UNION, so its statement cannot be read from the source. Write it as a SELECT, or as an INSERT ... SELECT.', $name ) );
			}

			$select = self::selectIn( $value );

			if ( null === $select ) {
				return array();
			}

			// A read that stops at IN takes the placeholder list of an IN clause, `( %s, … )`.
			$select = 1 === preg_match( '/\bIN\s*$/i', $select ) ? rtrim( $select ) . ' ( {list} )' : $select;

			return array( $name => array( self::shapeOf( $select ) ) );
		}

		$reads = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$reads = self::merged( $reads, self::readsIn( $item, $name . '[' . $key . ']' ) );
			}
		}

		return $reads;
	}

	/**
	 * Finds the nearest token before a position that is neither white space nor a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     Where to start looking, before this one.
	 * @return int Its position; minus one when there is none.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function significantBefore( array $tokens, int $at ): int {
		do {
			--$at;
		} while ( $at >= 0 && is_array( $tokens[ $at ] ) && in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );

		return $at;
	}

	/**
	 * Finds the next token that is neither white space nor a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The file's tokens.
	 * @param int   $at     Where to start looking, after this one.
	 * @return int Its position; one past the end when there is none.
	 *
	 * @phpstan-param list<array{0: int, 1: string, 2: int}|string> $tokens
	 */
	private static function significantAfter( array $tokens, int $at ): int {
		do {
			++$at;
		} while ( is_array( $tokens[ $at ] ?? null ) && in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );

		return $at;
	}

	/**
	 * Returns the shape of a head: its placeholders given values, and its table tokens a stand-in, then shaped as a sent statement is.
	 *
	 * @since 0.1.0
	 *
	 * @param string $head The literal's text.
	 * @return string The shape.
	 */
	private static function shapeOf( string $head ): string {
		$text = str_replace( array( '{list}', '%d', '%s' ), array( '0', '0', "'x'" ), $head );
		$text = (string) preg_replace( '/%i|\{[a-z_]+\}/', self::TABLE, $text );

		return ( new Statement( $text, '' ) )->shape();
	}
}
