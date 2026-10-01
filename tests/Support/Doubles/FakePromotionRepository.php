<?php
/**
 * FakePromotionRepository: a PromotionRepository in memory, for unit tests of the resolver and the usage ledger
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Promotion\Application\PromotionRepository;
use SEOCart\Promotion\Application\UsageClaim;
use SEOCart\Promotion\Domain\Promotion;

/**
 * The unit-test stand-in for MysqlPromotionRepository, as the promotion services see it.
 *
 * Owns one fact: what each promotion statement does, without a database. Every method applies
 * its statement's WHERE clause to rows in memory and answers as the statement would; the ones
 * that write or lock refuse to run at depth 0 of the transaction manager it is given, as the real
 * repository does. It records each call as `method:argument` in $calls, so a test can assert
 * the order of the statements. It does not undo anything when a transaction rolls back; a test
 * of rollback belongs in the integration suite, against the real tables.
 *
 * @since 0.1.0
 */
final class FakePromotionRepository implements PromotionRepository {

	/**
	 * Every call, as `method:argument`, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $calls = array();

	/**
	 * The promotions, by id, with their limit and count.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{promotion: Promotion, limit: int|null, used: int}>
	 */
	private array $promotions = array();

	/**
	 * The usage rows, as `promotion id:order id` => state.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public array $usage = array();

	/**
	 * Creates the repository.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $transactions The unit of work, whose depth the writes check.
	 */
	public function __construct( private TransactionManager $transactions ) {
	}

	/**
	 * Adds a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param Promotion $promotion The promotion.
	 * @param int|null  $limit     Optional. Its usage limit; null for none. Default null.
	 * @param int       $used      Optional. Its count. Default 0.
	 */
	public function add( Promotion $promotion, ?int $limit = null, int $used = 0 ): void {
		$this->promotions[ $promotion->id ] = array(
			'promotion' => $promotion,
			'limit'     => $limit,
			'used'      => $used,
		);
	}

	/**
	 * Returns a promotion's count.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return int The count.
	 */
	public function used( int $promotionId ): int {
		return $this->promotions[ $promotionId ]['used'];
	}

	/**
	 * Returns the promotions with any of the codes, last added first, as a database may, each with its count and limit as they are now.
	 *
	 * @since 0.1.0
	 *
	 * @param array $codes The codes.
	 * @return list<Promotion> The promotions.
	 *
	 * @phpstan-param non-empty-list<string> $codes
	 */
	public function findByCodes( array $codes ): array {
		$this->calls[] = 'findByCodes:' . implode( ',', $codes );

		$found = array();

		foreach ( array_reverse( $this->promotions ) as $row ) {
			$promotion = $row['promotion'];

			if ( in_array( $promotion->code, $codes, true ) ) {
				$found[] = new Promotion( $promotion->id, $promotion->uuid, $promotion->code, $promotion->status, $promotion->effect, $promotion->priority, $promotion->startsAt, $promotion->endsAt, $row['used'], $row['limit'] );
			}
		}

		return $found;
	}

	/**
	 * Counts a use of an active promotion within its limit.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when counted.
	 */
	public function claim( int $promotionId ): bool {
		$this->write( __FUNCTION__, $promotionId );

		$row = $this->promotions[ $promotionId ] ?? null;

		if ( null === $row || Promotion::ACTIVE !== $row['promotion']->status || ( null !== $row['limit'] && $row['used'] >= $row['limit'] ) ) {
			return false;
		}

		++$this->promotions[ $promotionId ]['used'];

		return true;
	}

	/**
	 * Records a reserved use.
	 *
	 * @since 0.1.0
	 *
	 * @param UsageClaim $claim The use.
	 */
	public function insertUsage( UsageClaim $claim ): void {
		$this->write( __FUNCTION__, $claim->promotionId );

		$this->usage[ $claim->promotionId . ':' . $claim->orderId ] = 'reserved';
	}

	/**
	 * Commits an order's reserved uses.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function commitUsage( int $orderId ): void {
		$this->write( __FUNCTION__, $orderId );
		$this->mark( $orderId, 'committed' );
	}

	/**
	 * Returns the promotions of an order's reserved uses, ascending.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<int> The promotions.
	 */
	public function reservedPromotionIds( int $orderId ): array {
		$this->write( __FUNCTION__, $orderId );

		$ids = array();

		foreach ( $this->usage as $key => $state ) {
			list( $promotionId, $order ) = array_map( 'intval', explode( ':', $key ) );

			if ( $order === $orderId && 'reserved' === $state ) {
				$ids[] = $promotionId;
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Gives a use back when the count is above zero.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when given back.
	 */
	public function giveBack( int $promotionId ): bool {
		$this->write( __FUNCTION__, $promotionId );

		if ( ( $this->promotions[ $promotionId ]['used'] ?? 0 ) < 1 ) {
			return false;
		}

		--$this->promotions[ $promotionId ]['used'];

		return true;
	}

	/**
	 * Releases an order's reserved uses.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function releaseUsage( int $orderId ): void {
		$this->write( __FUNCTION__, $orderId );
		$this->mark( $orderId, 'released' );
	}

	/**
	 * Records a write, refusing it outside a transaction as the real repository does.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method   The method.
	 * @param int    $argument Its id argument.
	 */
	private function write( string $method, int $argument ): void {
		if ( 0 === $this->transactions->depth() ) {
			throw new \LogicException( "FakePromotionRepository::{$method}() runs only inside a transaction." );
		}

		$this->calls[] = $method . ':' . $argument;
	}

	/**
	 * Moves an order's reserved uses to a state.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $orderId The order.
	 * @param string $state   The new state.
	 */
	private function mark( int $orderId, string $state ): void {
		foreach ( $this->usage as $key => $current ) {
			if ( str_ends_with( $key, ':' . $orderId ) && 'reserved' === $current ) {
				$this->usage[ $key ] = $state;
			}
		}
	}
}
