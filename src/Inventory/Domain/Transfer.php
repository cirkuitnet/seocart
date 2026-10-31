<?php
/**
 * Transfer: a movement of units from one location to another, as one document
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One transfer: its two locations, its kind and state, the order it serves, its note, its times and its lines.
 *
 * Owns one fact: what a transfer is when it is read. One source and one destination per document,
 * so a merchant sends one parcel per transfer. The times are UTC, from the database clock, as
 * `Y-m-d H:i:s.u`. Who created it is part of its record and never part of what it answers.
 *
 * @since 0.2.0
 */
final readonly class Transfer {

	/**
	 * Records a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param int            $id                    The id its lines and ledger entries name.
	 * @param string         $uuid                  The public identifier.
	 * @param int            $sourceLocationId      The location the units leave.
	 * @param int            $destinationLocationId The location the units arrive at.
	 * @param TransferKind   $kind                  Who started it.
	 * @param int|null       $orderId               The order that waits for it, for one of kind order.
	 * @param TransferState  $state                 Where it stands.
	 * @param string|null    $note                  The merchant's note, or null for none.
	 * @param string|null    $createdAt             When it was created; null for one just written in this unit of work, whose time the database chose.
	 * @param string|null    $sentAt                When its units left, or null until then.
	 * @param string|null    $receivedAt            When its last units were received or written off, or null until then.
	 * @param TransferLine[] $lines                 Its lines, ascending by variant, when read whole; none in a list, or for one just written.
	 *
	 * @phpstan-param list<TransferLine> $lines
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public int $sourceLocationId,
		public int $destinationLocationId,
		public TransferKind $kind,
		public ?int $orderId,
		public TransferState $state,
		public ?string $note,
		public ?string $createdAt,
		public ?string $sentAt,
		public ?string $receivedAt,
		public array $lines
	) {
	}

	/**
	 * Finds one of its lines.
	 *
	 * @since 0.2.0
	 *
	 * @param int $lineId The line's id.
	 * @return TransferLine|null The line, or null when the transfer has no such line.
	 */
	public function line( int $lineId ): ?TransferLine {
		foreach ( $this->lines as $line ) {
			if ( $line->id === $lineId ) {
				return $line;
			}
		}

		return null;
	}
}
