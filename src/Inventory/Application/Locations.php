<?php
/**
 * Locations: lists, creates, changes, deactivates and deletes the store's locations
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\LocationDetails;
use SEOCart\Inventory\Domain\LocationInUse;
use SEOCart\Inventory\Domain\LocationRecord;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Inventory\Domain\MerchantText;
use SEOCart\Inventory\Domain\PickupHours;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * The location operations' service: every change of a location's record goes through it.
 *
 * Owns one fact: the rules a location's record changes by. A new location is active, and the
 * store's first becomes its default. The default stays active and stays the default until another
 * location is made the default, and only an active location can be made it. Deactivating stops
 * orders being routed to a location at once and keeps its stock where it is. A location is
 * deleted only while nothing refers to it: no ledger entry, no item with units, no hold, no
 * allocation, no transfer, and not the default; its items that count nothing go with it.
 *
 * Each change is one unit of work at READ COMMITTED, as every stock-writing unit is, with the
 * deadlock retry policy, and begins with a locking read of the location's row, so two changes of
 * one location, or a change and an adjustment there, happen one after the other: an adjustment
 * takes the same row's shared lock before it creates or changes an item at the location. An
 * update changes only the fields it is given, checked before any statement; one that turns a
 * location off goes through the same rule as a deactivation. Moving the default is the
 * repository's moveDefault(), whose writes no plain read precedes.
 *
 * Every operation authorizes its actor itself: the reads with the capability that edits stock,
 * so whoever edits a location's stock can list the locations, and the changes with the one that
 * edits the store's settings. A deactivation that also moves the location's stock asks for both,
 * since it moves stock as a transfer does.
 *
 * @since 0.2.0
 *
 * @phpstan-type LocationChanges array{name?: string, address?: Address, phone?: string|null, fulfils_online?: bool, offers_pickup?: bool, pickup_ready_in?: string, pickup_instructions?: string|null, priority?: int}
 */
final class Locations {

	/**
	 * The address fields other than the country, each one line of text.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const ADDRESS_LINES = array( 'line1', 'line2', 'city', 'region', 'postcode' );

	/**
	 * A character no line of text holds: a control character, line breaks and tabs included.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOT_IN_A_LINE = '/[\x00-\x1F\x7F]/';


	/**
	 * The locations.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationRepository
	 */
	private LocationRepository $locations;

	/**
	 * The stock statements: what each location has, and what refers to it.
	 *
	 * @since 0.2.0
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock;

	/**
	 * The unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Mints a new location's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * The capability check of the operations this service performs.
	 *
	 * @since 0.2.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Moves a deactivated location's stock, as one transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var Transfers
	 */
	private Transfers $transfers;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param LocationRepository $locations  The locations.
	 * @param StockRepository    $stock      The stock statements.
	 * @param TransactionManager $tx         The unit of work.
	 * @param IdGenerator        $ids        Mints a new location's public identifier.
	 * @param Authorizer         $authorizer The capability check of the operations it performs.
	 * @param Transfers          $transfers  Moves a deactivated location's stock.
	 */
	public function __construct( LocationRepository $locations, StockRepository $stock, TransactionManager $tx, IdGenerator $ids, Authorizer $authorizer, Transfers $transfers ) {
		$this->locations  = $locations;
		$this->stock      = $stock;
		$this->tx         = $tx;
		$this->ids        = $ids;
		$this->authorizer = $authorizer;
		$this->transfers  = $transfers;
	}

	/**
	 * Performs `inventory.list_locations`: every location, active or not, with its units on hand and its open allocations.
	 *
	 * Four plain reads: the records, their pickup hours, and the two grouped totals.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage inventory.
	 *
	 * @param array<string, mixed> $input The prepared input: none.
	 * @param Actor                $actor Who reads.
	 * @return array{locations: list<array<string, mixed>>} The locations, in the order they are tried, keyed by wire name.
	 */
	public function listLocations( array $input, Actor $actor ): array {
		unset( $input );

		$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

		$records = $this->locations->records();
		$totals  = $this->stock->locationTotals( array_map( static fn( LocationRecord $record ): int => $record->id, $records ) );
		$listed  = array();

		foreach ( $records as $record ) {
			$listed[] = self::answer( $record ) + array(
				'units_on_hand'    => $totals[ $record->id ]['on_hand'] ?? 0,
				'open_allocations' => $totals[ $record->id ]['open_allocations'] ?? 0,
			);
		}

		return array( 'locations' => $listed );
	}

