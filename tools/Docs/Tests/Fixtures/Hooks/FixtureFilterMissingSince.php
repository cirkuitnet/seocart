<?php
/**
 * FixtureFilterMissingSince: a fixture filter declaration whose class doc comment has no @since
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Hooks;

use SEOCart\Platform\Hooks\FilterDeclaration;

/**
 * A fixture filter, deliberately missing its class doc comment's version tag.
 */
final class FixtureFilterMissingSince implements FilterDeclaration {

	/**
	 * Returns the filter's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function name(): string {
		return 'seocart_fixture_missing_since';
	}

	/**
	 * Returns the PHP type of the value passed through the filter.
	 *
	 * @since 0.1.0
	 *
	 * @return string `bool`.
	 */
	public static function valueType(): string {
		return 'bool';
	}

	/**
	 * Describes the value passed through the filter.
	 *
	 * @since 0.1.0
	 *
	 * @return string One sentence.
	 */
	public static function valueDescription(): string {
		return 'Whether.';
	}

	/**
	 * Returns the default value.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public static function defaultValue(): mixed {
		return true;
	}

	/**
	 * Describes what returning each value does.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Keyed `true` and `false`.
	 */
	public static function effects(): array {
		return array(
			'true'  => 'Does the thing.',
			'false' => 'Does not.',
		);
	}
}
