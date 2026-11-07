<?php
/**
 * CoreGatewayCurrencies: the currencies SEOCart's own gateways take
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The currencies of the gateways SEOCart registers itself: the offline payment methods and the stand-in.
 *
 * Owns one fact: which currencies those gateways declare in their capability matrices, one row
 * per currency for an account of any country. A store selling in another currency cannot offer
 * them until the list grows; the conformance suite exercises every declared currency, so each
 * one added costs the suite a placement per rule.
 *
 * @since 0.2.0
 */
final class CoreGatewayCurrencies {

	/**
	 * The currencies, ISO 4217: the US dollar, the pound sterling and the euro.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const LAUNCH = array( 'USD', 'GBP', 'EUR' );
}
