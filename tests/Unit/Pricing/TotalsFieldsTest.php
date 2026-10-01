<?php
/**
 * Tests that the declared shape of totals is exactly what the totals write
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Interfaces\TotalsFields;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Tests\Support\Pricing\CartB;
use SEOCart\Tests\Support\Pricing\FactsEvaluator;

/**
 * On the totals of the reference cart, which has lines, discounts, shipping and tax components,
 * every key Totals::toArray() writes is declared, at every depth and in the same order, and every
 * declared key is written; and serializing the totals through the declaration gives them back
 * unchanged.
 *
 * Planted violation: in TotalsSummary::toArray(), add a key: the declaration no longer lists
 * every key, and this test names it.
 *
 * @since 0.1.0
 */
final class TotalsFieldsTest extends TestCase {

	/**
	 * Tests that the declaration's keys are the keys the totals write, at every depth.
	 *
	 * @since 0.1.0
	 */
	public function test_the_declaration_lists_exactly_the_keys_the_totals_write(): void {
		$written = self::cartBTotals();

		$this->assertNotSame( array(), $written['adjustments'], 'The reference totals must have adjustments for their keys to be compared.' );
		$this->assertNotSame( array(), $written['lines'][0]['components'], 'The reference totals must have tax components for their keys to be compared.' );
		$this->assertSame( array(), self::drift( self::field()->fields(), $written, 'totals' ) );
	}

	/**
	 * Tests that serializing the totals through the declaration gives them back unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_the_totals_pass_through_the_declaration_unchanged(): void {
		$written = self::cartBTotals();
		$schema  = new ResourceSchema( 'Priced', array( self::field() ) );

		$this->assertSame( array( 'totals' => $written ), $schema->serialize( array( 'totals' => $written ), false ) );
	}

	/**
	 * Returns the declared totals field.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function field(): FieldSpec {
		return TotalsFields::totals( 'totals', 'The totals.', static fn(): string => 'Totals' );
	}

	/**
	 * Returns the reference cart's totals as they are written.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Totals::toArray().
	 */
	private static function cartBTotals(): array {
		$engine = new Engine();
		$phaseA = $engine->phaseA( CartB::input(), new FactsEvaluator() );

		return $engine->phaseB( $phaseA, CartB::quotes( $phaseA ) )->toArray();
	}

	/**
	 * Compares declared fields with a written object, recursively, and lists every difference.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[]          $fields The declared fields.
	 * @param array<string, mixed> $written The written object.
	 * @param string               $path   Where the object is, for the messages.
	 * @return list<string> One line per difference.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private static function drift( array $fields, array $written, string $path ): array {
		$declared = array_map( static fn( FieldSpec $field ): string => $field->name(), $fields );
		$problems = array();

		if ( array_keys( $written ) !== $declared ) {
			$problems[] = sprintf( '%s: written [%s], declared [%s]', $path, implode( ', ', array_keys( $written ) ), implode( ', ', $declared ) );
		}

		foreach ( $fields as $field ) {
			$value = $written[ $field->name() ] ?? null;

			if ( FieldType::Object === $field->type() && is_array( $value ) ) {
				$problems = array_merge( $problems, self::drift( $field->fields(), $value, $path . '.' . $field->name() ) );
			}

			if ( FieldType::ObjectList === $field->type() && is_array( $value ) ) {
				foreach ( $value as $index => $item ) {
					$problems = array_merge( $problems, self::drift( $field->fields(), $item, $path . '.' . $field->name() . '[' . $index . ']' ) );
				}
			}
		}

		return $problems;
	}
}