	/**
	 * Performs `inventory.create_location`: a new active location, the store's default when it is its first.
	 *
	 * The fields are checked before any statement. The first location of a store is read for with a
	 * plain read inside the unit of work; two first locations created at the same moment cannot
	 * both become the default, because the unique key on the marker refuses the second.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage the store's settings;
	 *                        `stock.location_invalid` when a field is not valid.
	 *
	 * @param array<string, mixed> $input The prepared input: the name, and optionally the other fields.
	 * @param Actor                $actor Who creates it.
	 * @return array<string, mixed> The location, keyed by wire name.
	 */
	public function createLocation( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, LocationOperations::CAPABILITY );

		$details = self::merged( new LocationDetails( '', null, null, true, false, null, null, 0 ), self::changes( $input ) );
		$hours   = self::hours( $input );
		$uuid    = $this->ids->generate();

		$record = $this->tx->transaction(
			function () use ( $uuid, $details, $hours ): LocationRecord {
				$isDefault = ! $this->locations->any();
				$id        = $this->locations->insert( $uuid, $details, $isDefault );

				if ( null !== $hours ) {
					$this->locations->replaceHours( $id, $hours );
				}

				return new LocationRecord( $id, $uuid, true, $isDefault, $details, $hours ?? array() );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);

		return self::answer( $record );
	}

	/**
	 * Performs `inventory.update_location`: changes the fields given, and only those.
	 *
	 * The fields are checked before any statement; then, under the location's lock, the refusals
	 * come before the writes: the default cannot stop being it, an inactive location cannot become
	 * it, and turning a location off goes through deactivate(). Turning one on again ends a move of
	 * its stock under way: the move's next page reads the location active and receives the move
	 * with what it moved. Pickup hours, when given, replace the location's seven days. Making the
	 * location the default moves the marker last, in the same unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage the store's settings;
	 *                        `stock.location_invalid` when a field is not valid; `stock.location_missing` when no
	 *                        location has the uuid; `stock.location_is_default` when the default would be
	 *                        deactivated or stop being the default; `stock.location_inactive` when an inactive
	 *                        location would become the default.
	 *
	 * @param array<string, mixed> $input The prepared input: location_uuid, and the fields to change.
	 * @param Actor                $actor Who changes it.
	 * @return array<string, mixed> The location as it is now, keyed by wire name.
	 */
	public function updateLocation( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, LocationOperations::CAPABILITY );

		$changes   = self::changes( $input );
		$hours     = self::hours( $input );
		$active    = isset( $input['active'] ) ? (bool) $input['active'] : null;
		$isDefault = isset( $input['is_default'] ) ? (bool) $input['is_default'] : null;
		$uuid      = (string) $input['location_uuid'];

		$record = $this->tx->transaction(
			fn(): LocationRecord => $this->update( $uuid, $changes, $hours, $active, $isDefault ),
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);

