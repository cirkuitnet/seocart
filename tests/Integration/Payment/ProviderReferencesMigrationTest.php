<?php
/**
 * Tests the migration that rewrites the provider references and event ids kept before they were canonical
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\CanonicalizeProviderReferences;
use SEOCart\Payment\Infrastructure\Migrations\CreateWebhookReceipts;
use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Each authorization below was applied as the request's answer was before references were canonical, its provider object kept as the answer gave it; each receipt kept its event id as given. The migration rewrites what is neither an identifier nor a shape the store makes, leaves everything else, fails rather than give two rows one identity, and a delivery after it meets the row an earlier one wrote.
 *
 * Planted violations, each shown red and removed:
 * - in CanonicalizeProviderReferences::reference(), rewrite a value of the earlier `event:` form as
 *   an earlier identity: a provider's object `event:test:x` is then taken for one;
 * - in CanonicalizeProviderReferences::reference(), leave every value as it is (`return $kept;`):
 *   the provider's object kept verbatim is no longer the one a delivery keeps;
 * - in CanonicalizeProviderReferences::reference(), leave every value that begins with the mark:
 *   a provider's `~charge/1` kept verbatim stays, and a delivery of it misses its row;
 * - in CanonicalizeProviderReferences::eventId(), the same: a receipt's `~evt/1` stays, and a
 *   delivery of it misses its receipt;
 * - in CanonicalizeProviderReferences::runBatch(), swallow the DuplicateKey a rewrite meets: the
 *   migration then finishes and releases the store with two rows meant to share one identity.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class ProviderReferencesMigrationTest extends PaymentTestCase {

	/**
	 * A provider object shaped like the digest an earlier development build kept: an identifier, left as it is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGEST_SHAPED = 'sha256:2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae';

	/**
	 * The receipts over `$this->db`.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlWebhookReceipts
	 */
	private MysqlWebhookReceipts $receipts;

	/**
	 * Creates the receipts' table.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateWebhookReceipts() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->receipts = new MysqlWebhookReceipts( $this->db );
	}

	/**
	 * Tests that a reference or event id that is neither an identifier nor a shape the store makes, `~charge/1` and `~evt/1` among them, is rewritten to its stand-in, every other value is left as it is, running it again changes nothing, and a delivery after it meets the row an earlier one wrote.
	 *
	 * @since 0.2.0
	 */
	public function test_what_is_no_identifier_and_no_shape_the_store_makes_is_rewritten(): void {
		$made = '~sha256:' . hash( 'sha256', 'cap 9' );

		$objects = array(
			'cap 1'               => '~sha256:' . hash( 'sha256', 'cap 1' ),
			'~charge/1'           => '~sha256:' . hash( 'sha256', '~charge/1' ),
			'event:test:x'        => 'event:test:x',
			self::DIGEST_SHAPED   => self::DIGEST_SHAPED,
			$made                 => $made,
			'~event:test:evt_new' => '~event:test:evt_new',
			'ch_2'                => 'ch_2',
		);
		$intents = array();

		foreach ( array_keys( $objects ) as $object ) {
			$intents[ $object ] = $this->authorizedAs( $object, null );
		}

		$odd = $this->authorizedAs( 'ch_3', 'pi 1' );

		foreach ( array( self::DIGEST_SHAPED, 'evt 2', '~sha256:' . hash( 'sha256', 'evt 4' ), 'evt_3', '~evt/1' ) as $eventId ) {
			$this->db->transaction( fn() => $this->receipts->claim( StubGateway::ID, Mode::Test, $eventId, 'capture.approved', null, str_repeat( 'a', 64 ), '' ) );
		}

		$report = $this->migrator()->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( CanonicalizeProviderReferences::ID, array_column( $report->applied(), 'id' ) );

		foreach ( $objects as $kept => $canonical ) {
			$this->assertSame( $canonical, $this->objectOf( $intents[ $kept ] ), $kept );
		}

		$this->assertSame( '~sha256:' . hash( 'sha256', 'pi 1' ), $this->intentRow( $odd->uuid )['provider_intent_id'], 'The intent\'s reference kept verbatim is its stand-in now.' );
		$this->assertSame(
			array( self::DIGEST_SHAPED, '~sha256:' . hash( 'sha256', 'evt 2' ), '~sha256:' . hash( 'sha256', 'evt 4' ), 'evt_3', '~sha256:' . hash( 'sha256', '~evt/1' ) ),
			array_column( $this->db->fetchAll( 'SELECT event_id FROM %i ORDER BY id', $this->table( WebhookReceiptTables::RECEIPTS ) ), 'event_id' )
		);

		// The request's answer delivered again by webhook: canonical, it meets the row the request wrote.
		foreach ( array( 'cap 1', '~charge/1' ) as $object ) {
			$again = $this->deliver( DeliveredValues::canonical( $this->authorization( $intents[ $object ], $object, null ) ) );

			$this->assertSame( ApplicationKind::Duplicate, $again->kind, $object . ': a delivery after the migration meets the row an earlier one wrote.' );
		}

		foreach ( array( 'evt 2', '~evt/1' ) as $eventId ) {
			$this->assertNotNull( $this->receipts->find( StubGateway::ID, Mode::Test, DeliveredValues::eventId( $eventId ) ), $eventId . ': a receipt is found by the event id a delivery keeps.' );
		}

		$rerun = $this->captureQueries( fn() => ( new CanonicalizeProviderReferences() )->runBatch( null, $this->db ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'UPDATE' ), 'A batch run again rewrites nothing' );
	}

	/**
	 * Tests that a rewrite that would give a row the identity another row holds fails the migration by name, leaves both rows as they were, and keeps the store held.
	 *
	 * @since 0.2.0
	 */
	public function test_a_rewrite_that_would_share_an_identity_fails_the_migration(): void {
		$verbatim = $this->authorizedAs( 'cap 1', null );
		$taken    = $this->authorizedAs( '~sha256:' . hash( 'sha256', 'cap 1' ), null );

		try {
			$this->migrator()->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'The migration finished with two rows meant to share one identity.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( CanonicalizeProviderReferences::ID, $failed->migrationId() );
			$this->assertStringContainsString( 'two rows would share an identity', $failed->detail() );
		}

		$this->assertSame( array( 'cap 1', '~sha256:' . hash( 'sha256', 'cap 1' ) ), array( $this->objectOf( $verbatim ), $this->objectOf( $taken ) ), 'Both rows are as they were.' );
		$this->assertSame( 'failed', (string) $this->db->fetchValue( 'SELECT state FROM %i WHERE migration_id = %s', $this->db->table( 'migrations' ), CanonicalizeProviderReferences::ID ), 'The migration is recorded failed.' );
		$this->assertNotSame( CanonicalizeProviderReferences::ID, ( new MigrationsTableState( $this->db ) )->schemaHead(), 'The store stays held: the head is not this migration.' );
	}

	/**
	 * Tests the migration's place: after the receipts' migration, the newest, in the production registry, and the store waits for it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_after_the_receipts_and_holds_the_store(): void {
		$ids = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( CanonicalizeProviderReferences::ID, $ids, 'The registry runs it.' );
		$this->assertGreaterThan( CreateWebhookReceipts::ID, CanonicalizeProviderReferences::ID, 'It runs after the receipts are created.' );
		$this->assertFalse( ( new CanonicalizeProviderReferences() )->canOperateHalfApplied(), 'A delivery before it ran would miss the row an earlier one wrote.' );
	}

	/**
	 * Places an order and applies its authorization as a request's answer was applied before references were canonical: the provider's object and reference kept as given.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $providerObject The provider's object.
	 * @param string|null $providerRef The provider's reference to the intent, or null.
	 * @return IntentRef The intent.
	 */
	private function authorizedAs( string $providerObject, ?string $providerRef ): IntentRef {
		list( , $intent ) = $this->placeWithIntent( NewOrders::forTwoLines( 'USD', 'USD' ) );

		$this->assertSame( ApplicationKind::Applied, $this->deliver( $this->authorization( $intent, $providerObject, $providerRef ) )->kind, $providerObject );

		return $intent;
	}

	/**
	 * Builds the stand-in's approval of an intent's authorization, named as given.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef   $intent      The intent.
	 * @param string      $providerObject The provider's object.
	 * @param string|null $providerRef The provider's reference to the intent, or null.
	 * @return GatewayResult The approval.
	 */
	private function authorization( IntentRef $intent, string $providerObject, ?string $providerRef ): GatewayResult {
		return new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $intent->uuid, Money::of( self::GRAND_TOTAL, Currency::of( 'USD' ) ), $providerObject, $providerRef );
	}

	/**
	 * Reads the provider object of an intent's authorization.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef $intent The intent.
	 * @return string The object.
	 */
	private function objectOf( IntentRef $intent ): string {
		return (string) $this->db->fetchValue( "SELECT provider_object_id FROM %i WHERE intent_id = ( SELECT id FROM %i WHERE uuid = %s ) AND operation = 'authorize'", $this->table( PaymentTables::TRANSACTIONS ), $this->table( PaymentTables::INTENTS ), $intent->uuid );
	}

	/**
	 * Builds the migrator over the bootstrap and this migration.
	 *
	 * @since 0.2.0
	 *
	 * @return Migrator The migrator.
	 */
	private function migrator(): Migrator {
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), array( new PlatformBootstrapMigration(), new CanonicalizeProviderReferences() ), FrozenClock::at( '2026-10-10 12:00:00' ), $this->reporter() );
	}
}
