<?php
/**
 * FixtureRegisterAction: a fixture action declaration HooksReference is proven against
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Hooks;

use SEOCart\Platform\Hooks\ActionDeclaration;
use SEOCart\Tools\Docs\Tests\Fixtures\Events\FixtureBinRestocked;

/**
 * Fires when the fixture warehouse first needs its bins, so a bin plugin can register its bins.
 *
 * Fixture only: proves HooksReference documents an action from its own declaration.
 *
 * @since 0.2.0
 */
final class FixtureRegisterAction implements ActionDeclaration {

	/**
	 * Returns the action's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name.
	 */
	public static function name(): string {
		return 'seocart_fixture_register';
	}

	/**
	 * Says when the action fires.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence.
	 */
	public static function firesWhen(): string {
		return 'Once per request, the first time a bin is needed.';
	}

	/**
	 * Returns the argument a listener receives.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> A fixture class, keyed `registry`.
	 */
	public static function arguments(): array {
		return array( 'registry' => FixtureBinRestocked::class );
	}
}
