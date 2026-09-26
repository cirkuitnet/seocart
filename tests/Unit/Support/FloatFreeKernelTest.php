<?php
/**
 * The no-float gate of the money path, and the ban on the bcmath, gmp and intl extensions
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Fails when a float can enter the money path, or when the kernel leans on an optional extension.
 *
 * The money path, for this gate, is every file under src/Support except src/Support/Schema/,
 * and every file under src/Pricing and src/Tax. Support is the kernel every module's amounts,
 * rates and instants pass through; Pricing and Tax are the calculation that turns them into
 * totals. None of their classes has a use for a float, so the rule is a set of directories
 * rather than a list of classes that could fall behind. Schema/ belongs to the operations work,
 * whose JSON Schema dialects have a `number` type of their own.
 *
 * The token rules, and why each one:
 *
 * - A float literal (`1.5`, `1e3`, and an integer literal too large for 64 bits, which PHP
 *   reads as a float).
 * - A `(float)` or `(double)` cast.
 * - The type name `float` or `double`, in a declaration or anywhere else.
 * - The division operator `/` and `/=`, which return a float unless the division is exact; the
 *   power operator `**` and `**=`, which return a float for a negative exponent or an overflow.
 *   `intdiv()` and `%` are the integer forms.
 * - A call to a function that returns a float or converts through one: floatval, doubleval,
 *   round, floor, ceil, fdiv, fmod, fpow, pow, sqrt, exp, expm1, log, log10, log1p, pi, the
 *   trigonometric and hyperbolic functions, hypot, deg2rad, rad2deg, lcg_value, microtime and
 *   gettimeofday (the time as a float), number_format, abs (a float for the smallest
 *   integer), array_sum and array_product (a float on overflow), hexdec, octdec, bindec and
 *   base_convert (through a float past 64 bits), is_nan, is_finite, is_infinite, json_decode
 *   and unserialize (floats from text), and settype.
 * - A string literal that names one of those functions, whatever the case and with or without
 *   a leading backslash: a function passed by name, as in `array_map( 'floatval', $list )` or
 *   `call_user_func( 'round', $value )`, is called all the same.
 * - A call to Randomizer::getFloat() or nextFloat().
 * - A float constant: PHP_FLOAT_*, INF, NAN, M_*, PHP_ROUND_* and the float filters.
 * - A printf-style format written in the call with a float conversion (%e, %f, %g, %h and
 *   their capitals). A format held in a variable is not read.
 *
 * Not covered, because no token shows it: `+`, `-` and `*` overflowing into a float. The money
 * classes check before adding or multiplying integers, and their tests prove the overflow
 * throws; DigitArithmetic works in limbs small enough never to overflow.
 *
 * The second check keeps bcmath, gmp and intl out of Support and its tests: a WordPress host is
 * not guaranteed to load them, and composer.json does not require them.
 *
 * @since 0.1.0
 */
final class FloatFreeKernelTest extends TestCase {

