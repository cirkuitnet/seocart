<?php
/**
 * SecretsStatusTest: Site Health and the support report tell the truth about the secrets, and never hold one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\EncryptionKeyState;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;

/**
 * The verdict in each configuration a site can be in: without the constant, with it, with it
 * defined after the first key, while a key retires, with the constant broken, and with the canary
 * failing.
 *
 * @since 0.1.0
 */
final class SecretsStatusTest extends DatabaseTestCase {

	/**
	 * The secret the tests store.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SECRET = 'sk_live_status_9QxT4mZp';

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
	 * Tests that without the constant Site Health is critical and says the key sits in the database.
	 *
	 * Planted violation: in SecretsStatus::findings(), drop the finding for an absent constant. The
	 * site without the constant is then reported as good.
	 *
	 * @since 0.1.0
	 */
	public function test_without_the_constant_site_health_is_critical_and_says_why(): void {
		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();

		$secrets->write( array( 'api_key' => self::SECRET ) );

		$report = $secrets->status->report();
		$test   = $secrets->status->siteHealthTest( $report );

		$this->assertTrue( $report->canary->ok(), 'The canary must open: the keys work, they are only unprotected.' );
		$this->assertSame( EncryptionKeyState::Absent, $report->encryptionKey );
		$this->assertFalse( $report->keys[0]['wrapped'] );
		$this->assertSame( 1, $report->keys[0]['records'] );
		$this->assertSame( $key_id, $report->keys[0]['key_id'] );
		$this->assertSame( SecretsStatus::CRITICAL, $test['status'] );
		$this->assertSame( SecretsStatus::TEST, $test['test'] );
		$this->assertStringContainsString( 'SEOCART_ENCRYPTION_KEY is not defined', $test['description'] );
		$this->assertStringContainsString( 'anyone with a copy of the database can read them', $test['description'] );
		$this->assertNoSecretIn( $secrets, $test, $report->toArray() );
	}

	/**
	 * Tests that with the constant, and nothing else wrong, Site Health is good.
	 *
	 * @since 0.1.0
	 */
	public function test_with_the_constant_site_health_is_good(): void {
		$kek     = SecretsHarness::newEncryptionKey();
		$secrets = new SecretsHarness( $this->db, $kek );

		$secrets->keys->initialize();
		$secrets->write( array( 'api_key' => self::SECRET ) );

		$next   = $secrets->withKey( $kek );
		$report = $next->status->report();
		$test   = $next->status->siteHealthTest( $report );

		$this->assertSame( EncryptionKeyState::Present, $report->encryptionKey );
		$this->assertTrue( $report->keys[0]['wrapped'] );
		$this->assertSame( SecretsStatus::GOOD, $test['status'] );
		$this->assertStringContainsString( 'SEOCART_ENCRYPTION_KEY in wp-config.php protects', $test['description'] );
		$this->assertNoSecretIn( $next, $test, $report->toArray() );
	}

	/**
	 * Tests that a constant defined after the first key is not taken for protection until rotate and rekey replace the key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_constant_defined_later_protects_nothing_until_the_key_is_replaced(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( array( 'api_key' => self::SECRET ) );

		$later = $secrets->withKey( SecretsHarness::newEncryptionKey() );
		$test  = $later->status->siteHealthTest();

		$this->assertSame( SecretsStatus::CRITICAL, $test['status'] );
		$this->assertStringContainsString( $old . ' was created before it was and is still stored unprotected', $test['description'] );

		$later->keys->rotate();

		$retiring = $later->status->siteHealthTest();

		$this->assertSame( SecretsStatus::CRITICAL, $retiring['status'], 'The unprotected key still retiring was forgotten.' );
		$this->assertStringContainsString( 'is being retired, and 1 stored secret is still sealed with it', $retiring['description'] );

		$later->vault->rekey( 10 );

		$done = $later->status->report();

		$this->assertSame( SecretsStatus::GOOD, SecretsStatus::severity( $done ) );
		$this->assertSame( array( SecretKeysTable::RETIRED, SecretKeysTable::ACTIVE ), array_column( $done->keys, 'state' ) );
		$this->assertNull( $done->keys[0]['wrapped'], 'A retired key still has material.' );
	}

	/**
	 * Tests that a key waiting to retire is a recommendation, not a critical.
	 *
	 * @since 0.1.0
	 */
	public function test_a_retiring_key_is_a_recommendation(): void {
		$kek     = SecretsHarness::newEncryptionKey();
		$secrets = new SecretsHarness( $this->db, $kek );

		$secrets->keys->initialize();
		$secrets->write( array( 'api_key' => self::SECRET ) );
		$secrets->keys->rotate();

		$report = $secrets->withKey( $kek )->status->report();

		$this->assertSame( SecretsStatus::RECOMMENDED, SecretsStatus::severity( $report ) );
		$this->assertSame( 1, $report->key( SecretKeysTable::RETIRING )['records'] ?? null );
	}

	/**
	 * Tests that an invalid constant, a failing canary and orphaned records are each critical.
	 *
	 * @since 0.1.0
	 */
	public function test_broken_configurations_are_critical(): void {
		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();

		$secrets->write( array( 'api_key' => self::SECRET ) );

		$invalid = $secrets->withKey( EncryptionKey::fromValue( 'typo' ) )->status->siteHealthTest();

		$this->assertSame( SecretsStatus::CRITICAL, $invalid['status'] );
		$this->assertSame( 'SEOCART_ENCRYPTION_KEY is not a valid key', $invalid['label'] );

		SecretsHarness::tamper( 'seocart_fixture_gateway_api_key', str_replace( 'v1:' . $key_id . ':', 'v1:0123456789abcdef:', (string) SecretsHarness::stored( 'seocart_fixture_gateway_api_key' ) ) );

		$orphaned = new SecretsHarness( $this->db );

		$this->assertSame( 1, $orphaned->status->report()->orphaned );
		$this->assertStringContainsString( '1 stored secret was sealed with a data key this site no longer has', $orphaned->status->siteHealthTest()['description'] );

		$wrapped = new SecretsHarness( $this->db, SecretsHarness::newEncryptionKey() );

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );
		$wrapped->keys->initialize();

		$failing = $wrapped->withKey( SecretsHarness::newEncryptionKey() )->status->siteHealthTest();

		$this->assertSame( SecretsStatus::CRITICAL, $failing['status'] );
		$this->assertSame( 'SEOCart cannot open its stored secrets', $failing['label'] );
		$this->assertStringContainsString( 'SEOCART_ENCRYPTION_KEY changed, or this database was restored onto a different site', $failing['description'] );
	}

	/**
	 * Asserts that nothing the status returned holds the stored secret or a key.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsHarness $secrets The harness that stored the secret.
	 * @param mixed          ...$outputs What the status returned.
	 */
	private function assertNoSecretIn( SecretsHarness $secrets, mixed ...$outputs ): void {
		$text = (string) wp_json_encode( $outputs );
		$key  = $secrets->keys->active();

		$this->assertStringNotContainsString( self::SECRET, $text );
		$this->assertStringNotContainsString( $secrets->keys->cipher()->encode( $key->bytes() ), $text );
		$this->assertStringNotContainsString( (string) SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ), $text );
	}
}
