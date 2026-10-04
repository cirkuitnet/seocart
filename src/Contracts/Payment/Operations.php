<?php
/**
 * Operations: the names of what a gateway can be declared able to do
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary of a capability matrix's rows: each operation a gateway may declare for a currency and an account country.
 *
 * Owns one fact: the operation names. A gateway must declare the required ones in every row: a
 * gateway that cannot authorize, capture in full, void an authorization, refund, or be asked
 * about an intent or a refund is not a gateway. The others it declares only where its provider
 * supports them, and the plugin refuses, before any call, an operation a row does not declare.
 *
 * @since 0.2.0
 *
 * @api
 */
final class Operations {

	/**
	 * Reserve the amount on the customer's payment method.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUTHORIZE = 'authorize';

	/**
	 * Take the whole authorized amount.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPTURE = 'capture';

	/**
	 * Take less than was authorized; the provider releases the rest.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PARTIAL_CAPTURE = 'partial_capture';

	/**
	 * Take an authorization in more than one capture.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MULTI_CAPTURE = 'multi_capture';

	/**
	 * Cancel an authorization without taking it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VOID = 'void';

	/**
	 * Give back the whole captured amount.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND = 'refund';

	/**
	 * Give back part of the captured amount.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PARTIAL_REFUND = 'partial_refund';

	/**
	 * Charge a saved payment method without the customer present.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OFF_SESSION = 'off_session';

	/**
	 * Ask the customer to confirm a payment, for example with their bank.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SCA = 'sca';

	/**
	 * Say where an intent stands.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const QUERY = 'query';

	/**
	 * Say what became of a refund, by its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const QUERY_REFUND = 'query_refund';

	/**
	 * Send signed deliveries to the plugin's webhook.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOKS = 'webhooks';

	/**
	 * Every operation a row may declare.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ALL = array(
		self::AUTHORIZE,
		self::CAPTURE,
		self::PARTIAL_CAPTURE,
		self::MULTI_CAPTURE,
		self::VOID,
		self::REFUND,
		self::PARTIAL_REFUND,
		self::OFF_SESSION,
		self::SCA,
		self::QUERY,
		self::QUERY_REFUND,
		self::WEBHOOKS,
	);

	/**
	 * The operations every row must declare.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const REQUIRED = array(
		self::AUTHORIZE,
		self::CAPTURE,
		self::VOID,
		self::REFUND,
		self::QUERY,
		self::QUERY_REFUND,
	);
}
