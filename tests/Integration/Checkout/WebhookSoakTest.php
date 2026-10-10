<?php
/**
 * Soaks the receiver in a storm of deliveries interleaved with the store's own calls, and checks that every payment was settled once
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use Random\Engine\Mt19937;
use Random\Randomizer;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A storm of the stand-in's deliveries, interleaved with the placements, captures, voids and refunds the store makes itself: every event delivered at least once, most several times, some forged or signed too long ago, in an order a seed decides.
 *
 * Every delivery goes through the route, as WordPress dispatches it. The script: placements the
 * stand-in approves, some of which are then captured or voided by a person, and some captured and
 * then refunded, the provider's word of the refund delivered after the store's answer or, for a
 * few, inside the gateway's call, before the store has its answer; one captured order the provider
 * refunds in its dashboard, which no claim of the store's asked for; placements whose shopper must
 * act, which a delivered approval settles, which the store voids when the shopper's time runs out,
 * or which the provider voids itself; declines; approvals of the wrong amount; placements the
 * gateway never answered, which only a delivery settles; and a dispute for every tenth order. Each
 * payment's steps keep their order, a delivery after the call it reports, except
 * that a provider may report a shopper's need to act at any time after the placement; the payments
 * are merged at random, and each event's further copies land anywhere after its first delivery.
 * Forged copies carry a tampered amount and a bad signature; stale ones a signed time 400 seconds off.
 *
 * The run passes when: (1) the ledger holds exactly the rows the script expects, one per provider
 * object and operation; (2) each event delivered genuinely has one receipt, settled, and no
 * rejected delivery left one; (3) only the wrong amounts and the dashboard's refund left money
 * for a person; (4) doctor finds
 * no critical drift; (5) no hold is left, and the units of every order whose payment stands are
 * allocated; (6) each placement's kept answer says the order's status, but the order the
 * dashboard's refund parked later; (7) every delivery of an event decided before cost two
 * statements.
 *
 * SEOCART_WEBHOOK_SOAK sets the number of deliveries (default 1 000), and SEOCART_WEBHOOK_SOAK_SEED
 * the seed, which the run prints so a red run can be repeated.
 *
 * A delivery answered anything but 200 or 401 is a fault, and the run fails on its faults first.
 * Planted violations, each shown red and removed:
 * - drop the ledger's unique key `provider_object_operation` from PaymentTables: copies of a
 *   result no longer meet the first one's row, so deliveries fault, or a copy's money is kept for
 *   a person and the store's own refund is then refused `payment.unreconciled`;
 * - in PaymentService::applyWait(), refuse a wait reported for an intent past waiting
 *   (`payment.unexpected_result`) instead of answering it stale: the late requests to act
 *   fault, and their receipts stay undecided;
 * - in StubGateway::readWebhook(), skip the signature check: the forged copies are read and
 *   settled with their tampered amounts, and at least one of them faults.
 *
 * @since 0.2.0
 *
 * @group soak
 */
final class WebhookSoakTest extends WebhookTestCase {

	/**
	 * How many payments of each kind the script makes.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const SCRIPT = array(
		'approved'  => 20,
		'captured'  => 30,
		'refunded'  => 10,
		'early'     => 5,
		'external'  => 1,
		'voided'    => 10,
		'waited'    => 5,
		'expired'   => 10,
		'abandoned' => 5,
		'declined'  => 10,
		'mismatch'  => 5,
		'unheard'   => 10,
	);

	/**
	 * How many deliveries the run makes when SEOCART_WEBHOOK_SOAK does not say.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const DELIVERIES = 1000;

	/**
	 * The share of the copies that are forged, and the share signed too long ago, in percent.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const REJECTED_PERCENT = 5;

	/**
	 * Every delivery made so far, by its event's id, and whether that event was decided: a later delivery of it must cost two statements.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, bool>
	 */
	private array $decided = array();

	/**
	 * The deliveries of events decided before that cost other than two statements, with what they cost.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $costly = array();

	/**
	 * The faults of the deliveries made inside a gateway's call, before the store had its answer.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $earlyFaults = array();

	/**
	 * The events delivered inside a gateway's call, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, bool>
	 */
	private array $earlyEvents = array();

