<?php
/**
 * Tests the registration of extensions' checkout fields: when the action fires, what is accepted under which key, and what is refused
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\CheckoutRules;
use SEOCart\Checkout\Application\ExtensionFields;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * An extension's checkout field joins the checkout's declared fields under `{extensionId}_{key}`, its extension named as its source; the registration action fires at most once a request, the first time the rules need their fields, and never before; every registration the checkout cannot carry is refused, reported once by its extension, key and reason, and left out. Doctor's note of the refusals is CheckoutChecksTest's.
 *
 * Planted violations, each shown red and removed:
 * - in ExtensionFields::refusalOf(), drop the reserved-key arm: an extension's `phone`, and the
 *   stored `tax_id`, are accepted, and the collision rows fail (the stored `tax_id` then also
 *   shadows the address's member: the rules' list holds two fields of that key);
 * - in ExtensionFields::fieldsBeside(), collect whether or not the action ran, and in
 *   CheckoutRules::fields(), drop the memo (`??=`): the action fires twice in one request. Either
 *   memo alone keeps it to once.
 *
 * @since 0.2.0
 */
final class ExtensionFieldsTest extends DatabaseTestCase {

	/**
	 * The registrations a test's listener makes, run when the action fires.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{string, CheckoutField}>
	 */
	private array $registrations = array();

	/**
	 * How many times the action fired.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $fired = 0;

	/**
	 * Listens to the action with the test's registrations.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		add_action( CheckoutFieldRegistry::ACTION, array( $this, 'registerAll' ) );
	}

	/**
	 * Stops listening, and stops the fixture extension.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		remove_action( CheckoutFieldRegistry::ACTION, array( $this, 'registerAll' ) );
		AcmeCheckoutFields::forget();

		parent::tear_down();
	}

	/**
	 * Makes the test's registrations: the listener of the action.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutFieldRegistry $registry The registry.
	 */
	public function registerAll( CheckoutFieldRegistry $registry ): void {
		++$this->fired;

		foreach ( $this->registrations as list( $extensionId, $field ) ) {
			$registry->register( $extensionId, $field );
		}
	}

	/**
	 * Tests that the fixture's three fields follow SEOCart's own under their stored keys, each with the fixture as its source and carried in the custom fields, and that the action fired once for two asks.
	 *
	 * @since 0.2.0
	 */
	public function test_an_extensions_fields_follow_the_built_in_ones_under_their_stored_keys(): void {
		AcmeCheckoutFields::listen();

		$rules = $this->rules();

		$this->assertSame( 0, $this->fired, 'Building the rules fired the action.' );

		$fields = $rules->fields();

		$rules->current();

		$this->assertSame( 1, $this->fired, 'The action fires once a request.' );
		$this->assertSame(
			array_merge( array_map( static fn( CheckoutField $field ): string => $field->key, BuiltInFields::all() ), array( AcmeCheckoutFields::PO_NUMBER, AcmeCheckoutFields::DELIVERY_SLOT, AcmeCheckoutFields::GIFT_WRAP ) ),
			array_map( static fn( CheckoutField $field ): string => $field->key, $fields )
		);

		foreach ( array_slice( $fields, count( BuiltInFields::all() ) ) as $field ) {
			$this->assertSame( AcmeCheckoutFields::ID, $rules->sourceOf( $field ), "{$field->key}'s source." );
			$this->assertSame( Carrier::CustomFields, Carrier::of( $field ), "{$field->key} is carried in the custom fields." );
			$this->assertNull( $field->autocomplete, "{$field->key} has an autocomplete token." );
		}

		$this->assertSame( BuiltInFields::SOURCE, $rules->sourceOf( BuiltInFields::all()[0] ) );
		$this->assertSame( array(), $this->reports, 'An accepted registration was reported.' );
	}

