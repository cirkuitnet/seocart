<?php
/**
 * CheckResult: what one doctor check found
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * The typed outcome of a check: passed or failed, one sentence, and a line per finding.
 *
 * Owns one fact: what a check reports. A failed check lists each problem it found on a line
 * of its own; a passed one lists none. The text names tables, indexes, locks, migrations and
 * counts, never a stored value.
 *
 * @since 0.1.0
 */
final readonly class CheckResult {

	/**
	 * An identifier: letters, digits and the punctuation names are built with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const IDENTIFIER = '/^[A-Za-z0-9_.:$-]{1,191}$/D';

	/**
	 * The name of the check.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $check;

	/**
	 * Whether the check found nothing wrong.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $passed;

	/**
	 * One sentence saying what was checked and how it came out.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $summary;

	/**
	 * One line per problem found; empty when the check passed.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $findings;

	/**
	 * Records an outcome. Use pass() or fail().
	 *
	 * @since 0.1.0
	 *
	 * @param string   $check    The name of the check.
	 * @param bool     $passed   Whether it passed.
	 * @param string   $summary  One sentence.
	 * @param string[] $findings One line per problem.
	 *
	 * @phpstan-param list<string> $findings
	 */
	private function __construct( string $check, bool $passed, string $summary, array $findings ) {
		$this->check    = $check;
		$this->passed   = $passed;
		$this->summary  = $summary;
		$this->findings = $findings;
	}

	/**
	 * Records a check that found nothing wrong.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check   The name of the check.
	 * @param string $summary What was checked.
	 * @return self The outcome.
	 */
	public static function pass( string $check, string $summary ): self {
		return new self( $check, true, $summary, array() );
	}

	/**
	 * Records a check that found something wrong.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $check    The name of the check.
	 * @param string   $summary  How it came out.
	 * @param string[] $findings Optional. One line per problem. Default none.
	 * @return self The outcome.
	 */
	public static function fail( string $check, string $summary, array $findings = array() ): self {
		return new self( $check, false, $summary, array_values( $findings ) );
	}

	/**
	 * Returns a name read from the database, fit to print only if it is an identifier.
	 *
	 * Table, option, role, lock and hook names are written by code, but anything can be planted
	 * in a database. A name that is not an identifier is not printed, so no stored value can
	 * reach the output through a name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The name as stored.
	 * @return string The name, or a placeholder that says it was withheld.
	 */
	public static function identifier( string $name ): string {
		return 1 === preg_match( self::IDENTIFIER, $name ) ? $name : '(a name that is not an identifier, withheld)';
	}
}