	/**
	 * Serves the webhook route from the test's own wiring.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->serveRouteFrom( fn(): Container => $this->kernel );
	}

	/**
	 * Tests that the storm settles every payment once, keeps one decided receipt per genuine event, and leaves the store consistent.
	 *
	 * @since 0.2.0
	 */
	public function test_a_storm_of_deliveries_settles_every_payment_once(): void {
		$count  = (int) self::setting( 'SEOCART_WEBHOOK_SOAK', (string) self::DELIVERIES );
		$seed   = (int) self::setting( 'SEOCART_WEBHOOK_SOAK_SEED', (string) random_int( 1, 2147483647 ) );
		$random = new Randomizer( new Mt19937( $seed ) );

		fwrite( STDOUT, sprintf( "\nThe webhook soak: %d deliveries, seed %d (SEOCART_WEBHOOK_SOAK_SEED=%d repeats it).\n", $count, $seed, $seed ) );

		$payments = $this->script( $random );
		$sequence = $this->withCopies( self::merged( $payments, $random ), $count, $random );
		$made     = array();
		$genuine  = array();
		$faults   = array();
		$started  = hrtime( true );
		$capturer = $this->capturer();
		$voider   = $this->userGranted( PaymentService::VOID_CAPABILITY );
		$refunder = $this->userGranted( RefundService::CAPABILITY );

		foreach ( $sequence as $entry ) {
			if ( isset( $entry['copy'] ) ) {
				$delivery = $this->copyOf( $made[ $entry['copy'] ], $entry['variant'], $entry['index'] );
			} else {
				$payment = $payments[ $entry['payment'] ];
				$step    = $payment['steps'][ $entry['step'] ];

				if ( 'call' === $step[0] ) {
					$this->call( $step[1], $payment, $capturer, $voider, $refunder );

					continue;
				}

				$delivery = $this->eventOf( $step[1], $payment['context'] );

				$made[ $entry['payment'] . ':' . $entry['step'] ] = $delivery;
			}

			$fault = $this->deliverMeasured( $delivery );

			if ( null !== $fault ) {
				$faults[] = $fault;
			} elseif ( 'plain' === ( $entry['variant'] ?? 'plain' ) ) {
				$genuine[ $delivery->eventId() ] = true;
			}
		}

		$faults  = array_merge( $faults, $this->earlyFaults );
		$genuine = $genuine + $this->earlyEvents;

		fwrite( STDOUT, sprintf( "The soak made %d steps in %.1f s; %d faults.\n", count( $sequence ), ( hrtime( true ) - $started ) / 1e9, count( $faults ) ) );

		$this->assertSame( array(), $faults, 'A delivery was neither decided nor rejected.' );
		$this->assertLedger( $payments );
		$this->assertReceipts( array_keys( $genuine ) );
		$this->assertMoneyKeptOnlyForTheMismatches( $payments );
		$this->assertStock( $payments );
		$this->assertKeptAnswers( $payments );
		$this->assertSame( array(), $this->costly, 'A delivery of an event decided before cost other than two statements.' );

		$critical = preg_grep( '/^Critical/', $this->kernel->get( PaymentLedgerCheck::class )->run()->findings );

		$this->assertSame( array(), array_values( (array) $critical ), 'Doctor found drift between the ledger and what it projects.' );
	}

	/**
	 * Reads a setting of the run from the environment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name     The variable.
	 * @param string $fallback What the run takes when it is not set.
	 * @return string The value.
	 */
	private static function setting( string $name, string $fallback ): string {
		$value = getenv( $name );

		return false === $value || '' === $value ? $fallback : $value;
	}