	/**
	 * Tests that the kernel's rules fire nothing until their fields are asked for, and that a request that never asks fires nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_kernels_rules_fire_the_action_only_when_asked(): void {
		$kernel = KernelContainer::build( $this->db, $this->reporter() );

		$kernel->get( CheckoutRules::class );

		$this->assertSame( 0, $this->fired, 'Building the kernel\'s rules fired the action.' );

		$kernel->get( CheckoutRules::class )->current();
		$kernel->get( CheckoutRules::class )->fields();

		$this->assertSame( 1, $this->fired );
	}

	/**
	 * Provides each registration the checkout refuses, with why.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure(): CheckoutField, string}> The extension's id, the field and the reason.
	 */
	public static function refusals(): array {
		$text = static fn( string $key, Section $section = Section::Order, CheckoutFieldType $type = CheckoutFieldType::Text ): \Closure => static fn(): CheckoutField => new CheckoutField( key: $key, section: $section, type: $type, label: static fn(): string => 'A label', maxLength: 20, presences: array( Presence::Optional ) );

		return array(
			'an address field'                      => array( 'acme', $text( 'floor', Section::Address ), ExtensionFields::ADDRESS_SECTION ),
			'an email field'                        => array( 'acme', $text( 'work_email', Section::Contact, CheckoutFieldType::Email ), ExtensionFields::TYPE ),
			'a tel field'                           => array( 'acme', $text( 'mobile', Section::Contact, CheckoutFieldType::Tel ), ExtensionFields::TYPE ),
			'a country field'                       => array( 'acme', $text( 'origin', Section::Order, CheckoutFieldType::Country ), ExtensionFields::TYPE ),
			'a built-in\'s key, given'              => array( 'acme', $text( 'phone' ), ExtensionFields::RESERVED_KEY ),
			'a built-in\'s key, stored'             => array( 'tax', $text( 'id' ), ExtensionFields::RESERVED_KEY ),
			'a member of the session write, given'  => array( 'acme', $text( 'custom_fields' ), ExtensionFields::RESERVED_KEY ),
			'a member of the session write, stored' => array( 'payment', $text( 'method_key' ), ExtensionFields::RESERVED_KEY ),
			'a stored key starting seocart_'        => array( 'seocart', $text( 'note' ), ExtensionFields::RESERVED_KEY ),
			'a stored key starting custom_'         => array( 'custom', $text( 'note' ), ExtensionFields::RESERVED_KEY ),
			'an extension id that is no key'        => array( 'Acme', $text( 'note' ), ExtensionFields::INVALID_KEY ),
			'a stored key longer than a key may be' => array( str_repeat( 'a', 40 ), $text( 'po_number' ), ExtensionFields::INVALID_KEY ),
		);
	}

	/**
	 * Tests one refused registration: left out of the rules' fields, reported once by its extension, key and reason, and listed by doctor; the field registered beside it is accepted.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider refusals
	 *
	 * @param string   $extensionId The extension's id.
	 * @param \Closure $field       Builds the field.
	 * @param string   $reason      Why it is refused.
	 *
	 * @phpstan-param \Closure(): CheckoutField $field
	 */
	public function test_a_refused_registration_is_reported_once_and_left_out( string $extensionId, \Closure $field, string $reason ): void {
		$refused = $field();

		$this->registrations = array( array( $extensionId, $refused ), array( 'zed', self::field( 'note' ) ) );

		$rules = $this->rules();
		$keys  = array_map( static fn( CheckoutField $declared ): string => $declared->key, $rules->fields() );

		$this->assertSame( array_merge( array_map( static fn( CheckoutField $declared ): string => $declared->key, BuiltInFields::all() ), array( 'zed_note' ) ), $keys, 'The refused field was accepted, or the other one was not.' );
		$this->assertSame(
			array(
				array(
					'code'    => ExtensionFields::REJECTED,
					'context' => array(
						'extension' => $extensionId,
						'key'       => $refused->key,
						'reason'    => $reason,
					),
				),
			),
			$this->reports
		);
		$this->assertSame( $this->reports[0]['context'], $rules->refusedRegistrations()[0] ?? null );
		$this->assertCount( 1, $this->reports, 'Asking again reported the refusal again.' );
	}

