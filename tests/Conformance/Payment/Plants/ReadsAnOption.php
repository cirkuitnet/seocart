<?php
/**
 * ReadsAnOption: a gateway whose declaration reads an option
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads a WordPress option while it describes itself.
 *
 * The plant of rule 15: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class ReadsAnOption extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'describe() called get_option()';

	/**
	 * Reads an option, then describes itself as the reference gateway does.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	public function describe(): GatewayDescriptor {
		get_option( 'reference_gateway_settings' );

		return parent::describe();
	}
}