	/**
	 * Writes each payment's script: the token it is placed with, and its steps in the order they happen.
	 *
	 * @since 0.2.0
	 *
	 * @param Randomizer $random The seeded source.
	 * @return list<array{kind: string, token: string, context: \ArrayObject<string, mixed>, steps: list<array{0: string, 1: string}>}> The payments.
	 */
	private function script( Randomizer $random ): array {
		$payments = array();

		foreach ( self::SCRIPT as $kind => $number ) {
			for ( $made = 0; $made < $number; $made++ ) {
				$steps = match ( $kind ) {
					'approved'  => array( array( 'call', 'place' ), array( 'event', 'authorized' ) ),
					'captured'  => array( array( 'call', 'place' ), array( 'event', 'authorized' ), array( 'call', 'capture' ), array( 'event', 'captured' ) ),
					'refunded'  => array( array( 'call', 'place' ), array( 'event', 'authorized' ), array( 'call', 'capture' ), array( 'event', 'captured' ), array( 'call', 'refund' ), array( 'event', 'refunded' ) ),
					'early'     => array( array( 'call', 'place' ), array( 'event', 'authorized' ), array( 'call', 'capture' ), array( 'event', 'captured' ), array( 'call', 'refund_early' ), array( 'event', 'refunded' ) ),
					'external'  => array( array( 'call', 'place' ), array( 'event', 'authorized' ), array( 'call', 'capture' ), array( 'event', 'captured' ), array( 'event', 'external' ) ),
					'voided'    => array( array( 'call', 'place' ), array( 'event', 'authorized' ), array( 'call', 'void' ), array( 'event', 'voided' ) ),
					'declined'  => array( array( 'call', 'place' ), array( 'event', 'declined' ) ),
					'mismatch'  => array( array( 'call', 'place' ), array( 'event', 'mismatched' ) ),
					'unheard'   => array( array( 'call', 'place' ), array( 'event', 'authorized' ) ),
					// A provider may report the shopper's need to act after the outcome it led to.
					'waited'    => self::withActionAnywhere( array( array( 'call', 'place' ), array( 'event', 'authorized' ) ), $random ),
					'expired'   => self::withActionAnywhere( array( array( 'call', 'place' ), array( 'call', 'expire' ), array( 'event', 'voided' ) ), $random ),
					'abandoned' => self::withActionAnywhere( array( array( 'call', 'place' ), array( 'event', 'voided' ) ), $random ),
				};

				if ( 0 === count( $payments ) % 10 ) {
					$steps[] = array( 'event', 'disputed' );
				}

				$payments[] = array(
					'kind'    => $kind,
					'token'   => self::tokenOf( $kind ),
					'context' => new \ArrayObject( array( 'key' => 'soak-' . count( $payments ) ) ),
					'steps'   => $steps,
				);
			}
		}

		return $payments;
	}

	/**
	 * Adds the provider's report of the shopper's need to act to a payment's steps, anywhere after its placement.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{0: string, 1: string}> $steps  The steps, the placement first.
	 * @param Randomizer                        $random The seeded source.
	 * @return list<array{0: string, 1: string}> The steps with the report.
	 */
	private static function withActionAnywhere( array $steps, Randomizer $random ): array {
		array_splice( $steps, $random->getInt( 1, count( $steps ) ), 0, array( array( 'event', 'action' ) ) );

		return $steps;
	}

	/**
	 * Returns the token a kind of payment is placed with.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kind The kind.
	 * @return string The stand-in's token.
	 */
	private static function tokenOf( string $kind ): string {
		return match ( $kind ) {
			'waited', 'expired', 'abandoned' => StubGateway::REQUIRES_ACTION,
			'declined'            => StubGateway::DECLINE,
			'mismatch'            => StubGateway::WRONG_AMOUNT,
			'unheard'             => StubGateway::THROW,
			default               => StubGateway::APPROVE,
		};
	}

	/**
	 * Merges the payments' steps at random, each payment's in its own order.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{steps: list<array{0: string, 1: string}>}> $payments The payments.
	 * @param Randomizer                                            $random   The seeded source.
	 * @return list<array{payment: int, step: int, event: bool}> The steps, merged.
	 */
	private static function merged( array $payments, Randomizer $random ): array {
		$next     = array_fill( 0, count( $payments ), 0 );
		$sequence = array();

		while ( array() !== $next ) {
			$payment = array_keys( $next )[ $random->getInt( 0, count( $next ) - 1 ) ];
			$step    = $next[ $payment ];

			$sequence[] = array(
				'payment' => $payment,
				'step'    => $step,
				'event'   => 'event' === $payments[ $payment ]['steps'][ $step ][0],
			);

			if ( ++$next[ $payment ] >= count( $payments[ $payment ]['steps'] ) ) {
				unset( $next[ $payment ] );
			}
		}

		return $sequence;
	}

