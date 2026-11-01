<?php
/**
 * RoutingProposal: where an order's units are to be held, as the calculation quoted it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Quote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name values built by code, for the developer; they are never rendered.

/**
 * A fulfilment plan as a quoted fact: the units of each variant at each location, the one location the order is fulfilled from, and whether units must travel there.
 *
 * Owns one fact: the plan a calculation was quoted with. Its destination is the origin of the
 * shipping and tax quotes; placement holds its lines, one per variant and location, where the
 * units are; the order records its destination and the days a transfer was said to add. The
 * lines are kept ascending by variant then location, so two proposals of the same plan have the
 * same fingerprint.
 *
 * @since 0.2.0
 */
final readonly class RoutingProposal {

	/**
	 * The units to hold, one line per variant and location, ascending by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{variant_id: int, location_id: int, quantity: int}>
	 */
	public array $lines;

	/**
	 * Holds a proposal.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When there is no line, a line repeats a variant at a location or
	 *                                   has a figure below 1, or the lead days are given for a plan that
	 *                                   needs no transfer, or left out of one that does.
	 *
	 * @param array    $lines                   The units to hold, each with its location, in any order.
	 * @param int      $destinationLocationId   The one location the order is fulfilled from.
	 * @param string   $destinationLocationUuid Its public identifier.
	 * @param bool     $requiresTransfer        Whether units held elsewhere must be brought to it before the order ships.
	 * @param bool     $split                   Whether the order ships from more than one location.
	 * @param string   $reason                  The rule that made the plan: pickup, whole_order, split or transfer.
	 * @param int|null $transferLeadDays        The extra days the shopper is told a transfer adds, when the plan
	 *                                          needs one; null when it does not.
	 *
	 * @phpstan-param list<array{variant_id: int, location_id: int, quantity: int}> $lines
	 */
	public function __construct(
		array $lines,
		public int $destinationLocationId,
		public string $destinationLocationUuid,
		public bool $requiresTransfer,
		public bool $split,
		public string $reason,
		public ?int $transferLeadDays
	) {
		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'A routing proposal holds at least one line.' );
		}

		$namesDays = null !== $transferLeadDays;

		if ( $namesDays !== $requiresTransfer ) {
			throw new \InvalidArgumentException( 'A routing proposal names the lead days exactly when its plan needs a transfer.' );
		}

		usort( $lines, static fn( array $a, array $b ): int => array( $a['variant_id'], $a['location_id'] ) <=> array( $b['variant_id'], $b['location_id'] ) );

		$seen = array();

		foreach ( $lines as $line ) {
			$row = $line['variant_id'] . ':' . $line['location_id'];

			if ( isset( $seen[ $row ] ) || min( $line['variant_id'], $line['location_id'], $line['quantity'] ) < 1 ) {
				throw new \InvalidArgumentException( sprintf( 'A routing proposal holds each variant once per location, with ids and a quantity of at least 1; the line for variant %d at location %d is not one.', $line['variant_id'], $line['location_id'] ) );
			}

			$seen[ $row ] = true;
		}

		$this->lines = $lines;
	}

	/**
	 * Returns the location the order's shipping and tax are quoted from: its destination.
	 *
	 * @since 0.2.0
	 *
	 * @return int The location's id.
	 */
	public function originLocationId(): int {
		return $this->destinationLocationId;
	}

	/**
	 * Tells whether any line is held at the destination.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False when every unit is brought to the destination from elsewhere.
	 */
	public function holdsAtDestination(): bool {
		foreach ( $this->lines as $line ) {
			if ( $line['location_id'] === $this->destinationLocationId ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the fingerprint of the proposal's lines: two proposals that hold the same units at the same locations have the same one.
	 *
	 * @since 0.2.0
	 *
	 * @return string The SHA-256, in lower-case hexadecimal.
	 */
	public function fingerprint(): string {
		return hash( 'sha256', implode( "\n", array_map( static fn( array $line ): string => $line['variant_id'] . "\t" . $line['location_id'] . "\t" . $line['quantity'], $this->lines ) ) );
	}

	/**
	 * Returns the proposal as a calculation's trace records it: scalars, and the lines as three lists of the same length.
	 *
	 * @since 0.2.0
	 *
	 * @return array{destination_location_id: int, destination_location_uuid: string, requires_transfer: bool, split: bool, reason: string, transfer_lead_days: int|null, variant_ids: list<int>, location_ids: list<int>, quantities: list<int>} The proposal.
	 */
	public function toArray(): array {
		return array(
			'destination_location_id'   => $this->destinationLocationId,
			'destination_location_uuid' => $this->destinationLocationUuid,
			'requires_transfer'         => $this->requiresTransfer,
			'split'                     => $this->split,
			'reason'                    => $this->reason,
			'transfer_lead_days'        => $this->transferLeadDays,
			'variant_ids'               => array_column( $this->lines, 'variant_id' ),
			'location_ids'              => array_column( $this->lines, 'location_id' ),
			'quantities'                => array_column( $this->lines, 'quantity' ),
		);
	}

	/**
	 * Reads a proposal back from what toArray() wrote; other keys, such as a trace entry's `record`, are ignored.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the lists differ in length, or the constructor refuses the proposal.
	 *
	 * @param array<string, mixed> $data The proposal, as toArray() wrote it.
	 * @return self The proposal.
	 */
	public static function fromArray( array $data ): self {
		$variants   = array_values( (array) $data['variant_ids'] );
		$locations  = array_values( (array) $data['location_ids'] );
		$quantities = array_values( (array) $data['quantities'] );

		if ( count( $variants ) !== count( $locations ) || count( $variants ) !== count( $quantities ) ) {
			throw new \InvalidArgumentException( 'A routing proposal\'s lists of variants, locations and quantities have the same length.' );
		}

		$lines = array();

		foreach ( $variants as $index => $variantId ) {
			$lines[] = array(
				'variant_id'  => (int) $variantId,
				'location_id' => (int) $locations[ $index ],
				'quantity'    => (int) $quantities[ $index ],
			);
		}

		return new self(
			$lines,
			(int) $data['destination_location_id'],
			(string) $data['destination_location_uuid'],
			(bool) $data['requires_transfer'],
			(bool) $data['split'],
			(string) $data['reason'],
			null === $data['transfer_lead_days'] ? null : (int) $data['transfer_lead_days']
		);
	}
}
