<?php
/**
 * Tests `order.list_field_values` on every surface: what staff read of an order's checkout field values
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\OrderFieldValues;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\OperationSurfaces;

/**
 * Staff read an order's checkout field values on the route, the ability and the command, compiled from one declaration: in the order the form showed their fields, each with its words as the shopper saw them and its label today; the value and a select's option label only for a user who may see personal data; two statements.
 *
 * Planted violations, each shown red and removed:
 * - in OrderFieldValues::list(), skip the authorizer: the service answers a user who may not read
 *   orders (each surface's own permission check, from the declaration, still refuses them);
 * - in OrderOperations::listFieldValues(), declare `field_value` without its privacy: a user who
 *   may not see personal data reads the values.
 *
 * @since 0.2.0
 */
final class OrderFieldValuesOperationTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The read on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Listens with the fixture extension.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		AcmeCheckoutFields::listen();
	}

	/**
	 * Discards the surfaces, the fixture and the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();
		AcmeCheckoutFields::forget();
		wp_set_current_user( 0 );
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests the declaration: the route, the command, the ability and the capability of a read.
	 *
	 * @since 0.2.0
	 */
	public function test_one_declaration_serves_a_route_a_command_and_an_ability(): void {
		$definition = OrderOperations::listFieldValues();

		$this->assertSame(
			array( 'GET', '/orders/{order_uuid}/field-values', 'seocart order field-values', 'seocart/list-order-field-values', 'seocart_view_orders' ),
			array( $definition->httpMethod(), $definition->rest()?->route(), $definition->cli()?->command(), $definition->abilityName(), $definition->capability() )
		);
		$this->assertTrue( $definition->annotations()->isReadOnly() );
	}

	/**
	 * Tests that staff who may see personal data read every value of an order on each surface, in form order, with its words then and now.
	 *
	 * @since 0.2.0
	 */
	public function test_staff_who_may_see_personal_data_read_every_value_on_each_surface(): void {
		$uuid     = $this->placedWith(
			array(
				AcmeCheckoutFields::DELIVERY_SLOT => 'morning',
				AcmeCheckoutFields::PO_NUMBER     => 'PO-1234',
				'order_note'                      => "Ring twice.\nThank you.",
			)
		);
		$expected = array(
			self::value( 'order_note', 'order', 'textarea', 'Order note', "Ring twice.\nThank you.", null ),
			self::value( AcmeCheckoutFields::PO_NUMBER, 'order', 'text', 'Purchase order number', 'PO-1234', null ),
			self::value( AcmeCheckoutFields::DELIVERY_SLOT, 'order', 'select', 'Delivery slot', 'morning', 'Morning' ),
		);

		wp_set_current_user( $this->userGranted( OrderOperations::VIEW_CAPABILITY, OperationInvoker::PERSONAL_DATA_CAPABILITY )->userId() );

		$route   = $this->surfaces()->rest( 'GET', '/orders/' . $uuid . '/field-values' );
		$ability = $this->surfaces()->ability( 'seocart/list-order-field-values', array( 'order_uuid' => $uuid ) );
		$command = $this->surfaces()->cli( 'seocart order field-values', array( $uuid ), array() );

		$this->assertSame( 200, $route->get_status(), (string) wp_json_encode( $route->get_data() ) );
		$this->assertSame( array( 'field_values' => $expected ), $route->get_data() );
		$this->assertSame( array( 'field_values' => $expected ), $ability );
		$this->assertNull( $command['failure'] );
		$this->assertSame( $expected, $command['printed']['item']['field_values'] ?? null );
	}

	/**
	 * Tests that staff who may read orders but not see personal data read each field without its value or option label.
	 *
	 * @since 0.2.0
	 */
	public function test_staff_without_personal_data_read_no_value(): void {
		$uuid = $this->placedWith( array( AcmeCheckoutFields::DELIVERY_SLOT => 'morning' ) );

		wp_set_current_user( $this->userGranted( OrderOperations::VIEW_CAPABILITY )->userId() );

		$route = $this->surfaces()->rest( 'GET', '/orders/' . $uuid . '/field-values' );
		$entry = self::value( AcmeCheckoutFields::DELIVERY_SLOT, 'order', 'select', 'Delivery slot', 'morning', 'Morning' );

		unset( $entry['field_value'], $entry['value_label'] );

		$this->assertSame( 200, $route->get_status() );
		$this->assertSame( array( 'field_values' => array( $entry ) ), $route->get_data() );
	}

	/**
	 * Tests the refusals: a user who may not read orders, on the route and the command, and an order there is not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_who_may_not_read_orders_and_an_unknown_order_are_refused(): void {
		$uuid = $this->placedWith( array( AcmeCheckoutFields::PO_NUMBER => 'PO-1234' ) );

		$subscriber = $this->createUser( 'subscriber' );

		wp_set_current_user( $subscriber );

		$this->assertSame( 403, $this->surfaces()->rest( 'GET', '/orders/' . $uuid . '/field-values' )->get_status() );
		$this->assertStringContainsString( 'requires the capability seocart_view_orders', (string) $this->surfaces()->cli( 'seocart order field-values', array( $uuid ), array() )['failure'] );

		// The service refuses too, for a caller in the same process that no surface checked.
		try {
			$this->kernel->get( OrderFieldValues::class )->list( array( 'order_uuid' => $uuid ), Actor::user( $subscriber ) );
			$this->fail( 'The service answered a user who may not read orders.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( AuthorizationError::Denied, $refused->errorCode() );
		}

		wp_set_current_user( $this->userGranted( OrderOperations::VIEW_CAPABILITY )->userId() );

		$missing = $this->surfaces()->rest( 'GET', '/orders/0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b/field-values' );

		$this->assertSame( array( 404, OrderError::NotFound->value ), array( $missing->get_status(), $missing->get_data()['code'] ?? null ) );
	}

	/**
	 * Tests that the read sends two statements, the order's id and its values, whatever the number of values, with an extension's fields registered: the action costs nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_read_sends_two_statements(): void {
		$uuid  = $this->placedWith(
			array(
				'order_note'                      => 'Please leave it with the porter.',
				AcmeCheckoutFields::PO_NUMBER     => 'PO-1234',
				AcmeCheckoutFields::DELIVERY_SLOT => 'afternoon',
				AcmeCheckoutFields::GIFT_WRAP     => '1',
			)
		);
		$staff = $this->userGranted( OrderOperations::VIEW_CAPABILITY, OperationInvoker::PERSONAL_DATA_CAPABILITY );

		wp_set_current_user( $staff->userId() );
		current_user_can( OrderOperations::VIEW_CAPABILITY );

		$read   = KernelContainer::build( $this->db, $this->reporter() )->get( OrderFieldValues::class );
		$answer = array();
		$log    = $this->captureQueries(
			function () use ( $read, $uuid, $staff, &$answer ): void {
				$answer = $read->list( array( 'order_uuid' => $uuid ), $staff );
			}
		);

		$this->assertCount( 4, $answer['field_values'] );
		$this->assertQueryCount( 2, $log, 'order.list_field_values' );
	}

	/**
	 * Places an order whose checkout holds custom field values, and returns its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $values The custom fields' values, by field key.
	 * @return string The order's uuid.
	 */
	private function placedWith( array $values ): string {
		$variant = $this->sellable();
		$cart    = $this->readyCart( array( $variant => 1 ) );

		$this->writeCheckout( $cart->version, StubGateway::ID, $values );

		$answer = $this->freshPlacement()->place( $this->placeInput( 'read-' . md5( (string) wp_json_encode( $values ) ) ), self::guest() );

		$this->assertSame( 'approved', $answer['outcome'] ?? null, (string) wp_json_encode( $answer ) );

		return (string) $answer['order_uuid'];
	}

	/**
	 * Returns the read on every surface, over the kernel's service.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationSurfaces The surfaces.
	 */
	private function surfaces(): OperationSurfaces {
		if ( ! isset( $this->surfaces ) ) {
			$registry = new OperationRegistry();

			$registry->add( OrderOperations::LIST_FIELD_VALUES, array( OrderOperations::class, 'listFieldValues' ) );

			$this->surfaces = new OperationSurfaces( $registry, null, array( OrderFieldValues::class => $this->kernel->get( OrderFieldValues::class ) ) );
		}

		return $this->surfaces;
	}

	/**
	 * Returns one value as the read answers it, the field declared today with the label the order keeps, in the site's locale.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $key        The field's key.
	 * @param string      $section    Its section.
	 * @param string      $type       Its type.
	 * @param string      $label      Its label.
	 * @param string      $value      The value.
	 * @param string|null $valueLabel The option's label, or null.
	 * @return array<string, string|null> The value, by wire name.
	 */
	private static function value( string $key, string $section, string $type, string $label, string $value, ?string $valueLabel ): array {
		return array(
			'key'           => $key,
			'section'       => $section,
			'type'          => $type,
			'label'         => $label,
			'current_label' => $label,
			'field_value'   => $value,
			'value_label'   => $valueLabel,
			'locale'        => get_locale(),
		);
	}
}
