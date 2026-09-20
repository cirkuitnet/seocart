<?php
/**
 * Tests that every PHPUnit group in use is a group that exists
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Guards the group names that composer.json and the test classes must agree on.
 *
 * A group name can appear in a Composer selection, in a test annotation, or in both places.
 * This is a vocabulary check rather than proof that every selected group already has a test:
 * every name used on either side must be declared below, and every declared name must be used
 * on at least one side. The Composer group commands use `--fail-on-empty-test-suite` to guard
 * the separate empty-selection case.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class TestGroupsTest extends TestCase {

	use ReadsTestSources;

	/**
	 * Every group the project uses. Add a group here in the change that introduces it.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const DECLARED_GROUPS = array(
		'concurrency',
		'contract',
		'international',
		'migration',
		'multilingual-conformance',
		'performance',
		'reference-fixture',
	);

	/**
	 * Tests that every group a Composer script selects or excludes is declared.
	 *
	 * @since 0.1.0
	 */
	public function test_every_group_selected_by_a_composer_script_is_declared(): void {
		$selected = self::groupsInComposerScripts( (string) file_get_contents( $this->root() . '/composer.json' ) );

		$this->assertNotSame( array(), $selected, 'No group option was found in composer.json, so the pattern that reads them is broken.' );

		$this->assertSame(
			array(),
			array_values( array_diff( $selected, self::DECLARED_GROUPS ) ),
			'composer.json selects a group that is not declared in ' . self::class . '::DECLARED_GROUPS.'
		);
	}

	/**
	 * Tests that every group a test is tagged with is declared.
	 *
	 * @since 0.1.0
	 */
	public function test_every_group_annotation_is_declared(): void {
		$unknown = array();

		foreach ( $this->groupAnnotations() as $file => $groups ) {
			foreach ( array_diff( $groups, self::DECLARED_GROUPS ) as $group ) {
				$unknown[] = $file . ': ' . $group;
			}
		}

		$this->assertSame(
			array(),
			$unknown,
			'A test is tagged with a group that is not declared in ' . self::class . '::DECLARED_GROUPS, so no Composer script will ever select it.'
		);
	}

	/**
	 * Tests that no declared group is a leftover that nothing selects and nothing is tagged with.
	 *
	 * @since 0.1.0
	 */
	public function test_every_declared_group_is_used(): void {
		$used = self::groupsInComposerScripts( (string) file_get_contents( $this->root() . '/composer.json' ) );

		foreach ( $this->groupAnnotations() as $groups ) {
			$used = array_merge( $used, $groups );
		}

		$this->assertSame(
			array(),
			array_values( array_diff( self::DECLARED_GROUPS, $used ) ),
			'A group is declared in ' . self::class . '::DECLARED_GROUPS but no Composer script selects it and no test is tagged with it.'
		);
	}

	/**
	 * Tests the two readers on text with a known answer, so that a reader which finds nothing cannot pass for a clean result.
	 *
	 * @since 0.1.0
	 */
	public function test_the_readers_find_the_groups_they_are_shown(): void {
		$this->assertSame(
			array( 'alpha', 'beta', 'delta', 'gamma-ray' ),
			self::groupsInComposerScripts( '"a": "phpunit --group alpha", "b": "@a --group=beta,gamma-ray", "c": "@a --exclude-group delta --testsuite unit"' )
		);

		$this->assertSame(
			array( 'alpha', 'gamma-ray' ),
			self::groupsInSource( "<?php\n/**\n * Summary.\n *\n * @group alpha\n\t * @group   gamma-ray  \r\n * @since 0.1.0\n */\n" )
		);

		$this->assertSame(
			array( 'alpha (slow)' ),
			self::groupsInSource( "<?php\n/**\n * @group alpha (slow)\n */\n" ),
			'PHPUnit takes the rest of the line as the group name, so the reader must too, or a remark after the name hides a group that nothing selects.'
		);

		$this->assertSame( array( 'beta' ), self::groupsInSource( "<?php\n/** @group beta */\n" ), 'A docblock written on one line.' );
		$this->assertSame( array( 'in passing.' ), self::groupsInSource( "<?php\n/**\n * Prose that mentions @group in passing.\n */\n" ), 'PHPUnit honours an annotation in the middle of a sentence as well.' );

		$this->assertSame(
			array(),
			self::groupsInSource( "<?php\n// @group line-comment\n/* @group block-comment */\n\$text = '\n * @group string';\n/**\n * @groups plural\n */\n" ),
			'PHPUnit reads docblocks only, and only the annotation with exactly this name.'
		);
	}

	/**
	 * Reads the group names that PHPUnit command lines select or exclude.
	 *
	 * @since 0.1.0
	 *
	 * @param string $manifest The text of composer.json.
	 * @return list<string> The distinct group names, sorted.
	 */
	private static function groupsInComposerScripts( string $manifest ): array {
		preg_match_all( '/--(?:exclude-)?group[= ]+([A-Za-z0-9_,-]+)/', $manifest, $matches );

		$groups = array();

		foreach ( $matches[1] as $list ) {
			$groups = array_merge( $groups, explode( ',', $list ) );
		}

		$groups = array_values( array_unique( array_filter( $groups, static fn( string $group ): bool => '' !== $group ) ) );

		sort( $groups );

		return $groups;
	}

	/**
	 * Reads the group names a PHP source file tags its tests with, the way PHPUnit 9 reads them.
	 *
	 * PHPUnit looks for annotations in docblocks only, anywhere on a line, and takes everything
	 * after the annotation name, up to the end of the line, as its value. A group annotation
	 * followed by a remark therefore names a group that includes the remark, which no group
	 * option selects. Reading a shorter name than PHPUnit does would let that through.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The text of a PHP file.
	 * @return list<string> The distinct group names, sorted.
	 */
	private static function groupsInSource( string $source ): array {
		$groups = array();

		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || T_DOC_COMMENT !== $token[0] ) {
				continue;
			}

			// Without the opening and closing marks, so that a docblock written on one line reads the same.
			preg_match_all( '/@([A-Za-z_-]+)(?:[ \t]+(.*?))?[ \t]*\r?$/m', substr( $token[1], 3, -2 ), $annotations, PREG_SET_ORDER );

			foreach ( $annotations as $annotation ) {
				if ( 'group' === $annotation[1] ) {
					$groups[] = $annotation[2] ?? '';
				}
			}
		}

		$groups = array_values( array_unique( $groups ) );

		sort( $groups );

		return $groups;
	}

	/**
	 * Reads the group annotations of every PHP file in the test directories.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>> The groups of each file that has any, keyed by its path relative to the repository root.
	 */
	private function groupAnnotations(): array {
		$annotations = array();

		foreach ( $this->testSources() as $file => $source ) {
			$groups = self::groupsInSource( $source );

			if ( array() !== $groups ) {
				$annotations[ $file ] = $groups;
			}
		}

		return $annotations;
	}
}
