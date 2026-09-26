<?php
/**
 * Tests that the settings operations are derived from the exposed settings, and restate nothing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Settings;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Platform\Settings\SettingsService;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Support\SettingsFixtures;

/**
 * The two definitions, built from the production list and from the fixture registry.
 *
 * @since 0.1.0
 */
final class SettingsOperationsTest extends TestCase {

	/**
	 * The parameters of FieldSpec's constructor, as the operations' copy of a setting's field
	 * passes them on. A parameter FieldSpec gains is a part of a field the copy would drop.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const FIELD_PARTS = array(
		'name',
		'type',
		'description',
		'label',
		'example',
		'required',
		'nullable',
		'default_value',
		'allowed',
		'max_length',
		'minimum',
		'maximum',
		'privacy',
		'translatable',
		'fields',
		'min_items',
		'max_items',
	);

	/**
	 * Tests the read: read-only, GET at the settings route, a command, no ability, the settings capability.
	 *
	 * @since 0.1.0
	 */
	public function test_the_read_is_declared_as_a_read(): void {
		$get = SettingsOperations::get();

		$this->assertSame( 'settings.get_settings', $get->id() );
		$this->assertSame( 'GET', $get->httpMethod() );
		$this->assertSame( '/settings', $get->rest()?->route() );
		$this->assertSame( 'seocart settings get', $get->cli()?->command() );
		$this->assertNull( $get->abilityName(), 'Settings are never exposed to agents.' );
		$this->assertFalse( $get->isAgentExposed() );
		$this->assertSame( 'seocart_manage_settings', $get->capability() );
		$this->assertNull( $get->resourceField() );
		$this->assertSame( array(), $get->input() );
		$this->assertTrue( $get->annotations()->isReadOnly() );
		$this->assertSame( array( SettingsError::StoredValueInvalid ), $get->errors() );
		$this->assertSame( array( SettingsService::class, 'get' ), $get->service() );
		$this->assertSame( 'Settings', $get->output()->name() );
		$this->assertSame( array( 'base_currency' ), self::names( $get->output()->fields() ) );
	}

	/**
	 * Tests the change: PATCH at the same route, destructive and idempotent, every exposed setting optional.
	 *
	 * @since 0.1.0
	 */
	public function test_the_change_is_declared_as_a_change(): void {
		$update = SettingsOperations::update();

		$this->assertSame( 'settings.update_settings', $update->id() );
		$this->assertSame( 'PATCH', $update->httpMethod() );
		$this->assertSame( '/settings', $update->rest()?->route() );
		$this->assertSame( 'seocart settings update', $update->cli()?->command() );
		$this->assertNull( $update->abilityName() );
		$this->assertSame( 'seocart_manage_settings', $update->capability() );
		$this->assertTrue( $update->annotations()->isDestructive(), 'A change overwrites the setting it replaces.' );
		$this->assertTrue( $update->annotations()->toArray()['idempotent'] );
		$this->assertSame( array( SettingsError::StoredValueInvalid, SupportError::UnknownCurrency ), $update->errors() );
		$this->assertSame( array( SettingsService::class, 'update' ), $update->service() );
		$this->assertSame( array( 'base_currency' ), self::names( $update->input() ) );
		$this->assertSame( 'Settings', $update->output()->name(), 'A change answers with the same resource as a read.' );
		$this->assertSame( array( 'base_currency' ), self::names( $update->output()->fields() ) );
	}

	/**
	 * Tests that the input carries every exposed setting, optional and without a default, and the output every one, required.
	 *
	 * Planted violation: in SettingsOperations::field(), pass
	 * `default_value: $required ? null : $field->defaultValue()`. The input then fills an absent
	 * setting with its default and overwrites it.
	 *
	 * @since 0.1.0
	 */
	public function test_input_fields_are_optional_without_default_and_output_fields_required(): void {
		$settings = SettingsFixtures::registry();
		$update   = SettingsOperations::update( $settings );
		$exposed  = array( 'hold_minutes', 'weight_unit', 'default_market', 'store_name', 'ledger_currency' );

		$this->assertSame( $exposed, self::names( $update->input() ), 'Every exposed setting, in declaration order, and no internal one.' );
		$this->assertSame( $exposed, self::names( $update->output()->fields() ) );

		foreach ( $update->input() as $field ) {
			$this->assertFalse( $field->isRequired(), "{$field->name()}: a change names only the settings it changes." );
			$this->assertNull( $field->defaultValue(), "{$field->name()}: a default would overwrite a setting the client did not send." );
		}

		foreach ( $update->output()->fields() as $field ) {
			$this->assertTrue( $field->isRequired(), "{$field->name()}: a read always carries every exposed setting." );
			$this->assertNull( $field->defaultValue() );
		}

		$this->assertSame( array( SettingsError::StoredValueInvalid, SupportError::UnknownCurrency ), $update->errors(), 'Each setting\'s codes are declared once, after the store\'s.' );
	}

	/**
	 * Tests that every other part of a setting's field reaches the operations unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_every_other_part_of_the_field_is_passed_on(): void {
		$settings = SettingsFixtures::registry();
		$copies   = array_merge( SettingsOperations::update( $settings )->input(), SettingsOperations::get( $settings )->output()->fields() );

		$this->assertNotEmpty( $copies );

		foreach ( $copies as $copy ) {
			$original = $settings->setting( $copy->name() )->field();

			$this->assertSame( self::parts( $original ), self::parts( $copy ), "{$copy->name()}: the operations changed a part of the field other than required and the default." );
		}
	}

	/**
	 * Tests that FieldSpec has exactly the parts the copy passes on, so a new part cannot be dropped unnoticed.
	 *
	 * @since 0.1.0
	 */
	public function test_the_copy_knows_every_part_of_a_field(): void {
		$parameters = array_map(
			static fn( \ReflectionParameter $parameter ): string => $parameter->getName(),
			( new \ReflectionMethod( FieldSpec::class, '__construct' ) )->getParameters()
		);

		$this->assertSame( self::FIELD_PARTS, $parameters, 'FieldSpec gained or lost a part: update SettingsOperations::field(), then this list.' );
	}

	/**
	 * Returns the names of fields.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return list<string> The names.
	 */
	private static function names( array $fields ): array {
		return array_values( array_map( static fn( FieldSpec $field ): string => $field->name(), $fields ) );
	}

	/**
	 * Returns every part of a field but whether it is required and its default.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> The parts.
	 */
	private static function parts( FieldSpec $field ): array {
		return array(
			'name'         => $field->name(),
			'type'         => $field->type(),
			'description'  => $field->description(),
			'label'        => $field->label(),
			'example'      => $field->example(),
			'nullable'     => $field->isNullable(),
			'allowed'      => $field->allowedValues(),
			'max_length'   => $field->maxLength(),
			'minimum'      => $field->minimum(),
			'maximum'      => $field->maximum(),
			'privacy'      => $field->privacy(),
			'translatable' => $field->isTranslatable(),
			'fields'       => $field->fields(),
			'min_items'    => $field->minItems(),
			'max_items'    => $field->maxItems(),
		);
	}
}