	/**
	 * Tests that an extension's key that is a built-in's leaves the session's own member as it is: the address still carries `phone` and `tax_id`, each once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_built_in_key_is_never_shadowed(): void {
		$this->registrations = array( array( 'acme', self::field( 'phone' ) ), array( 'tax', self::field( 'id' ) ) );

		$rules = $this->rules();
		$byKey = array();

		foreach ( $rules->current()->all() as $field ) {
			$byKey[ $field->key ][] = Carrier::of( $field );
		}

		$this->assertSame( array( Carrier::Address ), $byKey['phone'] ?? null, 'The session\'s phone is shadowed.' );
		$this->assertSame( array( Carrier::Address ), $byKey['tax_id'] ?? null, 'The session\'s tax id is shadowed.' );
		$this->assertArrayNotHasKey( 'acme_phone', $byKey );
	}

	/**
	 * Tests the limits that depend on what was registered before: the 21st field and a key registered twice are refused, a key registered twice as a duplicate even at the limit, the first registration of a key winning; a registration after the action ran is refused late.
	 *
	 * @since 0.2.0
	 */
	public function test_the_twenty_first_a_duplicate_and_a_late_registration_are_refused(): void {
		for ( $field = 1; $field <= CheckoutFieldRegistry::MAX_FIELDS; $field++ ) {
			$this->registrations[] = array( 'acme', self::field( "field_{$field}" ) );
		}

		$this->registrations[] = array( 'acme', self::field( 'field_1', 'Another label' ) );
		$this->registrations[] = array( 'acme', self::field( 'one_too_many' ) );

		$registry = new ExtensionFields( array(), $this->reporter() );
		$rules    = new CheckoutRules( new SettingsStore( Settings::registry(), $this->db ), InventorySettings::settings(), BuiltInFields::all(), $registry );
		$accepted = array_slice( $rules->fields(), count( BuiltInFields::all() ) );

		$this->assertCount( CheckoutFieldRegistry::MAX_FIELDS, $accepted );
		$this->assertSame( 'A label', ( $accepted[0]->label )(), 'The second registration of a key won.' );

		$registry->register( 'acme', self::field( 'too_late' ) );

		$this->assertSame(
			array( array( 'field_1', ExtensionFields::DUPLICATE ), array( 'one_too_many', ExtensionFields::TOO_MANY ), array( 'too_late', ExtensionFields::LATE ) ),
			array_map( static fn( array $report ): array => array( $report['context']['key'], $report['context']['reason'] ), $this->reports )
		);
		$this->assertCount( CheckoutFieldRegistry::MAX_FIELDS, array_slice( $rules->fields(), count( BuiltInFields::all() ) ), 'A late registration joined the fields.' );
	}

	/**
	 * Tests a key registered twice below the limit: refused as a duplicate, the first registration winning.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_registered_twice_keeps_the_first(): void {
		$this->registrations = array( array( 'acme', self::field( 'note', 'First' ) ), array( 'acme', self::field( 'note', 'Second' ) ) );

		$fields = $this->rules()->fields();

		$this->assertSame( 'First', ( end( $fields )->label )() );
		$this->assertSame( array( ExtensionFields::DUPLICATE ), array_map( static fn( array $report ): string => $report['context']['reason'], $this->reports ) );
	}

	/**
	 * Tests that a listener that throws is reported by the exception's class and the plugin its file lies in (none, for this test's file), never its message, and that the fields registered before it stay.
	 *
	 * @since 0.2.0
	 */
	public function test_a_listener_that_throws_is_reported_by_its_class(): void {
		$this->registrations = array( array( 'acme', self::field( 'note' ) ) );

		$thrower = static function (): void {
			throw new \DomainException( 'secret-in-the-message' );
		};

		add_action( CheckoutFieldRegistry::ACTION, $thrower, 20 );

		try {
			$fields = $this->rules()->fields();
		} finally {
			remove_action( CheckoutFieldRegistry::ACTION, $thrower, 20 );
		}

		$this->assertSame( 'acme_note', end( $fields )->key );
		$this->assertSame(
			array(
				'extension' => \DomainException::class,
				'key'       => '',
				'reason'    => ExtensionFields::FAILED,
				'plugin'    => null,
			),
			$this->reports[0]['context'] ?? null
		);
		$this->assertStringNotContainsString( 'secret-in-the-message', (string) wp_json_encode( $this->reports ) );
	}

