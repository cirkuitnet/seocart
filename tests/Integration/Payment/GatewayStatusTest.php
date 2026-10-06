<?php
/**
 * Tests the status of the store's gateways: what each one's status says, and that it is read with one statement for the open payments and no credential opened
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayStatus;
use SEOCart\Payment\Application\GatewayStatuses;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Payment\Infrastructure\Doctor\GatewaysCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\UndescribableGateway;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Payment\GatewayCommandRecorder;
use SEOCart\Tests\Support\Payment\GatewayKernel;
use SEOCart\Tests\Support\Payment\RefundTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test moves payments to a gateway that is gone, and damages a stored credential, on purpose.

/**
 * The stand-in and a gateway plugin's `second` (test and live modes, a secret key, an account country, a host) are registered: each status says the gateway's declaration, its plugin, its mode and effective mode, its switch, the state of its credentials for each mode and how many of its payments are open; the gateways open payments name that are not registered are counted; the open payments of every gateway are one statement; and a credential altered after its header still reads configured, so nothing was opened. `wp seocart gateway` prints the statuses, the refused registrations and the gateways gone, switches a gateway off and on, and never prints a credential.
 *
 * @since 0.2.0
 */
final class GatewayStatusTest extends RefundTestCase {

	/**
	 * The production wiring, with the test's data keys.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * Builds the kernel and has `second` register through the action.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		$this->kernel = GatewayKernel::over( $this->db, $this->reporter(), $this->publisherOver( $this->db ) );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'second', array( Mode::Test, Mode::Live ), null, null, null, PaymentGateway::CONTRACT_VERSION, array( DeclaredGateway::host( 'second' ) ) ), $registry->context( 'second' ) ) );
			}
		);
	}

	/**
	 * Deletes the boot record, the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Removes the record the command test planted.
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests each status, the unregistered gateway's count, one statement for the open payments, and a credential judged without opening it.
	 *
	 * @since 0.2.0
	 */
	public function test_each_gateways_status_and_the_gateways_open_payments_name(): void {
		$this->configureLive();

		foreach ( array( array( 'second', Mode::Live ), array( 'second', Mode::Live ), array( StubGateway::ID, Mode::Test ), array( StubGateway::ID, Mode::Test ) ) as $intent ) {
			GatewayKernel::openIntent( $this->kernel, $intent[0], $intent[1] );
		}

		// One of the stand-in's payments belongs to a gateway whose plugin has gone.
		$this->db->execute( "UPDATE %i SET gateway_id = 'gone' WHERE gateway_id = %s ORDER BY id LIMIT 1", $this->table( PaymentTables::INTENTS ), StubGateway::ID );

		$statuses = $this->statuses();
		$all      = array();
		$gone     = array();
		$log      = $this->captureQueries(
			static function () use ( $statuses, &$all, &$gone ): void {
				$all  = $statuses->all();
				$gone = $statuses->unregistered();
			}
		);

		$this->assertQueryCount( 1, $log->forTable( $this->table( PaymentTables::INTENTS ) ), 'The open payments of every gateway' );
		$this->assertSame( array( StubGateway::ID, 'second' ), array_map( static fn( GatewayStatus $status ): string => $status->id, $all ) );
		$this->assertSame(
			array(
				'id'             => 'second',
				'label'          => 'Declared gateway',
				'type'           => GatewayDescriptor::TYPE_PAYMENTS,
				'contract'       => PaymentGateway::CONTRACT_VERSION,
				'plugin'         => null,
				'mode'           => 'live',
				'effective_mode' => 'live',
				'enabled'        => true,
				'credentials'    => array(
					'test' => 'missing',
					'live' => 'configured',
				),
				'currencies'     => array( 'USD', 'GBP', 'EUR' ),
				'hosts'          => array(
					array(
						'id'      => 'second',
						'host'    => 'api.second.example',
						'service' => 'Example second',
					),
				),
				'open_intents'   => 2,
				'webhook_events' => array(),
			),
			$all[1]->toArray()
		);
		$this->assertSame( array( 'test', 'test', array( 'test' => 'configured' ), 1 ), array( $all[0]->toArray()['mode'], $all[0]->toArray()['effective_mode'], $all[0]->toArray()['credentials'], $all[0]->openIntents ), 'The stand-in needs no credential.' );
		$this->assertSame( array( 'gone' => 1 ), $gone, 'A gateway gone with open payments is counted.' );

		GatewayKernel::alterStored( 'second', Mode::Live, 'secret_key', static fn( string $sealed ): string => substr( $sealed, 0, -12 ) . str_repeat( 'A', 12 ) );
		$this->assertSame( 'configured', $this->statuses()->of( 'second' )->toArray()['credentials']['live'], 'A credential altered after its header reads configured: nothing was opened.' );

		GatewayKernel::alterStored( 'second', Mode::Live, 'secret_key', static fn( string $sealed ): string => (string) preg_replace( '/^v1:[0-9a-f]{16}:/', 'v1:' . str_repeat( '0', 16 ) . ':', $sealed ) );
		$this->assertSame( 'unreadable', $this->statuses()->of( 'second' )->toArray()['credentials']['live'], 'A credential sealed with a key the site does not hold.' );
	}

