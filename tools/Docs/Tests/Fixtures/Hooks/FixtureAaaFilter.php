<?php
/**
 * FixtureAaaFilter: a fixture filter whose name sorts before an event's, to prove combined ordering
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Hooks;

use SEOCart\Platform\Hooks\FilterDeclaration;

/**
 * Whether the fixture warehouse opens early.
 *
 * Fixture only: its hook name sorts before `seocart_bin_restocked`, to prove events and filters
 * are sorted together by hook name, not filters always after events.
 *
 * @since 0.1.0
 */
final class FixtureAaaFilter implements FilterDeclaration {

	/**
	 * The filter's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'seocart_aaa_filter';

	/**
	 * Returns the filter's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
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
		return 'Whether to open early.';
	}

	/**
	 * Returns the default value.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public static function defaultValue(): mixed {
		return false;
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
			'true'  => 'Opens early.',
			'false' => 'Opens at the usual time.',
		);
	}
}