	/**
	 * Adds the events' further copies, each after the event's first delivery, until the sequence holds the deliveries asked for; some copies are forged, some signed too long ago.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{payment: int, step: int, event: bool}> $sequence The merged steps.
	 * @param int                                               $count    How many deliveries in all.
	 * @param Randomizer                                        $random   The seeded source.
	 * @return list<array<string, mixed>> The steps and the copies.
	 */
	private function withCopies( array $sequence, int $count, Randomizer $random ): array {
		$events = array_keys( array_filter( $sequence, static fn( array $entry ): bool => $entry['event'] ) );
		$after  = array_fill( 0, count( $sequence ), array() );

		for ( $copy = count( $events ); $copy < $count; $copy++ ) {
			$position = $events[ $random->getInt( 0, count( $events ) - 1 ) ];
			$roll     = $random->getInt( 1, 100 );
			$original = $sequence[ $position ];

			$after[ $random->getInt( $position, count( $sequence ) - 1 ) ][] = array(
				'copy'    => $original['payment'] . ':' . $original['step'],
				'variant' => $roll <= self::REJECTED_PERCENT ? 'forged' : ( $roll <= 2 * self::REJECTED_PERCENT ? 'stale' : 'plain' ),
				'index'   => $copy,
			);
		}

		$all = array();

		foreach ( $sequence as $position => $entry ) {
			$all[] = $entry;

			foreach ( $after[ $position ] as $copy ) {
				$all[] = $copy;
			}
		}

		return $all;
	}

	/**
	 * Makes one of the store's own calls for a payment: its placement, a person's capture, void or refund, or the end of the shopper's time to act, which the store's run voids.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                     $call     The call: place, capture, void, expire, refund, or refund_early, whose answer the provider delivers inside the gateway's call.
	 * @param array{token: string, context: \ArrayObject<string, mixed>} $payment  The payment.
	 * @param Actor                                                      $capturer Who captures.
	 * @param Actor                                                      $voider   Who voids.
	 * @param Actor                                                      $refunder Who refunds.
	 */
	private function call( string $call, array $payment, Actor $capturer, Actor $voider, Actor $refunder ): void {
		$context = $payment['context'];

		if ( 'refund' === $call || 'refund_early' === $call ) {
			$this->refundOneUnit( $context, 'refund_early' === $call, $refunder );

			return;
		}

		if ( 'place' === $call ) {
			// Each payment is a shopper of its own, where the cart-creation limit counts one client.
			$this->db->execute( 'DELETE FROM %i', $this->table( RateCountersTable::NAME ) );

			$placed = $this->placed( $payment['token'], (string) $context['key'] );

			foreach ( $placed as $name => $value ) {
				$context[ $name ] = $value;
			}

			$context['intent'] = $this->intentRow( $placed['intent_uuid'] );

			return;
		}

		if ( 'expire' === $call ) {
			$this->db->execute(
				'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE uuid = %s',
				$this->table( PaymentTables::INTENTS ),
				(string) $context['intent_uuid']
			);
			$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

			return;
		}

		$payments = $this->kernel->get( PaymentService::class );

		if ( 'capture' === $call ) {
			$payments->capture( (string) $context['intent_uuid'], $capturer );
		} else {
			$payments->void( (string) $context['intent_uuid'], $voider, VoidReason::CustomerRequest );
		}
	}

