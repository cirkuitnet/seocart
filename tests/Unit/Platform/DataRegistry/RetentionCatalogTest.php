<?php
/**
 * Tests the retention catalog: the policies and their default periods
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\DataRegistry\RetentionCatalog;

/**
 * Pins the catalog to the retention model, and proves every period is a duration PHP can read.
 *
 * The expected policies below are that model, restated once on purpose: a test that derived
 * them from the class under test would prove nothing. Each is the default a new store starts
 * with, as an ISO 8601 duration keyed by the rows it applies to.
 *
 * @since 0.1.0
 */
final class RetentionCatalogTest extends TestCase {

	/**
	 * The retention model: policy id => default periods.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, string>>
	 */
	private const MODEL = array(
		'permanent'        => array(),
		'entity_lifetime'  => array(),
		'rebuildable'      => array(),
		'logs'             => array( 'all' => 'P30D' ),
		'outbox'           => array(
			'dispatched' => 'P7D',
			'failed'     => 'P90D',
		),
		'job_history'      => array(
			'finished' => 'P7D',
			'failed'   => 'P90D',
		),
		'stock_holds'      => array( 'expired' => 'PT24H' ),
		'carts'            => array(
			'guest'     => 'P7D',
			'logged_in' => 'P30D',
		),
		'idempotency_keys' => array( 'all' => 'P30D' ),
		'webhook_receipts' => array( 'all' => 'P30D' ),
		'financial'        => array( 'all' => 'P7Y' ),
	);

	/**
	 * Tests that the catalog is exactly the retention model, policy by policy.
	 *
	 * Planted violation: the logs period changed to P31D.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catalog_is_the_retention_model(): void {
		$catalog = new RetentionCatalog();

		$this->assertSame( array_keys( self::MODEL ), $catalog->ids() );
		$this->assertSame( RetentionCatalog::PERMANENT, $catalog->ids()[0] );

		foreach ( self::MODEL as $id => $defaults ) {
			$this->assertTrue( $catalog->has( $id ), $id );
			$this->assertSame( $defaults, $catalog->defaults( $id ), $id );
			$this->assertNotSame( '', trim( $catalog->rule( $id ) ), $id . ' says what it does.' );
		}
	}

	/**
	 * Tests that every default period is a positive ISO 8601 duration and every row kind a snake_case word.
	 *
	 * @since 0.1.0
	 */
	public function test_every_period_is_a_positive_iso_8601_duration(): void {
		$catalog = new RetentionCatalog();
		$epoch   = new \DateTimeImmutable( '@0' );

		foreach ( $catalog->ids() as $id ) {
			foreach ( $catalog->defaults( $id ) as $rows => $period ) {
				$this->assertMatchesRegularExpression( '/^[a-z][a-z_]*$/', $rows, $id );
				$this->assertGreaterThan( $epoch, $epoch->add( new \DateInterval( $period ) ), "{$id}.{$rows}: {$period}" );
			}
		}
	}

	/**
	 * Tests that a policy the catalog does not declare is unknown, and reading it fails.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_policy_is_refused(): void {
		$catalog = new RetentionCatalog();

		$this->assertFalse( $catalog->has( 'forever' ) );
		$this->assertFalse( $catalog->has( '' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No retention policy is called "forever".' );

		$catalog->defaults( 'forever' );
	}
}
