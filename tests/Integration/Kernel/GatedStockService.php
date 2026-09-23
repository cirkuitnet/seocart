<?php
/**
 * GatedStockService: the fixture stock service, run inside a unit of work as every commerce write is
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;

/**
 * The fixture service behind a gated unit of work, as every commerce service runs its writes.
 *
 * @since 0.1.0
 */
final class GatedStockService {

	/**
	 * How many adjustments ran.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $ran = 0;

	/**
	 * The transaction manager the service received.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $transactions;

	/**
	 * The fixture service.
	 *
	 * @since 0.1.0
	 *
	 * @var FixtureStockService
	 */
	private FixtureStockService $inner;

	/**
	 * Creates the service.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager  $transactions The transaction manager.
	 * @param FixtureStockService $inner        The fixture service.
	 */
	public function __construct( TransactionManager $transactions, FixtureStockService $inner ) {
		$this->transactions = $transactions;
		$this->inner        = $inner;
	}

	/**
	 * Adjusts a level inside a unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The input values, keyed by wire name.
	 * @param Actor                $actor Who acts.
	 * @return array<string, mixed> The result.
	 */
	public function adjust( array $input, Actor $actor ): array {
		return $this->transactions->transaction(
			function () use ( $input, $actor ): array {
				++$this->ran;

				return $this->inner->adjust( $input, $actor );
			}
		);
	}
}
