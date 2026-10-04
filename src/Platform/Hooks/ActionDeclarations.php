<?php
/**
 * ActionDeclarations: the one list of every public action the plugin fires outside the event bridge
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Names every ActionDeclaration, so the hooks reference and the companion test that scans src/ read the same list.
 *
 * Not part of Modules.php, for the reason FilterDeclarations is not: nothing here is needed to
 * answer a request. A test in the same namespace holds this list to what src/ declares.
 *
 * @since 0.2.0
 */
final class ActionDeclarations {

	/**
	 * Every action declaration class.
	 *
	 * @since 0.2.0
	 *
	 * @var list<class-string<ActionDeclaration>>
	 */
	public const ALL = array(
		RegisterPaymentGatewaysAction::class,
	);
}