	/**
	 * Functions whose result is, or passes through, a float.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const FLOAT_FUNCTIONS = array(
		'abs',
		'acos',
		'acosh',
		'array_product',
		'array_sum',
		'asin',
		'asinh',
		'atan',
		'atan2',
		'atanh',
		'base_convert',
		'bindec',
		'ceil',
		'cos',
		'cosh',
		'deg2rad',
		'doubleval',
		'exp',
		'expm1',
		'fdiv',
		'floatval',
		'floor',
		'fmod',
		'fpow',
		'gettimeofday',
		'hexdec',
		'hypot',
		'is_finite',
		'is_infinite',
		'is_nan',
		'json_decode',
		'lcg_value',
		'log',
		'log10',
		'log1p',
		'microtime',
		'number_format',
		'octdec',
		'pi',
		'pow',
		'rad2deg',
		'round',
		'settype',
		'sin',
		'sinh',
		'sqrt',
		'tan',
		'tanh',
		'unserialize',
	);

	/**
	 * Methods whose result is a float.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const FLOAT_METHODS = array( 'getfloat', 'nextfloat' );

	/**
	 * Constants whose value is a float, or that ask for one.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FLOAT_CONSTANT_PATTERN = '/^(?:PHP_FLOAT_\w+|INF|NAN|M_[A-Z0-9_]+|PHP_ROUND_\w+|FILTER_VALIDATE_FLOAT|FILTER_SANITIZE_NUMBER_FLOAT|FILTER_FLAG_ALLOW_FRACTION)$/';

	/**
	 * The printf-style functions, with the position of their format argument.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	private const FORMAT_FUNCTIONS = array(
		'sprintf'  => 0,
		'printf'   => 0,
		'vsprintf' => 0,
		'vprintf'  => 0,
		'fprintf'  => 1,
		'vfprintf' => 1,
		'sscanf'   => 1,
	);

	/**
	 * The bcmath functions.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const BCMATH_FUNCTIONS = array( 'bcadd', 'bcceil', 'bccomp', 'bcdiv', 'bcdivmod', 'bcfloor', 'bcmod', 'bcmul', 'bcpow', 'bcpowmod', 'bcround', 'bcscale', 'bcsqrt', 'bcsub' );

	/**
	 * Prefixes of the gmp and intl functions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const EXTENSION_FUNCTION_PATTERN = '/^(?:gmp_|numfmt_|collator_|datefmt_|msgfmt_|locale_|normalizer_|grapheme_|idn_to_|intl|transliterator_|resourcebundle_)/';

	/**
	 * The classes of the gmp, intl and bcmath extensions.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const EXTENSION_CLASSES = array(
		'bcmath\\number',
		'collator',
		'gmp',
		'intlbreakiterator',
		'intlcalendar',
		'intlchar',
		'intldateformatter',
		'intldatepatterngenerator',
		'intlgregoriancalendar',
		'intliterator',
		'intltimezone',
		'locale',
		'messageformatter',
		'normalizer',
		'numberformatter',
		'resourcebundle',
		'spoofchecker',
		'transliterator',
		'uconverter',
	);

	/**
	 * Tests that no file on the money path lets a float in.
	 *
	 * @since 0.1.0
	 */
	public function test_the_money_path_is_free_of_floats(): void {
		$violations = array();
		$sources    = PhpSource::files( 'src/Support', array( 'src/Support/Schema' ) ) + PhpSource::files( 'src/Pricing' ) + PhpSource::files( 'src/Tax' );

		foreach ( $sources as $file => $source ) {
			foreach ( self::floatViolations( $source ) as $violation ) {
				$violations[] = $file . ':' . $violation;
			}
		}

		$this->assertSame( array(), $violations, 'Money is integer minor units and Decimal; nothing in src/Support, src/Pricing or src/Tax may produce a float.' );
	}

	/**
	 * Tests that Support and its tests use none of bcmath, gmp and intl.
	 *
	 * @since 0.1.0
	 */
	public function test_support_and_its_tests_use_no_optional_math_extension(): void {
		$violations = array();
		$sources    = PhpSource::files( 'src/Support' ) + PhpSource::files( 'tests/Unit/Support' ) + PhpSource::files( 'tests/Support' );

		foreach ( $sources as $file => $source ) {
			foreach ( self::extensionViolations( $source ) as $violation ) {
				$violations[] = $file . ':' . $violation;
			}
		}

		$this->assertSame( array(), $violations, 'bcmath, gmp and intl are not guaranteed on a WordPress host and composer.json does not require them.' );
	}

