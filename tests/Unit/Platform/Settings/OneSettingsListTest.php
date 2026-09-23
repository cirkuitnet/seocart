<?php
/**
 * Tests that the plugin's code builds a settings registry in one place: the production list
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * One settings list: the data registry lists the options of Settings::registry(), so a store over
 * any other registry could write options nothing lists.
 *
 * Under src/, `new SettingsRegistry(` may appear only in the production list. A construction is
 * found in every spelling PHP resolves to the class — imported, aliased, fully qualified,
 * relative — and so is any `SettingsRegistry::class` outside the production list, since a class
 * name held in a variable could be constructed without a visible `new`. Tests and fixtures build
 * registries of their own on purpose, so they are not code here.
 *
 * Planted violation: in InternationalSettings::settings(), add
 * `$unused = new SettingsRegistry( array() );`.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class OneSettingsListTest extends TestCase {

	/**
	 * The one file allowed to build a settings registry.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PRODUCTION_LIST = 'src/Platform/Settings/Settings.php';

	/**
	 * Tests that the production list is the only place under src/ that builds a settings registry.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_production_list_builds_a_settings_registry(): void {
		$sites = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			foreach ( self::uses( $source ) as $use ) {
				$sites[] = $file . ':' . $use['line'] . ' (' . $use['kind'] . ')';
			}
		}

		$allowed = array_filter( $sites, static fn( string $site ): bool => str_starts_with( $site, self::PRODUCTION_LIST . ':' ) && str_ends_with( $site, '(new)' ) );

		$this->assertCount( 1, $allowed, 'The production list no longer builds the registry with one visible construction, so the search may be broken.' );
		$this->assertSame( array(), array_values( array_diff( $sites, $allowed ) ), 'A settings registry is built outside the production list, so the data registry would not list its options.' );
	}

	/**
	 * Tests the reader on source with a known answer, so a reader that finds nothing cannot pass for a clean code base.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reader_finds_every_spelling(): void {
		$source = <<<'PHP'
<?php
namespace Shop\Settings;

use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Platform\Settings\SettingsRegistry as Registry;
use SEOCart\Platform\Settings;

final class Planted {
	public function run(): void {
		$a = new SettingsRegistry( array() );
		$b = new Registry( array() );
		$c = new \SEOCart\Platform\Settings\SettingsRegistry( array() );
		$d = new Settings\SettingsRegistry( array() );
		$e = SettingsRegistry::class;
		$f = new Other( array() );
		$g = 'new SettingsRegistry( array() )';
		$h = SettingsRegistry::MODULE;
	}
}
PHP;

		$this->assertSame(
			array(
				array(
					'kind' => 'new',
					'line' => 10,
				),
				array(
					'kind' => 'new',
					'line' => 11,
				),
				array(
					'kind' => 'new',
					'line' => 12,
				),
				array(
					'kind' => 'new',
					'line' => 13,
				),
				array(
					'kind' => 'class',
					'line' => 14,
				),
			),
			self::uses( $source )
		);
	}

	/**
	 * Finds each construction of the settings registry, and each `SettingsRegistry::class`, in a file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<array{kind: string, line: int}> Each use: `new` or `class`, with its line.
	 */
	private static function uses( string $source ): array {
		$tokens    = PhpSource::tokens( $source );
		$namespace = PhpSource::namespaceOf( $tokens );
		$imports   = PhpSource::importsOf( $tokens );
		$names     = array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE );
		$uses      = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! $token->is( $names ) || SettingsRegistry::class !== PhpSource::resolve( $token->text, $namespace, $imports ) ) {
				continue;
			}

			$before = $tokens[ $index - 1 ] ?? null;
			$after  = $tokens[ $index + 1 ] ?? null;
			$last   = $tokens[ $index + 2 ] ?? null;

			if ( null !== $before && $before->is( T_NEW ) ) {
				$uses[] = array(
					'kind' => 'new',
					'line' => $token->line,
				);
			} elseif ( null !== $after && $after->is( T_DOUBLE_COLON ) && null !== $last && $last->is( T_CLASS ) ) {
				$uses[] = array(
					'kind' => 'class',
					'line' => $token->line,
				);
			}
		}

		return $uses;
	}
}
