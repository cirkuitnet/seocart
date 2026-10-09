<?php
/**
 * Tests the note a clearance of an order's unreconciled money keeps, as the order service checks it for every caller
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Application\UnreconciledMoney;
use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\ConversionContexts;
use SEOCart\Order\Domain\OrderNumberGenerator;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * The order service refuses a clearance's note longer than the clearance operation takes, whoever calls it: the route and the command refuse it by the field's schema, and a caller in PHP is refused here, before anything is read.
 *
 * The note is counted in characters, as the schema counts them. The repository is a mock that
 * fails the test on any call it does not expect, and user_can() is stubbed to allow, so the
 * refusal is the note's alone.
 *
 * Planted violation, shown red and removed: in Orders::clearUnreconciledMoney(), drop the length
 * check. The note of 501 characters then reaches the transaction and the order's lock.
 *
 * @since 0.2.0
 */
final class ReconciliationNoteTest extends TestCase {

	/**
	 * The order every clearance names.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ORDER = '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b';

	/**
	 * Stubs user_can() to allow every capability.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'user_can' )->justReturn( true );
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a note of 501 characters is refused with the longest the operation takes, before a transaction or any read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_note_longer_than_the_operation_takes_is_refused_before_anything_is_read(): void {
		$this->assertSame( 500, OrderOperations::NOTE_MAX_LENGTH );

		$repository = $this->createMock( OrderRepository::class );
		$tx         = new FakeTransactionManager();

		$repository->expects( $this->never() )->method( $this->anything() );

		try {
			$this->ordersOver( $repository, $tx )->clearUnreconciledMoney(
				array(
					'order_uuid' => self::ORDER,
					'note'       => str_repeat( 'x', 501 ),
				),
				Actor::user( 7 )
			);
			$this->fail( 'The clearance went on.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( OrderError::ReconciliationNoteRejected, array( 'max_length' => 500 ) ), array( $refused->errorCode(), $refused->context() ) );
		}

		$this->assertSame( 0, $tx->attempts(), 'No transaction was begun.' );
	}

	/**
	 * Tests that a note of 500 characters, of two bytes each, is taken: the clearance goes on to lock the order, here one that is not there.
	 *
	 * @since 0.2.0
	 */
	public function test_a_note_of_500_characters_is_taken_whatever_its_bytes(): void {
		$repository = $this->createMock( OrderRepository::class );

		$repository->expects( $this->once() )->method( 'lockReconciliation' )->with( self::ORDER )->willReturn( null );

		try {
			$this->ordersOver( $repository, new FakeTransactionManager() )->clearUnreconciledMoney(
				array(
					'order_uuid' => self::ORDER,
					'note'       => str_repeat( "\u{00E9}", 500 ),
				),
				Actor::user( 7 )
			);
			$this->fail( 'An order that is not there was cleared.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::NotFound, $refused->errorCode(), 'The note was taken, and the order looked for.' );
		}
	}

	/**
	 * Builds the order service over a repository and a unit of work, with stand-ins for what a clearance never uses.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderRepository        $repository The statements.
	 * @param FakeTransactionManager $tx         The unit of work.
	 * @return Orders The service.
	 */
	private function ordersOver( OrderRepository $repository, FakeTransactionManager $tx ): Orders {
		return new Orders(
			$repository,
			$this->createStub( OrderNumberGenerator::class ),
			$this->createStub( AccessKeys::class ),
			$this->createStub( ConversionContexts::class ),
			new OrderStatusRegistry(),
			$tx,
			new RecordingEventPublisher( $tx ),
			new SequentialIdGenerator(),
			FrozenClock::at( '2026-10-09 12:00:00' ),
			new CorrelationId( new SequentialIdGenerator() ),
			new Authorizer( new CapabilityDeclaration() ),
			$this->createStub( UnreconciledMoney::class )
		);
	}
}
