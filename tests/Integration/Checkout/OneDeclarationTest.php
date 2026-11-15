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
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Interfaces\Rest\CheckoutSettingsOperations;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\ChecksOutOverTheWire;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;

/**
 * One declaration behind every surface: the session's compiled schema, the form read, the staff's field list and the generated reference page show each built-in field's name, label, length and type as its declaration states them; a field added to the list the checkout's rules are handed appears on the form and in the staff's list, and takes a presence, with nothing else written for it; and no file under src/ but the registry and the address document declares a schema field named as an address member.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutOperations::address(), add one member by hand, `new FieldSpec( name: 'city', …,
 *   max_length: 120, … )` in place of the compiled one: the schema row and the scan fail;
 * - in CheckoutForm::read(), answer `'label' => 'City'` for every field: the form read's rows fail.
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
	 * Its value's way through the session write, the placement and the order needs the custom
	 * fields' carrier, which this version does not have.
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
		wp_set_current_user( $this->userGranted( CheckoutSettingsOperations::CAPABILITY )->userId() );

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
	 * Returns the rows of the generated reference page, by key: section, type, presences and longest value.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, list<string>> The rows.
	 */
	private static function pageRows(): array {
		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/reference/checkout-fields.md' );
		$rows = array();

		preg_match_all( '/^\| `([a-z0-9_]+)` \| ([a-z]+) \| ([a-z]+) \| `[a-z_]+` \| ([a-z, ]+) \| (\d+) \|$/m', $page, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$rows[ $match[1] ] = array( $match[2], $match[3], $match[4], $match[5] );
		}

		return $rows;
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
