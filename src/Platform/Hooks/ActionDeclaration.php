<?php
/**
 * ActionDeclaration: what a public action the plugin fires says about itself
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * What a public action the plugin fires says about itself, so the hooks reference documents it without reading the call site, and the call site reads the name from here instead of repeating it as a literal.
 *
 * Owns one fact per action: its name, when it fires, and what a listener receives. A class
 * implementing this lives in this namespace and is listed in ActionDeclarations::ALL. The actions
 * the event bridge fires for domain events are not declared here: the event catalog documents
 * them. Nothing here does I/O or reads a WordPress function.
 *
 * @since 0.2.0
 */
interface ActionDeclaration {

	/**
	 * Returns the action's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name passed to do_action(), for example `seocart_register_payment_gateways`.
	 */
	public static function name(): string;

	/**
	 * Says when the action fires.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence.
	 */
	public static function firesWhen(): string;

	/**
	 * Returns the arguments a listener receives, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each argument's type, the name of a class or interface, keyed by its variable name without the `$`.
	 */
	public static function arguments(): array;
}
