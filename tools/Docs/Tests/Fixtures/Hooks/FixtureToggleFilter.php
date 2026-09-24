<?php
/**
 * FixtureToggleFilter: a fixture filter declaration HooksReference is proven against
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Hooks;

use SEOCart\Platform\Hooks\FilterDeclaration;

/**
 * Whether the fixture warehouse's restock notice is sent.
 *
 * Fixture only: proves HooksReference documents a filter from its own declaration.
 *
 * @since 0.2.0
 */
final class FixtureToggleFilter implements FilterDeclaration {

	/**
	 * The filter's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'seocart_fixture_toggle';

	/**
	 * Returns the filter's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Returns the PHP type of the value passed through the filter.
	 *
	 * @since 0.2.0
	 *
	 * @return string `bool`.
	 */
	public static function valueType(): string {
		return 'bool';
	}

	/**
	 * Describes the value passed through the filter.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence.
	 */
	public static function valueDescription(): string {
		return 'Whether to send the notice.';
	}

	/**
	 * Returns the default value.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public static function defaultValue(): mixed {
		return false;
	}

	/**
	 * Describes what returning each value does.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Keyed `true` and `false`.
	 */
	public static function effects(): array {
		return array(
			'true'  => 'Sends the notice.',
			'false' => 'Sends nothing.',
		);
	}
}
