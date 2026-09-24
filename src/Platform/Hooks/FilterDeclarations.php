<?php
/**
 * FilterDeclarations: the one list of every filter the plugin applies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Names every FilterDeclaration, so the hooks reference and the companion test that scans src/
 * for undeclared `seocart_` filters and actions read the same list.
 *
 * This is not part of Modules.php: nothing here is bound into the container, resolved by the
 * kernel, or needed to answer a request until the one class that applies a filter is itself
 * loaded, so listing it there would buy nothing and would cost the idle-request budget the
 * kernel's own docblock holds it to. A test in the same namespace holds this list to what src/
 * declares, the same way Modules::EVENT_CLASSES is held to it.
 *
 * @since 0.1.0
 */
final class FilterDeclarations {

	/**
	 * Every filter declaration class.
	 *
	 * @since 0.1.0
	 *
	 * @var list<class-string<FilterDeclaration>>
	 */
	public const ALL = array(
		EndResponseEarlyFilter::class,
	);
}
