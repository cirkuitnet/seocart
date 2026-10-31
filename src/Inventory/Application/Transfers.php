<?php
/**
 * Transfers: moves stock between locations as transfer documents, each change one unit of work with its events
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Event\StockTransferRequested;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\Event\StockTransferShort;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\LocationRecord;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Inventory\Domain\MerchantText;
use SEOCart\Inventory\Domain\StockLines;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Inventory\Domain\Transfer;
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferLine;
use SEOCart\Inventory\Domain\TransferRepository;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The transfer operations' service: every movement of units between two locations goes through it.
 *
 * Owns one fact: how a transfer moves stock, in what order its statements run, and the events it
 * publishes. A merchant creates a draft, which promises nothing, edits it, sends it whole and
 * receives it in parts, writing lost units off; the system requests one per location an order's
 * units must come from, and detaches an order's lines when the order is cancelled; a
 * deactivation's move is one transfer worked page by page by a background job. Units on the way
 * are on the transfer's lines and in no location's stock; an allocation travelling with them is
 * in transit and counts at no item, and what arrives becomes the same order line's allocation at
 * the destination, so a unit promised to an order is never anyone else's while the order stands.
 *
 * One lock order for every path: the transfer's row or rows first, by the statement that moves
 * or locks them; then the stock items ascending by variant and location, a variant's policy and a
 * location's row in share mode before the item they guard, and each item's allocation rows after
 * it. A transfer's lines, and the allocations that travel with it, are written only under its
 * row's lock. A caller that holds an order's row, the order's cancel, takes it before all of
 * them; a send or a receipt never takes it. Every unit of work this class opens runs at READ
 * COMMITTED with the deadlock retry policy, as the stock service's do, so a deadlock, should one
 * come, runs the whole unit again; the seams an order's placement and cancel call run in the
 * caller's unit and leave its failures to the caller's policy.
 *
 * Every operation authorizes its actor with the capability that manages inventory, and refuses
 * what its declaration cannot: a note with a control character, a receipt line named twice or
 * receiving nothing, an edit leaving no line or too many.
 *
 * @since 0.2.0
 */
final class Transfers {

	/**
	 * The name of the background job that works a deactivation's move: the inventory module's prefix, as the hold sweep's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MOVE_JOB = 'stock.move_location_stock';

	/**
	 * The most variants one page of a deactivation's move sends and receives.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOVE_PAGE = 200;

	/**
	 * Why an edit is refused: the transfer was sent, received or cancelled.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_EDITABLE = 'not_editable';

	/**
	 * Why a send is refused: the transfer was sent, received or cancelled already.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_SENDABLE = 'not_sendable';

	/**
	 * Why a receipt is refused: the transfer is not in transit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_RECEIVABLE = 'not_receivable';

	/**
	 * Why a cancel is refused: the transfer was sent, received or cancelled already.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_CANCELLABLE = 'not_cancellable';

	/**
	 * Why a cancel is refused: the transfer carries an order's units, which only the order's cancel detaches.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARRIES_ORDER = 'carries_order';

	/**
	 * Why a deactivation's move is refused: another move from the location, to another location, is in transit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ANOTHER_MOVE = 'another_move';

	/**
	 * The documents.
	 *
	 * @since 0.2.0
	 *
	 * @var TransferRepository
	 */
	private TransferRepository $transfers;

	/**
	 * The stock statements: the items, allocations and ledger entries a transfer moves.
	 *
	 * @since 0.2.0
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock;

	/**
	 * The locations, whose rows a new transfer locks in share mode.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationRepository
	 */
	private LocationRepository $locations;

	/**
	 * The locations as the answers name them.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationDirectory
	 */
	private LocationDirectory $directory;

	/**
	 * The unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Publishes the events.
	 *
	 * @since 0.2.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Mints transfers' public identifiers.
	 *
	 * @since 0.2.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Says when an event happened.
	 *
	 * @since 0.2.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The request's correlation id, which a ledger entry shares with its events.
	 *
	 * @since 0.2.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * The capability check of the operations this service performs.
	 *
	 * @since 0.2.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Queues the job of a deactivation's move, once its unit of work has committed.
	 *
	 * @since 0.2.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $queue;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param TransferRepository $transfers   The documents.
	 * @param StockRepository    $stock       The stock statements.
	 * @param LocationRepository $locations   The locations, whose rows a new transfer locks.
	 * @param LocationDirectory  $directory   The locations as the answers name them.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param IdGenerator        $ids         Mints transfers' public identifiers.
	 * @param Clock              $clock       Says when an event happened.
	 * @param CorrelationId      $correlation The request's correlation id.
	 * @param Authorizer         $authorizer  The capability check of the operations it performs.
	 * @param JobQueue           $queue       Queues a deactivation's move after its commit.
	 */
	public function __construct( TransferRepository $transfers, StockRepository $stock, LocationRepository $locations, LocationDirectory $directory, TransactionManager $tx, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation, Authorizer $authorizer, JobQueue $queue ) {
		$this->transfers   = $transfers;
		$this->stock       = $stock;
		$this->locations   = $locations;
		$this->directory   = $directory;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->ids         = $ids;
		$this->clock       = $clock;
		$this->correlation = $correlation;
		$this->authorizer  = $authorizer;
		$this->queue       = $queue;
	}

