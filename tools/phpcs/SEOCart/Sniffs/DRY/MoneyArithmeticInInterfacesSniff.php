<?php
/**
 * Sniff: adapters format money, they never compute it
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
 * A syntax tripwire for direct spellings of DRY rule 7 in directories named `Interfaces`.
 *
 * An adapter (REST, WP-CLI, admin, block, e-mail) receives amounts that the calculation
 * pipeline has already computed and only formats them. Two shapes are reported:
 *
 * - `MethodCall`: a call to a method named in `$arithmeticMethods`.
 * - `Operator`: an arithmetic operator, its compound assignment, `++` or `--`, with an
 *   operand that ends in an accessor named in `$accessors`: `$total->minorUnits() / 100`.
 *   Parentheses that only group are looked into: `( $total->minorUnits() ) / 100`.
 *
 * The sniff reads tokens and knows no types, so it goes by name. Two kinds of call are never
 * reported: one on `$this`, `self`, `static` or `parent`, because an adapter is not a Money,
 * and one whose first argument begins with a string literal that is not a number, because a
 * Money operand is a Money or a number and never a word. The second keeps
 * `WP_Error::add( 'code', $message )` clean. Any other namesake, `DateTimeImmutable::add()`
 * for one, is reported; an adapter that really needs it carries a `phpcs:ignore` for this
 * sniff with the reason.
 *
 * Out of reach, because the sniff follows no values: an amount that is first assigned to a
 * variable (`$minor = $total->minorUnits(); $minor / 100`) and arithmetic spelled as a
 * function call (`intdiv( $total->minorUnits(), 100 )`, the bcmath functions). This sniff is
 * a tripwire for the direct shapes and does not replace review.
 *
 * The two lists below are a hand-maintained copy of the money API in src/Support (Money,
 * TaxedMoney, Decimal and the methods of other classes that return one of them), which makes
 * them a parallel list under DRY rule 11. Tests/MoneyApiListsTest.php is their companion
 * set-equality test: it derives the methods that compute an amount and the methods that expose
 * a raw number from the classes themselves, and fails when either list differs from the API.
 * A change to the money API updates the lists in the same change.
 *
 * @since 0.1.0
 */
final class MoneyArithmeticInInterfacesSniff implements Sniff {

	/**
	 * The name of the directory that holds a module's adapters.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const ADAPTER_DIRECTORY = 'Interfaces';

	/**
	 * The shipped code this rule governs. Set once for every DRY sniff, in ruleset.xml.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $shippedPaths = array();

	/**
	 * Names of the money API methods that compute an amount. Compared without regard to case.
	 *
	 * Exactly the public methods of the classes under src/Support that return a new Money,
	 * TaxedMoney or Decimal (or an array of them, from allocate()), other than static methods
	 * that build an amount from scalars and getters that return a stored component. Pinned by
	 * Tests/MoneyApiListsTest.php, which derives the set from the classes and fails when this
	 * list and the API differ.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $arithmeticMethods = array(
		'add',
		'allocate',
		'convertToBaseMoney',
		'convertToQuote',
		'convertToQuoteMoney',
		'divide',
		'fromGross',
		'fromNet',
		'multiply',
		'negate',
		'ofDecimal',
		'rescale',
		'roundToCashStep',
		'subtract',
		'toDecimal',
		'toFactor',
	);

	/**
	 * Names of the money API methods or properties that expose a raw number. Compared without regard to case.
	 *
	 * Exactly the public parameterless methods of Money, TaxedMoney and Decimal that return an
	 * int or a string: the minor units, and a Decimal's digits, scale and sign. Pinned by
	 * Tests/MoneyApiListsTest.php.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public $accessors = array( 'minorUnits', 'scale', 'sign', 'toString', 'toUnscaledInt' );

	/**
	 * Returns the tokens this sniff listens for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array_merge( $this->memberOperators(), $this->arithmeticOperators() );
	}

	/**
	 * Dispatches a member access or an arithmetic operator to its check.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the operator token.
	 * @return int|void The position to resume at, when the rest of the file can be skipped.
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		if ( ! PathScope::isUnder( $phpcsFile, $this->shippedPaths ) || ! PathScope::hasDirectory( $phpcsFile, self::ADAPTER_DIRECTORY ) ) {
			return $phpcsFile->numTokens;
		}

		$tokens = $phpcsFile->getTokens();

		if ( in_array( $tokens[ $stackPtr ]['code'], $this->memberOperators(), true ) ) {
			$this->processMemberAccess( $phpcsFile, $stackPtr );
		} else {
			$this->processOperator( $phpcsFile, $stackPtr );
		}
	}

	/**
	 * Reports `->add( ... )` and the other configured arithmetic methods.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the `->`, `?->` or `::` token.
	 */
	private function processMemberAccess( File $phpcsFile, int $stackPtr ): void {
		$tokens  = $phpcsFile->getTokens();
		$namePtr = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );

