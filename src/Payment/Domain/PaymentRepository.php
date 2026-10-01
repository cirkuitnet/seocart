<?php
/**
 * PaymentRepository: the statements of the payment tables, named by what they do
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The payment module's port to its tables: intents, and the ledger of what gateways reported.
 *
 * Owns one fact: which statements the payment service may send. Every change of an intent's
 * state is one conditional update whose WHERE clause lists the states IntentTransitions allows
 * the target to be entered from, so a method that returns false was refused by the database.
 * The ledger is appended to and never changed; appending a result is also the claim that
 * applies it once, by its provider, provider object and operation.
 *
 * Every method that writes runs only inside the caller's transaction, and refuses to run
 * outside one.
 *
 * @since 0.1.0
 */
interface PaymentRepository {

	/**
	 * Inserts an intent in its first state, with its amounts frozen.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $orderId             The order it pays for.
	 * @param string $uuid                Its public identifier.
	 * @param string $gatewayId           The gateway it is paid through.
	 * @param Money  $amount              Its amount, in the order's currency.
	 * @param Money  $baseAmount          Its amount in the base currency, at the order's rate.
	 * @param int    $conversionContextId The rate it is frozen at.
	 * @return int The intent's id.
	 */
	public function insertIntent( int $orderId, string $uuid, string $gatewayId, Money $amount, Money $baseAmount, int $conversionContextId ): int;

	/**
	 * Reads an intent with a locking read: its latest committed row, locked until the caller commits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The intent's public identifier.
	 * @return PaymentIntent|null The intent, or null when there is none.
	 */
	public function lock( string $uuid ): ?PaymentIntent;

	/**
	 * Reads an intent without locking it, to decide whether a gateway call is worth making.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The intent's public identifier.
	 * @return PaymentIntent|null The intent, or null when there is none.
	 */
	public function find( string $uuid ): ?PaymentIntent;

	/**
	 * Tells whether an intent has a ledger row the projection refused: money a person must reconcile.
	 *
	 * @since 0.1.0
	 *
	 * @param int $intentId The intent.
	 * @return bool True when it has one.
	 */
	public function hasUnappliedResult( int $intentId ): bool;

	/**
	 * Appends a gateway result to the ledger, which claims it: a result is applied once.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentIntent $intent        The intent the result is about, locked.
	 * @param GatewayResult $result        The result.
	 * @param Money         $baseAmount    The result's amount in the order's base currency, at its frozen rate; zero when it has none.
	 * @param bool          $applied       Whether the result moves the projection: false for one parked for a person.
	 * @param string        $actorType     `user` or `system`.
	 * @param int|null      $actorId       The user on whose authority, or null.
	 * @param string        $correlationId The request's correlation id.
	 * @return int|null The ledger row's id; null when a row with the same provider, provider object and operation exists.
	 */
	public function appendResult( PaymentIntent $intent, GatewayResult $result, Money $baseAmount, bool $applied, string $actorType, ?int $actorId, string $correlationId ): ?int;

	/**
	 * Reads the ledger row a result was first recorded in.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result The result.
	 * @return int|null The row's id, or null when the result has no row.
	 */
	public function findResult( GatewayResult $result ): ?int;

	/**
	 * Applies an approval to its intent: its state and the amount its operation moves.
	 *
	 * An authorization authorizes the amount and records the provider's reference; a capture
	 * captures it, never past what was authorized; a refund refunds it, never past what was
	 * captured, and leaves the intent refunded once everything captured is.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentIntent $intent     The intent, locked.
	 * @param GatewayResult $result     The approval.
	 * @param Money         $baseAmount The amount in the order's base currency, which the intent's base currency must be.
	 * @return bool True when the intent changed; false when its state or its amounts refused the operation.
	 */
	public function applyApproval( PaymentIntent $intent, GatewayResult $result, Money $baseAmount ): bool;

	/**
	 * Fails an intent the gateway declined.
	 *
	 * @since 0.1.0
	 *
	 * @param int $intentId The intent.
	 * @return bool True when the intent changed; false when its state refused it.
	 */
	public function applyDecline( int $intentId ): bool;

	/**
	 * Moves an intent to a state that waits: for the customer to act, or for the gateway to decide.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $intentId         The intent.
	 * @param IntentStatus $to               IntentStatus::RequiresAction or IntentStatus::Processing.
	 * @param string|null  $providerIntentId The provider's reference to the intent, kept when one is already recorded.
	 * @param int          $actionSeconds    How long the customer has to act, from the database's clock; for RequiresAction.
	 * @return bool True when the intent changed; false when its state refused it.
	 */
	public function await( int $intentId, IntentStatus $to, ?string $providerIntentId, int $actionSeconds ): bool;

	/**
	 * Lists a page of the intents in some states that have not changed for a while, by the database's clock, in the order they were created.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus[] $states           The states.
	 * @param int            $olderThanSeconds How long they have not changed, at least.
	 * @param string         $afterUuid        The uuid of the last intent of the page before, or '' for the first page.
	 * @param int            $limit            The most to list.
	 * @return list<IntentRef> The intents.
	 *
	 * @phpstan-param list<IntentStatus> $states
	 */
	public function stale( array $states, int $olderThanSeconds, string $afterUuid, int $limit ): array;
}
