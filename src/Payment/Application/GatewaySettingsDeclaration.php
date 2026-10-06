<?php
/**
 * GatewaySettingsDeclaration: how a payment gateway's declared settings are kept as one settings document
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\SchemaException;

/**
 * Folds a gateway's declared settings into the settings it is kept as: one versioned document per gateway.
 *
 * Owns one fact: where a gateway's settings live. A gateway's document is the group
 * `gateway_{id}`, stored in the option `seocart_gateway_{id}`, written whole by compare-and-swap
 * with its credentials sealed in the write's transaction, and never exposed by the settings
 * operations. It holds:
 *
 * - `{id}_mode`, the mode new payments are created in, for a gateway with more than one mode: a
 *   gateway with one mode has nothing to choose, and no such setting;
 * - each declared setting once per declared mode, as `{id}_{mode}_{name}`, since one registry
 *   holds every gateway's settings and their names must not meet;
 * - for a gateway that sets up its own webhook endpoints, `{id}_{mode}_webhook_endpoint` per
 *   mode: the id of the endpoint whose signing secret the mode's `webhook_secret` holds, kept
 *   beside it when the endpoint is set up, so the secret is known to be that endpoint's. No
 *   gateway may declare a setting of that name (GatewayDescriptor::WEBHOOK_ENDPOINT), so the
 *   value stored under it is always the plugin's.
 *
 * A gateway with one mode and no settings, such as the stand-in, has no document at all. The data
 * registry declares the documents as one option family (optionFamily()), since which gateways
 * exist is known only once they have registered. Pure: nothing here reads or writes.
 *
 * @since 0.2.0
 */
final class GatewaySettingsDeclaration {

	/**
	 * What every gateway's settings group begins with, before the gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GROUP_PREFIX = 'gateway_';

	/**
	 * Returns every setting a gateway is kept as: its mode, when it has more than one, each declared setting for each mode, and the webhook endpoint's id for each mode of a gateway that sets its endpoints up.
	 *
	 * @since 0.2.0
	 *
	 * @throws SchemaException When a declared setting cannot be a setting: required, nullable, an
	 *                         object, a credential that is not text or has a default, or personal data.
	 *
	 * @param GatewayDescriptor $descriptor         The gateway's descriptor.
	 * @param bool              $provisionsWebhooks Optional. Whether the gateway sets up its own webhook endpoints (ProvisionsWebhooks). Default false.
	 * @return list<Setting> The settings; none for a gateway with one mode and no settings.
	 */
	public static function of( GatewayDescriptor $descriptor, bool $provisionsWebhooks = false ): array {
		$mode     = self::modeSetting( $descriptor );
		$settings = null === $mode ? array() : array( $mode );

		foreach ( $descriptor->modes as $declared ) {
			array_push( $settings, ...self::forMode( $descriptor, $declared ) );

			if ( $provisionsWebhooks ) {
				$settings[] = self::webhookEndpointSetting( $descriptor, $declared );
			}
		}

		return $settings;
	}

	/**
	 * Returns the setting that keeps, for a mode, the id of the webhook endpoint the gateway set up, whose signing secret is kept beside it.
	 *
	 * Not a credential: a provider's endpoint id gives no access. It is cleared when a signing
	 * secret is entered by hand, which is no endpoint's this site set up.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor $descriptor The gateway's descriptor.
	 * @param Mode              $mode       The mode.
	 * @return Setting The setting, `{id}_{mode}_webhook_endpoint`.
	 */
	public static function webhookEndpointSetting( GatewayDescriptor $descriptor, Mode $mode ): Setting {
		return Setting::inDocument(
			self::group( $descriptor->id ),
			new FieldSpec(
				name: self::storedName( $descriptor->id, $mode, GatewayDescriptor::WEBHOOK_ENDPOINT ),
				type: FieldType::String,
				description: 'The id of the webhook endpoint the provider set up for this site and mode, whose signing secret is kept beside it.',
				label: static fn(): string => __( 'Webhook endpoint', 'seocart' ),
				example: 'we_123',
				max_length: WebhookProvisioning::ENDPOINT_ID_MAX_LENGTH
			),
			false
		);
	}

	/**
	 * Returns the settings a gateway is kept as for one mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws SchemaException When a declared setting cannot be a setting.
	 *
	 * @param GatewayDescriptor $descriptor The gateway's descriptor.
	 * @param Mode              $mode       The mode.
	 * @return list<Setting> The settings, in declaration order.
	 */
	public static function forMode( GatewayDescriptor $descriptor, Mode $mode ): array {
		$group = self::group( $descriptor->id );

		return array_map(
			static fn( FieldSpec $field ): Setting => Setting::inDocument( $group, $field->renamed( self::storedName( $descriptor->id, $mode, $field->name() ) ), false ),
			$descriptor->settings
		);
	}

	/**
	 * Returns the setting that holds the mode new payments through a gateway are created in.
	 *
	 * Its default is test, when the gateway has a test mode: a store goes live only when it is told to.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor $descriptor The gateway's descriptor.
	 * @return Setting|null The setting; null for a gateway with one mode.
	 */
	public static function modeSetting( GatewayDescriptor $descriptor ): ?Setting {
		if ( count( $descriptor->modes ) < 2 ) {
			return null;
		}

		$modes   = array_map( static fn( Mode $mode ): string => $mode->value, $descriptor->modes );
		$default = in_array( Mode::Test, $descriptor->modes, true ) ? Mode::Test->value : $modes[0];

		return Setting::inDocument(
			self::group( $descriptor->id ),
			new FieldSpec(
				name: $descriptor->id . '_mode',
				type: FieldType::String,
				description: 'The mode new payments through the gateway are created in; a payment keeps its mode for good.',
				label: static fn(): string => __( 'Mode', 'seocart' ),
				example: $default,
				default_value: $default,
				allowed: $modes
			),
			false
		);
	}

	/**
	 * Returns a gateway's settings group: its document's name.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return string `gateway_{id}`.
	 */
	public static function group( string $gatewayId ): string {
		return self::GROUP_PREFIX . $gatewayId;
	}

	/**
	 * Says what a gateway's document holds, as the settings registry asks of a document.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return string One sentence.
	 */
	public static function purpose( string $gatewayId ): string {
		return sprintf( 'The settings and sealed credentials of the %s payment gateway, for each of its modes.', $gatewayId );
	}

	/**
	 * Returns the name a gateway's setting of one mode is stored under.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @param string $name      The setting's name, as the gateway declared it.
	 * @return string `{id}_{mode}_{name}`, for example `stripe_test_secret_key`.
	 */
	public static function storedName( string $gatewayId, Mode $mode, string $name ): string {
		return $gatewayId . '_' . $mode->value . '_' . $name;
	}

	/**
	 * Declares the gateways' documents to the data registry, as one family of options.
	 *
	 * Each gateway's document is the option `seocart_gateway_{id}`; they hold credentials, so the
	 * family is classified secret, and none of them autoloads.
	 *
	 * @since 0.2.0
	 *
	 * @return OptionDefinition The family `seocart_gateway_%`.
	 */
	public static function optionFamily(): OptionDefinition {
		return OptionDefinition::prefixed( Setting::OPTION_PREFIX . self::GROUP_PREFIX, SettingsRegistry::MODULE, 'The settings and sealed credentials of each payment gateway, one versioned document per gateway.', Classification::Secret );
	}
}
