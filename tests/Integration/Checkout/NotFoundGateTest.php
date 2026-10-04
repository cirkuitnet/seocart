<?php
/**
 * Tests that reconciliation takes a provider's "not found" only from a searchable provider, about an intent old enough
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;

/**
 * A gateway `slow` whose provider can be searched only an hour after a call answers every status query "not found": reconciliation leaves an intent younger than that waiting, and says so in the log, and ends the placement once the intent is older; a provider that cannot be searched never ends one that way.
 *
 * Planted violation, shown red and removed: in PaymentService::queryGateway(), accept a "not
 * found" at once: the young intent's placement then fails.
 *
 * @since 0.2.0
 */
final class NotFoundGateTest extends PlacementTestCase {

	/**
	 * How long after a call `slow`'s provider can find it, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SEARCH_DELAY = 3600;

	/**
	 * Whether `slow`'s provider can be searched at all.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $searchable = true;

	/**
	 * Creates the log and has `slow` register.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				$slow        = new DeclaredGateway( DeclaredGateway::descriptor( 'slow', array( Mode::Test ), array(), null, new IdempotencyProfile( null, $this->searchable, self::SEARCH_DELAY ) ) );
				$slow->query = static fn( PaymentQuery $query ): GatewayResult => new GatewayResult( 'slow', Operation::Authorize, Outcome::Declined, $query->intentUuid, $query->amount, 'slow-nf-' . $query->intentUuid, null, PaymentGateway::NOT_FOUND );

				$registry->register( $slow );
			}
		);
	}

	/**
	 * Tests that a "not found" about an intent younger than the search delay is taken for no answer and logged, and one about an older intent ends the placement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_not_found_ends_a_placement_only_once_the_intent_is_old_enough(): void {
		$uuid = $this->pendingThroughSlow();

		$this->age( $uuid, 1200 );
		$this->reconcile();

		$this->assertSame( array( 'pending_payment', 'processing' ), $this->states( $uuid ), 'Twenty minutes is too young for the provider to have found the intent.' );
		$this->assertSame( array( PaymentService::NOT_FOUND_IGNORED ), $this->logged( PaymentService::NOT_FOUND_IGNORED ), 'Taking the answer for none is logged.' );

		$this->age( $uuid, self::SEARCH_DELAY + 100 );
		$this->reconcile();

		$this->assertSame( array( 'failed', 'failed' ), $this->states( $uuid ), 'Past the search delay, "not found" is the provider\'s final answer.' );
	}

	/**
	 * Tests that a provider that cannot be searched never ends a placement with "not found", however old the intent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_that_cannot_be_searched_never_ends_a_placement_with_not_found(): void {
		$this->searchable = false;

		$uuid = $this->pendingThroughSlow();

		$this->age( $uuid, 86400 );
		$this->reconcile();

		$this->assertSame( array( 'pending_payment', 'processing' ), $this->states( $uuid ) );
	}

	/**
	 * Places an order through `slow` that its provider leaves pending.
	 *
	 * @since 0.2.0
	 *
	 * @return string The order's uuid.
	 */
	private function pendingThroughSlow(): string {
		$cart    = $this->startCart( array( $this->sellable() => 1 ) );
		$address = array(
			'country'  => 'US',
			'line1'    => '1 Main Street',
			'city'     => 'Austin',
			'postcode' => '78701',
		);

		$this->kernel->get( UpdateCheckoutSession::class )->update(
			array(
				'cart_version'       => $cart->version,
				'billing_address'    => $address + array(
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'email'      => 'ada@example.com',
				),
				'shipping_address'   => $address,
				'payment_method_key' => 'slow',
			),
			self::guest()
		);

		$placed = $this->placement->place( $this->placeInput( 'slow-1', StubGateway::PENDING ), self::guest() );

		$this->assertSame( 'processing', $placed['outcome'] );

		return (string) $placed['order_uuid'];
	}

	/**
	 * Makes an order's intent as old as asked, by the database clock: created then, and unchanged since.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @param int    $seconds   How long ago.
	 */
	private function age( string $orderUuid, int $seconds ): void {
		$this->db->execute(
			'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.created_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND, i.updated_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND WHERE o.uuid = %s',
			$this->table( PaymentTables::INTENTS ),
			$this->table( OrderTables::ORDERS ),
			$seconds,
			$seconds,
			$orderUuid
		);
	}

	/**
	 * Runs reconciliation, as its job does, in a request of its own.
	 *
	 * @since 0.2.0
	 */
	private function reconcile(): void {
		$this->kernel = $this->kernelOver( $this->db, $this->tokens );

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );
	}

	/**
	 * Returns an order's status and its intent's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return list<string> The order's status, then its intent's.
	 */
	private function states( string $orderUuid ): array {
		$row = $this->db->fetchRow( 'SELECT o.status AS o_status, i.status AS i_status FROM %i o JOIN %i i ON i.order_id = o.id WHERE o.uuid = %s', $this->table( OrderTables::ORDERS ), $this->table( PaymentTables::INTENTS ), $orderUuid );

		return array( (string) $row['o_status'], (string) $row['i_status'] );
	}

	/**
	 * Returns the log lines of a code.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The code.
	 * @return list<string> The code of each line.
	 */
	private function logged( string $code ): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT machine_code FROM %i WHERE machine_code = %s', $this->table( LogsTable::NAME ), $code ), 'machine_code' ) );
	}
}
