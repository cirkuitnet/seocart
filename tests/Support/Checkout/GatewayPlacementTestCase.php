<?php
/**
 * GatewayPlacementTestCase: placements through a gateway plugin's gateway with two modes, under the production wiring
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Support\Clock;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Payment\GatewayKernel;

/**
 * A placement test in which a gateway plugin registers `second` through the real action: test and live modes, an account country for each and no credential, its calls counted.
 *
 * Owns one fact: what the tests of the gateways' switches share. Each request (freshPlacement())
 * builds the production wiring again, so its registry registers `second` anew, as the gateway is
 * now; Safe Mode is forced for the requests built while `$safeMode` is true, as
 * SEOCART_SAFE_MODE forces it. The log's table is created, so what the wiring reports can be read.
 *
 * @since 0.2.0
 */
abstract class GatewayPlacementTestCase extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const SECOND = 'second';

	/**
	 * The gateway, as the latest request registered it.
	 *
	 * @since 0.2.0
	 *
	 * @var DeclaredGateway|null
	 */
	protected ?DeclaredGateway $second = null;

	/**
	 * Whether the requests built from now on are in Safe Mode, forced.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	protected bool $safeMode = false;

	/**
	 * Creates the log's table, has `second` register through the action, and has readyCart() write the checkout through the kernel's registry, which gives `second` its context.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->checkout = $this->kernel->get( UpdateCheckoutSession::class );

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				$this->second = new DeclaredGateway( static::descriptor(), $registry->context( self::SECOND ) );

				$registry->register( $this->second );
			}
		);
	}

	/**
	 * Deletes the users and the gateway's document the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Builds the production wiring of a request, in Safe Mode while `$safeMode` is true.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db     The connection.
	 * @param FakeCartTokens $tokens The cart token the request presents.
	 * @return Container The container.
	 */
	protected function kernelOver( Database $db, FakeCartTokens $tokens ): Container {
		return PlacementKernel::over( $db, $tokens, $this->identities, $this->wake, $this->reporter(), $this->overrides() );
	}

	/**
	 * Returns what the test's wiring replaces: Safe Mode, forced on while `$safeMode` is true.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, callable(Container): object> The replacements.
	 */
	protected function overrides(): array {
		return array(
			SafeMode::class => fn( Container $c ): SafeMode => new SafeMode( $c->get( BootOption::class ), $c->get( Clock::class ), $this->safeMode ? true : null ),
		);
	}

	/**
	 * Declares `second`: test and live modes, an account country, and the stand-in's matrix.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor.
	 */
	protected static function descriptor(): GatewayDescriptor {
		return DeclaredGateway::descriptor(
			self::SECOND,
			array( Mode::Test, Mode::Live ),
			array( new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The country of the provider account.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ) )
		);
	}

	/**
	 * Sets `second` up for both modes, and makes a mode the one it takes new payments in.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode.
	 */
	protected function configureSecond( Mode $mode ): void {
		GatewayKernel::writeDocument(
			$this->kernel,
			static::descriptor(),
			array(
				self::SECOND . '_mode' => $mode->value,
				GatewayKernel::name( self::SECOND, Mode::Test, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
				GatewayKernel::name( self::SECOND, Mode::Live, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}

	/**
	 * Places an order for one sellable unit through a payment method, in a request of its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The payment method.
	 * @param string $key    The idempotency key.
	 * @param string $token  Optional. The payment token. Default APPROVE.
	 * @return array<string, mixed> The placement's answer.
	 */
	protected function placeThrough( string $method, string $key, string $token = self::APPROVE ): array {
		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, $method );

		return $this->freshPlacement()->place( $this->placeInput( $key, $token ), self::guest() );
	}

	/**
	 * Returns the uuid, mode and status of an order's intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return array{uuid: string, mode: string, status: string} The intent.
	 */
	protected function intentOf( string $orderUuid ): array {
		$row = $this->db->fetchRow( 'SELECT i.uuid, i.mode, i.status FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $orderUuid );

		return array(
			'uuid'   => (string) $row['uuid'],
			'mode'   => (string) $row['mode'],
			'status' => (string) $row['status'],
		);
	}

	/**
	 * Returns a user who may capture payments.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The user.
	 */
	protected function capturer(): Actor {
		return $this->userGranted( PaymentService::CAPTURE_CAPABILITY );
	}

	/**
	 * Returns the current request's gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @return Gateways The registry.
	 */
	protected function gateways(): Gateways {
		return $this->kernel->get( Gateways::class );
	}

	/**
	 * Returns the contexts of the lines the log holds under a code, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The code.
	 * @return list<array<string, mixed>> The contexts.
	 */
	protected function logged( string $code ): array {
		return array_map(
			static fn( array $line ): array => (array) json_decode( (string) $line['context_json'], true ),
			$this->db->fetchAll( 'SELECT context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->table( LogsTable::NAME ), $code )
		);
	}
}
