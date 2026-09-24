<?php
/**
 * Tests the declaration of the stock adjustment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Operations;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

/**
 * `inventory.adjust_stock` as it is declared: the one place its surfaces, schema, capability,
 * errors and annotations are written. The surfaces compiled from it are the integration suite's.
 *
 * Planted violation: in InventoryOperations::adjustStock(), write the reason's allowed values out
 * by hand, leaving one out. The allowed values no longer equal LedgerReason::merchant().
 *
 * @since 0.1.0
 */
final class InventoryOperationsTest extends TestCase {

	/**
	 * Tests that the production registry holds the adjustment under its id.
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_registry_holds_the_adjustment(): void {
		$ids = array_map( static fn( $definition ): string => $definition->id(), Operations::registry()->all() );

		$this->assertContains( InventoryOperations::ADJUST_STOCK, $ids );
	}

	/**
	 * Tests the surfaces: one REST route with the variant in the URL, one ability, one command with the variant as its argument.
	 *
	 * @since 0.1.0
	 */
	public function test_the_adjustment_is_bound_to_three_surfaces(): void {
		$definition = InventoryOperations::adjustStock();
		$rest       = $definition->rest();
		$cli        = $definition->cli();

		$this->assertNotNull( $rest );
		$this->assertNotNull( $cli );
		$this->assertSame( 'POST', $definition->httpMethod() );
		$this->assertSame( '/stock-items/{variant_id}/adjustments', $rest->route() );
		$this->assertSame( array( 'variant_id' ), $rest->pathParameters() );
		$this->assertSame( 'seocart/adjust-stock', $definition->abilityName() );
		$this->assertSame( 'seocart stock adjust', $cli->command() );
		$this->assertSame( array( 'variant_id' ), $cli->positional() );
		$this->assertSame( array( StockService::class, 'adjustStock' ), $definition->service() );
	}

	/**
	 * Tests the input: the variant, a bounded change, a merchant reason and an optional expected count.
	 *
	 * @since 0.1.0
	 */
	public function test_the_input_is_declared_with_its_bounds(): void {
		$fields = self::byName( InventoryOperations::adjustStock()->input() );

		$this->assertSame( array( 'variant_id', 'delta', 'reason', 'expected_on_hand' ), array_keys( $fields ) );
		$this->assertSame( array( FieldType::Integer, true, 1, null ), array( $fields['variant_id']->type(), $fields['variant_id']->isRequired(), $fields['variant_id']->minimum(), $fields['variant_id']->maximum() ) );
		$this->assertSame( array( FieldType::Integer, true, -1000000, 1000000 ), array( $fields['delta']->type(), $fields['delta']->isRequired(), $fields['delta']->minimum(), $fields['delta']->maximum() ) );
		$this->assertSame( array( FieldType::String, true ), array( $fields['reason']->type(), $fields['reason']->isRequired() ) );
		$this->assertSame( array( FieldType::Integer, false, 0, null ), array( $fields['expected_on_hand']->type(), $fields['expected_on_hand']->isRequired(), $fields['expected_on_hand']->minimum(), $fields['expected_on_hand']->defaultValue() ) );
	}

	/**
	 * Tests that the reasons a client may give are the merchant's, from the one list.
	 *
	 * @since 0.1.0
	 */
	public function test_the_reasons_are_the_merchant_reasons(): void {
		$fields = self::byName( InventoryOperations::adjustStock()->input() );

		$this->assertSame( array_map( static fn( LedgerReason $reason ): string => $reason->value, LedgerReason::merchant() ), $fields['reason']->allowedValues() );
		$this->assertNotContains( LedgerReason::VariantDeleted->value, $fields['reason']->allowedValues() );
	}

	/**
	 * Tests the output: the stock level, every field a required integer.
	 *
	 * @since 0.1.0
	 */
	public function test_the_output_is_the_stock_level(): void {
		$output = InventoryOperations::adjustStock()->output();

		$this->assertSame( 'StockLevel', $output->name() );
		$this->assertSame( array( 'variant_id', 'on_hand', 'allocated', 'held', 'available', 'ledger_entry_id' ), array_keys( self::byName( $output->fields() ) ) );

		foreach ( $output->fields() as $field ) {
			$this->assertSame( array( FieldType::Integer, true ), array( $field->type(), $field->isRequired() ), $field->name() );
		}
	}

	/**
	 * Tests the capability, the errors and the annotations: destructive, not idempotent, never exposed to agents.
	 *
	 * @since 0.1.0
	 */
	public function test_the_adjustment_is_destructive_and_never_exposed_to_agents(): void {
		$definition = InventoryOperations::adjustStock();

		$this->assertSame( 'seocart_manage_inventory', $definition->capability() );
		$this->assertNull( $definition->resourceField() );
		$this->assertSame(
			array( InventoryError::ItemMissing, InventoryError::ZeroDelta, InventoryError::OnHandConflict, InventoryError::AdjustmentBelowZero, AuthorizationError::Denied ),
			$definition->errors()
		);
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			$definition->annotations()->toArray()
		);
		$this->assertFalse( $definition->isAgentExposed() );
	}

	/**
	 * Indexes fields by name.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return array<string, FieldSpec> The fields, in order, by name.
	 */
	private static function byName( array $fields ): array {
		$named = array();

		foreach ( $fields as $field ) {
			$named[ $field->name() ] = $field;
		}

		return $named;
	}
}
