<?php
/**
 * OrderOperations: the order operations a merchant's client calls
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `order.clear_unreconciled_money`: a person's word that the money an order was flagged for is reconciled.
 *
 * Owns one fact: how the clearance is offered to clients. One declaration serves the REST route
 * `POST seocart/v1/orders/{order_uuid}/money-reconciliation`, the ability
 * `seocart/clear-unreconciled-money` and the command `wp seocart order reconcile <order_uuid>`;
 * each is compiled from it and none restates it.
 *
 * Clearing the flag overrides what the plugin knows of the order's money, with a person's word,
 * so it is destructive, needs `seocart_override_money_state`, and is never exposed to agents. A
 * cleared order's flag stays down until money the ledger did not expect lands again; clearing it
 * twice is refused, so a retry is safe.
 *
 * Declarations are data: building the definition reads nothing.
 *
 * @since 0.2.0
 */
final class OrderOperations {

	/**
	 * The id of the clearance.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLEAR_UNRECONCILED_MONEY = 'order.clear_unreconciled_money';

	/**
	 * The REST route of the clearance, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLEAR_ROUTE = '/orders/{order_uuid}/money-reconciliation';

	/**
	 * The ability slug of the clearance, below `seocart/`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLEAR_ABILITY = 'clear-unreconciled-money';

	/**
	 * The capability that overrides what the plugin knows of an order's money with a person's word: clearing the flag, and settling a refund claim.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MONEY_OVERRIDE_CAPABILITY = 'seocart_override_money_state';

	/**
	 * The longest note a clearance keeps, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const NOTE_MAX_LENGTH = 500;

	/**
	 * Builds the clearance.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function clearUnreconciledMoney(): OperationDefinition {
		return new OperationDefinition(
			id: self::CLEAR_UNRECONCILED_MONEY,
			label: static fn(): string => __( 'Clear unreconciled money', 'seocart' ),
			summary: 'Lowers the flag of an order holding money a person must reconcile, with the note saying why, once a person has reconciled that money; a payment result the plugin could not apply that is older than the clearance then no longer holds the order\'s refunds back. An order not flagged is refused, so a retry is safe.',
			input: array(
				self::orderUuid( 'The public identifier of the order whose unreconciled money is cleared.' ),
				new FieldSpec(
					name: 'note',
					type: FieldType::String,
					description: 'Why the person says the order\'s money is reconciled; kept with the order, and refused when it holds a card number.',
					label: static fn(): string => __( 'Note', 'seocart' ),
					example: 'The second capture was refunded in the gateway\'s dashboard.',
					required: true,
					max_length: self::NOTE_MAX_LENGTH,
					privacy: Privacy::Pii
				),
			),
			output: new ResourceSchema(
				'MoneyReconciliation',
				array(
					self::orderUuid( 'The order whose unreconciled money was cleared.' ),
					new FieldSpec(
						name: 'has_unreconciled_money',
						type: FieldType::Boolean,
						description: 'Whether the order holds money a person must reconcile: false once cleared.',
						label: static fn(): string => __( 'Unreconciled money', 'seocart' ),
						example: false,
						required: true
					),
					new FieldSpec(
						name: 'money_reconciled_at',
						type: FieldType::String,
						description: 'When the flag was cleared, UTC, in ISO 8601 to the microsecond, by the database clock.',
						label: static fn(): string => __( 'Reconciled at', 'seocart' ),
						example: '2026-10-09T14:03:27.512345Z',
						required: true
					),
				)
			),
			capability: self::MONEY_OVERRIDE_CAPABILITY,
			resource_field: null,
			errors: array(
				AuthorizationError::Denied,
				OrderError::ReconciliationNoteRejected,
				OrderError::NotFound,
				OrderError::NotUnreconciled,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( Orders::class, 'clearUnreconciledMoney' ),
			rest: new RestBinding( self::CLEAR_ROUTE, WriteMethod::Post ),
			ability: self::CLEAR_ABILITY,
			cli: new CliBinding( array( 'order', 'reconcile' ), array( 'order_uuid' ) ),
			agent_exposed: false
		);
	}

	/**
	 * Returns an order's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description What it is.
	 * @return FieldSpec The field.
	 */
	private static function orderUuid( string $description ): FieldSpec {
		return new FieldSpec(
			name: 'order_uuid',
			type: FieldType::Uuid,
			description: $description,
			label: static fn(): string => __( 'Order', 'seocart' ),
			example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
			required: true
		);
	}
}