	/**
	 * Builds the delivery of one of a payment's events, as the stand-in reports it.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $event   The event.
	 * @param \ArrayObject $context What the payment's placement left: its intent above all.
	 * @return StubWebhooks The delivery.
	 *
	 * @phpstan-param \ArrayObject<string, mixed> $context
	 */
	private function eventOf( string $event, \ArrayObject $context ): StubWebhooks {
		$uuid      = (string) $context['intent_uuid'];
		$intent    = (array) $context['intent'];
		$amount    = (int) $intent['amount_minor'];
		$currency  = (string) $intent['currency'];
		$reference = 'stub-pi-approve-' . $uuid;

		return match ( $event ) {
			'authorized' => StubWebhooks::of( self::stubResult( $uuid, Operation::Authorize, Outcome::Approved, $amount, $currency, 'stub-ch-' . $uuid, $reference ) ),
			'declined'   => StubWebhooks::of( self::stubResult( $uuid, Operation::Authorize, Outcome::Declined, $amount, $currency, 'stub-ch-' . $uuid, null, StubGateway::CARD_DECLINED ) ),
			'mismatched' => StubWebhooks::of( self::stubResult( $uuid, Operation::Authorize, Outcome::Approved, $amount + 1, $currency, 'stub-ch-' . $uuid ) ),
			'action'     => StubWebhooks::of( self::stubResult( $uuid, Operation::Authorize, Outcome::RequiresAction, $amount, $currency, null ) ),
			'captured'   => StubWebhooks::of( self::stubResult( $uuid, Operation::Capture, Outcome::Approved, $amount, $currency, 'stub-cap-' . $uuid ) ),
			'voided'     => StubWebhooks::of( self::stubResult( $uuid, Operation::Void, Outcome::Approved, $amount, $currency, 'stub-void-' . $uuid ) ),
			'refunded'   => self::refundOf( $uuid, (string) $context['refund_uuid'], (int) $context['refund_minor'], $currency ),
			'external'   => StubWebhooks::of( self::stubResult( $uuid, Operation::Refund, Outcome::Approved, 500, $currency, 'external-re-' . $context['key'] ) ),
			default      => StubWebhooks::dispute( $uuid ),
		};
	}

	/**
	 * Builds the stand-in's word of a refund it made under the store's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent refunded.
	 * @param string $refundUuid The refund's uuid, which the stand-in echoes.
	 * @param int    $minor      What it gave back, in minor units.
	 * @param string $currency   The currency.
	 * @return StubWebhooks The delivery.
	 */
	private static function refundOf( string $intentUuid, string $refundUuid, int $minor, string $currency ): StubWebhooks {
		return StubWebhooks::of( self::stubResult( $intentUuid, Operation::Refund, Outcome::Approved, $minor, $currency, 'stub-re-' . $refundUuid ), $refundUuid );
	}

	/**
	 * Refunds one unit of the order's first line, through the refund service as the kernel wires it, and keeps the refund on the payment's context.
	 *
	 * For an early refund, the provider delivers its word of the refund inside the gateway's call,
	 * before the stand-in answers: the delivery records the refund through its open claim, and the
	 * store's answer then meets it.
	 *
	 * @since 0.2.0
	 *
	 * @param \ArrayObject $context  The payment's context.
	 * @param bool         $early    Whether the provider delivers its word inside the gateway's call.
	 * @param Actor        $refunder Who refunds.
	 *
	 * @phpstan-param \ArrayObject<string, mixed> $context
	 */
	private function refundOneUnit( \ArrayObject $context, bool $early, Actor $refunder ): void {
		$orderId = (int) $context['order_id'];
		$line    = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $orderId );

		if ( $early ) {
			$this->gateway->during( fn() => $this->deliverInsideTheCall( (string) $context['intent_uuid'], $orderId ) );
		}

		try {
			$refund = $this->kernel->get( RefundService::class )->refund( new RefundRequest( (string) $context['order_uuid'], array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $refunder );
		} finally {
			$this->gateway->during( static function (): void {} );
		}

		$context['refund_uuid']  = $refund->uuid;
		$context['refund_minor'] = $refund->total->minorUnits();
	}