	/**
	 * Performs `inventory.list_transfers`: a page of transfers, newest first, each with its lines summed up.
	 *
	 * Two plain reads whatever the page's size, the page and its lines' totals, and the list of
	 * locations when the object cache does not hold it. The page asks for one transfer more than it
	 * shows, which says whether there is a next page; no total is counted.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.location_missing` when no location has the uuid given.
	 *
	 * @param array<string, mixed> $input The prepared input: optionally state, location_uuid, order_id, page and per_page.
	 * @param Actor                $actor Who reads.
	 * @return array{transfers: list<array<string, mixed>>, page: int, per_page: int, has_more: bool} The page, keyed by wire name.
	 */
	public function listTransfers( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$state    = isset( $input['state'] ) ? TransferState::from( (string) $input['state'] ) : null;
		$location = isset( $input['location_uuid'] ) ? $this->location( (string) $input['location_uuid'] )->id : null;
		$orderId  = isset( $input['order_id'] ) ? (int) $input['order_id'] : null;
		$page     = min( TransferOperations::MOST_PAGE, max( 1, (int) ( $input['page'] ?? 1 ) ) );
		$perPage  = min( TransferOperations::MOST_PER_PAGE, max( 1, (int) ( $input['per_page'] ?? TransferOperations::PER_PAGE ) ) );
		$found    = $this->transfers->page( $state, $location, $orderId, $perPage + 1, ( $page - 1 ) * $perPage );
		$shown    = array_slice( $found, 0, $perPage );
		$totals   = $this->transfers->lineTotals( array_map( static fn( Transfer $transfer ): int => $transfer->id, $shown ) );
		$listed   = array();

		foreach ( $shown as $transfer ) {
			$listed[] = $this->header( $transfer ) + ( $totals[ $transfer->id ] ?? array(
				'lines'    => 0,
				'sent'     => 0,
				'received' => 0,
				'lost'     => 0,
			) );
		}

		return array(
			'transfers' => $listed,
			'page'      => $page,
			'per_page'  => $perPage,
			'has_more'  => count( $found ) > $perPage,
		);
	}

