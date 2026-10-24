<?php
/**
 * PlacementSnapshots: every row a placement's settlement may write, taken whole, and what changed between two takes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Promotion\Infrastructure\PromotionTables;

/**
 * Takes every row of the tables a placement's settlement writes, and describes each row that changed between two takes by the payment it belongs to.
 *
 * Owns one fact: how a settlement test proves that nothing moved but what it names. A take holds
 * every row of the intents, the ledger, the orders and their events, the outbox, the stock items,
 * holds, allocations and movements, the promotion uses and the carts. changesSince() compares a
 * take with the rows as they are now: each row that is gone and each that is new, a changed row
 * being both, is described by the name of the payment it belongs to, so assertChanges() asserts
 * the whole difference at once and any other change fails it. The builders of expected changes
 * are for a placement of one unit of a variant stocked with five, as WebhookTestCase::placed()
 * makes one, whose payment waits for its answer.
 *
 * @since 0.2.0
 */
trait PlacementSnapshots {

	/**
	 * Asserts that every row a settlement writes changed since a take as expected, and no other.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array{gone: list<string>, new: list<string>}> $expected The changes, by table, in any order.
	 * @param array<string, list<array<string, mixed>>>                   $before   The take.
	 * @param array<string, array<string, mixed>>                         $payments The payments, by name.
	 */
	protected function assertChanges( array $expected, array $before, array $payments ): void {
		foreach ( $expected as $table => $rows ) {
			sort( $rows['gone'] );
			sort( $rows['new'] );

			$expected[ $table ] = $rows;
		}

		$actual = $this->changesSince( $before, $payments );

		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual, 'Every row a settlement writes, before and after.' );
	}

	/**
	 * Returns what keeping an approval for a person changes for a payment waiting on its shopper: the unapplied row, the order flagged and on hold, its units allocated and its cart converted; the intent, nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name      The payment.
	 * @param string $operation The operation kept.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes.
	 */
	protected static function keptForAPerson( string $name, string $operation ): array {
		return array(
			PaymentTables::TRANSACTIONS => self::change( array(), array( $name . ' ' . $operation . ':approved:0' ) ),
			OrderTables::ORDERS         => self::change( array( $name . ' pending_payment/pending/0' ), array( $name . ' on_hold/pending/1' ) ),
			OrderTables::EVENTS         => self::change( array(), array( $name . ' order:pending_payment>on_hold:unexpected_result' ) ),
			OutboxTable::NAME           => self::change( array(), array( $name . ' order_status_changed', $name . ' stock_allocated' ) ),
		) + self::allocated( $name );
	}

	/**
	 * Returns what an approved authorization's settlement changes for a payment waiting on its shopper, but its intent: the applied row, the order accepted, its units allocated and its cart converted.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The payment.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes.
	 */
	protected static function authorized( string $name ): array {
		return array(
			PaymentTables::TRANSACTIONS => self::change( array(), array( $name . ' authorize:approved:1' ) ),
			OrderTables::ORDERS         => self::change( array( $name . ' pending_payment/pending/0' ), array( $name . ' processing/authorized/0' ) ),
			OrderTables::EVENTS         => self::change( array(), array( $name . ' order:pending_payment>processing:payment_approved', $name . ' payment:pending>authorized:payment_authorized' ) ),
			OutboxTable::NAME           => self::change( array(), array( $name . ' order_placed', $name . ' order_status_changed', $name . ' payment_authorized', $name . ' payment_status_changed', $name . ' stock_allocated' ) ),
		) + self::allocated( $name );
	}

	/**
	 * Returns what allocating a payment's one held unit changes: its item, its hold row gone, its allocation, and its cart converted.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The payment.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes.
	 */
	protected static function allocated( string $name ): array {
		return array(
			InventoryTables::ITEMS       => self::change( array( $name . ' 5/0/1' ), array( $name . ' 5/1/0' ) ),
			InventoryTables::HOLDS       => self::change( array( $name . ' held 1' ), array() ),
			InventoryTables::ALLOCATIONS => self::change( array(), array( $name . ' allocated 1 open' ) ),
			CartTables::CARTS            => self::change( array( $name . ' placing' ), array( $name . ' converted' ) ),
		);
	}

	/**
	 * Builds one table's change.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $gone The rows gone.
	 * @param string[] $made The rows new.
	 * @return array{gone: list<string>, new: list<string>} The change.
	 *
	 * @phpstan-param list<string> $gone
	 * @phpstan-param list<string> $made
	 */
	protected static function change( array $gone, array $made ): array {
		return array(
			'gone' => $gone,
			'new'  => $made,
		);
	}

	/**
	 * Merges the changes of several payments, table by table.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array{gone: list<string>, new: list<string>}> ...$changes The changes.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes merged.
	 */
	protected static function merged( array ...$changes ): array {
		$merged = array();

		foreach ( $changes as $change ) {
			foreach ( $change as $table => $rows ) {
				$merged[ $table ] = self::change( array_merge( $merged[ $table ]['gone'] ?? array(), $rows['gone'] ), array_merge( $merged[ $table ]['new'] ?? array(), $rows['new'] ) );
			}
		}

		return $merged;
	}

	/**
	 * Takes every row of the tables a settlement writes.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, list<array<string, mixed>>> The rows, by table.
	 */
	protected function takeSnapshot(): array {
		$rows = array();

		foreach ( self::snapshotTables() as $table ) {
			$rows[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i', $this->table( $table ) );
		}

		return $rows;
	}

	/**
	 * Describes what changed since a take: per table, the rows gone and the rows new, each named by the payment it belongs to.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<array<string, mixed>>> $before   A take.
	 * @param array<string, array<string, mixed>>       $payments The payments, by name: each with its `order_id` and
	 *                                                            `variant`, and its `intent_uuid` when it has one.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The tables that changed, each row described
	 *                                                                    and sorted.
	 */
	protected function changesSince( array $before, array $payments ): array {
		$after   = $this->takeSnapshot();
		$names   = $this->namesOf( $payments, array_merge( $before[ PaymentTables::INTENTS ], $after[ PaymentTables::INTENTS ] ) );
		$changes = array();

		foreach ( self::snapshotTables() as $table ) {
			$old      = array_map( static fn( array $row ): string => (string) wp_json_encode( $row ), $before[ $table ] );
			$new      = array_map( static fn( array $row ): string => (string) wp_json_encode( $row ), $after[ $table ] );
			$describe = static fn( string $row ): string => self::describeRow( $table, (array) json_decode( $row, true ), $names );
			$gone     = array_map( $describe, array_values( array_diff( $old, $new ) ) );
			$made     = array_map( $describe, array_values( array_diff( $new, $old ) ) );

			if ( array() === $gone && array() === $made ) {
				continue;
			}

			sort( $gone );
			sort( $made );

			$changes[ $table ] = array(
				'gone' => $gone,
				'new'  => $made,
			);
		}

		return $changes;
	}

	/**
	 * Returns the tables a take holds.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The tables' unprefixed names.
	 */
	private static function snapshotTables(): array {
		return array(
			PaymentTables::INTENTS,
			PaymentTables::TRANSACTIONS,
			OrderTables::ORDERS,
			OrderTables::EVENTS,
			OutboxTable::NAME,
			InventoryTables::ITEMS,
			InventoryTables::HOLDS,
			InventoryTables::ALLOCATIONS,
			InventoryTables::LEDGER,
			PromotionTables::USAGE,
			CartTables::CARTS,
		);
	}

	/**
	 * Returns the name of each payment's order, intent and variant, by their ids.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $payments The payments, by name.
	 * @param list<array<string, mixed>>          $intents  The intents' rows, which give each intent's id.
	 * @return array{order: array<int, string>, intent: array<string, string>, variant: array<int, string>} The names.
	 */
	private function namesOf( array $payments, array $intents ): array {
		$names = array(
			'order'   => array(),
			'intent'  => array(),
			'variant' => array(),
		);

		foreach ( $payments as $name => $payment ) {
			$names['order'][ (int) $payment['order_id'] ]  = $name;
			$names['variant'][ (int) $payment['variant'] ] = $name;

			if ( isset( $payment['intent_uuid'] ) ) {
				$names['intent'][ (string) $payment['intent_uuid'] ] = $name;
			}
		}

		foreach ( $intents as $intent ) {
			if ( isset( $names['intent'][ (string) $intent['uuid'] ] ) ) {
				$names['intent'][ (string) $intent['id'] ] = $names['intent'][ (string) $intent['uuid'] ];
			}
		}

		return $names;
	}

	/**
	 * Describes a row by the payment it belongs to and what a settlement changes in it.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                   $table The table.
	 * @param array<string, mixed>                     $row   The row.
	 * @param array<string, array<int|string, string>> $names The names: of the orders, the intents and the variants.
	 * @return string The description; a row of no payment named is named by its id, so it shows.
	 *
	 * @phpstan-param array{order: array<int, string>, intent: array<string, string>, variant: array<int, string>} $names
	 */
	private static function describeRow( string $table, array $row, array $names ): string {
		$order   = static fn( mixed $id ): string => $names['order'][ (int) $id ] ?? 'order#' . (string) $id;
		$variant = static fn( mixed $id ): string => $names['variant'][ (int) $id ] ?? 'variant#' . (string) $id;

		switch ( $table ) {
			case PaymentTables::INTENTS:
				return ( $names['intent'][ (string) $row['uuid'] ] ?? 'intent#' . (string) $row['id'] ) . ' ' . $row['status'];

			case PaymentTables::TRANSACTIONS:
				return $order( $row['order_id'] ) . ' ' . $row['operation'] . ':' . $row['result'] . ':' . $row['applied'];

			case OrderTables::ORDERS:
				return $order( $row['id'] ) . ' ' . $row['status'] . '/' . $row['payment_status'] . '/' . $row['has_unreconciled_money'];

			case OrderTables::EVENTS:
				return $order( $row['order_id'] ) . ' ' . $row['machine'] . ':' . $row['from_status'] . '>' . $row['to_status'] . ':' . $row['reason'];

			case OutboxTable::NAME:
				$of = match ( (string) $row['aggregate_type'] ) {
					'order'          => $order( $row['aggregate_id'] ),
					'payment_intent' => $names['intent'][ (string) $row['aggregate_id'] ] ?? 'intent#' . (string) $row['aggregate_id'],
					'variant'        => $variant( $row['aggregate_id'] ),
					default          => $row['aggregate_type'] . '#' . (string) $row['aggregate_id'],
				};

				return $of . ' ' . $row['event_name'];

			case InventoryTables::ITEMS:
				return $variant( $row['variant_id'] ) . ' ' . $row['on_hand'] . '/' . $row['allocated'] . '/' . $row['held'];

			case InventoryTables::HOLDS:
				return $variant( $row['variant_id'] ) . ' held ' . $row['quantity'];

			case InventoryTables::ALLOCATIONS:
				return $variant( $row['variant_id'] ) . ' allocated ' . $row['quantity'] . ' ' . $row['state'];

			case InventoryTables::LEDGER:
				return $variant( $row['variant_id'] ) . ' ' . $row['delta'] . ' ' . $row['reason'];

			case PromotionTables::USAGE:
				return $order( $row['order_id'] ) . ' ' . $row['state'];

			default:
				return $order( $row['order_id'] ) . ' ' . $row['status'];
		}
	}
}