	/**
	 * Delivers, inside the gateway's call, the provider's word of the refund the order's open claim asks for.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent refunded.
	 * @param int    $orderId    The order.
	 */
	private function deliverInsideTheCall( string $intentUuid, int $orderId ): void {
		$claim    = (array) $this->db->fetchRow( "SELECT uuid, amount_minor, currency FROM %i WHERE order_id = %d AND state = 'claimed'", $this->table( RefundClaimTables::CLAIMS ), $orderId );
		$delivery = self::refundOf( $intentUuid, (string) $claim['uuid'], (int) $claim['amount_minor'], (string) $claim['currency'] );
		$fault    = $this->deliverMeasured( $delivery );

		if ( null === $fault ) {
			$this->earlyEvents[ $delivery->eventId() ] = true;
		} else {
			$this->earlyFaults[] = $fault;
		}
	}

	/**
	 * Returns a further copy of a delivery: the same, signed too long ago, or a forgery of it with a tampered amount, another object and a bad signature.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $original The first delivery.
	 * @param string       $variant  plain, stale or forged.
	 * @param int          $index    The copy's number, which names a forgery's event.
	 * @return StubWebhooks The copy.
	 */
	private function copyOf( StubWebhooks $original, string $variant, int $index ): StubWebhooks {
		if ( 'stale' === $variant ) {
			return $original->staleBy( 400 );
		}

		if ( 'plain' === $variant ) {
			return $original;
		}

		$event = (array) json_decode( $original->body(), true );
		$data  = (array) $event['data'];

		$data['amount_minor']       = (int) ( $data['amount_minor'] ?? 0 ) + 500;
		$data['provider_object_id'] = 'stub-forged-' . $index;

		return StubWebhooks::event( 'evt_forged_' . $index, (string) $event['type'], $data )->withBadSignature();
	}

	/**
	 * Delivers through the route, measuring a delivery of an event decided before, and returns a fault, if any: anything but a decision or a rejection.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery The delivery.
	 * @return string|null The fault, or null.
	 */
	private function deliverMeasured( StubWebhooks $delivery ): ?string {
		$decided  = isset( $this->decided[ $delivery->eventId() ] );
		$response = null;
		$log      = $this->captureQueries(
			function () use ( $delivery, &$response ): void {
				$response = $this->post( $delivery );
			}
		);
		$status   = null === $response ? 0 : $response->get_status();

		if ( 200 === $status ) {
			if ( $decided && 2 !== count( $log ) ) {
				$this->costly[] = sprintf( '%s: %d statements', $delivery->eventId(), count( $log ) );
			}

			$this->decided[ $delivery->eventId() ] = true;

			return null;
		}

		if ( 401 === $status ) {
			return null;
		}

		return sprintf( '%d %s %s', $status, (string) ( ( (array) $response?->get_data() )['code'] ?? '' ), $delivery->eventId() );
	}

