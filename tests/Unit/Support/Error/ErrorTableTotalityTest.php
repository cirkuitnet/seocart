<?php
/**
 * Tests that the one error table is total over the code base (DRY rule 8)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Error;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\ErrorCatalogs;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * The totality test of the one error table, over everything under src/.
 *
 * Both directions of DRY rule 8 are checked:
 *
 * - Every code src/ can raise has exactly one row. A code is raised through
 *   CodedException::raise() or built by CodedException::because(), and both accept only a case
 *   of an ErrorCode enum, so the codes src/ can raise are the cases of the ErrorCode enums
 *   declared under src/. This test finds every such enum with ErrorCatalogs, the one search the
 *   kernel's list of catalogs is held to as well, composes them all into one table —
 *   which fails for a case with no row or two, and for a code two catalogs declare — and
 *   checks every row: a 4xx or 5xx status, a message, and placeholders equal to the row's
 *   declared context keys.
 * - Every row is used: each case is referenced somewhere under src/ outside its own catalog.
 *
 * The seam for the operations layer: an operation's declaration names the codes the operation
 * can fail with, as ErrorCode cases. Those references are code under src/ and so already count
 * as uses here. The stricter rule — a code reachable from a public surface must be declared by
 * its operation — adds the declared codes as a second source next to codesReferencedIn() and
 * compares; nothing else here changes. The seam for the REST layer is
 * ErrorTable::definitionFor() with ErrorDefinition's httpStatus() and render(), fed by
 * CodedException::errorCode() and context().
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class ErrorTableTotalityTest extends TestCase {

	/**
	 * Matches a placeholder or a literal percent sign in a message format.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONVERSION_PATTERN = '/%(?:%|(\d+)\$s|[^%]*?[a-zA-Z])/';

	/**
	 * Sets up Brain Monkey, which stands in for WordPress's gettext functions.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that every catalog under src/ composes into one table, and that the search finds them.
	 *
	 * @since 0.1.0
	 */
	public function test_every_catalog_under_src_composes_into_one_table(): void {
		$catalogs = ErrorCatalogs::under( 'src' );

		$this->assertContains( SupportError::class, array_keys( $catalogs ), 'The search for catalogs did not find the shared kernel\'s own; it cannot be trusted to find the others.' );

		$table = ErrorTable::compose( ...array_keys( $catalogs ) );
		$cases = 0;

		foreach ( array_keys( $catalogs ) as $catalog ) {
			$cases += count( $catalog::cases() );
		}

		$this->assertCount( $cases, $table->definitions(), 'Every case of every catalog has exactly one row.' );
	}

	/**
	 * Tests every row: an error status, a message, and exactly the placeholders it declares.
	 *
	 * @since 0.1.0
	 */
	public function test_every_row_has_an_error_status_a_message_and_exactly_its_placeholders(): void {
		Functions\when( '__' )->returnArg();

		$problems = array();

		foreach ( ErrorTable::compose( ...array_keys( ErrorCatalogs::under( 'src' ) ) )->definitions() as $row ) {
			$problems = array_merge( $problems, self::problemsWith( $row ) );
		}

		$this->assertSame( array(), $problems );
	}

	/**
	 * Tests that every code is used somewhere under src/ outside its own catalog.
	 *
	 * @since 0.1.0
	 */
	public function test_every_code_is_used_under_src(): void {
		$used   = self::codesReferencedIn( 'src' );
		$unused = array();

		foreach ( ErrorCatalogs::under( 'src' ) as $catalog => $file ) {
			foreach ( $catalog::cases() as $case ) {
				$key = $catalog . '::' . $case->name;

				if ( ! isset( $used[ $key ] ) || array() === array_diff( $used[ $key ], array( $file ) ) ) {
					$unused[] = $case->value . ' (' . $key . ')';
				}
			}
		}

		$this->assertSame( array(), $unused, 'A row no code under src/ raises is dead: remove it, or raise it where it belongs.' );
	}

	/**
	 * Tests the row checks on rows with a known answer, so a check that finds nothing cannot pass for a clean table.
	 *
	 * @since 0.1.0
	 */
	public function test_the_row_checks_find_the_problems_they_are_shown(): void {
		$code = SupportError::UnknownCurrency;

		$this->assertSame( array(), self::problemsWith( new ErrorDefinition( $code, 400, static fn(): string => '%1$s is 100%% unknown.', array( 'currency' ) ) ) );
		$this->assertSame(
			array( 'currency.unknown: the message is empty.' ),
			self::problemsWith( new ErrorDefinition( $code, 400, static fn(): string => ' ', array() ) )
		);
		$this->assertSame(
			array( 'currency.unknown: the message uses placeholders [1, 2] but the row declares [1] (currency).' ),
			self::problemsWith( new ErrorDefinition( $code, 400, static fn(): string => '%1$s or %2$s', array( 'currency' ) ) )
		);
		$this->assertSame(
			array( 'currency.unknown: the message uses placeholders [] but the row declares [1] (currency).' ),
			self::problemsWith( new ErrorDefinition( $code, 400, static fn(): string => 'Unknown.', array( 'currency' ) ) )
		);
		$this->assertSame(
			array( 'currency.unknown: the message uses "%s"; placeholders are numbered: %1$s, %2$s.' ),
			self::problemsWith( new ErrorDefinition( $code, 400, static fn(): string => '%s is unknown.', array() ) )
		);
	}

	/**
	 * Tests the source readers on text with a known answer, so a reader that finds nothing cannot pass for a clean tree.
	 *
	 * @since 0.1.0
	 */
	public function test_the_source_readers_find_what_they_are_shown(): void {
		$source = <<<'PHP'
<?php
namespace Shop\Module;

use SEOCart\Support\SupportError;
use SEOCart\Support\SupportError as Kernel;
use Other\{Thing, Stuff as Aliased};
use function Other\helper;

enum LocalError: string implements \SEOCart\Support\Error\ErrorCode {}
final class Service {
	use SomeTrait;

	public function run(): void {
		SupportError::UnknownCurrency;
		Kernel::Other;
		\Full\Name::CASE_A;
		Aliased::B;
		Thing::method();
		Local::C;
		self::D;
		$x = Service::class;
		$y = new class() {};
		$z = function () use ( $x ) {};
	}
}
PHP;

		$this->assertSame( array( 'Shop\\Module\\LocalError', 'Shop\\Module\\Service' ), PhpSource::declarations( $source ) );
		$this->assertSame(
			array(
				array( 'SEOCart\\Support\\SupportError', 'UnknownCurrency' ),
				array( 'SEOCart\\Support\\SupportError', 'Other' ),
				array( 'Full\\Name', 'CASE_A' ),
				array( 'Other\\Stuff', 'B' ),
				array( 'Shop\\Module\\Local', 'C' ),
			),
			array_map( static fn( array $reference ): array => array( $reference['class'], $reference['constant'] ), PhpSource::constantReferences( $source ) )
		);
	}

	/**
	 * Collects the files under a directory that reference each catalog case.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory A directory relative to the repository root.
	 * @return array<string, list<string>> The referencing files, keyed by "Catalog::Case".
	 */
	private static function codesReferencedIn( string $directory ): array {
		$used = array();

		foreach ( PhpSource::files( $directory ) as $file => $source ) {
			foreach ( PhpSource::constantReferences( $source ) as $reference ) {
				$used[ $reference['class'] . '::' . $reference['constant'] ][] = $file;
			}
		}

		return $used;
	}

	/**
	 * Lists what is wrong with one row, rendered with gettext standing in as the identity.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorDefinition $row The row.
	 * @return list<string> The problems, empty for a good row.
	 */
	private static function problemsWith( ErrorDefinition $row ): array {
		$code     = (string) $row->code()->value;
		$format   = $row->messageFormat();
		$problems = array();

		if ( $row->httpStatus() < 400 || $row->httpStatus() > 599 ) {
			$problems[] = $code . ': the status ' . $row->httpStatus() . ' is not a client or server error.';
		}

		if ( '' === trim( $format ) ) {
			return array_merge( $problems, array( $code . ': the message is empty.' ) );
		}

		preg_match_all( self::CONVERSION_PATTERN, $format, $matches, PREG_SET_ORDER );

		$used = array();

		foreach ( $matches as $match ) {
			if ( '%%' === $match[0] ) {
				continue;
			}

			if ( ! isset( $match[1] ) ) {
				$problems[] = $code . ': the message uses "' . $match[0] . '"; placeholders are numbered: %1$s, %2$s.';
				continue;
			}

			$used[ (int) $match[1] ] = true;
		}

		$used = array_keys( $used );
		sort( $used );

		$declared = range( 1, count( $row->placeholders() ) );

		if ( array() === $row->placeholders() ) {
			$declared = array();
		}

		if ( $used !== $declared ) {
			$problems[] = sprintf(
				'%s: the message uses placeholders [%s] but the row declares [%s] (%s).',
				$code,
				implode( ', ', $used ),
				implode( ', ', $declared ),
				implode( ', ', $row->placeholders() )
			);
		}

		return $problems;
	}
}
