<?php
/**
 * DeclaredFields: collects the declared fields the redactor is built from, as the kernel does
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Logging;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Schema\FieldSpec;

/**
 * Flattens the operations' and the settings' fields for Redactor::fromDeclarations().
 *
 * Owns one fact for the tests: which declared fields the production redactor is built from.
 * The logging module reads FieldSpecs only; collecting them from the registries that hold them
 * is the kernel's wiring, and the tests do the same here, so a test builds the redactor the
 * plugin runs with.
 *
 * @since 0.1.0
 */
final class DeclaredFields {

	/**
	 * Returns every input and output field of every operation in a registry.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $operations The operations.
	 * @return list<FieldSpec> The fields, in declaration order.
	 */
	public static function ofOperations( OperationRegistry $operations ): array {
		$fields = array();

		foreach ( $operations->all() as $definition ) {
			$fields = array_merge( $fields, $definition->input(), $definition->output()->fields() );
		}

		return $fields;
	}

	/**
	 * Returns the fields of the production operations and of every setting, as the kernel collects them for the production redactor.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	public static function production(): array {
		return Modules::declaredFields( Operations::registry() );
	}
}
