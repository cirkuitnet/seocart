<?php
/**
 * ExtensionType: the kinds of SEOCart extension the kit can generate and test
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension;

/**
 * The one list of extension types, with what each type means to the kit.
 *
 * A type decides what bin/dev/new-extension.sh writes (bin/dev/extension-types/<type>/), how
 * the extension's plugin is named, which SEOCart action it registers on, and which of
 * SEOCart's conformance suites its CI runs (bin/ci/extension.sh conformance). The value is
 * what an extension's CI passes as `extension-type`.
 *
 * @since 0.2.0
 */
enum ExtensionType: string {

	case Payments = 'payments';

	/**
	 * Returns the plugin's display name, from the name of the service it integrates.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The service, for example `Stripe`.
	 * @return string For example `SEOCart Gateway for Stripe`.
	 */
	public function pluginName( string $label ): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => 'SEOCart Gateway for ' . $label,
		};
	}

	/**
	 * Returns the SEOCart action an extension of this type registers itself on.
	 *
	 * Empty while SEOCart at this commit declares none: the generator then writes no
	 * registration and says so.
	 *
	 * @since 0.2.0
	 *
	 * @return string The action's name, or an empty string.
	 */
	public function registrationAction(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => '',
		};
	}

	/**
	 * Returns the Composer script of SEOCart that runs this type's conformance suite against an extension.
	 *
	 * Empty while SEOCart at this commit has no such suite: the CI step then says so and passes.
	 * A script named here that SEOCart's composer.json does not define fails the step instead,
	 * so a rename can never turn into a silent skip.
	 *
	 * @since 0.2.0
	 *
	 * @return string The script's name in SEOCart's composer.json, or an empty string.
	 */
	public function conformanceScript(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- an enum's method has its case as $this; the sniff predates enums.
		return match ( $this ) {
			self::Payments => '',
		};
	}
}
