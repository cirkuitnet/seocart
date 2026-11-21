<?php
/**
 * Tests that a checkout field is declared once and every surface shows that declaration
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Checkout\Application\CheckoutRules;
use SEOCart\Checkout\Application\ExtensionFields;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Interfaces\Rest\CheckoutSettingsOperations;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
use SEOCart\Tests\Support\Checkout\ChecksOutOverTheWire;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;

/**
 * One declaration behind every surface: the session's compiled schema, the form read, the staff's field list and the generated reference page show each built-in field's name, label, length and type as its declaration states them; a field added to the list the checkout's rules are handed appears on the form and in the staff's list, and takes a presence, with nothing else written for it; an extension's fields, by their one registration, reach every surface from the form to the order's read, and the order keeps them as the shopper gave them; and no file under src/ but the registry and the address document declares a schema field named as an address member.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutOperations::address(), add one member by hand, `new FieldSpec( name: 'city', …,
 *   max_length: 120, … )` in place of the compiled one: the schema row and the scan fail;
 * - in CheckoutForm::read(), answer `'label' => 'City'` for every field: the form read's rows fail;
 * - in OrderFieldValues::list(), answer today's declaration's label as `label`: the relabelled and
 *   the extension-gone orders answer another label than the shopper saw;
 * - in PlaceOrder::placeInside(), build the order from the session as read, not from the rules'
 *   answer: the order holds the value of a field hidden since the session kept it;
 * - Carrier::VALUE named `value`: the redactor keeps a custom field's value;
 * - in CheckoutTables::sessions(), declare `field_values_json` public: the eraser's listing leaves
 *   the session's values out.
 *
 * @since 0.2.0
 */
final class OneDeclarationTest extends PlacementTestCase {

	use ChecksOutOverTheWire;
	use CreatesUsers;
	use GrantsCapabilities;
	use ServesStoreApi;

