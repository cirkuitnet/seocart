<?php
/**
 * ClaimEndedElsewhere: another request ended a refund's claim while this one recorded a decline of it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown inside the savepoint that records a refund's decline when the refund's claim had already ended, so the savepoint takes the decline's ledger row back.
 *
 * Owns one fact: that a decline would be recorded twice for one claim. A gateway may decline a
 * refund without naming a provider object, so the ledger's unique key does not tell the decline
 * answering refund() from the same decline answering queryRefund() on another request; the claim
 * does. RefundService catches this once its transaction has rolled back, and answers from the
 * claim as the other request ended it. It never leaves the refund service.
 *
 * Thrown too when a person's settlement, or the provider's own delivery of the refund's answer,
 * finds under the intent's lock that the claim it read open has ended since.
 *
 * @since 0.1.0
 * @since 0.2.0 A settlement or a delivery finding the claim ended.
 */
final class ClaimEndedElsewhere extends \RuntimeException {
}
