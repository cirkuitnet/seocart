<?php
/**
 * Tests reading an order: by its uuid only, and from its own snapshots, whatever the catalog does afterwards
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\FulfillmentStatus;
use SEOCart\Order\Domain\LineOption;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An order is read by its uuid, from its own rows, and reads the same after the catalog changes.
 *
 * Planted violation, shown red and removed: in MysqlOrderRepository::FIND_LINES, take the SKU
 * from the live variant (`JOIN {variants} v ON v.id = l.variant_id`, `v.sku AS sku_snapshot`):
 * the statement names a catalog table, which the statement scan and expand() both refuse, and the
 * order no longer reads the same once the variant is renamed.
 *
 * @since 0.1.0
 */
final class OrderReadsTest extends OrderTestCase {

	/**
	 * Tests that an order is read back by its uuid, with its lines, their options and its addresses, as they were placed.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_is_read_back_by_its_uuid(): void {
		$inserted = $this->place( NewOrders::forTwoLines( 'EUR', 'USD', 12 ) );
		$view     = null;
		$log      = $this->captureQueries(
			function () use ( $inserted, &$view ): void {
				$view = $this->orders->findByUuid( $inserted->uuid );
			}
		);

		$this->assertNotNull( $view );
		$this->assertQueryCount( 4, $log, 'reading an order: its row, its lines, their options and its addresses' );
		$this->assertSame( array( $inserted->uuid, '000001', OrderStatus::PendingPayment, PaymentStatus::Unpaid, FulfillmentStatus::Unfulfilled ), array( $view->uuid, $view->orderNumber, $view->status, $view->paymentStatus, $view->fulfillmentStatus ) );
		$this->assertSame( array( 'EUR', 'en_GB', 12, 3080, 3080, 280 ), array( $view->currency->code(), $view->locale->toString(), $view->customerId, $view->grandTotal->minorUnits(), $view->due->minorUnits(), $view->taxTotal->minorUnits() ) );
		$this->assertCount( 2, $view->lines );
		$this->assertSame( array( 'TEE-M', 'Tee', 'Medium', 2, AmountBasis::Net, 1980 ), array( $view->lines[0]->sku, $view->lines[0]->title, $view->lines[0]->variantLabel, $view->lines[0]->quantity, $view->lines[0]->unitAmountBasis, $view->lines[0]->lineTotal->minorUnits() ) );
		$this->assertEquals( array( new LineOption( 'size', 'Size', 'm', 'Medium' ) ), $view->lines[0]->options );
		$this->assertSame( array(), $view->lines[1]->options );
		$this->assertSame( array( 1800, 180, 1980 ), array( $view->lines[0]->amount->net()->minorUnits(), $view->lines[0]->amount->tax()->minorUnits(), $view->lines[0]->amount->gross()->minorUnits() ) );

		$placed = NewOrders::forTwoLines();

		$this->assertTrue( $view->billingAddress->equals( $placed->billingAddress ) );
		$this->assertNotNull( $view->shippingAddress );
		$this->assertTrue( $view->shippingAddress->equals( $placed->shippingAddress ?? $placed->billingAddress ) );
		$this->assertSame( 'UTC', $view->placedAt->getTimezone()->getName() );
	}

	/**
	 * Tests that an unknown uuid is not found, and that a string that is not a uuid is not found without a query.
	 *
	 * @since 0.1.0
	 */
	public function test_only_a_uuid_names_an_order(): void {
		$inserted = $this->place();

		foreach ( array( SequentialIdGenerator::nth( 999999 ), (string) $inserted->id, $inserted->orderNumber, '' ) as $name ) {
			$log = $this->captureQueries(
				function () use ( $name ): void {
					try {
						$this->orders->findByUuid( $name );
						$this->fail( sprintf( '"%s" found an order.', $name ) );
					} catch ( CodedException $refused ) {
						$this->assertSame( OrderError::NotFound, $refused->errorCode() );
					}
				}
			);

			$this->assertQueryCount( SequentialIdGenerator::nth( 999999 ) === $name ? 1 : 0, $log, "reading {$name}" );
		}
	}

	/**
	 * Tests that a placed order reads the same after its variant is renamed and its product deleted.
	 *
	 * The catalog's rows for the order's first product and variant are planted with the ids the
	 * document names, the order is placed, and then the variant's SKU is changed and the product
	 * row deleted: the order, read by its uuid, is byte for byte what it was.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_reads_the_same_after_the_catalog_changes(): void {
		( new CreateCatalogTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$products = $this->table( CatalogTables::PRODUCTS );
		$variants = $this->table( CatalogTables::VARIANTS );

		$this->db->execute( 'INSERT INTO %i ( id, uuid, created_at, updated_at ) VALUES ( 401, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )', $products, SequentialIdGenerator::nth( 800401 ) );
		$this->db->execute( 'INSERT INTO %i ( id, uuid, product_id, sku, combination_hash, generation, created_at, updated_at ) VALUES ( 501, %s, 401, %s, %s, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )', $variants, SequentialIdGenerator::nth( 800501 ), 'TEE-M', str_repeat( '0', 64 ) );

		$inserted = $this->place();
		$before   = serialize( $this->orders->findByUuid( $inserted->uuid ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Compares two reads byte for byte.

		$this->db->execute( 'UPDATE %i SET sku = %s WHERE id = 501', $variants, 'TEE-M-RENAMED' );
		$this->db->execute( 'DELETE FROM %i WHERE id = 401', $products );

		$this->assertSame( $before, serialize( $this->orders->findByUuid( $inserted->uuid ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Compares two reads byte for byte.
		$this->assertStringContainsString( 'TEE-M', $before );
		$this->assertStringNotContainsString( 'RENAMED', $before );
	}
}
