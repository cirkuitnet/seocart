<?php
/**
 * CardNumberProperty: a gateway with a property named like card data
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Keeps a property named like a card number.
 *
 * The plant of rule 4: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class CardNumberProperty extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = '::$card_number';

	/**
	 * A card number the gateway would keep.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	public ?string $card_number = null;
}
