<?php
/**
 * SecretRotationConcurrencyTest: a rotation waits for a secret being sealed and written
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\Envelope;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- B sends the store's own statement, prepared from the store's own constant, so a change to the statement changes B's too.

/**
 * A writes a secret: it seals it with the active key and writes it in one transaction. B, another
 * request, rotates the keys at that moment, with the store's own compare-and-swap statement on the
 * data keys row. B must wait until A commits, so the rotation comes after the write, and the
 * re-sealing that follows finds the secret sealed with the key B moved to retiring.
 *
 * Were B not to wait, A could write a value sealed with a key that a rotation and a rekey had
 * already retired, and the secret would never open again.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class SecretRotationConcurrencyTest extends DatabaseTestCase {

	/**
	 * Creates the key registry and removes what an earlier run left.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );
	}

	/**
	 * Removes what the test wrote.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		SecretsHarness::removeAll();

		parent::tear_down();
	}

	/**
	 * Tests that a rotation of the data keys waits until a secret sealed with the active key is committed.
	 *
	 * Planted violation: in SettingsStore::READ_ROW_FOR_SHARE, drop `LOCK IN SHARE MODE`. B's
	 * rotation then goes through while A still holds the value it sealed, and awaitWaiting() fails.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rotation_waits_for_a_secret_being_written(): void {
		global $wpdb;

		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();
		$read    = (string) SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION );
		$rotated = (string) wp_json_encode(
			array(
				'version' => 2,
				'values'  => (object) array(),
			)
		);

		$b         = $this->secondConnection();
		$b_rotates = (string) $wpdb->prepare( SettingsStore::COMPARE_AND_SWAP, $wpdb->options, $rotated, SecretsHarness::DATA_KEYS_OPTION, $read );

		$this->db->transaction(
			function () use ( $secrets, $b, $b_rotates ): void {
				$sealed = $secrets->vault->seal( $secrets->registry->setting( 'api_key' ), 'sk_live_during_rotation' );

				$b->queryAsync( $b_rotates );
				$this->awaitWaiting( $b, $b_rotates, 'updating' );

				$secrets->store->writeScalars( array( 'api_key' => $sealed ) );
			}
		);

		$this->assertSame( 1, $b->reap(), 'B\'s rotation did not go through once A had committed.' );
		$this->assertSame( $key_id, Envelope::keyId( (string) SecretsHarness::stored( 'seocart_fixture_gateway_api_key' ) ) );
		$this->assertSame( $rotated, SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ) );
	}
}
