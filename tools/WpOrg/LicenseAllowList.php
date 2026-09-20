<?php
/**
 * LicenseAllowList: the licences a runtime dependency may carry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Decides whether an SPDX licence expression is compatible with GPL-3.0-or-later.
 *
 * This class owns one fact: which licences code shipped inside the plugin may carry.
 * SEOCart is distributed as GPL-3.0-or-later, and the WordPress.org directory holds the
 * developer responsible for every bundled file (guidelines 1 and 2). The list is
 * explicit on purpose. A licence that is not on it fails the gate until somebody has
 * checked it against https://www.gnu.org/licenses/license-list.html and added it here.
 *
 * Deliberately absent: `GPL-2.0-only`, which cannot be combined with GPLv3 code, and the
 * deprecated bare identifiers (`GPL-2.0`, `GPL-3.0`, `LGPL-2.1`), which do not say whether
 * they mean "only" or "or later".
 *
 * @since 0.1.0
 */
final class LicenseAllowList {

	/**
	 * The allowed SPDX licence identifiers. The one list.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const ALLOWED = array(
		'GPL-3.0-or-later',
		'GPL-3.0-only',
		'GPL-2.0-or-later',
		'LGPL-3.0-or-later',
		'LGPL-3.0-only',
		'LGPL-2.1-or-later',
		'LGPL-2.1-only',
		'MIT',
		'MIT-0',
		'BSD-2-Clause',
		'BSD-3-Clause',
		'ISC',
		'0BSD',
		'Zlib',
		'Apache-2.0',
		'MPL-2.0',
		'CC0-1.0',
		'Unlicense',
	);

	/**
	 * The tokens of the expression being evaluated.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $tokens;

	/**
	 * Index of the next token to read.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $tokens The tokens of one expression.
	 */
	private function __construct( array $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Determines whether a licence expression permits shipping the package under GPLv3.
	 *
	 * `A OR B` passes when either alternative is allowed, because the recipient may choose.
	 * `A AND B` passes only when both are allowed, because both apply at once. `AND` binds
	 * tighter than `OR`; parentheses group. `A WITH exception` is allowed only when that
	 * exact combination is on the list. The deprecated `+` suffix means `-or-later`.
	 * Identifiers and operators are compared without regard to letter case.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the expression is empty or malformed.
	 *
	 * @param string $expression An SPDX licence expression, for example '(MIT OR GPL-2.0-only)'.
	 * @return bool True when the expression is satisfied by allowed licences alone.
	 */
	public static function permits( string $expression ): bool {
		preg_match_all( '/[()]|[^\s()]+/', $expression, $matches );

		$parser = new self( $matches[0] );
		$result = $parser->parseOr();

		if ( $parser->position < count( $parser->tokens ) ) {
			throw new \InvalidArgumentException( "Unexpected \"{$parser->tokens[ $parser->position ]}\"." );
		}

		return $result;
	}

	/**
	 * Parses `term ( OR term )*`.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether any alternative is allowed.
	 */
	private function parseOr(): bool {
		$allowed = $this->parseAnd();

		while ( $this->nextIs( 'OR' ) ) {
			++$this->position;
			$allowed = $this->parseAnd() || $allowed;
		}

		return $allowed;
	}

	/**
	 * Parses `atom ( AND atom )*`.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether every conjunct is allowed.
	 */
	private function parseAnd(): bool {
		$allowed = $this->parseAtom();

		while ( $this->nextIs( 'AND' ) ) {
			++$this->position;
			$allowed = $this->parseAtom() && $allowed;
		}

		return $allowed;
	}

	/**
	 * Parses a parenthesized expression, or a licence identifier with an optional exception.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the expression is malformed.
	 *
	 * @return bool Whether the atom is allowed.
	 */
	private function parseAtom(): bool {
		$token = $this->tokens[ $this->position ] ?? null;

		if ( null === $token || ')' === $token || $this->nextIs( 'OR' ) || $this->nextIs( 'AND' ) || $this->nextIs( 'WITH' ) ) {
			throw new \InvalidArgumentException( 'A licence identifier is missing.' );
		}

		++$this->position;

		if ( '(' === $token ) {
			$allowed = $this->parseOr();

			if ( ')' !== ( $this->tokens[ $this->position ] ?? null ) ) {
				throw new \InvalidArgumentException( 'A closing parenthesis is missing.' );
			}

			++$this->position;
			return $allowed;
		}

		if ( $this->nextIs( 'WITH' ) ) {
			$exception = $this->tokens[ $this->position + 1 ] ?? null;

			if ( null === $exception || in_array( $exception, array( '(', ')' ), true ) ) {
				throw new \InvalidArgumentException( 'WITH must be followed by an exception identifier.' );
			}

			$this->position += 2;
			$token          .= ' WITH ' . $exception;
		}

		if ( str_ends_with( $token, '+' ) ) {
			$token = substr( $token, 0, -1 ) . '-or-later';
		}

		return in_array( strtolower( $token ), array_map( 'strtolower', self::ALLOWED ), true );
	}

	/**
	 * Determines whether the next token is the given operator.
	 *
	 * @since 0.1.0
	 *
	 * @param string $operator 'OR', 'AND' or 'WITH'.
	 * @return bool True when it is.
	 */
	private function nextIs( string $operator ): bool {
		return strtoupper( $this->tokens[ $this->position ] ?? '' ) === $operator;
	}
}
