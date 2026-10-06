<?php
/**
 * Tests that a refund's note holding a card number is refused on every surface, and never kept or shown
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentOperations;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A refund's note is kept for as long as the order is, so a note that holds a card number is refused `payment.refund_note_rejected` before anything is read, claimed or asked of the gateway, by the refund service itself: asked of the service directly, with a key or without one, and on the route and on the command, where neither answer shows the number.
 *
 * The number is a published test card number, written with spaces as a person would type it.
 *
 * Planted violation, shown red and removed: in RefundService::refund(), drop the check of the
 * note, and check it in RefundService::requestOf() instead, the operation's adapter alone: the
 * route and the command still refuse it, but a refund asked of the service directly is made, and
 * its claim keeps the number.
 *
 * @since 0.2.0
 */
final class RefundNoteTest extends RefundTestCase {

	/**
	 * A note with a published test card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'Charged twice on 4111 1111 1111 1111, refund one.';

	/**
	 * The refund on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Wires the refund on every surface, over the test's refund service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();

		$registry->add( PaymentOperations::REFUND_ORDER, array( PaymentOperations::class, 'refundOrder' ) );

		$this->surfaces = new OperationSurfaces( $registry, $this->refunds );
	}

	/**
	 * Discards the surfaces.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Tests that the note is refused on the route and on the command, that nothing is claimed or asked, and that no answer shows the number.
	 *
	 * @since 0.2.0
	 */
	public function test_a_note_with_a_card_number_is_refused_and_never_shown(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$lines         = array(
			array(
				'line_uuid' => $tee,
				'quantity'  => 1,
			),
		);

		wp_set_current_user( $this->agent()->userId() );

		$route = $this->surfaces->rest(
			'POST',
			'/orders/' . $order->uuid . '/refunds',
			array(
				'lines'       => $lines,
				'reason_code' => 'other',
				'note'        => self::NOTE,
			),
			array(),
			array( IdempotencyKey::HEADER => 'note-on-route' )
		);
		$text  = (string) wp_json_encode( $route->get_data() );

		$this->assertSame( array( 422, PaymentError::RefundNoteRejected->value ), array( $route->get_status(), $route->get_data()['code'] ?? null ), $text );
		$this->assertStringNotContainsString( '4111', $text, 'The route\'s answer shows the number.' );

		$command = $this->surfaces->cli(
			'seocart order refund',
			array( $order->uuid ),
			array(
				'lines'           => (string) wp_json_encode( $lines ),
				'reason_code'     => 'other',
				'note'            => self::NOTE,
				'idempotency_key' => 'note-on-command',
			)
		);

		$this->assertStringStartsWith( PaymentError::RefundNoteRejected->value, (string) $command['failure'] );
		$this->assertStringNotContainsString( '4111', (string) wp_json_encode( $command ), 'The command\'s output shows the number.' );

		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed, so the note was kept nowhere.' );
		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );
	}

	/**
	 * Tests that the service itself refuses the note, asked directly without a key and with one, before any read: nothing is claimed, kept or asked of the gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_the_service_refuses_the_note_before_any_read(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$request       = self::request( $order->uuid, array( $tee => 1 ), false, self::NOTE );
		$keys          = array(
			'without a key' => null,
			'with a key'    => $this->requestKey( $request, 'note-on-service' ),
		);

		foreach ( $keys as $how => $key ) {
			$refused = null;
			$log     = $this->captureQueries(
				function () use ( $request, $key, &$refused ): void {
					try {
						$this->refunds->refund( $request, $this->agent(), $key );
					} catch ( CodedException $error ) {
						$refused = $error;
					}
				}
			)->matching( self::STATEMENTS );

			$this->assertSame( PaymentError::RefundNoteRejected, $refused?->errorCode(), 'The note was taken ' . $how . '.' );
			$this->assertQueryCount( 0, $log, 'a note refused ' . $how );
		}

		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed, so the note was kept nowhere.' );
		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );
	}
}
