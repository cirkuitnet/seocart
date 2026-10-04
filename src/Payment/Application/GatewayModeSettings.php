<?php
/**
 * GatewayModeSettings: one gateway's settings and credentials for one mode, read when asked
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\CredentialUnavailable;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewaySettings;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\Privacy;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * A gateway's settings for one mode, as its descriptor declared them and its document holds them.
 *
 * Owns one fact: how a gateway's setting of a mode is read. Each read goes to the gateway's
 * settings document through the settings store, whose one option read the object cache keeps for
 * the rest of the request; a credential is opened through the secrets vault each time it is asked
 * for, and nothing keeps it. A credential that was never saved, or whose sealed value does not
 * open, is a CredentialUnavailable, so that gateway alone answers that it is unavailable.
 *
 * Besides the gateway, the plugin reads here whether the mode is configured, and the account's
 * non-secret settings that availability is judged with.
 *
 * @since 0.2.0
 */
final class GatewayModeSettings implements GatewaySettings {

	/**
	 * Records what to read. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor $descriptor The gateway's descriptor.
	 * @param Mode              $mode       The mode.
	 * @param SettingsStore     $store      The settings store, which holds the gateway's document.
	 * @param SecretVault       $vault      The vault that opens the gateway's credentials.
	 */
	public function __construct(
		private GatewayDescriptor $descriptor,
		private Mode $mode,
		private SettingsStore $store,
		private SecretVault $vault
	) {
	}

	/**
	 * Returns a setting that is not a credential.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway has no such mode, or declared no such setting, or declared it a credential.
	 *
	 * @param string $name The setting's name, as declared.
	 * @return int|string|null The value saved, or the setting's default; null when it has neither.
	 */
	public function value( string $name ): int|string|null {
		if ( Privacy::Secret === $this->field( $name )->privacy() ) {
			throw new \InvalidArgumentException( sprintf( 'The setting %s is a credential: read it with secret().', $name ) );
		}

		return $this->store->value( $this->storedName( $name ) );
	}

	/**
	 * Opens a credential.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway has no such mode, or declared no such setting, or declared it not a credential.
	 * @throws CredentialUnavailable     When the credential was never saved, or it does not open.
	 *
	 * @param string $name The setting's name, as declared.
	 * @return string The credential.
	 */
	public function secret( string $name ): string {
		if ( Privacy::Secret !== $this->field( $name )->privacy() ) {
			throw new \InvalidArgumentException( sprintf( 'The setting %s is not a credential: read it with value().', $name ) );
		}

		try {
			$secret = $this->vault->reveal( $this->storedName( $name ) );
		} catch ( CodedException $unopened ) {
			throw new CredentialUnavailable( CredentialUnavailable::UNREADABLE, $name, $unopened );
		}

		return $secret ?? throw new CredentialUnavailable( CredentialUnavailable::MISSING, $name );
	}

	/**
	 * Tells whether the mode is configured: every setting of it without a default has a value saved.
	 *
	 * Judged from the document alone: a credential counts as saved when its sealed value is there,
	 * without opening it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it is; true for a mode of a gateway with no settings.
	 */
	public function isConfigured(): bool {
		if ( ! $this->declaresMode() ) {
			return false;
		}

		foreach ( $this->read() as $name => $value ) {
			if ( null === $value && null === $this->field( $name )->defaultValue() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the mode's settings that are not credentials, by the names the gateway declared them with.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string|null> The values; empty for a gateway with no settings, or no such mode.
	 */
	public function account(): array {
		if ( ! $this->declaresMode() ) {
			return array();
		}

		return array_filter( $this->read(), fn( string $name ): bool => Privacy::Secret !== $this->field( $name )->privacy(), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Opens every credential of the mode, to know before a call that the gateway can be asked.
	 *
	 * @since 0.2.0
	 *
	 * @throws CredentialUnavailable When one was never saved, or does not open, or the gateway has no such mode.
	 */
	public function openAll(): void {
		$secrets = $this->descriptor->secrets();

		if ( array() !== $secrets && ! $this->declaresMode() ) {
			throw new CredentialUnavailable( CredentialUnavailable::MISSING, $this->mode->value );
		}

		foreach ( $secrets as $field ) {
			$this->secret( $field->name() );
		}
	}

	/**
	 * Reads every setting of the mode with one read of the gateway's document.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string|null> The values, sealed for a credential, by the names the gateway declared them with.
	 */
	private function read(): array {
		$settings = GatewaySettingsDeclaration::forMode( $this->descriptor, $this->mode );

		if ( array() === $settings ) {
			return array();
		}

		$stored = $this->store->values( $settings );
		$values = array();

		foreach ( $this->descriptor->settings as $field ) {
			$values[ $field->name() ] = $stored[ $this->storedName( $field->name() ) ];
		}

		return $values;
	}

	/**
	 * Returns a setting's declaration.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the gateway has no such mode, or declared no such setting.
	 *
	 * @param string $name The setting's name, as declared.
	 * @return FieldSpec The field.
	 */
	private function field( string $name ): FieldSpec {
		if ( ! $this->declaresMode() ) {
			throw new \InvalidArgumentException( sprintf( 'The gateway %1$s has no %2$s mode.', $this->descriptor->id, $this->mode->value ) );
		}

		foreach ( $this->descriptor->settings as $field ) {
			if ( $field->name() === $name ) {
				return $field;
			}
		}

		throw new \InvalidArgumentException( sprintf( 'The gateway %1$s declares no setting %2$s.', $this->descriptor->id, $name ) );
	}

	/**
	 * Returns the name a setting of this mode is stored under.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The setting's name, as declared.
	 * @return string The stored name.
	 */
	private function storedName( string $name ): string {
		return GatewaySettingsDeclaration::storedName( $this->descriptor->id, $this->mode, $name );
	}

	/**
	 * Tells whether the gateway declares this mode.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it does.
	 */
	private function declaresMode(): bool {
		return in_array( $this->mode, $this->descriptor->modes, true );
	}
}