	/**
	 * The field a test hands the rules besides the built-in ones.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXTRA = 'delivery_slot';

	/**
	 * Wires the kernel and boots a spy server; in the test of an added field, the rules are handed one more.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi( 'test_a_field_added_to_the_list_reaches_the_form_and_the_staff' === $this->getName( false ) ? self::withExtra() : array() );
	}

	/**
	 * Restores the request globals, the store's choices and the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		AcmeCheckoutFields::forget();
		wp_set_current_user( 0 );
		$this->shutStoreApi();
		$this->forgetCheckoutChoices();
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that each built-in field's name, label, length and type are its declaration's on the session's schema, the form read, the staff list and the reference page.
	 *
	 * @since 0.2.0
	 */
	public function test_every_surface_shows_the_declaration(): void {
		$hidden = array();

		foreach ( BuiltInFields::all() as $field ) {
			if ( Presence::Hidden === $field->defaultPresence() ) {
				$hidden[ $field->key ] = Presence::Optional->value;
			}
		}

		// Every field the form shows, so the form read lists every built-in.
		$this->choosePresences( $hidden );
		$this->newRequest();

		$schema = $this->schemaMembers();
		$form   = self::byKey( $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body']['fields'] ?? array() );
		$staff  = self::byKey( $this->staff( 'GET', CheckoutSettingsOperations::FIELDS_ROUTE )['body']['fields'] ?? array() );
		$page   = self::pageRows();

		foreach ( BuiltInFields::all() as $field ) {
			$label = ( $field->label )();

			foreach ( Carrier::addressesOf( $field ) as $address ) {
				$member = $schema[ $address ][ $field->key ] ?? null;

				$this->assertNotNull( $member, "{$address}.{$field->key} is not on the session's schema." );
				$this->assertSame( array( $label, $field->maxLength, FieldType::String ), array( ( $member->label() )(), $member->maxLength(), $member->type() ), "{$address}.{$field->key} on the session's schema." );
			}

			$this->assertSame(
				array( $field->key, $field->section->value, $field->type->value, $label, $field->maxLength, $field->autocomplete, Carrier::of( $field )->value ),
				array( $form[ $field->key ]['key'] ?? null, $form[ $field->key ]['section'] ?? null, $form[ $field->key ]['type'] ?? null, $form[ $field->key ]['label'] ?? null, $form[ $field->key ]['max_length'] ?? null, $form[ $field->key ]['autocomplete'] ?? null, $form[ $field->key ]['carrier'] ?? null ),
				"{$field->key} on the form read."
			);
			$this->assertSame(
				array( $field->key, $field->section->value, $field->type->value, $label, array_map( static fn( Presence $presence ): array => array( 'presence' => $presence->value ), $field->presences ), $field->isLocked() ),
				array( $staff[ $field->key ]['key'] ?? null, $staff[ $field->key ]['section'] ?? null, $staff[ $field->key ]['type'] ?? null, $staff[ $field->key ]['label'] ?? null, $staff[ $field->key ]['presences'] ?? null, $staff[ $field->key ]['locked'] ?? null ),
				"{$field->key} on the staff's list."
			);
			$this->assertSame(
				array( $field->section->value, $field->type->value, implode( ', ', array_map( static fn( Presence $presence ): string => $presence->value, $field->presences ) ), (string) $field->maxLength ),
				$page[ $field->key ] ?? null,
				"{$field->key} on docs/reference/checkout-fields.md."
			);
		}

		$this->assertSame( array_map( static fn( CheckoutField $field ): string => $field->key, BuiltInFields::all() ), array_keys( $form ), 'The form read lists the fields in form order.' );
		$this->assertSame( array_keys( $form ), array_keys( $page ), 'The page lists the fields in form order.' );
	}

	/**
	 * Tests that one field added to the list the rules are handed appears on the form and in the staff's list, and takes a presence, with nothing else written for it.
	 *
	 * Its value's way through the session write, the placement and the order is the fixture
	 * extension's walk (test_an_extensions_fields_reach_every_surface()).
	 *
	 * @since 0.2.0
	 */
	public function test_a_field_added_to_the_list_reaches_the_form_and_the_staff(): void {
		$listed = self::byKey( $this->staff( 'GET', CheckoutSettingsOperations::FIELDS_ROUTE )['body']['fields'] ?? array() );

		$this->assertSame(
			array(
				'key'       => self::EXTRA,
				'section'   => 'order',
				'type'      => 'select',
				'label'     => 'Delivery slot',
				'help'      => 'When the courier may call.',
				'presence'  => 'hidden',
				'presences' => array( array( 'presence' => 'hidden' ), array( 'presence' => 'optional' ), array( 'presence' => 'required' ) ),
				'source'    => BuiltInFields::SOURCE,
				'locked'    => false,
			),
			$listed[ self::EXTRA ] ?? null
		);
		$this->assertArrayNotHasKey( self::EXTRA, self::byKey( $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body']['fields'] ?? array() ), 'A hidden field is on the form.' );

		$changed = $this->staff(
			'PATCH',
			CheckoutSettingsOperations::FIELDS_ROUTE,
			array(
				'fields' => array(
					array(
						'key'      => self::EXTRA,
						'presence' => 'required',
					),
				),
			)
		);

		$this->assertSame( 200, $changed['status'], (string) wp_json_encode( $changed['body'] ) );
		$this->assertSame( 'required', self::byKey( $changed['body']['fields'] )[ self::EXTRA ]['presence'] ?? null );

		$this->newRequestWith( self::withExtra() );

		$this->assertSame(
			array(
				'key'          => self::EXTRA,
				'section'      => 'order',
				'type'         => 'select',
				'label'        => 'Delivery slot',
				'help'         => 'When the courier may call.',
				'required'     => true,
				'max_length'   => 20,
				'options'      => array(
					array(
						'value' => 'morning',
						'label' => 'Morning',
					),
				),
				'autocomplete' => null,
				'carrier'      => 'custom_fields',
			),
			self::byKey( $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body']['fields'] ?? array() )[ self::EXTRA ] ?? null
		);
	}

	/**
	 * Tests that no file under src/ but the registry and the address document declares a schema field named as an address member; a stock location's own details are a location's, not an address's.
	 *
	 * @since 0.2.0
	 */
	public function test_no_other_file_declares_an_address_member(): void {
		$root    = dirname( __DIR__, 3 );
		$members = implode( '|', array_map( static fn( string $member ): string => preg_quote( $member, '/' ), array_keys( AddressDocument::FIELDS ) ) );
		$pattern = '/(?:new\s+FieldSpec|FieldSpec::object(?:List)?|->renamed)\s*\(\s*(?:name\s*:\s*)?[\'"](?:' . $members . ')[\'"]/';
		$allowed = array(
			'src/Checkout/Domain/Fields/BuiltInFields.php',
			'src/Checkout/Domain/AddressDocument.php',
			// A stock location's own country and phone, which describe the store's premises, not a shopper's address.
			'src/Inventory/Application/LocationOperations.php',
		);
		$found   = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			$path = substr( (string) $file, strlen( $root ) + 1 );

			if ( ! str_ends_with( $path, '.php' ) || in_array( $path, $allowed, true ) ) {
				continue;
			}

			if ( 1 === preg_match( $pattern, (string) file_get_contents( (string) $file ) ) ) {
				$found[] = $path;
			}
		}

		$this->assertSame( 1, preg_match( $pattern, "new FieldSpec(\n\tname: 'city'," ), 'The scan finds nothing, so it would prove nothing.' );
		$this->assertSame( array(), $found, 'These files declare a schema field named as an address member: compile it from the built-in checkout fields.' );
	}

	/**
	 * Tests that an extension's fields, with their one registration and nothing else, reach every surface: the form, the staff's list and its change, the write's rules (length, characters, an option, a checkbox, the field's own check), the placement's question for a required one, the session, the order's rows with their words, and the order's read; and that no log line a failing write or a failing placement writes holds a value, through the production redactor.
	 *
	 * @since 0.2.0
	 */
	public function test_an_extensions_fields_reach_every_surface(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		AcmeCheckoutFields::listen();
		$this->newRequest();

		$declared = self::fixtureFields();
		$form     = self::byKey( $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body']['fields'] ?? array() );
		$staff    = self::byKey( $this->staff( 'GET', CheckoutSettingsOperations::FIELDS_ROUTE )['body']['fields'] ?? array() );

		foreach ( $declared as $key => $field ) {
			$this->assertSame( self::formEntry( $key, $field, $field->defaultPresence() ), $form[ $key ] ?? null, "{$key} on the form read." );
			$this->assertSame( self::staffEntry( $key, $field, $field->defaultPresence() ), $staff[ $key ] ?? null, "{$key} on the staff's list." );
		}

		$this->assertSame( array_keys( $declared ), array_slice( array_keys( $form ), -count( $declared ) ), 'The extension\'s fields follow the built-in ones, in the order registered.' );

		// The store makes the purchase order number required, through the staff's change.
		$changed = $this->staff(
			'PATCH',
			CheckoutSettingsOperations::FIELDS_ROUTE,
			array(
				'fields' => array(
					array(
						'key'      => AcmeCheckoutFields::PO_NUMBER,
						'presence' => 'required',
					),
				),
			)
		);

		$this->assertSame( 200, $changed['status'], (string) wp_json_encode( $changed['body'] ) );
		$this->newRequest();
		$this->assertSame( self::formEntry( AcmeCheckoutFields::PO_NUMBER, $declared[ AcmeCheckoutFields::PO_NUMBER ], Presence::Required ), self::byKey( $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body']['fields'] ?? array() )[ AcmeCheckoutFields::PO_NUMBER ] ?? null );

		// The write's rules, every fault at once, and a check that throws.
		$this->cartOverTheWire();

		$this->assertSame(
			array(
				self::fault( AcmeCheckoutFields::PO_NUMBER, 'rejected' ) + array( 'message' => AcmeCheckoutFields::PO_REFUSAL ),
				self::fault( AcmeCheckoutFields::DELIVERY_SLOT, 'not_an_option' ),
				self::fault( AcmeCheckoutFields::GIFT_WRAP, 'format' ),
			),
			$this->refusedWrite(
				array(
					AcmeCheckoutFields::PO_NUMBER     => 'SECRET-12345',
					AcmeCheckoutFields::DELIVERY_SLOT => 'evening',
					AcmeCheckoutFields::GIFT_WRAP     => '2',
				)
			)
		);
		$this->assertSame( array( self::fault( AcmeCheckoutFields::PO_NUMBER, 'too_long' ) ), $this->refusedWrite( array( AcmeCheckoutFields::PO_NUMBER => 'PO-' . str_repeat( '9', $declared[ AcmeCheckoutFields::PO_NUMBER ]->maxLength ) ) ) );
		$this->assertSame( array( self::fault( AcmeCheckoutFields::PO_NUMBER, 'characters' ) ), $this->refusedWrite( array( AcmeCheckoutFields::PO_NUMBER => "PO-SECRET\u{202E}1" ) ) );
		$this->assertSame( array( self::fault( AcmeCheckoutFields::PO_NUMBER, 'rejected' ) ), $this->refusedWrite( array( AcmeCheckoutFields::PO_NUMBER => 'PO-SECRET?' ) ) );

		// The placement asks for the required field.
		$written = $this->writeOverTheWire( self::addressesBody() );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$asked = $this->placeOverTheWire( $written['body'], 'walk-asked' );

		$this->assertSame( array( 'custom_fields.' . AcmeCheckoutFields::PO_NUMBER ), $asked['body']['data']['details']['missing'] ?? null );

		// A session kept with a value the check refuses is refused at placement.
		$written = $this->writeOverTheWire( self::addressesBody( array( AcmeCheckoutFields::PO_NUMBER => 'PO-1234' ) ) );

		$this->db->execute( 'UPDATE %i SET field_values_json = %s', $this->table( CheckoutTables::SESSIONS ), (string) wp_json_encode( array( AcmeCheckoutFields::PO_NUMBER => 'SECRET-KEPT' ) ) );
		$this->assertSame( array( self::fault( AcmeCheckoutFields::PO_NUMBER, 'rejected' ) + array( 'message' => AcmeCheckoutFields::PO_REFUSAL ) ), $this->placeOverTheWire( $written['body'], 'walk-kept' )['body']['data']['details']['fields'] ?? null );

		// No line the failing writes and placements logged holds a value: one line, the check that threw, by its field and class.
		$lines = $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i ORDER BY id', $this->table( LogsTable::NAME ) );

		$this->assertSame( array( CheckoutRules::CHECK_FAILED ), array_column( $lines, 'machine_code' ) );
		$this->assertSame(
			array(
				'field'     => AcmeCheckoutFields::PO_NUMBER,
				'exception' => \UnexpectedValueException::class,
			),
			array_intersect_key( (array) json_decode( (string) $lines[0]['context_json'], true ), array_flip( array( 'field', 'exception' ) ) )
		);
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $lines ), 'A log line holds a value.' );

		// The production redactor hides a custom field's value by the name it travels under: in the
		// whole list, and in one entry on its own, as a line about that one value would carry it.
		$entry    = array(
			Carrier::KEY   => AcmeCheckoutFields::PO_NUMBER,
			Carrier::VALUE => 'PO-SECRET-REDACTED',
		);
		$redacted = $this->kernel->get( Redactor::class )->context(
			array(
				Carrier::CustomFields->value => array( $entry ),
				'entry'                      => $entry,
			)
		);

		$this->assertStringNotContainsString( 'PO-SECRET-REDACTED', (string) wp_json_encode( $redacted ), 'The redactor keeps a custom field\'s value.' );
		$this->assertSame( AcmeCheckoutFields::PO_NUMBER, $redacted['entry'][ Carrier::KEY ] ?? null, 'The redactor hides the field\'s key, which is no personal data.' );

		// The values are stored on the session, placed, written to the order with their words, and read back.
		$values  = array(
			'order_note'                      => "Ring twice.\nThank you.",
			AcmeCheckoutFields::PO_NUMBER     => 'PO-1234',
			AcmeCheckoutFields::DELIVERY_SLOT => 'morning',
			AcmeCheckoutFields::GIFT_WRAP     => '1',
		);
		$written = $this->writeOverTheWire( self::addressesBody( $values ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( $values, (array) json_decode( (string) $this->db->fetchValue( 'SELECT field_values_json FROM %i', $this->table( CheckoutTables::SESSIONS ) ), true ), 'The session keeps the values.' );

		$placed = $this->placeOverTheWire( $written['body'], 'walk-placed' );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );

		$read     = $this->orderValues( (string) $placed['body']['order_uuid'] );
		$fields   = array( 'order_note' => self::builtIn( 'order_note' ) ) + $declared;
		$expected = array();

		foreach ( $values as $key => $value ) {
			$option     = $fields[ $key ]->options[ $value ] ?? null;
			$expected[] = array(
				'key'           => $key,
				'section'       => $fields[ $key ]->section->value,
				'type'          => $fields[ $key ]->type->value,
				'label'         => ( $fields[ $key ]->label )(),
				'current_label' => ( $fields[ $key ]->label )(),
				'field_value'   => $value,
				'value_label'   => null === $option ? null : $option(),
				'locale'        => get_locale(),
			);
		}

		$this->assertSame( $expected, $read, 'The order\'s read answers each value with its field\'s words, in form order.' );
	}

	/**
	 * Tests that an order keeps its values as the shopper gave them, whatever the declarations become: a field and an option relabelled keep the labels the shopper saw, the field's label today beside them; the extension gone, the field is still answered, its label today null; a field hidden after a session kept its value leaves no row; and an erased order keeps each field's key and label and loses both values, which its declarations say the eraser destroys.
	 *
	 * @since 0.2.0
	 */
	public function test_the_order_keeps_what_the_shopper_saw(): void {
		AcmeCheckoutFields::listen();
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire( self::addressesBody( array( AcmeCheckoutFields::DELIVERY_SLOT => 'morning' ) ) );
		$uuid    = (string) ( $this->placeOverTheWire( $written['body'], 'keeps-placed' )['body']['order_uuid'] ?? '' );

		AcmeCheckoutFields::relabel(
			array(
				'Delivery slot' => 'Delivery window',
				'Morning'       => 'Early morning',
			)
		);
		$this->newRequest();

		$relabelled = $this->orderValues( $uuid );

		$this->assertSame( array( 'Delivery slot', 'Morning', 'Delivery window' ), array( $relabelled[0]['label'] ?? null, $relabelled[0]['value_label'] ?? null, $relabelled[0]['current_label'] ?? null ), 'The order answers today\'s words for what the shopper saw.' );

		AcmeCheckoutFields::relabel( array() );

		AcmeCheckoutFields::forget();
		$this->newRequest();

		$gone = $this->orderValues( $uuid );

		$this->assertSame( array( AcmeCheckoutFields::DELIVERY_SLOT, 'Delivery slot', 'morning' ), array( $gone[0]['key'] ?? null, $gone[0]['label'] ?? null, $gone[0]['field_value'] ?? null ), 'The order no longer answers a field its extension no longer declares, as it was.' );
		$this->assertArrayHasKey( 'current_label', $gone[0] );
		$this->assertNull( $gone[0]['current_label'], 'A field no longer declared has a label today.' );

		// A field hidden after the session kept its value.
		AcmeCheckoutFields::listen();
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire(
			self::addressesBody(
				array(
					AcmeCheckoutFields::GIFT_WRAP     => '1',
					AcmeCheckoutFields::DELIVERY_SLOT => 'afternoon',
				)
			)
		);

		$this->choosePresences( array( AcmeCheckoutFields::GIFT_WRAP => 'hidden' ) );

		$hidden = (string) ( $this->placeOverTheWire( $written['body'], 'keeps-hidden' )['body']['order_uuid'] ?? '' );

		$this->assertSame( array( AcmeCheckoutFields::DELIVERY_SLOT ), array_column( $this->orderValues( $hidden ), 'key' ), 'The order holds the value of a field hidden since the session kept it.' );

		// The eraser, as the declarations instruct it: each personal column it destroys is emptied.
		$destroyed = self::destroyedColumns();

		$this->assertContains( CheckoutTables::FIELD_VALUES, $destroyed[ CheckoutTables::SESSIONS ] ?? array(), 'The declarations leave the session\'s values out of what the eraser destroys.' );
		$this->assertSame( array( 'field_value', 'value_label_snapshot' ), $destroyed[ OrderTables::FIELD_VALUES ] ?? null, 'The declarations say the eraser destroys an order\'s values, and nothing else of their rows.' );

		foreach ( $destroyed[ OrderTables::FIELD_VALUES ] as $column ) {
			$this->db->execute( 'UPDATE %i SET %i = NULL', $this->table( OrderTables::FIELD_VALUES ), $column );
		}

		$erased = $this->orderValues( $uuid );

		$this->assertSame( array( AcmeCheckoutFields::DELIVERY_SLOT, 'Delivery slot' ), array( $erased[0]['key'] ?? null, $erased[0]['label'] ?? null ), 'An erased order keeps the field.' );
		$this->assertSame( array( null, null ), array( $erased[0]['field_value'], $erased[0]['value_label'] ), 'An erased order keeps a value.' );
	}

	/**
	 * Tests that the schema's outer bounds of the custom fields are the declaration's own: a value as long as the longest any field may declare, and a key as long as the longest stored key the registry can produce.
	 *
	 * The schema says the list's shape alone; each field's own length is the rules'. Its value bound
	 * is the largest maxLength a CheckoutField accepts, and its key bound the longest
	 * `{extensionId}_{key}` the registry keeps, which the key grammar caps.
	 *
	 * @since 0.2.0
	 */
	public function test_the_custom_fields_outer_bounds_are_the_declarations(): void {
		$members = array();

		foreach ( CheckoutOperations::updateSession()->input() as $input ) {
			if ( Carrier::CustomFields->value === $input->name() ) {
				foreach ( $input->fields() as $member ) {
					$members[ $member->name() ] = $member->maxLength();
				}
			}
		}

		$longest = 0;

		foreach ( CheckoutFieldType::cases() as $type ) {
			$options = CheckoutFieldType::Select === $type ? array( 'one' => static fn(): string => 'One' ) : array();

			foreach ( array( CheckoutField::LINE_MAX_LENGTH, CheckoutField::TEXTAREA_MAX_LENGTH ) as $length ) {
				try {
					new CheckoutField( key: 'any', section: Section::Order, type: $type, label: static fn(): string => 'Any', maxLength: $length, presences: array( Presence::Optional ), options: $options );
					$longest = max( $longest, $length );
				} catch ( \InvalidArgumentException ) {
					continue;
				}
			}
		}

		$this->assertSame( $longest, $members[ Carrier::VALUE ] ?? null, 'The value bound is not the longest value a field may declare.' );

		$kept     = array();
		$registry = new ExtensionFields( array(), $this->reporter() );
		$listener = static function ( CheckoutFieldRegistry $registry ): void {
			$field = static fn( string $key ): CheckoutField => new CheckoutField( key: $key, section: Section::Order, type: CheckoutFieldType::Text, label: static fn(): string => 'A label', maxLength: 20, presences: array( Presence::Optional ) );

			// An id and a key the grammar accepts whose stored key is the longest it allows, and one character more.
			$registry->register( str_repeat( 'a', CheckoutField::KEY_MAX_LENGTH - 2 ), $field( 'b' ) );
			$registry->register( str_repeat( 'c', CheckoutField::KEY_MAX_LENGTH - 1 ), $field( 'd' ) );
		};

		add_action( CheckoutFieldRegistry::ACTION, $listener );

		try {
			foreach ( $registry->fieldsBeside( array() ) as $field ) {
				$kept[] = $field->key;
			}
		} finally {
			remove_action( CheckoutFieldRegistry::ACTION, $listener );
		}

		$this->assertCount( 1, $kept, 'The registry kept a stored key longer than a key may be.' );
		$this->assertSame( strlen( $kept[0] ), $members[ Carrier::KEY ] ?? null, 'The key bound is not the longest stored key the registry can produce.' );
	}

	/**
	 * Returns the replacement that hands the rules one field more than the built-in ones.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, callable(Container): object> The replacement.
	 */
	private static function withExtra(): array {
		$extra = new CheckoutField(
			key: self::EXTRA,
			section: Section::Order,
			type: CheckoutFieldType::Select,
			label: static fn(): string => 'Delivery slot',
			maxLength: 20,
			presences: array( Presence::Hidden, Presence::Optional, Presence::Required ),
			help: static fn(): string => 'When the courier may call.',
			options: array( 'morning' => static fn(): string => 'Morning' )
		);

		return array(
			CheckoutRules::class => static fn( Container $c ): CheckoutRules => new CheckoutRules( $c->get( SettingsStore::class ), InventorySettings::settings(), array_merge( BuiltInFields::all(), array( $extra ) ) ),
		);
	}

	/**
	 * Ends the request and serves the next with replacements.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, callable(Container): object> $overrides The replacements.
	 */
	private function newRequestWith( array $overrides ): void {
		$this->shutStoreApi();
		wp_cache_flush();
		$this->bootStoreApi( $overrides );
	}

	/**
	 * Serves a staff request to the plugin's REST namespace, as a user who may manage the store's settings.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route, relative to the namespace.
	 * @param array<string, mixed> $body   Optional. The body. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function staff( string $method, string $route, array $body = array() ): array {
		return $this->staffAs( array( CheckoutSettingsOperations::CAPABILITY ), $method, $route, $body );
	}

	/**
	 * Serves a staff request to the plugin's REST namespace, as a user who holds the capabilities given.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]             $capabilities The capabilities.
	 * @param string               $method       The HTTP method.
	 * @param string               $route        The route, relative to the namespace.
	 * @param array<string, mixed> $body         Optional. The body. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function staffAs( array $capabilities, string $method, string $route, array $body = array() ): array {
		wp_set_current_user( $this->userGranted( ...$capabilities )->userId() );

		$sent = $this->serve( $this->server, $method, '/' . RestBinding::NAMESPACE . $route, array(), $body );

		wp_set_current_user( 0 );

		return $sent;
	}

	/**
	 * Returns the members of both addresses as the session write's input declares them.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, FieldSpec>> The members by name, by address.
	 */
	private function schemaMembers(): array {
		$members = array();

		foreach ( CheckoutOperations::updateSession()->input() as $input ) {
			if ( in_array( $input->name(), array( Carrier::BILLING, Carrier::SHIPPING ), true ) ) {
				foreach ( $input->fields() as $member ) {
					$members[ $input->name() ][ $member->name() ] = $member;
				}
			}
		}

		return $members;
	}

	/**
	 * Returns each field's entry on the generated reference page, by key: section, type, presences and longest value.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, list<string>> The entries.
	 */
	private static function pageRows(): array {
		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/reference/checkout-fields.md' );
		$rows = array();

		preg_match_all( '/^## `([a-z0-9_]+)`\n\n- Section: ([a-z]+)\n- Type: ([a-z]+)\n- Sent in: `[a-z_]+`\n- Presences \(default first\): ([a-z, ]+)\n- Longest value: (\d+)$/m', $page, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$rows[ $match[1] ] = array( $match[2], $match[3], $match[4], $match[5] );
		}

		return $rows;
	}

	/**
	 * Returns the fixture extension's fields as it declares them, by the key the checkout stores each under, by running its registration against a registry that keeps what it is given.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, CheckoutField> The fields as declared, by stored key, in the order registered.
	 */
	private static function fixtureFields(): array {
		$registry = new class() implements CheckoutFieldRegistry {

			/**
			 * The fields registered, by stored key.
			 *
			 * @var array<string, CheckoutField>
			 */
			public array $fields = array();

			/**
			 * Supports every version.
			 *
			 * @param string $version The version.
			 * @return bool True.
			 */
			public function supportsContract( string $version ): bool {
				return '' !== $version;
			}

			/**
			 * Keeps the field under its stored key.
			 *
			 * @param string        $extensionId The extension's id.
			 * @param CheckoutField $field       The field.
			 */
			public function register( string $extensionId, CheckoutField $field ): void {
				$this->fields[ $extensionId . '_' . $field->key ] = $field;
			}
		};

		AcmeCheckoutFields::register( $registry );

		return $registry->fields;
	}

	/**
	 * Returns a field's entry on the form read, compiled from its declaration here: what the read must answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $key      The key it is stored under.
	 * @param CheckoutField $field    The field as declared.
	 * @param Presence      $presence Its presence in the store.
	 * @return array<string, mixed> The entry, by wire name.
	 */
	private static function formEntry( string $key, CheckoutField $field, Presence $presence ): array {
		$options = array();

		foreach ( $field->options as $value => $label ) {
			$options[] = array(
				'value' => (string) $value,
				'label' => $label(),
			);
		}

		return array(
			'key'          => $key,
			'section'      => $field->section->value,
			'type'         => $field->type->value,
			'label'        => ( $field->label )(),
			'help'         => null === $field->help ? null : ( $field->help )(),
			'required'     => Presence::Required === $presence,
			'max_length'   => $field->maxLength,
			'options'      => $options,
			'autocomplete' => null,
			'carrier'      => Carrier::CustomFields->value,
		);
	}

	/**
	 * Returns a field's entry on the staff's list, compiled from its declaration here: what the list must answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $key      The key it is stored under.
	 * @param CheckoutField $field    The field as declared.
	 * @param Presence      $presence Its presence in the store.
	 * @return array<string, mixed> The entry, by wire name.
	 */
	private static function staffEntry( string $key, CheckoutField $field, Presence $presence ): array {
		return array(
			'key'       => $key,
			'section'   => $field->section->value,
			'type'      => $field->type->value,
			'label'     => ( $field->label )(),
			'help'      => null === $field->help ? null : ( $field->help )(),
			'presence'  => $presence->value,
			'presences' => array_map( static fn( Presence $allowed ): array => array( 'presence' => $allowed->value ), $field->presences ),
			'source'    => AcmeCheckoutFields::ID,
			'locked'    => $field->isLocked(),
		);
	}

	/**
	 * Returns a session write's body: two complete addresses, the stand-in's payment method and the custom fields' values given.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $values Optional. The custom fields' values, by field key. Default none.
	 * @return array<string, mixed> The body, without the cart's version.
	 */
	private static function addressesBody( array $values = array() ): array {
		$custom = array();

		foreach ( $values as $key => $value ) {
			$custom[] = array(
				Carrier::KEY   => $key,
				Carrier::VALUE => $value,
			);
		}

		return array(
			Carrier::BILLING             => self::wireAddress( Carrier::BILLING, 'GB', 'ada@example.com' ),
			Carrier::SHIPPING            => self::wireAddress( Carrier::SHIPPING, 'GB' ),
			'payment_method_key'         => 'stub',
			Carrier::CustomFields->value => $custom,
		);
	}

	/**
	 * Writes custom fields' values the checkout refuses, and returns the faults it answers.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $values The values, by field key.
	 * @return mixed The `fields` of `checkout.invalid_fields`.
	 */
	private function refusedWrite( array $values ): mixed {
		$written = $this->writeOverTheWire( self::addressesBody( $values ) );

		$this->assertSame( array( 422, 'checkout.invalid_fields' ), array( $written['status'], $written['body']['code'] ?? null ), (string) wp_json_encode( $written['body'] ) );

		return $written['body']['data']['details']['fields'] ?? null;
	}

	/**
	 * Returns one fault of a custom field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key    The field's key.
	 * @param string $reason Why.
	 * @return array{field: string, reason: string} The fault.
	 */
	private static function fault( string $key, string $reason ): array {
		return array(
			'field'  => Carrier::CustomFields->value . '.' . $key,
			'reason' => $reason,
		);
	}

	/**
	 * Reads an order's checkout field values over the route, as staff who may see personal data.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order's uuid.
	 * @return list<array<string, mixed>> The values.
	 */
	private function orderValues( string $uuid ): array {
		$read = $this->staffAs( array( OrderOperations::VIEW_CAPABILITY, OperationInvoker::PERSONAL_DATA_CAPABILITY ), 'GET', str_replace( '{order_uuid}', $uuid, OrderOperations::FIELD_VALUES_ROUTE ) );

		$this->assertSame( 200, $read['status'], (string) wp_json_encode( $read['body'] ) );

		return $read['body']['field_values'] ?? array();
	}

	/**
	 * Returns a built-in field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return CheckoutField The field.
	 */
	private static function builtIn( string $key ): CheckoutField {
		foreach ( BuiltInFields::all() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		self::fail( "No built-in field {$key}." );
	}

	/**
	 * Returns the personal columns the eraser destroys, by table, as the production registry lists them for it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, list<string>> The columns' names, by unprefixed table name.
	 */
	private static function destroyedColumns(): array {
		$destroyed = array();

		foreach ( OwnedData::registry()->columnsClassified( Classification::Pii ) as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ColumnSpec::ERASE_DESTROY === $column->erasure() ) {
					$destroyed[ $table ][] = $column->name();
				}
			}
		}

		return $destroyed;
	}

	/**
	 * Returns a list of fields by key.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $fields The fields, each with its `key`.
	 * @return array<string, array<string, mixed>> The fields by key, in the order given.
	 */
	private static function byKey( array $fields ): array {
		$keyed = array();

		foreach ( $fields as $field ) {
			$keyed[ (string) $field['key'] ] = $field;
		}

		return $keyed;
	}
}