	/**
	 * Tests the float rules on planted violations, one per rule, and on look-alikes that must pass.
	 *
	 * These samples are the permanent planted violations of the gate: each rule is shown to fire.
	 *
	 * @since 0.1.0
	 */
	public function test_every_float_rule_fires_on_a_planted_violation(): void {
		$planted = array(
			'float literal'          => '$a = 1.5;',
			'exponent literal'       => '$a = 1e3;',
			'integer past 64 bits'   => '$a = 9223372036854775808;',
			'float cast'             => '$a = (float) $b;',
			'double cast'            => '$a = (double) $b;',
			'float parameter type'   => 'function f( float $a ): int { return 1; }',
			'float return type'      => 'function f(): float { return 1; }',
			'union with float'       => 'function f( int|float $a ) {}',
			'division'               => '$a = $b / 100;',
			'division assignment'    => '$a /= 100;',
			'power'                  => '$a = 10 ** $b;',
			'power assignment'       => '$a **= 2;',
			'round'                  => '$a = round( $b );',
			'fully qualified floor'  => '$a = \floor( $b );',
			'abs'                    => '$a = abs( $b );',
			'array_sum'              => '$a = array_sum( $b );',
			'microtime'              => '$a = microtime( true );',
			'number_format'          => '$a = number_format( $b );',
			'json_decode'            => '$a = json_decode( $b );',
			'Randomizer::getFloat'   => '$a = $random->getFloat( 0, 1 );',
			'PHP_FLOAT_EPSILON'      => '$a = PHP_FLOAT_EPSILON;',
			'M_PI'                   => '$a = M_PI;',
			'INF'                    => '$a = INF;',
			'PHP_ROUND_HALF_UP'      => '$a = PHP_ROUND_HALF_UP;',
			'sprintf %f'             => '$a = sprintf( "%.2f", $b );',
			'sprintf %1$e'           => '$a = sprintf( \'%1$e\', $b );',
			'fprintf %g'             => 'fprintf( $h, \'%g\', $b );',
			'gettimeofday'           => '$a = gettimeofday( true );',
			'base_convert'           => '$a = base_convert( $b, 16, 10 );',
			'array_map floatval'     => '$a = array_map( \'floatval\', $b );',
			'call_user_func round'   => '$a = call_user_func( "\\\\round", $b );',
			'callable in a variable' => '$f = \'ROUND\'; $a = $f( $b );',
		);

		foreach ( $planted as $rule => $code ) {
			$this->assertNotSame( array(), self::floatViolations( "<?php\n" . $code ), 'The rule did not fire: ' . $rule );
		}

		$look_alikes = array(
			'integer arithmetic'       => '$a = intdiv( $b, 100 ) + $b % 100 - 1 * 2;',
			'a method named float'     => '$a = $b->float() + Foo::double();',
			'a comment about a float'  => '// A float would be 1.5 / 2.' . "\n" . '$a = 1;',
			'a docblock about a float' => '/** @var float $a 1.5 */' . "\n" . '$a = 1;',
			'a string with a slash'    => '$a = "1.5 / 2 ** 3";',
			'sprintf without floats'   => '$a = sprintf( \'%1$s is %2$d%% of %3$x\', $b, $c, $d );',
			'hexadecimal integer'      => '$a = 0x3FFFFFFFFFFFFFFF;',
			'a variable format'        => '$a = vsprintf( $format, $values );',
			'a word in a sentence'     => '$a = \'round the total\' . "abs." . \'floor_plan\';',
		);

		foreach ( $look_alikes as $name => $code ) {
			$this->assertSame( array(), self::floatViolations( "<?php\n" . $code ), 'A look-alike was reported: ' . $name );
		}
	}

	/**
	 * Tests the extension rules on planted violations, and that the kernel's own Locale passes.
	 *
	 * @since 0.1.0
	 */
	public function test_every_extension_rule_fires_on_a_planted_violation(): void {
		$planted = array(
			'bcmath function'     => '$a = bcadd( $b, $c, 2 );',
			'bcmath class'        => '$a = new \BcMath\Number( "1.5" );',
			'gmp function'        => '$a = gmp_add( $b, $c );',
			'gmp class'           => 'function f( \GMP $a ) {}',
			'intl function'       => '$a = numfmt_create( "en", 1 );',
			'intl class'          => '$a = new \NumberFormatter( "en_US", 2 );',
			'intl class imported' => 'use NumberFormatter;',
			'intl Locale'         => '$a = \Locale::getDefault();',
		);

		foreach ( $planted as $rule => $code ) {
			$this->assertNotSame( array(), self::extensionViolations( "<?php\nnamespace SEOCart\\Support;\n" . $code ), 'The rule did not fire: ' . $rule );
		}

		$this->assertSame(
			array(),
			self::extensionViolations( "<?php\nnamespace SEOCart\\Support;\nuse SEOCart\\Support\\Locale;\n\$a = Locale::of( 'en_GB' );\n\$b = \\SEOCart\\Support\\Locale::of( 'de_DE' );\n\$c = \$d->bcadd();" ),
			'The kernel\'s own Locale class and a method that merely shares a name are not the extensions.'
		);
	}

	/**
	 * Lists the float rules a source breaks.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source.
	 * @return list<string> One "line: rule" entry per violation.
	 */
	private static function floatViolations( string $source ): array {
		$tokens     = PhpSource::tokens( $source );
		$violations = array();

		foreach ( $tokens as $index => $token ) {
			$previous = $tokens[ $index - 1 ] ?? null;
			$next     = $tokens[ $index + 1 ] ?? null;
			$member   = null !== $previous && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ) );
			$called   = null !== $next && '(' === $next->text;
			$name     = strtolower( ltrim( $token->text, '\\' ) );