	/**
	 * Performs `inventory.get_transfer`: one transfer with its lines. One plain read.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_not_found` when no transfer has the uuid.
	 *
	 * @param array<string, mixed> $input The prepared input: transfer_uuid.
	 * @param Actor                $actor Who reads.
	 * @return array<string, mixed> The transfer, keyed by wire name.
	 */
	public function getTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		return $this->answer( $this->document( (string) $input['transfer_uuid'] ) );
	}

	/**
	 * Performs `inventory.create_transfer`: a merchant's draft from one location to another.
	 *
	 * The fields are checked before any statement. Then both locations are read with a locking
	 * read in share mode, so a deletion of either, whose own check of what uses a location is a
	 * plain read after its exclusive lock, waits for this unit of work and then finds the transfer,
	 * or comes first and leaves no location to find: no transfer ever names a location that is gone.
	 * The destination must be active; the source may be deactivated, so its units can be moved
	 * away. Every variant must have a stock policy. A draft promises nothing: it may name more
	 * units than the source has, and its send is what claims them. Lines of one variant are added
	 * together.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_same_location`
	 *                        when the two locations are one; `stock.location_missing` when either does not exist;
	 *                        `stock.location_inactive` when the destination is deactivated; `stock.item_missing` when a
	 *                        variant has no stock policy; `stock.transfer_invalid` when the note or the lines are not valid.
	 *
	 * @param array<string, mixed> $input The prepared input: source_location_uuid, destination_location_uuid, lines and optionally note.
	 * @param Actor                $actor Who creates it.
	 * @return array<string, mixed> The draft, keyed by wire name.
	 */
	public function createTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$source      = strtolower( (string) $input['source_location_uuid'] );
		$destination = strtolower( (string) $input['destination_location_uuid'] );

		if ( $source === $destination ) {
			CodedException::raise( InventoryError::TransferSameLocation, array( 'location_id' => $source ) );
		}

		$lines = self::newLines( $input['lines'] ?? null, 1 );
		$note  = array_key_exists( 'note', $input ) ? self::note( $input['note'] ) : null;
		$uuid  = $this->ids->generate();

		return $this->tx->transaction(
			function () use ( $source, $destination, $lines, $note, $uuid, $actor ): array {
				$locked = $this->locations->lockShared( array( $source, $destination ) );
				$from   = $locked[ $source ] ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $source ) );
				$to     = $locked[ $destination ] ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $destination ) );

				if ( ! $to->active ) {
					CodedException::raise( InventoryError::LocationInactive, array( 'location_id' => $destination ) );
				}

				$this->requirePolicies( array_keys( $lines ) );

				$id = $this->transfers->insert( $uuid, $from->id, $to->id, TransferKind::Manual, TransferState::Draft, null, $note, self::userOf( $actor ) );

				$this->transfers->insertLines( $id, self::rows( $lines ) );

				return $this->answer( $this->document( $uuid ) );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Performs `inventory.update_transfer`: changes a draft's or a requested transfer's note and lines, the fields given only.
	 *
	 * The fields are checked before any statement; then the transfer is locked, by the statement
	 * that requires it to be a draft or requested, and its lines are read under that lock. Lines
	 * given replace the lines that carry no allocation; a line carrying an order's units is kept as
	 * it is. The transfer keeps at least one line and at most TransferOperations::MOST_LINES. An
	 * empty note removes it.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_not_found` when
	 *                        no transfer has the uuid; `stock.transfer_state` when it was sent, received or cancelled;
	 *                        `stock.item_missing` when a variant has no stock policy; `stock.transfer_invalid` when the
	 *                        note or the lines are not valid.
	 *
	 * @param array<string, mixed> $input The prepared input: transfer_uuid, and optionally lines and note.
	 * @param Actor                $actor Who changes it.
	 * @return array<string, mixed> The transfer as it is now, keyed by wire name.
	 */
	public function updateTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$uuid  = (string) $input['transfer_uuid'];
		$lines = array_key_exists( 'lines', $input ) ? self::newLines( $input['lines'], 0 ) : null;
		$note  = array_key_exists( 'note', $input ) ? array( self::note( $input['note'] ) ) : null;

		return $this->tx->transaction(
			function () use ( $uuid, $lines, $note ): array {
				if ( ! $this->transfers->lockEditable( $uuid ) ) {
					$this->refuseState( $uuid, self::NOT_EDITABLE );
				}

				$transfer = $this->document( $uuid );

				if ( null !== $lines ) {
					$kept = count( array_filter( $transfer->lines, static fn( TransferLine $line ): bool => $line->isAllocated() ) );

					if ( $kept + count( $lines ) < 1 || $kept + count( $lines ) > TransferOperations::MOST_LINES ) {
						CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
					}

					$this->requirePolicies( array_keys( $lines ) );
					$this->transfers->deleteUnallocatedLines( $transfer->id );
					$this->transfers->insertLines( $transfer->id, self::rows( $lines ) );
				}

				if ( null !== $note ) {
					$this->transfers->setNote( $uuid, $note[0] );
				}

				return $this->answer( null === $lines && null === $note ? $transfer : $this->document( $uuid ) );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Performs `inventory.send_transfer`: every line leaves the source at once, or none does.
	 *
	 * The first statement moves the transfer to in transit, from a draft or requested, and locks
	 * it; a second send finds it in transit and is refused. Its lines are read under that lock and
	 * sent ascending by variant from the source: a line carrying an order's allocation takes its
	 * units off on_hand and `allocated` together, as a post does, then sets the allocation out with
	 * them, in transit; any other line takes available units only, which no order or checkout
	 * claims. Each line's ledger entry, `transfer_out`, names the transfer, with on_hand read under
	 * the item's lock. A line whose units are not there refuses the whole send. An allocation that
	 * is no longer open at the source is never fixed up after its units left `allocated`, which
	 * would then be another order's count: every writer of a transfer's allocations takes the
	 * transfer's row first, so the order's cancel has detached the line before the send, and the
	 * line goes as units no order waits for, or waits for the send and finds the allocation in
	 * transit. A note given, the tracking reference, replaces the note.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_not_found` when
	 *                        no transfer has the uuid; `stock.transfer_state` when it is not a draft or requested;
	 *                        `stock.transfer_refused` when the source lacks a line's units; `stock.transfer_invalid` when
	 *                        the note is not valid.
	 *
	 * @param array<string, mixed> $input The prepared input: transfer_uuid and optionally note.
	 * @param Actor                $actor Who sends it.
	 * @return array<string, mixed> The transfer in transit, keyed by wire name.
	 */
	public function sendTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$uuid = (string) $input['transfer_uuid'];
		$note = array_key_exists( 'note', $input ) ? array( self::note( $input['note'] ) ) : null;

		return $this->tx->transaction(
			function () use ( $uuid, $note, $actor ): array {
				if ( ! $this->transfers->markSent( $uuid ) ) {
					$this->refuseState( $uuid, self::NOT_SENDABLE );
				}

				if ( null !== $note ) {
					$this->transfers->setNote( $uuid, $note[0] );
				}

				$transfer = $this->document( $uuid );
				$moved    = array();

				foreach ( $transfer->lines as $line ) {
					$this->sendLine( $transfer, $line, $actor );

					$moved[] = array( $line->variantId, $transfer->sourceLocationId, $line->quantity );
				}

				$this->publishMovement( $transfer->id, $transfer->sourceLocationId, $transfer->destinationLocationId, StockTransferred::SENT, $moved );

				return $this->answer( $transfer );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Performs `inventory.receive_transfer`: units of some lines arrive at the destination, or are written off as lost.
	 *
	 * The receipt is checked before any statement: each line named once, each receiving or losing at
	 * least one unit. Then the transfer is locked, by the statement that requires it in transit, and
	 * its lines are read under that lock; a line not on it is refused. Ascending by variant, for each
	 * line that receives units: the variant's policy and the destination's row are locked in share
	 * mode, a write that creates a stock item at a location taking the location's lock first, and the
	 * item at the destination is created or found and locked. The line's own statement records the
	 * units, only when it has received and lost exactly the figures the caller expected, which makes a
	 * repeated receipt safe, one that only writes units off included, and has that many on the way.
	 * For a line carrying an order's allocation the allocation in transit is read with a locking read;
	 * the units received join the destination's on_hand, and, when the allocation was found, its
	 * `allocated` and the order line's open allocation there, created or added to; the allocation in
	 * transit shrinks by the units received and lost, and goes once none is left. Each line's ledger
	 * entry, `transfer_in`, names the transfer. Units lost from an order's allocation leave the order
	 * short, and StockTransferShort says so. Then the transfer is received once no line has units on
	 * the way. The destination may be deactivated: what was sent still arrives. A receipt applies
	 * whole or not at all.
	 *
	 * Known limitation: a line whose order line was partly shipped from the destination meets that
	 * order line's posted allocation there, which the allocations' key holds once per order line,
	 * variant and location; the fulfilment of orders decides what such a receipt does.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_not_found` when
	 *                        no transfer has the uuid, or a line is not on it; `stock.transfer_state` when it is not in
	 *                        transit; `stock.transfer_receipt_conflict` when a line has received or lost other figures
	 *                        than the ones expected; `stock.transfer_invalid` when a line is named twice, receives
	 *                        nothing or more than it has on the way; `stock.item_missing` or `stock.location_missing`
	 *                        when the variant's policy or the destination is gone.
	 *
	 * @param array<string, mixed> $input The prepared input: transfer_uuid and lines.
	 * @param Actor                $actor Who receives it.
	 * @return array<string, mixed> The transfer as it is now, keyed by wire name.
	 */
	public function receiveTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$uuid     = (string) $input['transfer_uuid'];
		$receipts = self::receipts( $input['lines'] ?? null );

		return $this->tx->transaction(
			function () use ( $uuid, $receipts, $actor ): array {
				if ( ! $this->transfers->lockReceivable( $uuid ) ) {
					$this->refuseState( $uuid, self::NOT_RECEIVABLE );
				}

				$transfer = $this->document( $uuid );

				foreach ( array_keys( $receipts ) as $lineId ) {
					if ( null === $transfer->line( $lineId ) ) {
						CodedException::raise( InventoryError::TransferNotFound, array( 'transfer_id' => $transfer->uuid ), array( 'line_id' => $lineId ) );
					}
				}

				$arrived = array();
				$short   = array();

				foreach ( $transfer->lines as $line ) {
					if ( isset( $receipts[ $line->id ] ) ) {
						$lost = $this->receiveLine( $transfer, $line, $receipts[ $line->id ], $actor, $arrived );

						if ( null !== $lost ) {
							$short[] = $lost;
						}
					}
				}

				$this->transfers->markReceived( $transfer->id );
				$this->publishMovement( $transfer->id, $transfer->sourceLocationId, $transfer->destinationLocationId, StockTransferred::RECEIVED, $arrived );

				if ( array() !== $short ) {
					$this->events->publish( ...$short );
				}

				return $this->answer( $this->document( $uuid ) );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Performs `inventory.cancel_transfer`: a draft, or a requested transfer that carries no order's units, is cancelled.
	 *
	 * One conditional update, carrying both rules; a transfer in transit is never cancelled, and
	 * one that carries an order's units is detached from them only by the order's cancel.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory; `stock.transfer_not_found` when
	 *                        no transfer has the uuid; `stock.transfer_state` when it was sent, received or cancelled, or
	 *                        carries an order's units.
	 *
	 * @param array<string, mixed> $input The prepared input: transfer_uuid.
	 * @param Actor                $actor Who cancels it.
	 * @return array<string, mixed> The transfer cancelled, keyed by wire name.
	 */
	public function cancelTransfer( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$uuid = (string) $input['transfer_uuid'];

		return $this->tx->transaction(
			function () use ( $uuid ): array {
				$transfer = $this->document( $uuid );

				if ( ! $this->transfers->cancel( $transfer->id ) ) {
					$now     = $this->document( $uuid );
					$carries = in_array( $now->state, array( TransferState::Draft, TransferState::Requested ), true );

					CodedException::raise(
						InventoryError::TransferState,
						array(
							'transfer_id' => $now->uuid,
							'state'       => $now->state->value,
						),
						array( 'reason' => $carries ? self::CARRIES_ORDER : self::NOT_CANCELLABLE )
					);
				}

				return $this->answer( $this->document( $uuid ) );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Requests one transfer per location an order's open allocations are at, other than the order's destination: inside the caller's unit of work.
	 *
	 * For a placement whose units were claimed where they are and must be brought together. The
	 * order's open allocations away from the destination are read on the allocations' order key;
	 * per location, ascending, one requested transfer of kind `order` is written with one line per
	 * allocation, carrying it, and StockTransferRequested is published. Nothing is locked: the
	 * sources hold the order's units, so a deletion of either is refused by what uses it, and the
	 * destination is the order's own location. Four statements for one source, three more for
	 * each further one. It writes nothing of the order's own.
	 *
	 * Outside a transaction it throws \LogicException before any statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the order or the destination is below 1.
	 *
	 * @param int $orderId       The order.
	 * @param int $destinationId The location the order's units are brought to.
	 * @return list<Transfer> The transfers requested, ascending by source, each without its lines and its time, which the database chose.
	 */
	public function requestForOrder( int $orderId, int $destinationId ): array {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( $orderId < 1 || $destinationId < 1 ) {
			throw new \InvalidArgumentException( 'A transfer is requested for an order, to a location.' );
		}

		$bySource = array();

		foreach ( $this->stock->openAllocationsAway( $orderId, $destinationId ) as $allocation ) {
			$bySource[ $allocation['location_id'] ][] = $allocation;
		}

		$requested = array();
		$events    = array();

		foreach ( $bySource as $sourceId => $allocations ) {
			$uuid = $this->ids->generate();
			$id   = $this->transfers->insert( $uuid, $sourceId, $destinationId, TransferKind::Order, TransferState::Requested, $orderId, null, null );

			$this->transfers->insertLines(
				$id,
				array_map(
					static fn( array $allocation ): array => array(
						'variant_id'        => $allocation['variant_id'],
						'quantity'          => $allocation['quantity'],
						'received_quantity' => 0,
						'allocation_id'     => $allocation['id'],
					),
					$allocations
				)
			);

			list( $variantIds, $quantities ) = StockLines::of( array_map( static fn( array $allocation ): array => array( $allocation['variant_id'], $sourceId, $allocation['quantity'] ), $allocations ) );

			$events[]    = new StockTransferRequested( $id, $orderId, $sourceId, $destinationId, $variantIds, $quantities, $this->clock->now() );
			$requested[] = new Transfer( $id, $uuid, $sourceId, $destinationId, TransferKind::Order, $orderId, TransferState::Requested, null, null, null, null, array() );
		}

		if ( array() !== $events ) {
			$this->events->publish( ...$events );
		}

		return $requested;
	}

	/**
	 * Detaches a cancelled order from its stock: inside the caller's unit of work, which holds the order's row.
	 *
	 * The order's transfers requested or in transit are locked first, ascending, then its
	 * allocations are read, and taken item by item, ascending: an open allocation's item is locked,
	 * the allocation cancelled, and, only when that matched, its unposted units given back to the
	 * item's `allocated`; an allocation in transit is cancelled with no stock statement, since its
	 * units are on the transfer's line. Every line that carried one of the order's allocations is
	 * detached: its units go on as units no order waits for, so a transfer in transit completes as a
	 * plain move and its units become available where they arrive. A requested transfer left
	 * carrying nothing is cancelled in the same unit of work, so no one picks for a dead order. A
	 * receipt of the same transfer waits for the transfer's lock, or holds it first, and the cancel
	 * then finds the units it received as an open allocation at the destination, and gives them
	 * back there. It publishes nothing: the order's cancel says what it announces.
	 *
	 * Outside a transaction it throws \LogicException before any statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.projection_corrupt` when an item's `allocated` is below the units an open allocation gives back.
	 *
	 * @param int $orderId The order.
	 */
	public function detachOrder( int $orderId ): void {
		$this->requireCallersTransaction( __FUNCTION__ );

		$locked      = $this->transfers->lockOrderTransfers( $orderId );
		$allocations = $this->stock->orderAllocations( $orderId );
		$carried     = $this->transfers->carriedLines( array_map( static fn( array $transfer ): int => $transfer['id'], $locked ) );
		$detached    = array();

		foreach ( $allocations as $allocation ) {
			if ( $allocation['in_transit'] ) {
				$this->stock->cancelInTransitAllocation( $allocation['id'] );
			} else {
				$this->cancelOpen( $allocation );
			}

			if ( isset( $carried[ $allocation['id'] ] ) ) {
				$detached[] = $carried[ $allocation['id'] ];
			}
		}

		$this->transfers->detachLines( $detached );

		foreach ( $locked as $transfer ) {
			if ( TransferState::Requested === $transfer['state'] ) {
				$this->transfers->cancel( $transfer['id'] );
			}
		}
	}

	/**
	 * Starts, or finds, the move of a location's available units to another as one transfer: inside the deactivation's unit of work, which holds the location's lock.
	 *
	 * The target is read with a locking read in share mode: it must exist, be another location and
	 * be active. A move from the location in transit is the answer when it goes to the same target,
	 * and its job is queued again, which finds nothing to do once one waits or runs; a move in
	 * transit to another target refuses this one. A repeat of a deactivation, the location already
	 * inactive, answers the newest move from it to the same target, received or not. Otherwise one
	 * transfer of kind `deactivation` is written in transit with no line, and its job queued once
	 * this unit of work commits, since nothing is queued inside a transaction. The job sends and
	 * receives the units page by page.
	 *
	 * Two deactivations crossing, each moving to the other's location, may deadlock on the two
	 * locations' rows; the deadlock retry policy runs the second again, and it is then refused,
	 * its target deactivated. Known limitation: a process killed between the commit and the job's
	 * queueing leaves a move in transit with no line and no job; doctor names it once it is older
	 * than the warning period, and repeating the deactivation with the same target queues its job.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` when no location has the target's uuid; `stock.transfer_same_location`
	 *                        when it is the location itself; `stock.location_inactive` when it is deactivated;
	 *                        `stock.transfer_state` when another move from the location is in transit.
	 *
	 * @param LocationRecord $location    The location deactivated, locked.
	 * @param string         $targetUuid  The location its units go to.
	 * @param bool           $wasInactive Whether it was inactive already: a repeat of its deactivation.
	 * @param Actor          $actor       Who deactivates it.
	 * @return string The move's public identifier.
	 */
	public function moveStockOf( LocationRecord $location, string $targetUuid, bool $wasInactive, Actor $actor ): string {
		$this->requireCallersTransaction( __FUNCTION__ );

		$targetUuid = strtolower( $targetUuid );
		$target     = $this->locations->lockShared( array( $targetUuid ) )[ $targetUuid ] ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $targetUuid ) );

		if ( $target->id === $location->id ) {
			CodedException::raise( InventoryError::TransferSameLocation, array( 'location_id' => $targetUuid ) );
		}

		if ( ! $target->active ) {
			CodedException::raise( InventoryError::LocationInactive, array( 'location_id' => $targetUuid ) );
		}

		$moving = $this->transfers->movingFrom( $location->id );

		if ( null !== $moving ) {
			if ( $moving['destination_location_id'] !== $target->id ) {
				CodedException::raise(
					InventoryError::TransferState,
					array(
						'transfer_id' => $moving['uuid'],
						'state'       => TransferState::InTransit->value,
					),
					array( 'reason' => self::ANOTHER_MOVE )
				);
			}

			$this->queueMove( $moving['id'] );

			return $moving['uuid'];
		}

		$latest = $wasInactive ? $this->transfers->latestMove( $location->id, $target->id ) : null;

		if ( null !== $latest ) {
			return $latest['uuid'];
		}

		$uuid = $this->ids->generate();

		$this->queueMove( $this->transfers->insert( $uuid, $location->id, $target->id, TransferKind::Deactivation, TransferState::InTransit, null, null, self::userOf( $actor ) ) );

		return $uuid;
	}

	/**
	 * Sends and receives one page of a deactivation's move, in a unit of work of its own: the job's page.
	 *
	 * The transfer is locked, in transit and of kind deactivation, or there is nothing to do. Its
	 * source's row is read with it in share mode: a location turned on again keeps the stock it still
	 * has, so the page then receives the transfer with the lines it has and moves nothing more. A
	 * reactivation takes the location's row first, so it either waits for a page under way or is seen
	 * by the next. The transfer's last line says how far the move came, so a page that was rolled
	 * back, by a crash or a kill, is sent again from there. The source's variants with units available
	 * after that are listed with a plain read, and each is decided again under its locks, ascending by
	 * variant: the variant's policy and the destination's row in share mode, then its two items in
	 * ascending order of location, the source's level read under its lock. Its available units only
	 * leave the source, allocated and held ones staying, and arrive at the destination, whose item is
	 * created when it has none; the line is written sent and received at once, and the two ledger
	 * entries name the transfer. A page that lists fewer variants than its size is the last: the
	 * transfer is received. One StockTransferred is published per phase of a page that moved units.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The move.
	 * @param int $after      Only variants after this one: where the previous page of the same run ended, or 0.
	 * @param int $limit      Optional. The most variants a page lists. Default MOVE_PAGE.
	 * @return array{done: bool, after: int} Whether the move is over, and the last variant this page listed.
	 */
	public function movePage( int $transferId, int $after, int $limit = self::MOVE_PAGE ): array {
		return $this->tx->transaction(
			function () use ( $transferId, $after, $limit ): array {
				$move = $this->transfers->lockMoving( $transferId ) ? $this->transfers->moveOf( $transferId ) : null;

				if ( null === $move ) {
					return array(
						'done'  => true,
						'after' => $after,
					);
				}

				// The location was turned on again: it keeps the stock still there, and the move ends with what it moved.
				if ( $move['source_active'] ) {
					return array(
						'done'  => $this->transfers->markReceived( $transferId ),
						'after' => $after,
					);
				}

				$after    = max( $after, $this->transfers->lastLineVariant( $transferId ) );
				$variants = $this->stock->movableItems( $move['source_location_id'], $after, $limit );
				$actor    = Actor::system( self::MOVE_JOB, $move['created_by'] ?? 0 );
				$lines    = array();

				foreach ( $variants as $variantId ) {
					$units = $this->moveItem( $transferId, $variantId, $move['source_location_id'], $move['destination_location_id'], $actor );

					if ( $units > 0 ) {
						$lines[] = array(
							'variant_id'        => $variantId,
							'quantity'          => $units,
							'received_quantity' => $units,
							'allocation_id'     => null,
						);
					}
				}

				$this->transfers->insertLines( $transferId, $lines );

				$done = count( $variants ) < $limit && $this->transfers->markReceived( $transferId );

				$moved = array_map( static fn( array $line ): array => array( $line['variant_id'], $move['source_location_id'], $line['quantity'] ), $lines );

				$this->publishMovement( $transferId, $move['source_location_id'], $move['destination_location_id'], StockTransferred::SENT, $moved );
				$this->publishMovement( $transferId, $move['source_location_id'], $move['destination_location_id'], StockTransferred::RECEIVED, $moved );

				return array(
					'done'  => $done,
					'after' => array() === $variants ? $after : (int) end( $variants ),
				);
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Queues the job of a deactivation's move once the unit of work commits: a rolled-back attempt queues nothing.
	 *
	 * The job's unique key is the transfer's, so queueing it while one waits or runs adds nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The move.
	 */
	private function queueMove( int $transferId ): void {
		$this->tx->afterCommit( fn(): bool => $this->queue->enqueue( new Job( self::MOVE_JOB, array( 'transfer_id' => $transferId ), 'transfer:' . $transferId ) ) );
	}

	/**
	 * Sends one line of a transfer from its source, with its ledger entry, under the item's lock.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_refused` when the source lacks the line's units, or its allocation is not open there.
	 *
	 * @param Transfer     $transfer The transfer, locked.
	 * @param TransferLine $line     The line.
	 * @param Actor        $actor    Who sends it.
	 */
	private function sendLine( Transfer $transfer, TransferLine $line, Actor $actor ): void {
		$source = $transfer->sourceLocationId;
		$sent   = null === $line->allocationId
			? $this->stock->transferOutAvailable( $line->variantId, $source, $line->quantity )
			: $this->stock->transferOutAllocated( $line->variantId, $source, $line->quantity ) && $this->stock->allocationInTransit( $line->allocationId, $source );

		// A diagnostic read under the item's lock when refused; otherwise the on_hand the ledger entry records.
		$level = $this->stock->lockedLevel( $line->variantId, $source );

		if ( ! $sent || null === $level ) {
			$available = null === $level ? 0 : ( null === $line->allocationId ? $level->available() : min( $level->onHand, $level->allocated ) );

			CodedException::raise(
				InventoryError::TransferRefused,
				array(
					'variant_id' => $line->variantId,
					'requested'  => $line->quantity,
					'available'  => max( 0, $available ),
				),
				array( 'location_id' => $this->directory->find( $source )?->uuid )
			);
		}

		$this->record( $line->variantId, $source, -$line->quantity, $level->onHand, LedgerReason::TransferOut, $actor, $transfer->id );
	}

	/**
	 * Receives one line of a transfer at its destination: the units it receives and writes off, with the allocation they travel with.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException As receiveTransfer().
	 *
	 * @param Transfer                                                                    $transfer The transfer, locked.
	 * @param TransferLine                                                                $line     The line, as read under the lock.
	 * @param array{received: int, lost: int, expected_received: int, expected_lost: int} $receipt  What arrived, what was lost, and the figures the caller read.
	 * @param Actor                                                                       $actor    Who receives it.
	 * @param list<array{0: int, 1: int, 2: int}>                                         $arrived  The units arrived so far, as event lines; this line's are added.
	 * @return StockTransferShort|null The shortage the line leaves an order with, or null.
	 */
	private function receiveLine( Transfer $transfer, TransferLine $line, array $receipt, Actor $actor, array &$arrived ): ?StockTransferShort {
		$destination = $transfer->destinationLocationId;
		$received    = $receipt['received'];
		$lost        = $receipt['lost'];

		if ( $received > 0 ) {
			$this->requireAdjustable( $line->variantId, $destination );
			$this->stock->createItemAt( $line->variantId, $destination );
		}

		if ( ! $this->transfers->receiveLine( $line->id, $transfer->id, $receipt['expected_received'], $receipt['expected_lost'], $received, $lost ) ) {
			if ( $line->receivedQuantity !== $receipt['expected_received'] || $line->lostQuantity !== $receipt['expected_lost'] ) {
				CodedException::raise(
					InventoryError::TransferReceiptConflict,
					array(
						'line_id'           => $line->id,
						'expected'          => $receipt['expected_received'],
						'expected_lost'     => $receipt['expected_lost'],
						'received_quantity' => $line->receivedQuantity,
						'lost_quantity'     => $line->lostQuantity,
					)
				);
			}

			CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
		}

		$carried = null === $line->allocationId ? null : $this->stock->lockInTransitAllocation( $line->allocationId );

		if ( $received > 0 ) {
			$this->stock->transferIn( $line->variantId, $destination, $received, null === $carried ? 0 : $received );
			$this->record( $line->variantId, $destination, $received, $this->lockedLevel( $line->variantId, $destination )->onHand, LedgerReason::TransferIn, $actor, $transfer->id );

			if ( null !== $carried ) {
				$this->stock->allocateReceived( $line->variantId, $destination, $carried['order_id'], $carried['order_line_id'], $received );
			}

			$arrived[] = array( $line->variantId, $destination, $received );
		}

		if ( null === $carried ) {
			return null;
		}

		$left = $carried['quantity'] === $received + $lost
			? $this->stock->deleteInTransit( $line->allocationId, $received + $lost )
			: $this->stock->shrinkInTransit( $line->allocationId, $received + $lost );

		if ( ! $left ) {
			// The allocation in transit holds fewer units than its line has on the way, which only a change outside this code can make.
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $line->variantId ) );
		}

		return $lost > 0 ? new StockTransferShort( $carried['order_id'], $transfer->id, $line->id, $line->variantId, $lost, $this->clock->now() ) : null;
	}

	/**
	 * Moves one variant's available units from a deactivated location to its target, under both items' locks: a step of a page.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` when the destination is gone; `stock.projection_corrupt` when the
	 *                        source's update refuses the units read under its lock.
	 *
	 * @param int   $transferId    The move, locked.
	 * @param int   $variantId     The variant.
	 * @param int   $sourceId      The location deactivated.
	 * @param int   $destinationId The location its units go to.
	 * @param Actor $actor         On whose authority.
	 * @return int The units moved; 0 when none was available under the lock, or the variant's policy is gone with its stock.
	 */
	private function moveItem( int $transferId, int $variantId, int $sourceId, int $destinationId, Actor $actor ): int {
		$found = $this->stock->lockAdjustable( $variantId, $destinationId );

		if ( ! $found['location'] && $found['policy'] ) {
			CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => (string) $destinationId ) );
		}

		if ( ! $found['policy'] ) {
			return 0;
		}

		// The two items in ascending order of location: the destination first when its id is lower.
		if ( $destinationId < $sourceId ) {
			$this->stock->createItemAt( $variantId, $destinationId );
		}

		$level = $this->stock->lockedLevel( $variantId, $sourceId );

		if ( null === $level || $level->available() < 1 ) {
			return 0;
		}

		$units = $level->available();

		if ( ! $this->stock->transferOutAvailable( $variantId, $sourceId, $units ) ) {
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $variantId ) );
		}

		if ( $destinationId > $sourceId ) {
			$this->stock->createItemAt( $variantId, $destinationId );
		}

		$this->stock->transferIn( $variantId, $destinationId, $units, 0 );
		$this->record( $variantId, $sourceId, -$units, $level->onHand - $units, LedgerReason::TransferOut, $actor, $transferId );
		$this->record( $variantId, $destinationId, $units, $this->lockedLevel( $variantId, $destinationId )->onHand, LedgerReason::TransferIn, $actor, $transferId );

		return $units;
	}

	/**
	 * Cancels one open allocation of an order under its item's lock, and gives its unposted units back.
	 *
	 * An allocation whose item is gone, which doctor reports, is cancelled with nothing to give back.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.projection_corrupt` when the item's `allocated` is below the units.
	 *
	 * @param array{id: int, variant_id: int, location_id: int, quantity: int, posted_quantity: int, in_transit: bool} $allocation The allocation, as read.
	 */
	private function cancelOpen( array $allocation ): void {
		$found = $this->stock->lockItem( $allocation['variant_id'], $allocation['location_id'] );
		$units = $allocation['quantity'] - $allocation['posted_quantity'];

		if ( $this->stock->cancelOpenAllocation( $allocation['id'] ) && $found && $units > 0 && ! $this->stock->giveBackAllocated( $allocation['variant_id'], $allocation['location_id'], $units ) ) {
			CodedException::raise( InventoryError::ProjectionCorrupt, array( 'variant_id' => $allocation['variant_id'] ) );
		}
	}

	/**
	 * Appends a movement's ledger entry, naming its transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param int          $variantId   The item's variant.
	 * @param int          $locationId  The item's location.
	 * @param int          $delta       The change of on_hand.
	 * @param int          $onHandAfter on_hand after it, read or decided under the item's lock.
	 * @param LedgerReason $reason      `transfer_out` or `transfer_in`.
	 * @param Actor        $actor       On whose authority.
	 * @param int          $transferId  The transfer.
	 */
	private function record( int $variantId, int $locationId, int $delta, int $onHandAfter, LedgerReason $reason, Actor $actor, int $transferId ): void {
		$this->stock->appendLedger( $variantId, $locationId, $delta, $onHandAfter, $reason, null === $actor->systemName() ? 'user' : 'system', self::userOf( $actor ), $this->correlation->current(), $transferId );
	}

	/**
	 * Publishes one movement of a transfer, when it moved units.
	 *
	 * @since 0.2.0
	 *
	 * @param int                                 $transferId    The transfer.
	 * @param int                                 $sourceId      The location its units leave.
	 * @param int                                 $destinationId The location they arrive at.
	 * @param string                              $phase         StockTransferred::SENT or RECEIVED.
	 * @param list<array{0: int, 1: int, 2: int}> $moved         Each line's variant, location and units; none publishes nothing.
	 */
	private function publishMovement( int $transferId, int $sourceId, int $destinationId, string $phase, array $moved ): void {
		if ( array() === $moved ) {
			return;
		}

		list( $variantIds, $quantities ) = StockLines::of( $moved );

		$this->events->publish( new StockTransferred( $transferId, $sourceId, $destinationId, $phase, $variantIds, $quantities, $this->clock->now() ) );
	}

	/**
	 * Refuses a variant without a policy, or a location that is gone, keeping both until the commit: a receipt's first locks of a line.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.item_missing` or `stock.location_missing`.
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 */
	private function requireAdjustable( int $variantId, int $locationId ): void {
		$found = $this->stock->lockAdjustable( $variantId, $locationId );

		if ( ! $found['policy'] ) {
			CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
		}

		if ( ! $found['location'] ) {
			CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $this->directory->find( $locationId )->uuid ?? (string) $locationId ) );
		}
	}

	/**
	 * Refuses variants without a stock policy: one plain read of the policies.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.item_missing` for the first, ascending.
	 *
	 * @param int[] $variantIds The variants, ascending.
	 */
	private function requirePolicies( array $variantIds ): void {
		if ( array() === $variantIds ) {
			return;
		}

		// The read every hold starts with; the expiry it also computes is not used.
		$policies = $this->stock->configuration( $variantIds, 1 )['policies'];

		foreach ( $variantIds as $variantId ) {
			if ( ! isset( $policies[ $variantId ] ) ) {
				CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
			}
		}
	}

	/**
	 * Reads an item's level under the lock the caller holds.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.item_missing` when it is gone, which the lock rules out.
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return \SEOCart\Inventory\Domain\StockLevel The level.
	 */
	private function lockedLevel( int $variantId, int $locationId ): \SEOCart\Inventory\Domain\StockLevel {
		return $this->stock->lockedLevel( $variantId, $locationId ) ?? CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
	}

	/**
	 * Raises the refusal of a transfer's state, from one read of it under the lock the refused statement did not take.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_not_found` when no transfer has the uuid; `stock.transfer_state` otherwise, always.
	 *
	 * @param string $uuid   The transfer's uuid.
	 * @param string $reason Why the operation needs another state.
	 * @return never
	 */
	private function refuseState( string $uuid, string $reason ): never {
		$transfer = $this->document( $uuid );

		CodedException::raise(
			InventoryError::TransferState,
			array(
				'transfer_id' => $transfer->uuid,
				'state'       => $transfer->state->value,
			),
			array( 'reason' => $reason )
		);
	}

	/**
	 * Reads a transfer with its lines.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_not_found` when no transfer has the uuid.
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return Transfer The transfer.
	 */
	private function document( string $uuid ): Transfer {
		return $this->transfers->document( $uuid ) ?? CodedException::raise( InventoryError::TransferNotFound, array( 'transfer_id' => $uuid ) );
	}

	/**
	 * Finds a location a client names.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` when no location has the uuid.
	 *
	 * @param string $uuid The location's uuid.
	 * @return Location The location.
	 */
	private function location( string $uuid ): Location {
		return $this->directory->findByUuid( $uuid ) ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $uuid ) );
	}

	/**
	 * Returns a transfer with its lines by wire name, as every operation that answers with one does.
	 *
	 * @since 0.2.0
	 *
	 * @param Transfer $transfer The transfer, read whole.
	 * @return array<string, mixed> The transfer's fields, keyed by wire name.
	 */
	private function answer( Transfer $transfer ): array {
		$moving = TransferState::InTransit === $transfer->state;

		return $this->header( $transfer ) + array(
			'lines' => array_map(
				static fn( TransferLine $line ): array => array(
					'line_id'           => $line->id,
					'variant_id'        => $line->variantId,
					'quantity'          => $line->quantity,
					'received_quantity' => $line->receivedQuantity,
					'lost_quantity'     => $line->lostQuantity,
					'in_transit'        => $moving ? $line->remaining() : 0,
					'allocated'         => $line->isAllocated(),
				),
				$transfer->lines
			),
		);
	}

	/**
	 * Returns what both a list and a whole transfer answer of a transfer, by wire name.
	 *
	 * @since 0.2.0
	 *
	 * @param Transfer $transfer The transfer.
	 * @return array<string, mixed> The fields.
	 */
	private function header( Transfer $transfer ): array {
		return array(
			'transfer_uuid'             => $transfer->uuid,
			'kind'                      => $transfer->kind->value,
			'state'                     => $transfer->state->value,
			'source_location_uuid'      => $this->directory->find( $transfer->sourceLocationId )?->uuid,
			'destination_location_uuid' => $this->directory->find( $transfer->destinationLocationId )?->uuid,
			'order_id'                  => $transfer->orderId,
			'note'                      => $transfer->note,
			'created_at'                => self::time( $transfer->createdAt ),
			'sent_at'                   => self::time( $transfer->sentAt ),
			'received_at'               => self::time( $transfer->receivedAt ),
		);
	}

	/**
	 * Refuses a call that must run inside the caller's transaction, before any statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException At depth 0.
	 *
	 * @param string $method The method called.
	 */
	private function requireCallersTransaction( string $method ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'Transfers::%s() runs inside the caller\'s transaction: what it writes belongs with the order or the location the caller changes.', $method ) );
		}
	}

	/**
	 * Checks a transfer's new lines, before any statement, and adds up those of one variant.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_invalid` for the field `lines` when a line is not a variant and its units, or there are fewer than $fewest or more than TransferOperations::MOST_LINES.
	 *
	 * @param mixed $lines  The lines, as the schema prepared them.
	 * @param int   $fewest The fewest lines allowed: 1 for a new transfer, 0 for an edit that keeps others.
	 * @return array<int, int> The units of each variant, by variant id, ascending.
	 */
	private static function newLines( mixed $lines, int $fewest ): array {
		$units = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$variantId = is_array( $line ) ? ( $line['variant_id'] ?? null ) : null;
			$quantity  = is_array( $line ) ? ( $line['quantity'] ?? null ) : null;

			if ( ! is_int( $variantId ) || ! is_int( $quantity ) || $variantId < 1 || $quantity < 1 ) {
				CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
			}

			$units[ $variantId ] = ( $units[ $variantId ] ?? 0 ) + $quantity;
		}

		if ( count( $units ) < $fewest || count( $units ) > TransferOperations::MOST_LINES ) {
			CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
		}

		ksort( $units );

		return $units;
	}

	/**
	 * Returns new lines as the repository writes them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $lines The units of each variant.
	 * @return list<array{variant_id: int, quantity: int, received_quantity: int, allocation_id: int|null}> The rows.
	 */
	private static function rows( array $lines ): array {
		$rows = array();

		foreach ( $lines as $variantId => $quantity ) {
			$rows[] = array(
				'variant_id'        => $variantId,
				'quantity'          => $quantity,
				'received_quantity' => 0,
				'allocation_id'     => null,
			);
		}

		return $rows;
	}

	/**
	 * Checks a receipt, before any statement: each line once, each receiving or losing at least one unit.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_invalid` for the field `lines` when a line is named twice, is not a line id with its figures, or receives and loses nothing.
	 *
	 * @param mixed $lines The receipt's lines, as the schema prepared them.
	 * @return array<int, array{received: int, lost: int, expected_received: int, expected_lost: int}> What each line receives and loses, and the figures it expects, by line id.
	 */
	private static function receipts( mixed $lines ): array {
		$receipts = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$lineId           = is_array( $line ) ? ( $line['line_id'] ?? null ) : null;
			$received         = is_array( $line ) ? ( $line['received'] ?? 0 ) : null;
			$lost             = is_array( $line ) ? ( $line['lost'] ?? 0 ) : null;
			$expectedReceived = is_array( $line ) ? ( $line['expected_received_quantity'] ?? null ) : null;
			$expectedLost     = is_array( $line ) ? ( $line['expected_lost_quantity'] ?? null ) : null;

			if ( ! is_int( $lineId ) || ! is_int( $received ) || ! is_int( $lost ) || ! is_int( $expectedReceived ) || ! is_int( $expectedLost ) || $lineId < 1 || $received < 0 || $lost < 0 || $expectedReceived < 0 || $expectedLost < 0 || $received + $lost < 1 || isset( $receipts[ $lineId ] ) ) {
				CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
			}

			$receipts[ $lineId ] = array(
				'received'          => $received,
				'lost'              => $lost,
				'expected_received' => $expectedReceived,
				'expected_lost'     => $expectedLost,
			);
		}

		if ( array() === $receipts ) {
			CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'lines' ) );
		}

		return $receipts;
	}

	/**
	 * Checks a note: trimmed, text of several lines, and an empty one removes the note.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.transfer_invalid` for the field `note` when it holds a control character.
	 *
	 * @param mixed $value The note, a string after the schema's sanitizing.
	 * @return string|null The note, or null for none.
	 */
	private static function note( mixed $value ): ?string {
		$note = trim( is_string( $value ) ? $value : '' );

		if ( ! MerchantText::isText( $note ) ) {
			CodedException::raise( InventoryError::TransferInvalid, array( 'field' => 'note' ) );
		}

		return '' === $note ? null : $note;
	}

	/**
	 * Returns the user an actor acts for, or null for none.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return int|null The user id, or null.
	 */
	private static function userOf( Actor $actor ): ?int {
		return $actor->userId() > 0 ? $actor->userId() : null;
	}

	/**
	 * Writes a database time as an answer gives it: UTC, ISO 8601, to the microsecond.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $time The time, `Y-m-d H:i:s.u`, or null.
	 * @return string|null The time, such as `2026-10-25T14:03:27.512345Z`, or null.
	 */
	private static function time( ?string $time ): ?string {
		return null === $time ? null : str_replace( ' ', 'T', $time ) . 'Z';
	}
}
