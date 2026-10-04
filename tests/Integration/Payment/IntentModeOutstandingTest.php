<?php
/**
 * Tests that a site whose intent mode migration is outstanding refuses commerce writes through the schema gate
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddIntentMode;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GatedTransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A site upgraded to this version, whose intents table has no `mode` yet because the migration that adds it is still outstanding: a placement is refused by the schema gate, `store.unavailable`, before anything is written, never by the database's error about the missing column; the reconciliation job's read of waiting intents, which opens no transaction, fails with the database's error and writes nothing, and runs again on its schedule.
 *
 * The kernel here keeps its gated transaction manager, which the other placement tests replace.
 *
 * Planted violation, shown red and removed: make AddIntentMode::canOperateHalfApplied() true: the
 * gate lets the placement through, and its intent's insert fails on the missing column.
 *
 * @since 0.2.0
 */
final class IntentModeOutstandingTest extends PlacementTestCase {

	use PricesInCurrencies;

	/**
	 * Puts the column back, and the boot record as the test found it.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		( new AddIntentMode() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that the placement is refused by the gate before anything is written, and what the reconciliation job's read does meanwhile.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_is_refused_by_the_gate_while_the_mode_is_outstanding(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input = $this->placeInput();

		$this->recordHeadBefore( AddIntentMode::ID );
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN mode', $this->table( PaymentTables::INTENTS ) );

		$kernel = $this->gatedKernel();

		$this->assertSame( GateState::CodeNewer, $kernel->get( SchemaGate::class )->state(), 'The migration is outstanding, and holds the store.' );

		try {
			$kernel->get( PlaceOrder::class )->place( $input, self::guest() );
			$this->fail( 'The placement was let through.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( KernelError::StoreUnavailable, array( 'reason' => GateState::CodeNewer->value ) ), array( $refused->errorCode(), $refused->context() ) );
		}

		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::ORDERS ) ), 'No order was written.' );

		$this->expectException( QueryFailed::class );

		$kernel->get( ReconcileStalePlacements::class )->handle( array() );
	}

	/**
	 * Records in the boot record a schema head just before a migration, so the migration, and any after it, is outstanding.
	 *
	 * @since 0.2.0
	 *
	 * @param string $migrationId The migration.
	 */
	private function recordHeadBefore( string $migrationId ): void {
		$ids      = array_map( static fn( Migration $migration ): string => $migration->id(), OwnedData::registry()->migrations() );
		$position = array_search( $migrationId, $ids, true );

		$this->assertIsInt( $position );
		$this->assertGreaterThan( 0, $position );

		$this->plantBootRecord();

		$previous = $ids[ $position - 1 ];

		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn( BootRecord $record ): BootRecord => $record->withSchemaHead( $previous ) );
	}

	/**
	 * Builds the placement's kernel with the production transaction manager, which asks the schema gate.
	 *
	 * @since 0.2.0
	 *
	 * @return Container The kernel.
	 */
	private function gatedKernel(): Container {
		return PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => new GatedTransactionManager( $c->get( Database::class ), $c->get( SchemaGate::class ) ),
			)
		);
	}
}