			if ( $token->is( T_DNUMBER ) ) {
				$violations[] = $token->line . ': float literal ' . $token->text;
			} elseif ( $token->is( T_CONSTANT_ENCAPSED_STRING ) && in_array( strtolower( ltrim( substr( $token->text, 1, -1 ), '\\' ) ), self::FLOAT_FUNCTIONS, true ) ) {
				$violations[] = $token->line . ': function named in a string, ' . $token->text;
			} elseif ( $token->is( T_DOUBLE_CAST ) ) {
				$violations[] = $token->line . ': cast ' . $token->text;
			} elseif ( '/' === $token->text || $token->is( array( T_DIV_EQUAL, T_POW, T_POW_EQUAL ) ) ) {
				$violations[] = $token->line . ': operator ' . $token->text . ' (use intdiv() and %)';
			} elseif ( ! $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) ) {
				continue;
			} elseif ( $member ) {
				if ( $called && in_array( $name, self::FLOAT_METHODS, true ) ) {
					$violations[] = $token->line . ': method ' . $token->text . '()';
				}
			} elseif ( in_array( $name, array( 'float', 'double' ), true ) ) {
				$violations[] = $token->line . ': type ' . $token->text;
			} elseif ( $called && in_array( $name, self::FLOAT_FUNCTIONS, true ) ) {
				$violations[] = $token->line . ': function ' . $token->text . '()';
			} elseif ( $called && isset( self::FORMAT_FUNCTIONS[ $name ] ) && self::hasFloatConversion( $tokens, $index + 1, self::FORMAT_FUNCTIONS[ $name ] ) ) {
				$violations[] = $token->line . ': float conversion in the format of ' . $token->text . '()';
			} elseif ( ! $called && 1 === preg_match( self::FLOAT_CONSTANT_PATTERN, ltrim( $token->text, '\\' ) ) ) {
				$violations[] = $token->line . ': constant ' . $token->text;
			}
		}

		return $violations;
	}

	/**
	 * Tells whether a printf-style call's literal format asks for a float conversion.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens   The significant tokens.
	 * @param int         $open     The position of the call's opening parenthesis.
	 * @param int         $argument The position of the format among the arguments, from 0.
	 * @return bool True when the format argument is a literal with a %e, %f, %g or %h conversion.
	 */
	private static function hasFloatConversion( array $tokens, int $open, int $argument ): bool {
		$depth    = 0;
		$position = 0;
		$count    = count( $tokens );

		for ( $index = $open + 1; $index < $count; $index++ ) {
			$text = $tokens[ $index ]->text;

			if ( in_array( $text, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					return false;
				}

				--$depth;
			} elseif ( ',' === $text && 0 === $depth ) {
				++$position;
			} elseif ( $position === $argument && 0 === $depth && $tokens[ $index ]->is( T_CONSTANT_ENCAPSED_STRING ) ) {
				preg_match_all( '/%(?:%|(?:\d+\$)?[-+ 0]*(?:\'.)?\d*(?:\.\d+)?([a-zA-Z]))/', substr( $text, 1, -1 ), $matches );

				return array() !== array_intersect( $matches[1], array( 'e', 'E', 'f', 'F', 'g', 'G', 'h', 'H' ) );
			}
		}

		return false;
	}

	/**
	 * Lists the extension rules a source breaks.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source.
	 * @return list<string> One "line: rule" entry per violation.
	 */
	private static function extensionViolations( string $source ): array {
		$tokens     = PhpSource::tokens( $source );
		$violations = array();

		foreach ( $tokens as $index => $token ) {
			$previous = $tokens[ $index - 1 ] ?? null;
			$next     = $tokens[ $index + 1 ] ?? null;

			if ( ! $token->is( array( T_STRING, T_NAME_FULLY_QUALIFIED ) ) || null === $next || '(' !== $next->text ) {
				continue;
			}

			if ( null !== $previous && $previous->is( array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ) ) ) {
				continue;
			}

			$name = strtolower( ltrim( $token->text, '\\' ) );

			if ( in_array( $name, self::BCMATH_FUNCTIONS, true ) || 1 === preg_match( self::EXTENSION_FUNCTION_PATTERN, $name ) ) {
				$violations[] = $token->line . ': function ' . $token->text . '()';
			}
		}

		foreach ( PhpSource::classNames( $source ) as $class ) {
			if ( in_array( strtolower( $class['name'] ), self::EXTENSION_CLASSES, true ) ) {
				$violations[] = $class['line'] . ': class ' . $class['name'];
			}
		}

		return $violations;
	}
}