	/**
	 * Tests that the command prints the statuses as a table and as JSON, the refused registrations and the gateways gone, switches a gateway off and on, refuses what it cannot do, and never prints a credential.
	 *
	 * Planted violation, shown red and removed: in GatewayStatuses::of(), give `second`'s live
	 * secret as its label: the scan of everything printed finds it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_command_prints_the_states_and_never_a_credential(): void {
		( new BootOption( $this->db, $this->reporter() ) )->mutate( static fn(): BootRecord => KernelTestCase::installedRecord() );

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'second', array( Mode::Test ), array() ) ) );
			}
		);

		$this->configureLive();
		GatewayKernel::openIntent( $this->kernel, 'second', Mode::Live );

		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status' ) ) );
		$this->assertNoCredentialIn( $command->printed() );
		$this->assertSame(
			array(
				'id'             => 'second',
				'label'          => 'Declared gateway',
				'plugin'         => '-',
				'enabled'        => 'yes',
				'mode'           => 'live',
				'effective_mode' => 'live',
				'credentials'    => 'test: missing, live: configured',
				'currencies'     => 'USD, GBP, EUR',
				'hosts'          => 'api.second.example',
				'open_intents'   => 1,
			),
			$command->items[1]
		);
		$this->assertContains( 'Refused: second, from no plugin (duplicate): A gateway of this id is registered already.', $command->lines );

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status', 'second' ), array( 'format' => 'json' ) ) );

		$json = json_decode( $command->last(), true );

		$this->assertSame( array( 'second', 1, 'configured', 'missing' ), array( $json['gateways'][0]['id'], $json['gateways'][0]['open_intents'], $json['gateways'][0]['credentials']['live'], $json['gateways'][0]['credentials']['test'] ) );

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'disable', 'second' ) ) );
		$this->assertSame( 'Disabled second: it takes no new payment. Its 1 open payment is still captured, voided, refunded and reconciled through it.', $command->last() );
		$command->run( array( 'status' ) );
		$this->assertSame( 'no', $command->items[3]['enabled'] );
		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'enable', 'second' ) ) );
		$command->run( array( 'status' ) );
		$this->assertSame( 'yes', $command->items[5]['enabled'] );

		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'disable', 'gone' ) ) );
		$this->assertStringStartsWith( 'payment.gateway_unavailable:', $command->last() );
		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'enable', 'Not-An-Id' ) ) );
		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'status' ), array( 'format' => 'yaml' ) ) );
		$this->assertSame( GatewayCommand::EXIT_FAILED, $command->run( array( 'disable' ) ) );

		$this->assertNoCredentialIn( $command->printed() );
	}

	/**
	 * Tests that a registration refused for what a plugin threw names the exception by its class, never by its message: the table, the JSON, doctor's and Site Health's check and the log never show the credential the message quoted.
	 *
	 * Planted violation, shown red and removed: in Gateways::register(), keep the message of what
	 * describe() threw as the refusal's detail, as before: the table and the JSON print the credential.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refusal_never_shows_the_message_a_plugin_threw(): void {
		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new UndescribableGateway( static fn(): GatewayDescriptor => throw new \RuntimeException( 'key sk_test_example refused' ) ) );
			}
		);
		add_action(
			GatewayRegistry::ACTION,
			static function (): void {
				throw new \RuntimeException( 'the listener: key sk_test_example refused' );
			},
			20
		);

		$command = new GatewayCommandRecorder( $this->kernel );

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status' ) ) );

		$table = $command->lines;

		$this->assertSame( GatewayCommand::EXIT_OK, $command->run( array( 'status' ), array( 'format' => 'json' ) ) );

		$json   = json_decode( $command->last(), true );
		$check  = GatewayKernel::request( $this->kernel, $this->db, $this->reporter(), $this->publisherOver( $this->db ) )->get( GatewaysCheck::class );
		$result = $check->run();
		$shown  = array(
			'the command' => $command->printed(),
			'doctor'      => $result->summary . "\n" . implode( "\n", $result->findings ),
			'Site Health' => (string) wp_json_encode( $check->siteHealthTest() ),
			'the reports' => (string) wp_json_encode( $this->reports ),
			'the log'     => (string) wp_json_encode( $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i', $this->db->table( LogsTable::NAME ) ) ),
		);

		foreach ( $shown as $where => $text ) {
			$this->assertStringNotContainsString( 'sk_test_example', $text, "The message a plugin threw reached {$where}." );
		}

		$this->assertContains( 'Refused: ' . UndescribableGateway::class . ', from no plugin (invalid_descriptor): Its descriptor could not be built: describe(), or the settings it declares, threw RuntimeException.', $table );
		$this->assertContains( 'Refused: RuntimeException, from no plugin (registration_failed): A listener of the registration action threw RuntimeException.', $table );
		$this->assertSame( array( 'invalid_descriptor', 'registration_failed' ), array_column( (array) ( is_array( $json ) ? $json['refused'] : array() ), 'reason' ) );
	}

	/**
	 * Asserts that a text holds `second`'s live credential neither plain nor as its document stores it, sealed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $printed What was printed.
	 */
	private function assertNoCredentialIn( string $printed ): void {
		$sealed = (string) ( GatewayKernel::storedDocument( 'second' )['values'][ GatewayKernel::name( 'second', Mode::Live, 'secret_key' ) ] ?? '' );

		$this->assertStringStartsWith( 'v1:', $sealed, 'The credential is stored sealed.' );

		foreach ( array( 'sk_live_planted', $sealed ) as $value ) {
			$this->assertStringNotContainsString( $value, $printed, 'A credential, plain or sealed, was printed.' );
		}
	}

	/**
	 * Returns a reader of the gateways' statuses, as a new request would build it.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayStatuses The reader.
	 */
	private function statuses(): GatewayStatuses {
		wp_cache_flush();

		return new GatewayStatuses( $this->kernel->get( Gateways::class ), $this->kernel->get( PaymentRepository::class ) );
	}

	/**
	 * Saves `second`'s live settings and makes live its mode; its test mode is never set up.
	 *
	 * @since 0.2.0
	 */
	private function configureLive(): void {
		GatewayKernel::writeDocument(
			$this->kernel,
			DeclaredGateway::descriptor( 'second' ),
			array(
				'second_mode' => Mode::Live->value,
				GatewayKernel::name( 'second', Mode::Live, 'secret_key' ) => 'sk_live_planted',
				GatewayKernel::name( 'second', Mode::Live, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}
}