	/**
	 * Tests the contract's version rule: before 1.0 the major and minor must be the plugin's.
	 *
	 * @since 0.2.0
	 */
	public function test_the_contract_versions_supported(): void {
		$registry = new ExtensionFields( array(), $this->reporter() );

		$this->assertTrue( $registry->supportsContract( CheckoutFieldRegistry::CONTRACT_VERSION ) );
		$this->assertTrue( $registry->supportsContract( '0.2' ) );
		$this->assertTrue( $registry->supportsContract( '0.2.9' ) );
		$this->assertFalse( $registry->supportsContract( '0.1.0' ) );
		$this->assertFalse( $registry->supportsContract( '0.3.0' ) );
		$this->assertFalse( $registry->supportsContract( '1.0.0' ) );
		$this->assertFalse( $registry->supportsContract( 'latest' ) );
	}

	/**
	 * Tests that the kernel reserves every member of the session write: the rules' registry refuses each as a key.
	 *
	 * @since 0.2.0
	 */
	public function test_every_member_of_the_session_write_is_reserved(): void {
		$members = array_map( static fn( FieldSpec $member ): string => $member->name(), CheckoutOperations::updateSession()->input() );

		foreach ( $members as $member ) {
			$this->registrations[] = array( 'acme', self::field( $member ) );
		}

		$this->createLogs();

		KernelContainer::build( $this->db, $this->reporter() )->get( CheckoutRules::class )->fields();

		$this->assertSame( array_fill( 0, count( $members ), ExtensionFields::RESERVED_KEY ), array_column( $this->loggedRejections(), 'reason' ) );
		$this->assertSame( $members, array_column( $this->loggedRejections(), 'key' ) );
	}

	/**
	 * Returns the rules over the built-in fields and the fields extensions register, reporting to the test.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckoutRules The rules.
	 */
	private function rules(): CheckoutRules {
		$wire = array_map( static fn( FieldSpec $member ): string => $member->name(), CheckoutOperations::updateSession()->input() );

		return new CheckoutRules( new SettingsStore( Settings::registry(), $this->db ), InventorySettings::settings(), BuiltInFields::all(), new ExtensionFields( $wire, $this->reporter() ), $this->reporter() );
	}

	/**
	 * Creates the log's table, which the kernel's reporter writes to.
	 *
	 * @since 0.2.0
	 */
	private function createLogs(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Returns the context of each refused registration the kernel's reporter wrote to the log, as the log keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>> The contexts, in order.
	 */
	private function loggedRejections(): array {
		$rows = $this->db->fetchAll( 'SELECT context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->db->table( LogsTable::NAME ), ExtensionFields::REJECTED );

		return array_map( static fn( array $row ): array => (array) json_decode( (string) $row['context_json'], true ), $rows );
	}

	/**
	 * Builds an optional order text field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key   The key, without the extension's id.
	 * @param string $label Optional. The label. Default `A label`.
	 * @return CheckoutField The field.
	 */
	private static function field( string $key, string $label = 'A label' ): CheckoutField {
		return new CheckoutField( key: $key, section: Section::Order, type: CheckoutFieldType::Text, label: static fn(): string => $label, maxLength: 20, presences: array( Presence::Optional ) );
	}
}
