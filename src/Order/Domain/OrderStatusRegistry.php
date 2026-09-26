<?php
/**
 * OrderStatusRegistry: the order statuses as data, and the transitions between them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The one table of the order statuses: what each means, and which status may follow which.
 *
 * Owns one fact: the order state machine. Nothing else lists a transition. The conditional
 * update that changes an order's status compiles its WHERE clause from allowedFrom() at the
 * moment it is sent, so the database refuses what the table does not allow; no code decides in
 * PHP first. The tests generate their matrix from the table too, so a row added here changes the
 * behaviour and the expectation together.
 *
 * A status whose metadata says `is_paid` may be entered only while the order's payment status is
 * settled (PaymentStatus::settled()). Only `completed` says so: an order becomes `processing` when
 * its payment is authorized, before any money is captured.
 *
 * `pending_payment` is where every order starts, and nothing leads back to it. `on_hold` parks an
 * order a person must look at, such as one whose payment did not match it, and a person resolves
 * it to `processing`, `cancelled` or `failed` without passing through another status.
 *
 * Pure data: no I/O and no WordPress call.
 *
 * @since 0.1.0
 */
final class OrderStatusRegistry {

	/**
	 * Every status: its metadata, its label key, and the statuses it may change to.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{is_paid: bool, is_final: bool, holds_allocation: bool, posts_stock_ledger: bool, releases_fulfilment: bool, label: string, to: list<string>}>
	 */
	private const TABLE = array(
		'pending_payment' => array(
			'is_paid'             => false,
			'is_final'            => false,
			'holds_allocation'    => false,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'pending',
			'to'                  => array( 'awaiting_review', 'processing', 'failed', 'cancelled', 'on_hold' ),
		),
		'awaiting_review' => array(
			'is_paid'             => false,
			'is_final'            => false,
			'holds_allocation'    => true,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'awaiting_review',
			'to'                  => array( 'processing', 'cancelled', 'on_hold' ),
		),
		'processing'      => array(
			'is_paid'             => false,
			'is_final'            => false,
			'holds_allocation'    => true,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => true,
			'label'               => 'processing',
			'to'                  => array( 'on_hold', 'preorder', 'completed', 'cancelled' ),
		),
		'on_hold'         => array(
			'is_paid'             => false,
			'is_final'            => false,
			'holds_allocation'    => true,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'on_hold',
			'to'                  => array( 'processing', 'cancelled', 'failed' ),
		),
		'preorder'        => array(
			'is_paid'             => false,
			'is_final'            => false,
			'holds_allocation'    => true,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'preorder',
			'to'                  => array( 'processing' ),
		),
		'completed'       => array(
			'is_paid'             => true,
			'is_final'            => true,
			'holds_allocation'    => false,
			'posts_stock_ledger'  => true,
			'releases_fulfilment' => false,
			'label'               => 'completed',
			'to'                  => array( 'cancelled' ),
		),
		'cancelled'       => array(
			'is_paid'             => false,
			'is_final'            => true,
			'holds_allocation'    => false,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'cancelled',
			'to'                  => array( 'processing' ),
		),
		'failed'          => array(
			'is_paid'             => false,
			'is_final'            => true,
			'holds_allocation'    => false,
			'posts_stock_ledger'  => false,
			'releases_fulfilment' => false,
			'label'               => 'failed',
			'to'                  => array(),
		),
	);

	/**
	 * Returns the status every order starts in.
	 *
	 * @since 0.1.0
	 *
	 * @return OrderStatus pending_payment.
	 */
	public function initial(): OrderStatus {
		return OrderStatus::PendingPayment;
	}

	/**
	 * Returns what a status means.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderStatus $status The status.
	 * @return StatusMetadata Its metadata.
	 */
	public function metadata( OrderStatus $status ): StatusMetadata {
		$row = self::TABLE[ $status->value ];

		return new StatusMetadata( $row['is_paid'], $row['is_final'], $row['holds_allocation'], $row['posts_stock_ledger'], $row['releases_fulfilment'], $row['label'] );
	}

	/**
	 * Returns the statuses an order may enter a status from: what the transition's WHERE clause lists.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderStatus $to The status entered.
	 * @return list<OrderStatus> The statuses, in the table's order; empty when nothing leads to it.
	 */
	public function allowedFrom( OrderStatus $to ): array {
		$from = array();

		foreach ( self::TABLE as $status => $row ) {
			if ( in_array( $to->value, $row['to'], true ) ) {
				$from[] = OrderStatus::from( $status );
			}
		}

		return $from;
	}

	/**
	 * Tells whether the table allows one status to follow another.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderStatus $from The status now.
	 * @param OrderStatus $to   The status wanted.
	 * @return bool True when the table lists the transition.
	 */
	public function isAllowed( OrderStatus $from, OrderStatus $to ): bool {
		return in_array( $to->value, self::TABLE[ $from->value ]['to'], true );
	}

	/**
	 * Returns the whole transition table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>> Every status => the statuses it may change to.
	 */
	public function transitions(): array {
		return array_map( static fn( array $row ): array => $row['to'], self::TABLE );
	}
}
