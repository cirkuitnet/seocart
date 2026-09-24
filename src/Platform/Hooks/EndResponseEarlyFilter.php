<?php
/**
 * EndResponseEarlyFilter: declares `seocart_end_response_early`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a request that published events ends its response before delivering them.
 *
 * Ending it early means the client never waits for a listener, and that nothing written after
 * WordPress's `shutdown` action has run at PHP_INT_MAX (output, headers, cookies) reaches the
 * client. EventWake::atShutdown() reads it, after the response the filter decides about; a site
 * that needs late output returns false, and its delivery is handed to the job runner instead, as
 * on a server that cannot end a response early.
 *
 * @since 0.1.0
 */
final class EndResponseEarlyFilter implements FilterDeclaration {

	/**
	 * The filter's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'seocart_end_response_early';

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
		return 'Whether to end the response before delivering.';
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
			'true'  => 'Ends the response before delivering, so the client never waits for a listener.',
			'false' => 'Hands the delivery to the job runner instead, as on a server that cannot end a response early.',
		);
	}
}
