<?php
/**
 * SettingsOperations: the operations that read and change the store settings
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `settings.get_settings` and `settings.update_settings`, derived from the exposed settings.
 *
 * This class owns one fact: how the exposed settings become operations. Each exposed setting's
 * field is used as it is declared, with two differences that are the operations' own:
 *
 * - in the output, which always carries every exposed setting, the field is required;
 * - in the update's input, which changes only the settings it names, the field is optional and
 *   has no default, because a default would overwrite a setting the client did not send.
 *
 * Both require `seocart_manage_settings`, are served at `seocart/v1/settings` (GET to read, PATCH
 * to change) and by `wp seocart settings get|update`, and have no ability: changing settings is
 * never exposed to agents. They are the only way settings reach a client; no setting is ever
 * registered with WordPress's own settings API, whose REST endpoint checks `manage_options` alone.
 *
 * An exposed secret is an input of the change and never part of any output: the output schema
 * leaves it out and the service never reads it back. A change that names one also needs
 * `seocart_manage_secrets`, so it declares the refusal and the codes sealing can end in.
 *
 * Declarations are data: building a definition reads the settings declarations and nothing else.
 *
 * @since 0.1.0
 */
final class SettingsOperations {

	/**
	 * The id of the read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GET = 'settings.get_settings';

	/**
	 * The id of the change.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE = 'settings.update_settings';

	/**
	 * The REST route of both, relative to the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE = '/settings';

	/**
	 * The capability both require.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY = 'seocart_manage_settings';

	/**
	 * The name of the resource both return.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RESOURCE = 'Settings';

	/**
	 * Builds the read.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry|null $settings Optional. The settings to expose. Default the plugin's.
	 * @return OperationDefinition The definition.
	 */
	public static function get( ?SettingsRegistry $settings = null ): OperationDefinition {
		return new OperationDefinition(
			id: self::GET,
			label: static fn(): string => __( 'Read the store settings', 'seocart' ),
			summary: 'Returns every store setting, with its saved value or, when it was never saved, its default.',
			input: array(),
			output: self::resource( $settings ?? Settings::registry() ),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array( SettingsError::StoredValueInvalid ),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( SettingsService::class, 'get' ),
			rest: new RestBinding( self::ROUTE ),
			cli: new CliBinding( array( 'settings', 'get' ) )
		);
	}

	/**
	 * Builds the change.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry|null $settings Optional. The settings to expose. Default the plugin's.
	 * @return OperationDefinition The definition.
	 */
	public static function update( ?SettingsRegistry $settings = null ): OperationDefinition {
		$settings = $settings ?? Settings::registry();
		$input    = array();
		$errors   = array( SettingsError::StoredValueInvalid );

		foreach ( $settings->exposed() as $setting ) {
			$input[] = self::field( $setting->field(), false );

			$codes = $setting->isSecret() ? array_merge( $setting->errors(), array( AuthorizationError::Denied ), SecretVault::SEAL_ERRORS ) : $setting->errors();

			foreach ( $codes as $code ) {
				if ( ! in_array( $code, $errors, true ) ) {
					$errors[] = $code;
				}
			}
		}

		return new OperationDefinition(
			id: self::UPDATE,
			label: static fn(): string => __( 'Change the store settings', 'seocart' ),
			summary: 'Changes the store settings the request names, checking every value before saving any, and returns every store setting.',
			input: $input,
			output: self::resource( $settings ),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: $errors,
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( SettingsService::class, 'update' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Patch ),
			cli: new CliBinding( array( 'settings', 'update' ) )
		);
	}

	/**
	 * Declares the resource both operations return: every exposed setting.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry $settings The settings.
	 * @return ResourceSchema The resource.
	 */
	private static function resource( SettingsRegistry $settings ): ResourceSchema {
		$fields = array();

		foreach ( $settings->exposed() as $setting ) {
			$fields[] = self::field( $setting->field(), true );
		}

		return new ResourceSchema( self::RESOURCE, $fields );
	}

	/**
	 * Returns a setting's field as an operation carries it: required or not, and without a default.
	 *
	 * Every other part of the field is passed on unchanged; a unit test fails when FieldSpec gains
	 * a part this copy does not pass on.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field    The setting's field.
	 * @param bool      $required Whether the operation's field is required.
	 * @return FieldSpec The operation's field.
	 */
	private static function field( FieldSpec $field, bool $required ): FieldSpec {
		return new FieldSpec(
			name: $field->name(),
			type: $field->type(),
			description: $field->description(),
			label: $field->label(),
			example: $field->example(),
			required: $required,
			nullable: $field->isNullable(),
			default_value: null,
			allowed: $field->allowedValues(),
			max_length: $field->maxLength(),
			minimum: $field->minimum(),
			maximum: $field->maximum(),
			privacy: $field->privacy(),
			translatable: $field->isTranslatable(),
			fields: $field->fields(),
			min_items: $field->minItems(),
			max_items: $field->maxItems()
		);
	}
}
