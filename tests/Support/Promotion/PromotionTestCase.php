<?php
/**
 * PromotionTestCase: the base of the tests that resolve promotions and claim their uses against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Promotion;

use SEOCart\Promotion\Application\PromotionResolver;
use SEOCart\Promotion\Application\PromotionUsage;
use SEOCart\Promotion\Application\UsageClaim;
use SEOCart\Promotion\Infrastructure\MysqlPromotionRepository;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\SystemClock;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

/**
 * A DatabaseTestCase with the promotion tables, and the resolver and the usage ledger wired as the kernel wires them.
 *
 * Owns one fact: how a promotion test gets real tables, services over them, and a second runner.
 * The tables are created by their own migration in set_up() and dropped by the base tear-down,
 * so every test starts from empty tables. The second runner is either connection B sending the
 * repository's own statements, prepared from its public constants (raw()), so a change to a
 * statement changes B's copy; or a second ledger over a SecondDatabase, when B must run the
 * plugin's code.
 *
 * @since 0.1.0
 */
abstract class PromotionTestCase extends DatabaseTestCase {

	use PlantsPromotions;

	/**
	 * The repository over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlPromotionRepository
	 */
	protected MysqlPromotionRepository $repository;

	/**
	 * The resolver over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var PromotionResolver
	 */
	protected PromotionResolver $resolver;

	/**
	 * The usage ledger over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var PromotionUsage
	 */
	protected PromotionUsage $usage;

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * Creates the tables and the services.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createPromotionTables();

		$this->repository = new MysqlPromotionRepository( $this->db );
		$this->resolver   = new PromotionResolver( $this->repository, new SystemClock() );
		$this->usage      = new PromotionUsage( $this->repository, $this->db );
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->secondDatabases as $second ) {
			$second->close();
		}

		$this->secondDatabases = array();

		parent::tear_down();
	}

	/**
	 * Opens a second runner of the plugin's code, and runs a claim through it in a transaction of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param UsageClaim ...$claims The uses to claim.
	 */
	protected function claimOnSecondRunner( UsageClaim ...$claims ): void {
		$second                  = new SecondDatabase( $this->reporter() );
		$this->secondDatabases[] = $second;
		$usage                   = new PromotionUsage( new MysqlPromotionRepository( $second->db() ), $second->db() );

		$second->db()->transaction( static fn() => $usage->claim( array_values( $claims ) ) );
	}

	/**
	 * Returns a use of a promotion by an order, with a discount of 1.00 in USD.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @param int $orderId     The order.
	 * @return UsageClaim The use.
	 */
	protected static function claimOf( int $promotionId, int $orderId ): UsageClaim {
		return new UsageClaim( $promotionId, $orderId, Money::of( -100, Currency::of( 'USD' ) ), Money::of( -100, Currency::of( 'USD' ) ) );
	}

	/**
	 * Reads a promotion's count as connection B sees it, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b           Connection B.
	 * @param int              $promotionId The promotion.
	 * @return int|null The count, or null when there is no such promotion.
	 *
	 * @phpstan-impure
	 */
	protected function committedUsed( SecondConnection $b, int $promotionId ): ?int {
		$used = $b->fetchValue( sprintf( 'SELECT used FROM `%s` WHERE id = %d', $this->db->table( PromotionTables::PROMOTIONS ), $promotionId ) );

		return null === $used ? null : (int) $used;
	}

	/**
	 * Reads a promotion's usage rows as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b           Connection B.
	 * @param int              $promotionId The promotion.
	 * @return list<string> Each row as `order id:state`, by id.
	 *
	 * @phpstan-impure
	 */
	protected function committedUsage( SecondConnection $b, int $promotionId ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( order_id, ':', state ) ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE promotion_id = %d", $this->db->table( PromotionTables::USAGE ), $promotionId ) );

		return '' === $rows ? array() : explode( ',', $rows );
	}

	/**
	 * Returns a repository statement prepared for connection B, from the repository's own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A MysqlPromotionRepository constant whose one table is the first placeholder.
	 * @param string $table     The table's unprefixed name.
	 * @param mixed  ...$values The other placeholders' values.
	 * @return string The statement, ready to send.
	 */
	protected function raw( string $statement, string $table, mixed ...$values ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the repository's constant; this is its prepare step.
		return (string) $wpdb->prepare( $statement, $this->db->table( $table ), ...$values );
	}
}