	/**
	 * Asserts that the ledger holds exactly the rows the script expects, one per provider object and operation, and no other.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{kind: string, context: \ArrayObject<string, mixed>}> $payments The payments.
	 */
	private function assertLedger( array $payments ): void {
		$expected = 0;

		foreach ( $payments as $payment ) {
			$uuid       = (string) $payment['context']['intent_uuid'];
			$authorized = 'authorize:approved:1:stub-ch-' . $uuid;
			$rows       = match ( $payment['kind'] ) {
				'captured'  => array( $authorized, 'capture:approved:1:stub-cap-' . $uuid ),
				'refunded', 'early' => array( $authorized, 'capture:approved:1:stub-cap-' . $uuid, 'refund:approved:1:stub-re-' . $payment['context']['refund_uuid'] ),
				'external'  => array( $authorized, 'capture:approved:1:stub-cap-' . $uuid, 'refund:approved:0:external-re-' . $payment['context']['key'] ),
				'voided'    => array( $authorized, 'void:approved:1:stub-void-' . $uuid ),
				'expired', 'abandoned' => array( 'void:approved:1:stub-void-' . $uuid ),
				'declined'  => array( 'authorize:declined:1:stub-ch-' . $uuid ),
				'mismatch'  => array( 'authorize:approved:0:stub-ch-' . $uuid ),
				default     => array( $authorized ),
			};

			$expected += count( $rows );

			$this->assertSame( $rows, $this->ledgerOf( (int) $payment['context']['order_id'] ), sprintf( 'The ledger of a %s payment.', $payment['kind'] ) );
		}

		$this->assertSame( $expected, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( PaymentTables::TRANSACTIONS ) ), 'The ledger holds a row the script did not make.' );
	}

	/**
	 * Asserts one receipt per genuine event, each decided, and none for a rejected delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $genuine The ids of the events delivered genuinely.
	 *
	 * @phpstan-param list<string> $genuine
	 */
	private function assertReceipts( array $genuine ): void {
		$receipts = $this->db->fetchAll( 'SELECT event_id, result FROM %i ORDER BY event_id', $this->table( WebhookReceiptTables::RECEIPTS ) );

		sort( $genuine );

		$this->assertSame( $genuine, array_column( $receipts, 'event_id' ), 'One receipt per genuine event, and none for a rejected delivery.' );
		$this->assertSame( array(), array_column( array_filter( $receipts, static fn( array $receipt ): bool => null === $receipt['result'] ), 'event_id' ), 'Every receipt is decided.' );
	}

	/**
	 * Asserts that only the approvals of the wrong amount and the dashboard's refund left money for a person: their rows alone moved nothing, their orders alone are flagged.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{kind: string, context: \ArrayObject<string, mixed>}> $payments The payments.
	 */
	private function assertMoneyKeptOnlyForTheMismatches( array $payments ): void {
		$mismatches = array();

		foreach ( $payments as $payment ) {
			if ( in_array( $payment['kind'], array( 'mismatch', 'external' ), true ) ) {
				$mismatches[] = (int) $payment['context']['order_id'];
			}
		}

		sort( $mismatches );

		$kept    = array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT DISTINCT order_id FROM %i WHERE applied = 0 ORDER BY order_id', $this->table( PaymentTables::TRANSACTIONS ) ), 'order_id' ) );
		$flagged = array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i WHERE has_unreconciled_money = 1 ORDER BY id', $this->table( OrderTables::ORDERS ) ), 'id' ) );

		$this->assertSame( $mismatches, $kept, 'Money was kept for a person beyond the mismatches.' );
		$this->assertSame( $mismatches, $flagged, 'An order beyond the mismatches is flagged.' );
	}

	/**
	 * Asserts that no hold is left, and that the unit of every order whose payment stands is allocated, and of no other.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{kind: string, context: \ArrayObject<string, mixed>}> $payments The payments.
	 */
	private function assertStock( array $payments ): void {
		$b = $this->secondConnection();

		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ), 'A hold is left.' );

		foreach ( $payments as $payment ) {
			$allocated = in_array( $payment['kind'], array( 'declined', 'expired', 'abandoned' ), true ) ? 0 : 1;

			$this->assertSame( $allocated, $this->committedStock( $b, (int) $payment['context']['variant'] )[1], sprintf( 'The unit of a %s payment.', $payment['kind'] ) );
		}
	}

	/**
	 * Asserts that each placement's kept answer says its order's status, but the order the dashboard's refund parked after its placement.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{kind: string, context: \ArrayObject<string, mixed>}> $payments The payments.
	 */
	private function assertKeptAnswers( array $payments ): void {
		$rows   = $this->db->fetchAll( 'SELECT o.uuid, o.status, k.response_json FROM %i k JOIN %i o ON o.id = k.order_id', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ) );
		$parked = array();

		foreach ( $payments as $payment ) {
			if ( 'external' === $payment['kind'] ) {
				$parked[] = (string) $payment['context']['order_uuid'];
			}
		}

		$this->assertNotSame( array(), $rows );

		foreach ( $rows as $row ) {
			if ( in_array( $row['uuid'], $parked, true ) ) {
				$this->assertSame( 'on_hold', $row['status'], 'The order the dashboard\'s refund parked.' );

				continue;
			}

			$this->assertSame( $row['status'], json_decode( (string) $row['response_json'], true )['status'] ?? null, 'The kept answer of order ' . $row['uuid'] );
		}
	}
}
