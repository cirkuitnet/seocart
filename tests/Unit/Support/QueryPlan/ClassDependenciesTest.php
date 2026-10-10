<?php
/**
 * Tests ClassDependencies: what a class extends or uses is found wherever the class is declared
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\QueryPlan;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\QueryPlan\ClassDependencies;

/**
 * The inventory is whole only if every class of a module takes its code from inside the module,
 * so the reading of a source must not depend on where a declaration stands in it.
 *
 * Runs with no WordPress and no database.
 *
 * Planted violations, each confirmed red then removed: look for declarations only at the start
 * of a line (the trait declared in a block is missed); read a trait `use` anywhere in a class
 * (the closure's `use` is taken for a trait); resolve a name against the namespace and not the
 * imports (the aliased parent resolves into the namespace).
 *
 * @since 0.2.0
 */
final class ClassDependenciesTest extends TestCase {

	/**
	 * Tests a parent class and traits written with imports, an alias and a leading backslash.
	 *
	 * @since 0.2.0
	 */
	public function test_a_parent_and_its_traits_are_resolved_against_the_imports(): void {
		$source = "<?php\nnamespace A\\B;\nuse C\\D\\Base as P;\nuse G\\Tr1, G\\Tr2;\nfinal class One extends P {\n\tuse Tr1, Tr2;\n\tuse \\H\\Tr3;\n}\n";

		$this->assertSame(
			array( 'A\\B\\One' => array( 'C\\D\\Base', 'G\\Tr1', 'G\\Tr2', 'H\\Tr3' ) ),
			ClassDependencies::of( $source )
		);
	}

	/**
	 * Tests that a declaration inside a block, or after other code, is found.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declaration_in_a_block_is_found(): void {
		$source = "<?php\nnamespace A;\necho 1;\nif ( ! class_exists( 'X' ) ) {\n\ttrait Late { use \\Out\\Side; }\n\tclass Other extends \\Out\\Parent2 {}\n}\n";

		$this->assertSame(
			array(
				'A\\Late'  => array( 'Out\\Side' ),
				'A\\Other' => array( 'Out\\Parent2' ),
			),
			ClassDependencies::of( $source )
		);
	}

	/**
	 * Tests that a closure's `use`, a `::class` and a name are no declaration or trait, and that an anonymous class is read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_closure_a_class_constant_and_an_anonymous_class(): void {
		$source = "<?php\nnamespace A;\nfinal class Two {\n\tpublic function f( \$x ) {\n\t\t\$g = function () use ( \$x ) { return \$x; };\n\t\treturn array( Two::class, new class extends \\Out\\Anon {} );\n\t}\n}\n";
		$found  = ClassDependencies::of( $source );

		$this->assertSame( array( 'A\\Two' ), array_slice( array_keys( $found ), 0, 1 ) );
		$this->assertSame( array(), $found['A\\Two'] );
		$this->assertCount( 2, $found );
		$this->assertSame( array( 'Out\\Anon' ), end( $found ) );
		$this->assertStringStartsWith( 'class@anonymous#', (string) array_key_last( $found ) );
	}

	/**
	 * Tests a source with no declaration, and a global namespace.
	 *
	 * @since 0.2.0
	 */
	public function test_a_source_without_a_class_and_a_class_in_the_global_namespace(): void {
		$this->assertSame( array(), ClassDependencies::of( "<?php\nfunction f() {}\n" ) );
		$this->assertSame( array( 'Plain' => array( 'WP_Thing' ) ), ClassDependencies::of( "<?php\nclass Plain extends WP_Thing {}\n" ) );
	}
}
