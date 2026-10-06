<?php
/**
 * RefundReason: why an order is refunded, as a merchant may say it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

/**
 * The reasons a refund is given for: the one list, which the refund operation offers and a refund's claim and document record.
 *
 * Owns one fact: what a refund may be given for. The list is read here and written nowhere else.
 *
 * @since 0.2.0
 */
enum RefundReason: string {

	/**
	 * The customer sent the units back.
	 *
	 * @since 0.2.0
	 */
	case CustomerReturn = 'customer_return';

	/**
	 * The units arrived damaged.
	 *
	 * @since 0.2.0
	 */
	case Damaged = 'damaged';

	/**
	 * The units were not what the store described.
	 *
	 * @since 0.2.0
	 */
	case NotAsDescribed = 'not_as_described';

	/**
	 * The order arrived late.
	 *
	 * @since 0.2.0
	 */
	case LateDelivery = 'late_delivery';

	/**
	 * The customer placed the same order twice.
	 *
	 * @since 0.2.0
	 */
	case DuplicateOrder = 'duplicate_order';

	/**
	 * The payment was fraudulent.
	 *
	 * @since 0.2.0
	 */
	case Fraud = 'fraud';

	/**
	 * A gesture of goodwill.
	 *
	 * @since 0.2.0
	 */
	case Goodwill = 'goodwill';

	/**
	 * Any other reason, which the refund's note may tell.
	 *
	 * @since 0.2.0
	 */
	case Other = 'other';

	/**
	 * Returns the reasons a merchant may give: every one.
	 *
	 * @since 0.2.0
	 *
	 * @return list<self> The reasons, in declaration order.
	 */
	public static function merchant(): array {
		return self::cases();
	}
}