		return self::answer( $record );
	}

	/**
	 * Performs `inventory.deactivate_location`: no order is routed to the location from now on, and its stock stays where it is, or its available units move to another location.
	 *
	 * Deactivating a location that is inactive already changes nothing and answers the same. A
	 * location to move the stock to also needs the capability that manages inventory, checked
	 * before any statement, so a refusal changes nothing. In the same unit of work as the
	 * deactivation, under the location's lock, Transfers::moveStockOf() starts the move, one
	 * transfer worked page by page by a background job, or finds the one under way: the answer
	 * names it, and a repeat with the same target answers the same move.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage the store's settings, or, to move its
	 *                        stock, inventory; `stock.location_missing` when no location has the uuid, or the target's;
	 *                        `stock.location_is_default` when it is the default location; `stock.transfer_same_location`
	 *                        when the target is the location itself; `stock.location_inactive` when the target is
	 *                        deactivated; `stock.transfer_state` when the location's stock is on its way elsewhere.
	 *
	 * @param array<string, mixed> $input The prepared input: location_uuid and optionally move_stock_to.
	 * @param Actor                $actor Who deactivates it.
	 * @return array<string, mixed> The location as it is now and the move, keyed by wire name.
	 */
	public function deactivateLocation( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, LocationOperations::CAPABILITY );

		$uuid   = (string) $input['location_uuid'];
		$target = isset( $input['move_stock_to'] ) ? strtolower( (string) $input['move_stock_to'] ) : null;

		if ( null !== $target ) {
			$this->authorizer->authorize( $actor, InventoryOperations::CAPABILITY );

			if ( strtolower( $uuid ) === $target ) {
				CodedException::raise( InventoryError::TransferSameLocation, array( 'location_id' => $target ) );
			}
		}

		list( $record, $move ) = $this->tx->transaction(
			function () use ( $uuid, $target, $actor ): array {
				$record      = $this->locked( $uuid );
				$wasInactive = ! $record->active;

				$this->deactivate( $record );

				return array(
					new LocationRecord( $record->id, $record->uuid, false, $record->isDefault, $record->details, $record->hours ),
					null === $target ? null : $this->transfers->moveStockOf( $record, $target, $wasInactive, $actor ),
				);
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);

		return self::answer( $record ) + array( 'move_transfer_uuid' => $move );
	}

	/**
	 * Performs `inventory.delete_location`: deletes a location nothing refers to, with its items that count nothing.
	 *
	 * Under the location's lock, one read says what refers to it; when nothing does, its empty
	 * items, its pickup hours, its markets and its row are deleted in the same unit of work.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` when the actor may not manage the store's settings;
	 *                        `stock.location_missing` when no location has the uuid; `stock.location_in_use`, with
	 *                        the reason, when it is the default or anything refers to it.
	 *
	 * @param array<string, mixed> $input The prepared input: location_uuid.
	 * @param Actor                $actor Who deletes it.
	 * @return array{location_uuid: string, stock_items_removed: int} What was deleted, keyed by wire name.
	 */
	public function deleteLocation( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, LocationOperations::CAPABILITY );

		$uuid = (string) $input['location_uuid'];

		return $this->tx->transaction(
			function () use ( $uuid ): array {
				$record = $this->locked( $uuid );
				$use    = $record->isDefault ? LocationInUse::IsDefault : $this->stock->locationUse( $record->id );

				if ( null !== $use ) {
					self::refuseDeletion( $record, $use );
				}

				$removed = $this->stock->deleteEmptyItemsAt( $record->id );

				if ( ! $this->locations->delete( $record->id ) ) {
					self::refuseDeletion( $record, LocationInUse::IsDefault );
				}

				return array(
					'location_uuid'       => $record->uuid,
					'stock_items_removed' => $removed,
				);
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Changes a location under its lock: the refusals, then the writes.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException As updateLocation().
	 *
	 * @param string                 $uuid      The location's uuid.
	 * @param array<string, mixed>   $changes   The checked fields, from changes().
	 * @param list<PickupHours>|null $hours     The checked pickup hours, or null to keep them.
	 * @param bool|null              $active    Whether it is to be active, or null to keep it.
	 * @param bool|null              $isDefault Whether it is to be the default, or null to keep it.
	 * @return LocationRecord The location as it is now.
	 *
	 * @phpstan-param LocationChanges $changes
	 */
	private function update( string $uuid, array $changes, ?array $hours, ?bool $active, ?bool $isDefault ): LocationRecord {
		$current        = $this->locked( $uuid );
		$active       ??= $current->active;
		$becomesDefault = true === $isDefault && ! $current->isDefault;

		if ( false === $isDefault && $current->isDefault ) {
			CodedException::raise( InventoryError::LocationIsDefault, array( 'location_id' => $current->uuid ) );
		}

		if ( $becomesDefault && ! $active ) {
			CodedException::raise( InventoryError::LocationInactive, array( 'location_id' => $current->uuid ) );
		}

		if ( $current->active && ! $active ) {
			$this->deactivate( $current );
		} elseif ( ! $current->active && $active ) {
			$this->locations->activate( $current->id );
		}

		$details = self::merged( $current->details, $changes );

		if ( array() !== $changes ) {
			$this->locations->updateDetails( $current->id, $details );
		}

		if ( null !== $hours ) {
			$this->locations->replaceHours( $current->id, $hours );
		}

		if ( $becomesDefault && ! $this->locations->moveDefault( $current->id ) ) {
			CodedException::raise( InventoryError::LocationInactive, array( 'location_id' => $current->uuid ) );
		}

		return new LocationRecord( $current->id, $current->uuid, $active, $current->isDefault || $becomesDefault, $details, $hours ?? $current->hours );
	}

	/**
	 * Deactivates a location under its lock: the one rule a deactivation and an update that turns a location off both go through.
	 *
	 * The default location is refused, and the repository's statement refuses it again in its WHERE
	 * clause.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_is_default` when it is the default location.
	 *
	 * @param LocationRecord $record The location, locked.
	 */
	private function deactivate( LocationRecord $record ): void {
		if ( $record->isDefault || ! $this->locations->deactivate( $record->id ) ) {
			CodedException::raise( InventoryError::LocationIsDefault, array( 'location_id' => $record->uuid ) );
		}
	}

	/**
	 * Reads a location's record under its lock.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` when no location has the uuid.
	 *
	 * @param string $uuid The location's uuid.
	 * @return LocationRecord The record.
	 */
	private function locked( string $uuid ): LocationRecord {
		return $this->locations->lockRecord( $uuid ) ?? CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $uuid ) );
	}

	/**
	 * Refuses a deletion, saying what refers to the location.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_in_use`, always.
	 *
	 * @param LocationRecord $record The location.
	 * @param LocationInUse  $reason What refers to it.
	 * @return never
	 */
	private static function refuseDeletion( LocationRecord $record, LocationInUse $reason ): never {
		CodedException::raise( InventoryError::LocationInUse, array( 'location_id' => $record->uuid ), array( 'reason' => $reason->value ) );
	}

	/**
	 * Checks the fields an input gives, before any statement, and returns them as a record holds them.
	 *
	 * Text is trimmed. A name is not blank; no line of text holds a control character; the pickup
	 * instructions may hold line breaks; an empty phone or empty instructions remove them. How soon a
	 * pickup is ready is one of LocationDetails::PICKUP_READY_IN, and a priority is 0 or more: the
	 * declaration says so to every surface, and the service keeps them for any other caller, here
	 * with the other fields, so a refused field is always named by the one error.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_invalid`, naming the field, when one is not valid.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return array<string, mixed> The fields given, by wire name.
	 *
	 * @phpstan-return LocationChanges
	 */
	private static function changes( array $input ): array {
		$changes = array();

		if ( array_key_exists( 'name', $input ) ) {
			$changes['name'] = self::line( $input['name'], 'name' );

			if ( '' === $changes['name'] ) {
				self::refuseField( 'name' );
			}
		}

		if ( array_key_exists( 'address', $input ) ) {
			$changes['address'] = self::address( is_array( $input['address'] ) ? $input['address'] : array() );
		}

		if ( array_key_exists( 'phone', $input ) ) {
			$phone            = self::line( $input['phone'], 'phone' );
			$changes['phone'] = '' === $phone ? null : $phone;
		}

		foreach ( array( 'fulfils_online', 'offers_pickup' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$changes[ $flag ] = (bool) $input[ $flag ];
			}
		}

		if ( array_key_exists( 'pickup_ready_in', $input ) ) {
			$changes['pickup_ready_in'] = is_string( $input['pickup_ready_in'] ) ? $input['pickup_ready_in'] : '';

			if ( ! in_array( $changes['pickup_ready_in'], LocationDetails::PICKUP_READY_IN, true ) ) {
				self::refuseField( 'pickup_ready_in' );
			}
		}

		if ( array_key_exists( 'pickup_instructions', $input ) ) {
			$instructions = trim( is_string( $input['pickup_instructions'] ) ? $input['pickup_instructions'] : '' );

			if ( ! MerchantText::isText( $instructions ) ) {
				self::refuseField( 'pickup_instructions' );
			}

			$changes['pickup_instructions'] = '' === $instructions ? null : $instructions;
		}

		if ( array_key_exists( 'priority', $input ) ) {
			$changes['priority'] = is_int( $input['priority'] ) ? $input['priority'] : 0;

			if ( $changes['priority'] < 0 ) {
				self::refuseField( 'priority' );
			}
		}

		return $changes;
	}

	/**
	 * Applies checked fields to a location's details.
	 *
	 * @since 0.2.0
	 *
	 * @param LocationDetails      $details The details now.
	 * @param array<string, mixed> $changes The checked fields, from changes().
	 * @return LocationDetails The details with the fields given changed and the others kept.
	 *
	 * @phpstan-param LocationChanges $changes
	 */
	private static function merged( LocationDetails $details, array $changes ): LocationDetails {
		return new LocationDetails(
			$changes['name'] ?? $details->name,
			array_key_exists( 'address', $changes ) ? $changes['address'] : $details->address,
			array_key_exists( 'phone', $changes ) ? $changes['phone'] : $details->phone,
			$changes['fulfils_online'] ?? $details->fulfilsOnline,
			$changes['offers_pickup'] ?? $details->offersPickup,
			$changes['pickup_ready_in'] ?? $details->pickupReadyIn,
			array_key_exists( 'pickup_instructions', $changes ) ? $changes['pickup_instructions'] : $details->pickupInstructions,
			$changes['priority'] ?? $details->priority
		);
	}

	/**
	 * Checks an address and returns it: a country, and lines of text.
	 *
	 * The country's shape is the Address's own rule, so the refusal is the Address's.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_invalid`, naming the field, when one is not valid.
	 *
	 * @param array<mixed> $fields The address object's members.
	 * @return Address The address.
	 */
	private static function address( array $fields ): Address {
		$lines = array();

		foreach ( self::ADDRESS_LINES as $name ) {
			$lines[ $name ] = self::line( $fields[ $name ] ?? '', 'address.' . $name );
		}

		try {
			return new Address(
				self::line( $fields['country'] ?? '', 'address.country' ),
				line1: $lines['line1'],
				line2: $lines['line2'],
				city: $lines['city'],
				region: $lines['region'],
				postcode: $lines['postcode']
			);
		} catch ( \InvalidArgumentException ) {
			self::refuseField( 'address.country' );
		}
	}

	/**
	 * Checks pickup hours, when the input gives them, and returns them by day.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_invalid` for the field `hours` when a day is not valid or is given twice.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return list<PickupHours>|null The days, ascending, or null when the input gives no hours.
	 */
	private static function hours( array $input ): ?array {
		if ( ! array_key_exists( 'hours', $input ) ) {
			return null;
		}

		$days = array();

		foreach ( is_array( $input['hours'] ) ? $input['hours'] : array() as $day ) {
			$weekday = is_array( $day ) && is_int( $day['weekday'] ?? null ) ? $day['weekday'] : -1;
			$opens   = is_array( $day ) && is_string( $day['opens_at'] ?? null ) ? $day['opens_at'] : '';
			$closes  = is_array( $day ) && is_string( $day['closes_at'] ?? null ) ? $day['closes_at'] : '';

			if ( isset( $days[ $weekday ] ) || ! PickupHours::isValid( $weekday, $opens, $closes ) ) {
				self::refuseField( 'hours' );
			}

			$days[ $weekday ] = new PickupHours( $weekday, $opens, $closes );
		}

		ksort( $days );

		return array_values( $days );
	}

	/**
	 * Returns one line of text, trimmed, refusing a control character.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_invalid` for the field when the text holds one.
	 *
	 * @param mixed  $value The value, a string after the schema's sanitizing.
	 * @param string $field The field's name, for the refusal.
	 * @return string The text.
	 */
	private static function line( mixed $value, string $field ): string {
		$text = trim( is_string( $value ) ? $value : '' );

		if ( 1 === preg_match( self::NOT_IN_A_LINE, $text ) ) {
			self::refuseField( $field );
		}

		return $text;
	}

	/**
	 * Refuses a field.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_invalid`, always.
	 *
	 * @param string $field The field's name.
	 * @return never
	 */
	private static function refuseField( string $field ): never {
		CodedException::raise( InventoryError::LocationInvalid, array( 'field' => $field ) );
	}

	/**
	 * Returns a location by wire name, as every operation that answers with one does.
	 *
	 * @since 0.2.0
	 *
	 * @param LocationRecord $record The location.
	 * @return array<string, mixed> The location's fields, keyed by wire name.
	 */
	private static function answer( LocationRecord $record ): array {
		$details = $record->details;
		$address = $details->address;

		return array(
			'location_uuid'       => $record->uuid,
			'name'                => $details->name,
			'address'             => null === $address ? null : array(
				'country'  => $address->country(),
				'line1'    => $address->line1(),
				'line2'    => $address->line2(),
				'city'     => $address->city(),
				'region'   => $address->region(),
				'postcode' => $address->postcode(),
			),
			'phone'               => $details->phone,
			'active'              => $record->active,
			'is_default'          => $record->isDefault,
			'fulfils_online'      => $details->fulfilsOnline,
			'offers_pickup'       => $details->offersPickup,
			'pickup_ready_in'     => $details->pickupReadyIn,
			'pickup_instructions' => $details->pickupInstructions,
			'priority'            => $details->priority,
			'hours'               => array_map(
				static fn( PickupHours $day ): array => array(
					'weekday'   => $day->weekday,
					'opens_at'  => $day->opensAt,
					'closes_at' => $day->closesAt,
				),
				$record->hours
			),
		);
	}
}
