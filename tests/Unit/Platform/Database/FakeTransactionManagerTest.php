<?php
/**
 * Tests the FakeTransactionManager test double against the TransactionManager contract
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;

/**
 * Proves that the stand-in keeps the rules application code relies on, so a unit test that
 * passes against it would also pass against Database.
 *
 * @since 0.1.0
 */
final class FakeTransactionManagerTest extends TestCase {

	/**
	 * Tests that the double can stand in for the port.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_a_transaction_manager(): void {
		$this->assertInstanceOf( TransactionManager::class, new FakeTransactionManager() );
	}

	/**
	 * Tests the return value, the depth inside, and after-commit callbacks at depth 0.
	 *
	 * @since 0.1.0
	 */
	public function test_it_returns_the_result_and_runs_after_commit_callbacks_outside(): void {
		$manager  = new FakeTransactionManager();
		$observed = array();

		$result = $manager->transaction(
			function () use ( $manager, &$observed ): string {
				$observed[] = 'depth ' . $manager->depth();

				$manager->transaction(
					function () use ( $manager, &$observed ): void {
						$observed[] = 'depth ' . $manager->depth();

						$manager->afterCommit(
							function () use ( $manager, &$observed ): void {
								$observed[] = 'committed at depth ' . $manager->depth();
							}
						);
					}
				);

				return 'result';
			}
		);

		$this->assertSame( 'result', $result );
		$this->assertSame( array( 'depth 1', 'depth 2', 'committed at depth 0' ), $observed );
		$this->assertSame( 1, $manager->commits() );
	}

	/**
	 * Tests that a rolled-back inner level drops its after-commit callbacks and runs its after-rollback ones.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rolled_back_level_drops_its_after_commit_callbacks(): void {
		$manager  = new FakeTransactionManager();
		$observed = array();

		$manager->transaction(
			function () use ( $manager, &$observed ): void {
				try {
					$manager->transaction(
						function () use ( $manager, &$observed ): void {
							$manager->afterCommit(
								function () use ( &$observed ): void {
									$observed[] = 'inner committed';
								}
							);
							$manager->afterRollback(
								function () use ( &$observed ): void {
									$observed[] = 'inner rolled back';
								}
							);

							throw new \DomainException( 'inner' );
						}
					);
				} catch ( \DomainException $expected ) {
					$observed[] = 'caught ' . $expected->getMessage();
				}
			}
		);

		$this->assertSame( array( 'inner rolled back', 'caught inner' ), $observed );
	}

	/**
	 * Tests the outermost retry on TransactionRetryable, and that touched keys are recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_the_outermost_level_retries_and_touched_keys_are_recorded(): void {
		$manager = new FakeTransactionManager();
		$runs    = 0;

		$manager->transaction(
			function () use ( $manager, &$runs ): void {
				++$runs;

				$manager->touchCacheKey( 'k' . $runs, 'g' );

				if ( 1 === $runs ) {
					throw QueryFailed::fromErrno( 1213, '40001', 'UPDATE t', 'deadlock', true );
				}
			},
			RetryPolicy::deadlocks()
		);

		$this->assertSame( 2, $runs );
		$this->assertSame( 2, $manager->attempts() );
		$this->assertSame( 1, $manager->rollbacks() );
		$this->assertSame(
			array(
				array(
					'key'   => 'k1',
					'group' => 'g',
				),
				array(
					'key'   => 'k2',
					'group' => 'g',
				),
			),
			$manager->touchedKeys()
		);
	}
}
