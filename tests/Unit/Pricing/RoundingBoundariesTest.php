<?php
/**
 * Tests that the calculation rounds only through its one rounder
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * A source scan: no pricing domain file but the Rounder calls a method that rounds or splits money.
 *
 * Those methods are the money API's rounding boundaries: turning a decimal into money
 * (`ofDecimal`), dividing or rescaling a decimal, building a taxed amount from a rate
 * (`fromNet`, `fromGross`), converting at a rate, splitting by largest remainder (`allocate`)
 * and cash rounding. A call to one anywhere else in the domain would be a rounding the trace
 * does not record. The price resolver is the one application file allowed to round, since it
 * converts a price before a calculation starts; every other application file is held to the
 * same rule.
 *
 * Planted violation, shown red and removed: in DiscountStep::discountLines(), round the
 * discount with `Money::ofDecimal( $exact, $currency, RoundingMode::HalfUp )` instead of the
 * rounder. The scan names the file and the call.
 *
 * @since 0.1.0
 */
final class RoundingBoundariesTest extends TestCase {

	/**
	 * The methods that round or split money.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ROUNDING_METHODS = array( 'allocate', 'convertToBaseMoney', 'convertToQuoteMoney', 'divide', 'fromGross', 'fromNet', 'ofDecimal', 'rescale', 'roundToCashStep' );

	/**
	 * The files allowed to call them.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ALLOWED = array( 'src/Pricing/Domain/Engine/Rounder.php', 'src/Pricing/Application/PriceResolver.php' );

	/**
	 * Tests that no pricing domain or application file rounds but the ones allowed.
	 *
	 * @since 0.1.0
	 */
	public function test_the_calculation_rounds_only_through_the_rounder(): void {
		$found = array();

		foreach ( array( 'src/Pricing/Domain', 'src/Pricing/Application' ) as $directory ) {
			foreach ( PhpSource::files( $directory ) as $file => $source ) {
				if ( in_array( $file, self::ALLOWED, true ) ) {
					continue;
				}

				foreach ( self::roundingCalls( $source ) as $call ) {
					$found[] = "{$file}: {$call}";
				}
			}
		}

		$this->assertSame( array(), $found, 'Round through the Rounder, which records every rounding in the trace.' );
	}

	/**
	 * Tests the scan on text with a known answer, so a scan that finds nothing cannot pass for a clean domain.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scan_finds_the_calls_it_is_shown(): void {
		$source = "<?php\n\$a = Money::ofDecimal( \$d, \$c, \$m );\n\$b = \$x->divide( \$y, 2, \$m );\n\$c = TaxedMoney::fromGross( \$g, \$r, \$m );\n\$d = \$m?->allocate( array( 1 ) );\n// ->rescale( is a comment\n\$e = 'ofDecimal(';\n\$f = \$rounder->split( \$t, 's', \$m, array( 1 ) );\n";

		$this->assertSame( array( 'ofDecimal() on line 2', 'divide() on line 3', 'fromGross() on line 4', 'allocate() on line 5' ), self::roundingCalls( $source ) );
	}

	/**
	 * Lists the calls of rounding methods in a source.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<string> Each call, with its line.
	 */
	private static function roundingCalls( string $source ): array {
		$tokens = PhpSource::tokens( $source );
		$calls  = array();

		foreach ( $tokens as $index => $token ) {
			$previous = $tokens[ $index - 1 ] ?? null;

			if ( null !== $previous && $token->is( T_STRING ) && in_array( $token->text, self::ROUNDING_METHODS, true ) && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ) ) ) {
				$calls[] = $token->text . '() on line ' . $token->line;
			}
		}

		return $calls;
	}
}
