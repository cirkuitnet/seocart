<?php
/**
 * GatewayKernel: the production wiring of the payment module, with gateway plugins and their credentials, for the registry's tests
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Order\Application\Orders;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\SecretsHarness;

/**
 * Builds the kernel's own container over a test's connection, with data keys of its own, and writes a gateway's settings document as the plugin writes one.
 *
 * Owns one fact: what a registry test replaces in the production wiring: the transaction manager
 * is the connection, the publisher the test's, and the encryption key a new random one. The log's
 * and the data keys' tables are created, and the keys initialized, so a gateway's credentials can
 * be sealed and opened. A gateway plugin registers through the real action, which the test adds.
 *
 * @since 0.2.0
 */
final class GatewayKernel {

	/**
	 * Builds the container, creates the log and the key registry, and initializes the data keys.
	 *
	 * @since 0.2.0
	 *
	 * @param Database       $db        The connection.
	 * @param callable       $report    Receives the reports of the kernel's own services.
	 * @param EventPublisher $events    The publisher.
	 * @param array          $overrides Optional. Further replacements, which win. Default none.
	 * @return Container The container.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param array<string, callable(Container): object>   $overrides
	 */
	public static function over( Database $db, callable $report, EventPublisher $events, array $overrides = array() ): Container {
		$operations = new SchemaOperations( $db, new DdlGenerator(), new SchemaVerifier( $db ) );

		( new CreateLogsMigration() )->up( $operations );
		( new CreateSecretKeysMigration() )->up( $operations );

		$key       = SecretsHarness::newEncryptionKey();
		$container = KernelContainer::build(
			$db,
			$report,
			$overrides + array(
				TransactionManager::class => static fn(): TransactionManager => $db,
				EventPublisher::class     => static fn(): EventPublisher => $events,
				EncryptionKey::class      => static fn(): EncryptionKey => $key,
			)
		);

		$container->get( SecretKeys::class )->initialize();

		return $container;
	}

	/**
	 * Builds the wiring of another request on the same site: the same connection and data keys, and nothing created again.
	 *
	 * @since 0.2.0
	 *
	 * @param Container      $kernel    The container over() built.
	 * @param Database       $db        The connection.
	 * @param callable       $report    Receives the reports of the kernel's own services.
	 * @param EventPublisher $events    The publisher.
	 * @param array          $overrides Optional. Further replacements, which win. Default none.
	 * @return Container The container.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param array<string, callable(Container): object>   $overrides
	 */
	public static function request( Container $kernel, Database $db, callable $report, EventPublisher $events, array $overrides = array() ): Container {
		$key = $kernel->get( EncryptionKey::class );

		return KernelContainer::build(
			$db,
			$report,
			$overrides + array(
				TransactionManager::class => static fn(): TransactionManager => $db,
				EventPublisher::class     => static fn(): EventPublisher => $events,
				EncryptionKey::class      => static fn(): EncryptionKey => $key,
			)
		);
	}

	/**
	 * Writes a gateway's settings document whole, as the plugin writes one: its credentials sealed in the transaction that writes it, by compare-and-swap.
	 *
	 * @since 0.2.0
	 *
	 * @param Container             $kernel     The container.
	 * @param GatewayDescriptor     $descriptor The gateway's descriptor.
	 * @param array<string, string> $values     The plain values, keyed by stored name, such as `example_live_secret_key`.
	 */
	public static function writeDocument( Container $kernel, GatewayDescriptor $descriptor, #[\SensitiveParameter] array $values ): void {
		$store    = $kernel->get( SettingsStore::class );
		$vault    = $kernel->get( SecretVault::class );
		$group    = GatewaySettingsDeclaration::group( $descriptor->id );
		$settings = array();

		foreach ( GatewaySettingsDeclaration::of( $descriptor ) as $setting ) {
			$settings[ $setting->name() ] = $setting;
		}

		$kernel->get( TransactionManager::class )->transaction(
			static function () use ( $store, $vault, $group, $settings, $values ): void {
				$document = $store->documentAsStored( $group );
				$written  = array();

				foreach ( $values as $name => $value ) {
					$written[ $name ] = $settings[ $name ]->isSecret() ? $vault->seal( $settings[ $name ], $value ) : $value;
				}

				$store->replaceDocument( $group, $document->version(), $written + $document->values() );
			}
		);
	}

	/**
	 * Returns the stored name of a gateway's setting of one mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @param Mode   $mode      The mode.
	 * @param string $name      The setting's declared name.
	 * @return string The stored name.
	 */
	public static function name( string $gatewayId, Mode $mode, string $name ): string {
		return GatewaySettingsDeclaration::storedName( $gatewayId, $mode, $name );
	}

	/**
	 * Places an order in USD and creates its intent through a gateway, in a mode: an open payment, waiting for its authorization's answer.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $kernel    The container, whose order and payment tables exist.
	 * @param string    $gatewayId The gateway.
	 * @param Mode      $mode      The intent's mode.
	 * @return string The intent's uuid.
	 */
	public static function openIntent( Container $kernel, string $gatewayId, Mode $mode ): string {
		$document = NewOrders::forTwoLines( 'USD', 'USD' );
		$orders   = $kernel->get( Orders::class );
		$payments = $kernel->get( PaymentService::class );

		return (string) $kernel->get( TransactionManager::class )->transaction(
			static function () use ( $orders, $payments, $document, $gatewayId, $mode ): string {
				$order = $orders->insert( $document, Actor::user( 0 ) );

				return $payments->createIntent( $order->id, $gatewayId, $mode, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId )->uuid;
			}
		);
	}

	/**
	 * Changes a gateway's stored setting where its document is stored, past every check, as damage or another writer would.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $gatewayId The gateway's id.
	 * @param Mode     $mode      The mode.
	 * @param string   $name      The setting's declared name.
	 * @param \Closure $change    Returns the text to store in place of the stored text it is given (string); null removes the value.
	 */
	public static function alterStored( string $gatewayId, Mode $mode, string $name, \Closure $change ): void {
		global $wpdb;

		$option = Setting::OPTION_PREFIX . GatewaySettingsDeclaration::group( $gatewayId );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The row as stored, past every cache.
		$document = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) ), true );
		$stored   = self::name( $gatewayId, $mode, $name );
		$value    = $change( (string) ( $document['values'][ $stored ] ?? '' ) );

		if ( null === $value ) {
			unset( $document['values'][ $stored ] );
		} else {
			$document['values'][ $stored ] = $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test damages a stored document on purpose.
		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $document ) ), array( 'option_name' => $option ) );
		wp_cache_flush();
	}

	/**
	 * Returns a gateway's settings document as the database stores it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return array{version?: int, values?: array<string, int|string>} The document; empty when it was never written.
	 */
	public static function storedDocument( string $gatewayId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test reads the row as stored, past every cache.
		return (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Setting::OPTION_PREFIX . GatewaySettingsDeclaration::group( $gatewayId ) ) ), true );
	}

	/**
	 * Deletes the options the registry tests write: the data keys and every gateway's document.
	 *
	 * @since 0.2.0
	 */
	public static function deleteOptions(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test removes the options it committed.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s OR option_name LIKE %s', $wpdb->options, SecretsHarness::DATA_KEYS_OPTION, $wpdb->esc_like( Setting::OPTION_PREFIX . GatewaySettingsDeclaration::GROUP_PREFIX ) . '%' ) );
		wp_cache_flush();
	}
}
