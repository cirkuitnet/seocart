<?php
/**
 * FixtureStockService: the application service behind the test-fixture operation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Support\Error\CodedException;

/**
 * Keeps stock levels in memory and records every call, so a test can see what each surface passed.
 *
 * Every item starts at INITIAL_LEVEL. An adjustment that would take a level below zero fails with
 * the fixture's declared code. The result carries, besides the declared public fields, a
 * personal-data note, a secret token and a value the output schema does not declare, so a test
 * sees each privacy rule applied to a real result.
 *
 * @since 0.1.0
 */
final class FixtureStockService {

	/**
	 * The level every item starts at.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const INITIAL_LEVEL = 5;

	/**
	 * The secret token every result carries, which no surface may serialize.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AUDIT_TOKEN = 'fixture-audit-token-never-serialized';

	/**
	 * The stock levels, keyed by item id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	private array $levels = array();

	/**
	 * The input of every call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $calls = array();

	/**
	 * Adjusts the level of one item.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With FixtureStockError::Insufficient when the level would drop below zero.
	 *
	 * @param array<string, mixed> $input The input values, keyed by wire name.
	 * @return array<string, mixed> The result, keyed by wire name.
	 */
	public function adjust( array $input ): array {
		$this->calls[] = $input;

		$item  = (string) $input['item_id'];
		$delta = (int) $input['delta'];
		$level = $this->levels[ $item ] ?? self::INITIAL_LEVEL;

		if ( $level + $delta < 0 ) {
			CodedException::raise(
				FixtureStockError::Insufficient,
				array(
					'requested' => -$delta,
					'available' => $level,
				)
			);
		}

		$this->levels[ $item ] = $level + $delta;

		return array(
			'item_id'     => $item,
			'on_hand'     => $this->levels[ $item ],
			'reason'      => $input['reason'],
			'note'        => $input['note'] ?? null,
			'audit_token' => self::AUDIT_TOKEN,
			'undeclared'  => 'a value the output schema does not declare',
		);
	}
}
