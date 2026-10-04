<?php
/**
 * Tests that FilterDeclarations::ALL is every class under src/ implementing FilterDeclaration
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Hooks;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Hooks\ActionDeclaration;
use SEOCart\Platform\Hooks\ActionDeclarations;
use SEOCart\Platform\Hooks\FilterDeclaration;
use SEOCart\Platform\Hooks\FilterDeclarations;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * A parallel-list gate (rule 11): FilterDeclarations::ALL is a hand-kept list, so this holds it to
 * what src/ actually declares — the same shape of search KernelListsTest runs for
 * Modules::EVENT_CLASSES, kept as its own copy rather than extracted: rule 15 extracts a shared
 * abstraction only on a third occurrence of the same knowledge, and this is the second.
 *
 * ActionDeclarations::ALL is held to src/ the same way, by the same search over another interface:
 * the third list of its kind, so the search takes the interface rather than being copied again.
 *
 * Planted violation, put back afterwards: remove EndResponseEarlyFilter::class from
 * FilterDeclarations::ALL, or RegisterPaymentGatewaysAction::class from ActionDeclarations::ALL.
 * This test then fails, because the search still finds it under src/.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class FilterDeclarationsTest extends TestCase {

	/**
	 * Tests that the declared filter list is every class under src/ implementing FilterDeclaration.
	 *
	 * @since 0.1.0
	 */
	public function test_the_filter_list_is_every_filter_declaration_under_src(): void {
		$found  = self::declarationsUnder( FilterDeclaration::class, 'src' );
		$listed = FilterDeclarations::ALL;

		sort( $listed );

		$this->assertNotSame( array(), $found, 'The search found no filter declaration, so the comparison would prove nothing.' );
		$this->assertSame( $found, $listed, 'FilterDeclarations::ALL must list every class under src/ implementing FilterDeclaration, and nothing else.' );
	}

	/**
	 * Tests that the declared action list is every class under src/ implementing ActionDeclaration.
	 *
	 * @since 0.2.0
	 */
	public function test_the_action_list_is_every_action_declaration_under_src(): void {
		$found  = self::declarationsUnder( ActionDeclaration::class, 'src' );
		$listed = ActionDeclarations::ALL;

		sort( $listed );

		$this->assertNotSame( array(), $found, 'The search found no action declaration, so the comparison would prove nothing.' );
		$this->assertSame( $found, $listed, 'ActionDeclarations::ALL must list every class under src/ implementing ActionDeclaration, and nothing else.' );
	}

	/**
	 * Finds the concrete classes declared under a directory that implement a declaration interface.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes the interface.
	 *
	 * @param string $declaration FilterDeclaration::class or ActionDeclaration::class.
	 * @param string $directory A directory relative to the repository root.
	 * @return list<string> The classes, sorted.
	 *
	 * @throws \Error When a declaration fails to load for any reason other than a missing global parent class.
	 */
	private static function declarationsUnder( string $declaration, string $directory ): array {
		$found      = array();
		$unloadable = array();

		foreach ( PhpSource::files( $directory ) as $path => $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				try {
					$loadable = class_exists( $class ) || interface_exists( $class ) || trait_exists( $class );
				} catch ( \Error $error ) {
					if ( 1 === preg_match( '/^Class "[A-Za-z_][A-Za-z0-9_]*" not found$/', $error->getMessage() ) ) {
						continue;
					}

					throw $error;
				}

				if ( ! $loadable ) {
					$unloadable[] = $class . ' (declared in ' . $path . ')';

					continue;
				}

				if ( is_subclass_of( $class, $declaration ) && ( new \ReflectionClass( $class ) )->isInstantiable() ) {
					$found[] = $class;
				}
			}
		}

		self::assertSame( array(), $unloadable, 'These declarations cannot be loaded by their names: the file name or namespace does not match the autoloader.' );

		sort( $found );

		return $found;
	}
}
