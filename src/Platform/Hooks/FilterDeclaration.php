<?php
/**
 * FilterDeclaration: the contract every documented `seocart_` filter is declared through
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * What a filter the plugin applies says about itself, so the hooks reference documents it without
 * reading the call site, and the call site reads the name and default from here instead of
 * repeating them as literals.
 *
 * Owns one fact per filter: its name, the value passed, its default, and what returning each
 * value does. A class implementing this lives in this namespace, next to the code that applies
 * the filter, and is listed in FilterDeclarations::ALL. Nothing here does I/O or reads a
 * WordPress function, so the list loads on the command line, and only when the filtering code
 * itself is loaded: a request that never publishes an event never loads it.
 *
 * @since 0.1.0
 */
interface FilterDeclaration {

	/**
	 * Returns the filter's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name passed to apply_filters(), for example `seocart_end_response_early`.
	 */
	public static function name(): string;

	/**
	 * Returns the PHP type of the value passed through the filter.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `bool`.
	 */
	public static function valueType(): string;

	/**
	 * Describes the value passed through the filter.
	 *
	 * @since 0.1.0
	 *
	 * @return string One sentence.
	 */
	public static function valueDescription(): string;

	/**
	 * Returns the default value: what is passed to apply_filters() when nothing has filtered it.
	 *
	 * @since 0.1.0
	 *
	 * @return mixed The default, of the type valueType() names.
	 */
	public static function defaultValue(): mixed;

	/**
	 * Describes what returning each value does.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> What a callback can return, as var_export() would print it,
	 *                                mapped to what returning it does. At least one entry.
	 */
	public static function effects(): array;
}