		if ( false === $namePtr || T_STRING !== $tokens[ $namePtr ]['code'] || ! $this->isNamed( $tokens[ $namePtr ]['content'], $this->arithmeticMethods ) ) {
			return;
		}

		$next = $phpcsFile->findNext( Tokens::$emptyTokens, $namePtr + 1, null, true );

		if ( false === $next || T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] ) {
			return;
		}

		$receiver = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );

		if ( false !== $receiver
			&& ( in_array( $tokens[ $receiver ]['code'], array( T_SELF, T_STATIC, T_PARENT ), true ) || '$this' === $tokens[ $receiver ]['content'] )
		) {
			return;
		}

		// A word is never a Money operand, so this is another class's method: WP_Error::add( 'code', $message ).
		$argument = $phpcsFile->findNext( Tokens::$emptyTokens, $next + 1, null, true );

		if ( false !== $argument
			&& T_CONSTANT_ENCAPSED_STRING === $tokens[ $argument ]['code']
			&& ! is_numeric( substr( $tokens[ $argument ]['content'], 1, -1 ) )
		) {
			return;
		}

		$phpcsFile->addError(
			'Money arithmetic in an adapter: %s(). Adapters format amounts and never compute them; move the calculation into the Application or Domain layer and pass the result in.',
			$namePtr,
			'MethodCall',
			array( $tokens[ $namePtr ]['content'] )
		);
	}

	/**
	 * Reports an arithmetic operator when either operand ends in a Money accessor.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the operator token.
	 */
	private function processOperator( File $phpcsFile, int $stackPtr ): void {
		$tokens = $phpcsFile->getTokens();
		$left   = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		$right  = $this->operandEnd( $phpcsFile, $stackPtr + 1 );

		if ( ( false === $left || ! $this->endsInAccessor( $phpcsFile, $left ) )
			&& ( null === $right || ! $this->endsInAccessor( $phpcsFile, $right ) )
		) {
			return;
		}

		$phpcsFile->addError(
			'Arithmetic on a Money amount in an adapter: "%s". Adapters format amounts and never compute them; move the calculation into the Application or Domain layer and pass the result in.',
			$stackPtr,
			'Operator',
			array( $tokens[ $stackPtr ]['content'] )
		);
	}

	/**
	 * Finds the last token of the operand that starts after an operator.
	 *
	 * Follows a chain of variables, names, member accesses, call arguments and array
	 * offsets: `(int) $line->total()->minorUnits()` ends at the final parenthesis. So does
	 * an operand that opens with a grouping parenthesis: `( $line->total() )->minorUnits()`.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $start     Position of the first token after the operator.
	 * @return int|null Position of the operand's last token, or null when it is not such a chain.
	 */
	private function operandEnd( File $phpcsFile, int $start ): ?int {
		$tokens = $phpcsFile->getTokens();
		$chain  = array_merge( $this->memberOperators(), array( T_VARIABLE, T_STRING, T_SELF, T_STATIC, T_PARENT, T_NS_SEPARATOR ) );
		$end    = null;

		for ( $i = $start; $i < $phpcsFile->numTokens; $i++ ) {
			$code = $tokens[ $i ]['code'];

			if ( isset( Tokens::$emptyTokens[ $code ] ) || ( null === $end && isset( Tokens::$castTokens[ $code ] ) ) ) {
				continue;
			}

			if ( T_OPEN_PARENTHESIS === $code && isset( $tokens[ $i ]['parenthesis_closer'] ) ) {
				$i = $tokens[ $i ]['parenthesis_closer'];
			} elseif ( null !== $end && T_OPEN_SQUARE_BRACKET === $code && isset( $tokens[ $i ]['bracket_closer'] ) ) {
				$i = $tokens[ $i ]['bracket_closer'];
			} elseif ( ! in_array( $code, $chain, true ) ) {
				break;
			}

			$end = $i;
		}

		return $end;
	}

	/**
	 * Determines whether an operand's last token closes a Money accessor: `->minorUnits()` or `->minorUnits`.
	 *
	 * Parentheses that only group are looked into, `( $total->minorUnits() )`. Those of a call or
	 * of a language construct are not: `strlen( (string) $total->minorUnits() )` is a length.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $end       Position of the operand's last token.
	 * @return bool True when the operand ends in a configured accessor.
	 */
	private function endsInAccessor( File $phpcsFile, int $end ): bool {
		$tokens  = $phpcsFile->getTokens();
		$namePtr = $end;

		if ( T_CLOSE_PARENTHESIS === $tokens[ $end ]['code'] ) {
			if ( ! isset( $tokens[ $end ]['parenthesis_opener'] ) ) {
				return false;
			}

			$opener  = $tokens[ $end ]['parenthesis_opener'];
			$namePtr = $phpcsFile->findPrevious( Tokens::$emptyTokens, $opener - 1, null, true );
			$called  = array( T_STRING, T_VARIABLE, T_CLOSE_PARENTHESIS, T_CLOSE_SQUARE_BRACKET, T_CLOSE_CURLY_BRACKET, T_SELF, T_STATIC, T_PARENT, T_ISSET, T_EMPTY );

			if ( ! isset( $tokens[ $end ]['parenthesis_owner'] ) && ( false === $namePtr || ! in_array( $tokens[ $namePtr ]['code'], $called, true ) ) ) {
				$inner = $phpcsFile->findPrevious( Tokens::$emptyTokens, $end - 1, $opener + 1, true );

				return false !== $inner && $this->endsInAccessor( $phpcsFile, $inner );
			}
		}

		if ( false === $namePtr || T_STRING !== $tokens[ $namePtr ]['code'] || ! $this->isNamed( $tokens[ $namePtr ]['content'], $this->accessors ) ) {
			return false;
		}

		$operator = $phpcsFile->findPrevious( Tokens::$emptyTokens, $namePtr - 1, null, true );

		return false !== $operator && in_array( $tokens[ $operator ]['code'], $this->memberOperators(), true );
	}

	/**
	 * Determines whether a name is in a configured list, without regard to case, as PHP resolves methods.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name  The name found in the code.
	 * @param string[] $names The configured names.
	 * @return bool True when the name is listed.
	 */
	private function isNamed( string $name, array $names ): bool {
		return in_array( strtolower( $name ), array_map( 'strtolower', $names ), true );
	}

	/**
	 * Returns the tokens that access a member of an object or a class.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string>
	 */
	private function memberOperators(): array {
		return array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON );
	}

	/**
	 * Returns the arithmetic operators, their compound assignments, and `++` and `--`.
	 *
	 * A method instead of a constant, because PHP_CodeSniffer defines some of these tokens at run time.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string>
	 */
	private function arithmeticOperators(): array {
		return array(
			T_PLUS,
			T_MINUS,
			T_MULTIPLY,
			T_DIVIDE,
			T_MODULUS,
			T_POW,
			T_PLUS_EQUAL,
			T_MINUS_EQUAL,
			T_MUL_EQUAL,
			T_DIV_EQUAL,
			T_MOD_EQUAL,
			T_POW_EQUAL,
			T_INC,
			T_DEC,
		);
	}
}
