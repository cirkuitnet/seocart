<?php
/**
 * Tests the stock allocations and the outbox events a placement of Cart B writes, against a recorded fixture
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * A placement of Cart B leaves exactly the stock and outbox rows the fixtures record, in the same order.
 *
 * The fixtures were recorded when every hold row, every allocation row and every outbox row was
 * an INSERT of its own. An approved placement converts its hold rows into allocation rows, so
 * none of them is left; a placement that waits for the shopper keeps its hold rows, and those are
 * compared instead. Every column is compared but the row's own id and its times; the times that
 * carry meaning are checked apart: an outbox row is due as soon as it is stored, and every hold
 * row expires when the hold the placement took does. The rows are read in id order, the order the outbox delivers them in, so a statement that writes several
 * rows at once must also write them in the order they were written one by one. An id a row names
 * is replaced by what it names: the order, an order line by its position in the order, a variant
 * by its slot in Cart B, the cart, the hold, the intent, the ledger row and the request, so the
 * fixtures hold whatever ids a run gets. Which kind of row an id names is told by its column or
 * payload field, because each table counts its ids from 1; an id the test cannot name is kept as
 * it is, and fails the comparison.
 *
 * @since 0.1.0
 */
final class PlacementRowsTest extends PlacementTestCase {

	/**
	 * The fixture of an approved placement's allocation and outbox rows, from the repository's root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FIXTURE = 'tests/Fixtures/Placement/cart-b-rows.json';

	/**
	 * The fixture of the hold rows of a placement that waits for the shopper, from the repository's root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HOLDS_FIXTURE = 'tests/Fixtures/Placement/cart-b-hold-rows.json';

	/**
	 * The kind of row each column or payload field that holds an id, a uuid or a number names.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const KINDS = array(
		'order_id'       => 'order',
		'order_uuid'     => 'order',
		'order_number'   => 'order',
		'order_line_id'  => 'line',
		'variant_id'     => 'variant',
		'variant_ids'    => 'variant',
		'intent_id'      => 'intent',
		'intent_uuid'    => 'intent',
		'transaction_id' => 'transaction',
		'cart_id'        => 'cart',
		'hold_group'     => 'hold',
	);

	/**
	 * The kind of row an outbox row's aggregate id names, by its aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const AGGREGATES = array(
		'order'          => 'order',
		'payment_intent' => 'intent',
	);

	/**
	 * Tests that placing Cart B, approved, writes the fixture's allocation and outbox rows, each outbox row due at once.
	 *
	 * Planted violation: in Outbox's insert, make a row due a second after it is stored: the
	 * placement's outbox rows are then not due at once.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_writes_the_recorded_rows(): void {
		$variants = $this->readyCartB();
		$after    = (int) $this->db->fetchValue( 'SELECT COALESCE( MAX( id ), 0 ) FROM %i', $this->table( OutboxTable::NAME ) );
		$answer   = $this->placement->place( $this->placeInput( 'rows' ), self::guest() );

		$this->assertSame( 'approved', $answer['outcome'] );

		$names = $this->namesOf( (string) $answer['order_uuid'], $variants );
		$rows  = array(
			'allocations' => $this->allocations( $names ),
			'outbox'      => $this->outbox( $after, $names ),
		);

		$this->assertSame( self::fixture( self::FIXTURE ), $rows );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( InventoryTables::HOLDS ) ), 'Every hold row became its allocation.' );
		$this->assertSame(
			array(),
			$this->db->fetchAll( 'SELECT id, available_at, created_at FROM %i WHERE id > %d AND NOT ( available_at = created_at AND available_at <= UTC_TIMESTAMP(6) )', $this->table( OutboxTable::NAME ), $after ),
			'Every outbox row is due at once, from the moment it was stored.'
		);
	}

	/**
	 * Tests that placing Cart B, waiting for the shopper to act, writes the fixture's hold rows, which the order keeps, each expiring when the hold does.
	 *
	 * Planted violation: in StockService::holdInside(), give insertHolds() an expiry a second later
	 * than the hold's: the hold rows then outlive the hold the placement took.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_that_waits_for_the_shopper_keeps_the_recorded_hold_rows(): void {
		$variants = $this->readyCartB();
		$expiries = array();

		add_action(
			'seocart_' . StockReserved::eventName(),
			static function ( StockReserved $reserved ) use ( &$expiries ): void {
				$expiries[] = $reserved->expiresAt;
			}
		);

		$answer = $this->placement->place( $this->placeInput( 'holds', StubGateway::REQUIRES_ACTION ), self::guest() );

		$this->assertSame( 'requires_action', $answer['outcome'] );
		$this->assertSame( self::fixture( self::HOLDS_FIXTURE ), $this->holds( $this->namesOf( (string) $answer['order_uuid'], $variants ) ) );
		$this->assertCount( 1, $expiries, 'The placement took one hold.' );
		$this->assertSame(
			array_fill( 0, count( $variants ), $expiries[0] ),
			array_map( static fn( array $row ): string => (string) $row['expires_at'], $this->db->fetchAll( 'SELECT expires_at FROM %i ORDER BY id', $this->table( InventoryTables::HOLDS ) ) ),
			'Every hold row expires when the hold the placement took does.'
		);
	}

	/**
	 * Reads a fixture.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The fixture, from the repository's root.
	 * @return mixed What it holds.
	 */
	private static function fixture( string $file ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A fixture of the test suite, read from disk.
		return json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $file ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns, for each kind of row, what each of its ids, uuids or numbers the placement's rows may hold stands for.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderUuid The order's uuid.
	 * @param int[]  $variants  Cart B's variants, in slot order.
	 * @return array<string, array<int|string, string>> The names, by kind and value.
	 *
	 * @phpstan-param list<int> $variants
	 */
	private function namesOf( string $orderUuid, array $variants ): array {
		$order = $this->db->fetchRow( 'SELECT id, uuid, order_number, hold_group FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $orderUuid );

		$this->assertNotNull( $order );

		$names = array(
			'order'       => array(
				(string) $order['id']           => 'order',
				(string) $order['uuid']         => 'order uuid',
				(string) $order['order_number'] => 'order number',
			),
			'variant'     => array(),
			'line'        => array(),
			'intent'      => array(),
			'transaction' => array(),
			'cart'        => array(),
			'hold'        => array( (string) $order['hold_group'] => 'hold' ),
		);

		foreach ( $this->db->fetchAll( 'SELECT id FROM %i WHERE order_id = %d', $this->table( CartTables::CARTS ), $order['id'] ) as $cart ) {
			$names['cart'][ (string) $cart['id'] ] = 'cart';
		}

		foreach ( $variants as $slot => $variantId ) {
			$names['variant'][ $variantId ] = 'variant ' . $slot;
		}

		foreach ( $this->db->fetchAll( 'SELECT id FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::LINES ), $order['id'] ) as $position => $line ) {
			$names['line'][ (string) $line['id'] ] = 'line ' . $position;
		}

		foreach ( $this->db->fetchAll( 'SELECT id, uuid FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::INTENTS ), $order['id'] ) as $position => $intent ) {
			$names['intent'][ (string) $intent['id'] ]   = 'intent ' . $position;
			$names['intent'][ (string) $intent['uuid'] ] = 'intent uuid ' . $position;
		}

		foreach ( $this->db->fetchAll( 'SELECT id FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $order['id'] ) as $position => $transaction ) {
			$names['transaction'][ (string) $transaction['id'] ] = 'transaction ' . $position;
		}

		return $names;
	}

	/**
	 * Reads the order's allocation rows, in id order, each with the ids it holds replaced by their names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int|string, string>> $names What each id stands for, by kind.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function allocations( array $names ): array {
		$rows = array();

		foreach ( $this->db->fetchAll( 'SELECT variant_id, order_id, order_line_id, quantity, posted_quantity, state FROM %i WHERE order_id = %d ORDER BY id', $this->table( InventoryTables::ALLOCATIONS ), array_search( 'order', $names['order'], true ) ) as $row ) {
			$rows[] = array(
				'variant_id'      => self::named( $names, 'variant_id', $row['variant_id'] ),
				'order_id'        => self::named( $names, 'order_id', $row['order_id'] ),
				'order_line_id'   => self::named( $names, 'order_line_id', $row['order_line_id'] ),
				'quantity'        => (int) $row['quantity'],
				'posted_quantity' => (int) $row['posted_quantity'],
				'state'           => $row['state'],
			);
		}

		return $rows;
	}

	/**
	 * Reads every hold row, in id order, each with the ids it holds replaced by their names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int|string, string>> $names What each id stands for, by kind.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function holds( array $names ): array {
		$rows = array();

		foreach ( $this->db->fetchAll( 'SELECT variant_id, cart_id, order_id, hold_group, quantity, reclaim_token FROM %i ORDER BY id', $this->table( InventoryTables::HOLDS ) ) as $row ) {
			$rows[] = array(
				'variant_id'    => self::named( $names, 'variant_id', $row['variant_id'] ),
				'cart_id'       => self::named( $names, 'cart_id', $row['cart_id'] ),
				'order_id'      => self::named( $names, 'order_id', $row['order_id'] ),
				'hold_group'    => self::named( $names, 'hold_group', $row['hold_group'] ),
				'quantity'      => (int) $row['quantity'],
				'reclaim_token' => $row['reclaim_token'],
			);
		}

		return $rows;
	}

	/**
	 * Reads the outbox rows written after an id, in id order, each with its payload decoded, its instant left out and the ids it holds replaced by their names.
	 *
	 * @since 0.1.0
	 *
	 * @param int                                      $after The last row before the placement.
	 * @param array<string, array<int|string, string>> $names What each id stands for, by kind.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function outbox( int $after, array $names ): array {
		$requests = array();
		$rows     = array();

		foreach ( $this->db->fetchAll( 'SELECT event_name, aggregate_type, aggregate_id, payload_json, correlation_id, state, claim_token, attempts, last_error FROM %i WHERE id > %d ORDER BY id', $this->table( OutboxTable::NAME ), $after ) as $row ) {
			$stored  = Outbox::decode( (string) $row['payload_json'] );
			$payload = array();

			foreach ( $stored['p'] as $field => $value ) {
				$payload[ $field ] = self::named( $names, (string) $field, $value );
			}

			$requests[ (string) $row['correlation_id'] ] ??= 'request ' . count( $requests );

			$rows[] = array(
				'event_name'     => $row['event_name'],
				'aggregate_type' => $row['aggregate_type'],
				'aggregate_id'   => $names[ self::AGGREGATES[ $row['aggregate_type'] ] ?? '' ][ (string) $row['aggregate_id'] ] ?? $row['aggregate_id'],
				'payload'        => array(
					'v' => $stored['v'],
					'p' => $payload,
				),
				'correlation_id' => $requests[ (string) $row['correlation_id'] ],
				'state'          => $row['state'],
				'claim_token'    => $row['claim_token'],
				'attempts'       => (int) $row['attempts'],
				'last_error'     => $row['last_error'],
			);
		}

		return $rows;
	}

	/**
	 * Replaces the id a column or field holds, or each id of its list, by its name; any other value, or an id the test cannot name, stays as it is.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<int|string, string>> $names What each id stands for, by kind.
	 * @param string                                   $field The column or payload field.
	 * @param mixed                                    $value Its value.
	 * @return mixed The name, the list of names, or the value unchanged.
	 */
	private static function named( array $names, string $field, mixed $value ): mixed {
		$kind = self::KINDS[ $field ] ?? null;

		if ( null === $kind || null === $value ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return array_map( static fn( mixed $item ): mixed => $names[ $kind ][ (string) $item ] ?? $item, $value );
		}

		return $names[ $kind ][ (string) $value ] ?? $value;
	}
}
