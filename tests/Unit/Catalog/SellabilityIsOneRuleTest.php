<?php
/**
 * Tests that whether a variant may be sold is decided in one place, from one fetch
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * The sellability rule has one home, and nothing under src/ grows a second one.
 *
 * Four structural checks over the shipped source:
 *
 * - The generation marker's column is named in a string, where SQL is written, by two files only:
 *   the table declaration and the product repository. No other class can select or filter on it.
 * - Inside the repository, the marker is compared with a value only in the statements that write
 *   it: the creation of a product, markUpdating() and relock() through remark(), leaveUpdating()
 *   and restoreMark(). The two
 *   reads that name it only select it: sellabilityFacts(), the one fetch the rule is fed by, and
 *   load(), which carries it as state.
 * - The marker's states are compared in PHP (`===`, `!==`, `==`, `!=`, a `case` or a `match`
 *   arm with a GenerationState case) only in Catalog\Domain and in the repository, so no reader
 *   decides a sale from a loaded product's state either.
 * - A verdict is named (`SellabilityReason::` followed by a case, or by from() or tryFrom()) only
 *   by the rule, Catalog\Domain\Sellability, and by the enum itself. SellabilityReason::cases(),
 *   which lists the verdicts and decides none, may be read anywhere, as a schema does.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - Add src/Catalog/Application/Query/Planted.php declaring a class with a method that returns
 *   `"SELECT id FROM products WHERE generation_state = 'complete'"`: the first check names the file.
 * - Add a method `sellable()` to MysqlProductRepository that sends
 *   `SELECT id FROM %i WHERE generation_state = 'complete'`: the second check names the method.
 * - Add src/Catalog/Application/Query/Planted.php declaring a class with a method that returns
 *   `GenerationState::Complete === $product->generation()`: the third check names the file.
 * - In Query\Sellability::of(), return `SellabilityReason::Sellable` for a variant it has facts
 *   for: the fourth check names the file.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class SellabilityIsOneRuleTest extends TestCase {

	/**
	 * The generation marker's column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MARKER = 'generation_state';

	/**
	 * The directory of the catalog's domain, where the rule and the aggregate live.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DOMAIN = 'src/Catalog/Domain/';

	/**
	 * The file that declares the column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DECLARATION = 'src/Catalog/Infrastructure/CatalogTables.php';

	/**
	 * The one class that reads and writes the column.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REPOSITORY = 'src/Catalog/Infrastructure/MysqlProductRepository.php';

	/**
	 * The repository's statements that write the marker, and may compare it with a value.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const MARKER_WRITES = array( 'insertProduct', 'markUpdating', 'remark', 'leaveUpdating', 'restoreMark' );

	/**
	 * The repository's reads that select the marker.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const MARKER_READS = array( 'sellabilityFacts', 'load' );

	/**
	 * The files that may name a verdict: the rule and the enum.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const VERDICT_NAMERS = array( 'src/Catalog/Domain/Sellability.php', 'src/Catalog/Domain/SellabilityReason.php' );

	/**
	 * Tests that only the declaration and the repository name the marker's column in a string.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_repository_writes_sql_on_the_marker(): void {
		$naming = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			foreach ( PhpSource::tokens( $source ) as $token ) {
				if ( self::isString( $token ) && str_contains( $token->text, self::MARKER ) ) {
					$naming[ $file ] = true;
				}
			}
		}

		$this->assertArrayHasKey( self::REPOSITORY, $naming, 'The search did not find the repository\'s SQL on the marker, so an empty result would prove nothing.' );
		$this->assertSame( array( self::DECLARATION, self::REPOSITORY ), array_keys( $naming ), 'Only the repository may read or write the generation marker; a reader asks Query\\Sellability.' );
	}

	/**
	 * Tests that inside the repository the marker is compared with a value only in the statements that write it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_marker_is_a_condition_only_where_it_is_written(): void {
		$naming = array();
		$tests  = array();

		foreach ( self::stringsByMethod( (string) file_get_contents( PhpSource::root() . '/' . self::REPOSITORY ) ) as $method => $literals ) {
			$named = array_filter( $literals, static fn( string $literal ): bool => str_contains( $literal, self::MARKER ) );

			if ( array() === $named ) {
				continue;
			}

			$naming[] = $method;

			if ( ! in_array( $method, self::MARKER_WRITES, true ) && array() !== array_filter( $named, array( self::class, 'comparesTheMarker' ) ) ) {
				$tests[] = $method;
			}
		}

		sort( $naming );

		$expected = array_merge( self::MARKER_WRITES, self::MARKER_READS );

		sort( $expected );

		$this->assertContains( 'sellabilityFacts', $naming, 'The one fetch does not select the marker, so the rule is fed without it.' );
		$this->assertSame( $expected, $naming, 'A repository method reads or writes the marker that is neither the fetch, the load nor a marker write.' );
		$this->assertSame( array(), $tests, 'These repository methods compare the marker with a value; the verdict is Sellability\'s, from sellabilityFacts().' );
	}

	/**
	 * Tests that the marker's states are compared in PHP only in Catalog\Domain and in the repository.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_domain_compares_the_markers_states(): void {
		$comparing = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			if ( self::comparesAState( $source ) ) {
				$comparing[] = $file;
			}
		}

		$this->assertContains( 'src/Catalog/Domain/Sellability.php', $comparing, 'The search did not find the rule comparing the marker\'s states, so an empty result would prove nothing.' );

		$outside = array_values(
			array_filter(
				$comparing,
				static fn( string $file ): bool => ! str_starts_with( $file, self::DOMAIN ) && self::REPOSITORY !== $file
			)
		);

		$this->assertSame( array(), $outside, 'These files decide on a product\'s generation marker; the verdict is Sellability\'s, through Query\\Sellability.' );
	}

	/**
	 * Tests that only the rule names a verdict; listing them all is allowed anywhere.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_rule_names_a_verdict(): void {
		$naming = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			$tokens = PhpSource::tokens( $source );

			foreach ( $tokens as $index => $token ) {
				$next   = $tokens[ $index + 1 ] ?? null;
				$member = $tokens[ $index + 2 ] ?? null;

				if ( ! $token->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ) ) || ! str_ends_with( $token->text, 'SellabilityReason' ) || null === $next || ! $next->is( T_DOUBLE_COLON ) ) {
					continue;
				}

				if ( null === $member || 'cases' !== $member->text ) {
					$naming[ $file ] = true;
				}
			}
		}

		$this->assertArrayHasKey( self::VERDICT_NAMERS[0], $naming, 'The search did not find the rule naming its verdicts, so an empty result would prove nothing.' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $naming ), self::VERDICT_NAMERS ) ), 'Only Catalog\\Domain\\Sellability decides a verdict; every reader asks Query\\Sellability.' );
	}

	/**
	 * Tests that the source readers find what they are shown, so the checks above cannot pass by finding nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_readers_find_what_they_are_shown(): void {
		$source = <<<'PHP'
<?php
final class Shown {
	public function one(): string {
		$inner = static fn(): string => 'SELECT a FROM t WHERE generation_state = %s';
		return 'x' . $inner();
	}
	private function two(): void {
		$sql = "UPDATE t SET b = 1";
	}
}
PHP;

		$this->assertSame(
			array(
				'one' => array( "'SELECT a FROM t WHERE generation_state = %s'", "'x'" ),
				'two' => array( '"UPDATE t SET b = 1"' ),
			),
			self::stringsByMethod( $source )
		);

		$this->assertTrue( self::comparesTheMarker( "'SELECT a FROM t WHERE p.generation_state = %s'" ) );
		$this->assertTrue( self::comparesTheMarker( "' AND generation_state IN ( %s, %s )'" ) );
		$this->assertTrue( self::comparesTheMarker( "\"WHERE 'complete' = generation_state\"" ) );
		$this->assertFalse( self::comparesTheMarker( "'SELECT p.generation_state, p.id FROM t'" ) );
		$this->assertFalse( self::comparesTheMarker( "'generation_state'" ) );

		$php = static fn( string $body ): string => "<?php\nuse SEOCart\\Catalog\\Domain\\GenerationState;\nfinal class Shown { public function f( \$p ) { {$body} } }\n";

		$this->assertTrue( self::comparesAState( $php( 'return GenerationState::Complete === $p->generation();' ) ) );
		$this->assertTrue( self::comparesAState( $php( 'return $p->generation() !== \\SEOCart\\Catalog\\Domain\\GenerationState::Updating;' ) ) );
		$this->assertTrue( self::comparesAState( $php( 'return $p->generation() == GenerationState::Incomplete;' ) ) );
		$this->assertTrue( self::comparesAState( $php( 'switch ( $p->generation() ) { case GenerationState::Complete: return 1; } return 0;' ) ) );
		$this->assertTrue( self::comparesAState( $php( 'return match ( $p->generation() ) { GenerationState::Complete => 1, default => 0 };' ) ) );
		$this->assertFalse( self::comparesAState( $php( 'return $r->leaveUpdating( 1, GenerationState::Complete );' ) ) );
		$this->assertFalse( self::comparesAState( $php( 'return GenerationState::fromStored( $p ) === $q;' ) ) );
		$this->assertFalse( self::comparesAState( $php( 'return GenerationState::cases();' ) ) );
	}

	/**
	 * Tells whether a source compares a value with a case of GenerationState.
	 *
	 * A case counts as compared when it is an operand of `===`, `!==`, `==` or `!=`, follows
	 * `case`, or opens a `match` arm. Handing a case to a method, such as a marker write, is not
	 * a comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return bool True when a case of GenerationState is compared.
	 */
	private static function comparesAState( string $source ): bool {
		$tokens      = PhpSource::tokens( $source );
		$states      = array_map( static fn( GenerationState $state ): string => $state->name, GenerationState::cases() );
		$comparisons = array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL );

		foreach ( $tokens as $index => $token ) {
			$colon = $tokens[ $index + 1 ] ?? null;
			$case  = $tokens[ $index + 2 ] ?? null;

			if ( ! $token->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ) ) || ! str_ends_with( $token->text, 'GenerationState' ) || null === $colon || ! $colon->is( T_DOUBLE_COLON ) || null === $case || ! in_array( $case->text, $states, true ) ) {
				continue;
			}

			$before = $tokens[ $index - 1 ] ?? null;
			$after  = $tokens[ $index + 3 ] ?? null;

			if ( ( null !== $before && $before->is( array_merge( $comparisons, array( T_CASE ) ) ) ) || ( null !== $after && $after->is( array_merge( $comparisons, array( T_DOUBLE_ARROW ) ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a string literal compares the marker with a value, as a WHERE, ON or CASE condition would.
	 *
	 * @since 0.1.0
	 *
	 * @param string $literal The literal, quotes included.
	 * @return bool True when the marker is an operand of a comparison.
	 */
	private static function comparesTheMarker( string $literal ): bool {
		$column   = '[\w.`]*\b' . self::MARKER . '\b`?';
		$operator = '(?:<=>|<>|!=|<=|>=|=|<|>|\bIN\b|\bLIKE\b|\bIS\b|\bBETWEEN\b)';

		return 1 === preg_match( '/' . $column . '\s*' . $operator . '|' . $operator . '\s*' . $column . '/i', $literal );
	}

	/**
	 * Collects the string literals of each named method of a class, closures within it included.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return array<string, list<string>> Each method's string literals, quotes included, keyed by method name.
	 */
	private static function stringsByMethod( string $source ): array {
		$tokens  = PhpSource::tokens( $source );
		$methods = array();
		$current = null;
		$depth   = 0;
		$open    = 0;

		foreach ( $tokens as $index => $token ) {
			if ( null === $current && $token->is( T_FUNCTION ) && ( $tokens[ $index + 1 ] ?? null )?->is( T_STRING ) ) {
				$current             = $tokens[ $index + 1 ]->text;
				$open                = $depth;
				$methods[ $current ] = array();
			}

			if ( '{' === $token->text || $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				++$depth;
			} elseif ( '}' === $token->text ) {
				--$depth;

				if ( null !== $current && $depth === $open ) {
					$current = null;
				}
			} elseif ( null !== $current && self::isString( $token ) ) {
				$methods[ $current ][] = $token->text;
			}
		}

		return $methods;
	}

	/**
	 * Tells whether a token is part of a string literal.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken $token The token.
	 * @return bool True for a quoted string or a piece of an interpolated one.
	 */
	private static function isString( \PhpToken $token ): bool {
		return $token->is( array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ) );
	}
}
