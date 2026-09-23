<?php
/**
 * Generators: the list of every generator of a committed document
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Application\Operations\Operations;
use SEOCart\Platform\Http\OutboundEndpoints;

/**
 * Builds every generator from its source, in the order they run.
 *
 * This class owns one fact: which committed documents are generated, and from what. The
 * documentation command runs this list, and its test runs the same list, so a generator cannot be
 * dropped from the command while its document still passes the check. A new generated document
 * is added here and to nothing else.
 *
 * @since 0.1.0
 */
final class Generators {

	/**
	 * Builds the generators.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root Absolute path of the repository root, whose src/ holds the error catalogs.
	 * @return list<Generator> The generators, in the order they run.
	 */
	public static function all( string $root ): array {
		$operations = Operations::registry();
		$errors     = ErrorCatalogs::table( rtrim( $root, '/' ) . '/src' );

		return array(
			new ExternalServicesSection( OutboundEndpoints::all() ),
			new OpenApiDocument( $operations, $errors ),
			new AbilitiesReference( $operations, $errors ),
			new CliReference( $operations, $errors ),
			new ErrorsReference( $errors ),
		);
	}
}
