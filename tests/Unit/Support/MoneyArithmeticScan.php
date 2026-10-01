<?php
/**
 * MoneyArithmeticScan: finds where PHP source computes an amount
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

/**
 * Reads PHP source for arithmetic on amounts: a call of a method that computes one, or an arithmetic operator in a statement that reads an amount's raw number.
 *
 * Owns one fact: how the scans that hold a module to no money arithmetic read its tokens. The
 * names of the money API's arithmetic methods and raw accessors are the money-arithmetic sniff's
 * own lists, read from its source, so they are written in one place. A call on `$this`, `self`,
 * `static` or `parent` is never one: a service is not an amount.
 *
 * @since 0.1.0
 */
final class MoneyArithmeticScan {

	/**
	 * The sniff whose lists name the money API.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SNIFF = 'tools/phpcs/SEOCart/Sniffs/DRY/MoneyArithmeticInInterfacesSniff.php';

	/**
	 * Receivers the scan never reports: an adapter, or a service, is not an amount.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const OWN = array( '$this', 'self', 'static', 'parent' );

	/**
	 * Returns the names of the methods that compute an amount, from the sniff, and `sum`.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function arithmeticMethods(): array {
		return array_merge( self::sniffList( 'arithmeticMethods' ), array( 'sum' ) );
	}

	/**
	 * Returns the names of the methods that expose an amount's raw number, from the sniff.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	public static function accessors(): array {
		return self::sniffList( 'accessors' );
	}

	/**
	 * Lists the arithmetic a source performs on amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $source    PHP source.
	 * @param string[] $methods   Optional. The names of the methods that compute an amount. Default arithmeticMethods().
	 * @param string[] $accessors Optional. The names of the methods that expose an amount's raw number. Default accessors().
	 * @return list<string> One line per violation: the line number and what was found.
	 *
	 * @phpstan-param list<string>|null $methods
	 * @phpstan-param list<string>|null $accessors
	 */
	public static function violations( string $source, ?array $methods = null, ?array $accessors = null ): array {
		$tokens     = array_values( array_filter( token_get_all( $source ), static fn( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
		$lowerNames = array_map( 'strtolower', $methods ?? self::arithmeticMethods() );
		$lowerRaw   = array_map( 'strtolower', $accessors ?? self::accessors() );
		$found      = array();
		$statement  = array(
			'raw'      => false,
			'operator' => false,
			'line'     => 0,
		);

		foreach ( $tokens as $index => $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$line = is_array( $token ) ? $token[2] : $statement['line'];
			$next = $tokens[ $index + 1 ] ?? '';
			$prev = $tokens[ $index - 1 ] ?? '';

			if ( in_array( $text, array( ';', '{', '}' ), true ) ) {
				if ( $statement['raw'] && $statement['operator'] ) {
					$found[] = $statement['line'] . ': arithmetic on an amount\'s raw number';
				}

				$statement = array(
					'raw'      => false,
					'operator' => false,
					'line'     => $line,
				);

				continue;
			}

			$statement['line'] = $line;

			if ( ! is_array( $token ) || T_STRING !== $token[0] || '(' !== $next ) {
				$statement['operator'] = $statement['operator'] || self::isArithmetic( $token );

				continue;
			}

			$called   = is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true );
			$receiver = $tokens[ $index - 2 ] ?? '';
			$own      = in_array( is_array( $receiver ) ? $receiver[1] : $receiver, self::OWN, true );

			if ( $called && ! $own && in_array( strtolower( $text ), $lowerNames, true ) ) {
				$found[] = $line . ': ' . $text . '()';
			}

			if ( $called && in_array( strtolower( $text ), $lowerRaw, true ) ) {
				$statement['raw'] = true;
			}

			if ( ! $called && 'array_sum' === strtolower( $text ) ) {
				$found[] = $line . ': array_sum()';
			}
		}

		return $found;
	}

	/**
	 * Tells whether a token is an arithmetic operator.
	 *
	 * @since 0.1.0
	 *
	 * @param array|string $token The token.
	 * @return bool True for + - * / % and their compound and increment forms.
	 *
	 * @phpstan-param array{0: int, 1: string, 2: int}|string $token
	 */
	private static function isArithmetic( array|string $token ): bool {
		if ( ! is_array( $token ) ) {
			return in_array( $token, array( '+', '-', '*', '/', '%' ), true );
		}

		return in_array( $token[0], array( T_INC, T_DEC, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW, T_POW_EQUAL ), true );
	}

	/**
	 * Reads one of the sniff's lists of names from its source.
	 *
	 * @since 0.1.0
	 *
	 * @param string $property The list's property: `arithmeticMethods` or `accessors`.
	 * @return list<string> The names.
	 */
	private static function sniffList( string $property ): array {
		$source = (string) file_get_contents( PhpSource::root() . '/' . self::SNIFF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A source file of the repository.

		if ( 1 !== preg_match( '/public \$' . $property . ' = array\((.*?)\);/s', $source, $list ) ) {
			return array();
		}

		preg_match_all( "/'([A-Za-z]+)'/", $list[1], $names );

		return $names[1];
	}
}
