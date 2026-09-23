<?php
/**
 * SecretsCommandTest: `wp seocart secrets status|rotate|rekey`, and that nothing it prints is a secret
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\Cli\SecretsCommand;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;

/**
 * Runs the command through run(), without WP-CLI, over committed keys and secrets, and searches
 * every line it printed for the secrets and the keys.
 *
 * @since 0.1.0
 */
final class SecretsCommandTest extends DatabaseTestCase {

	/**
	 * The secrets the tests store, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const SECRETS = array(
		'api_key'        => 'sk_live_cli_7Rq2Wm4Zt',
		'webhook_secret' => 'whsec_cli_3Kp8Xn1Vb',
		'signing_secret' => 'sign_cli_6Hj9Lc2Fd',
	);

	/**
	 * Every data key the test's site has had, in the encoding the command would print it in.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $keys = array();

	/**
	 * Every line the command printed, across the test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $printed = array();

	/**
	 * Creates the key registry and removes what an earlier run left.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->printed = array();
		$this->keys    = array();

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
	 * Tests status, rotate, rekey and status again, as an operator runs them, without the constant.
	 *
	 * Planted violation: in SecretsCommand::describe(), print the active key's material
	 * (`$this->keys->active()->bytes()` encoded). The search for the key then finds it.
	 *
	 * @since 0.1.0
	 */
	public function test_status_rotate_rekey_status(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );
		$this->remember( $secrets );

		$status = $this->execute( $secrets, array( 'status' ) );

		$this->assertSame( SecretsCommand::EXIT_OK, $status['exit'] );
		$this->assertContains( 'SEOCART_ENCRYPTION_KEY: not defined: the data keys are stored unprotected in the database', $status['lines'] );
		$this->assertContains( 'Canary: opens with the active key', $status['lines'] );
		$this->assertContains( 'Site Health: critical', $status['lines'] );
		$this->assertMatchesRegularExpression( '/^Data key ' . $old . ': active, stored unprotected, 3 records, created \d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{6} UTC$/', $status['lines'][2] );

		$rotate = $this->execute( $secrets, array( 'rotate' ) );
		$new    = (string) $secrets->keys->activeKeyId();

		$this->remember( $secrets );

		$this->assertSame( SecretsCommand::EXIT_OK, $rotate['exit'] );
		$this->assertStringContainsString( "Created the data key {$new}", $rotate['lines'][0] );
		$this->assertStringContainsString( "The data key {$old} is retiring and still seals 3 records.", $rotate['lines'][0] );

		$again = $this->execute( $secrets, array( 'rotate' ) );

		$this->assertSame( SecretsCommand::EXIT_FAILED, $again['exit'] );
		$this->assertStringStartsWith( 'secrets.rotation_pending: ', $again['lines'][0] );

		$rekey = $this->execute( $secrets, array( 'rekey' ), array( 'batch' => '1' ) );

		$this->assertSame( SecretsCommand::EXIT_OK, $rekey['exit'] );
		$this->assertSame(
			array(
				"Re-sealed 3 records with the data key {$new}.",
				"Retired the data key {$old}: no record names it any more.",
				'Every stored secret is sealed with the active key.',
			),
			$rekey['lines']
		);

		$after = $this->execute( new SecretsHarness( $this->db ), array( 'status', '--format=json' ), array( 'format' => 'json' ) );
		$json  = json_decode( $after['lines'][0], true );

		$this->assertSame( SecretsCommand::EXIT_OK, $after['exit'] );
		$this->assertSame( array( SecretKeysTable::RETIRED, SecretKeysTable::ACTIVE ), array_column( $json['keys'], 'state' ) );
		$this->assertSame( array( 0, 3 ), array_column( $json['keys'], 'records' ) );
		$this->assertTrue( $json['canary']['ok'] );
		$this->assertSame( 'absent', $json['encryption_key'] );
		$this->assertSame( 'critical', $json['site_health'] );

		$this->assertPrintedNoSecret();
	}

	/**
	 * Tests that status exits 2 when the canary fails, and rekey exits 2 while a record cannot be opened.
	 *
	 * @since 0.1.0
	 */
	public function test_what_needs_attention_exits_2(): void {
		$kek     = SecretsHarness::newEncryptionKey();
		$secrets = new SecretsHarness( $this->db, $kek );

		$secrets->keys->initialize();
		$secrets->write( self::SECRETS );
		$this->remember( $secrets );
		$secrets->keys->rotate();
		$this->remember( $secrets );

		SecretsHarness::tamper( 'seocart_fixture_gateway_webhook_secret', SecretsHarness::flipped( (string) SecretsHarness::stored( 'seocart_fixture_gateway_webhook_secret' ) ) );

		$rekey = $this->execute( $secrets->withKey( $kek ), array( 'rekey' ) );

		$this->assertSame( SecretsCommand::EXIT_ATTENTION, $rekey['exit'] );
		$this->assertContains( 'These records cannot be opened and must be entered again: seocart_fixture_gateway_webhook_secret/webhook_secret.', $rekey['lines'] );

		$status = $this->execute( $secrets->withKey( SecretsHarness::newEncryptionKey() ), array( 'status' ) );

		$canary = array_values( preg_grep( '/^Canary: /', $status['lines'] ) );

		$this->assertSame( SecretsCommand::EXIT_ATTENTION, $status['exit'] );
		$this->assertMatchesRegularExpression( '/^Canary: fails \(encryption_key_mismatch\): SEOCART_ENCRYPTION_KEY changed/', $canary[0] ?? '' );

		$this->assertPrintedNoSecret();
	}

	/**
	 * Tests that a command used wrongly, or on a site without keys, exits 1 with a line that says why.
	 *
	 * @since 0.1.0
	 */
	public function test_misuse_and_missing_keys_exit_1(): void {
		$secrets = new SecretsHarness( $this->db );

		$this->assertSame( SecretsCommand::EXIT_FAILED, $this->execute( $secrets, array( 'shred' ) )['exit'] );

		$rotate = $this->execute( $secrets, array( 'rotate' ) );

		$this->assertSame( SecretsCommand::EXIT_FAILED, $rotate['exit'] );
		$this->assertStringStartsWith( 'secrets.not_initialized: ', $rotate['lines'][0] );

		$status = $this->execute( $secrets, array( 'status' ) );

		$this->assertSame( SecretsCommand::EXIT_ATTENTION, $status['exit'] );
		$this->assertContains( 'Data keys: none yet', $status['lines'] );
	}

	/**
	 * Runs the command and records what it printed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsHarness             $secrets   The module to run it over.
	 * @param string[]                   $args      The positional arguments.
	 * @param array<string, string|bool> $assocArgs The options.
	 * @return array{exit: int, lines: list<string>} The exit code and the lines.
	 */
	private function execute( SecretsHarness $secrets, array $args, array $assocArgs = array() ): array {
		$lines   = array();
		$command = new SecretsCommand(
			$secrets->keys,
			$secrets->vault,
			$secrets->status,
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
		$exit    = $command->run( $args, $assocArgs );

		array_push( $this->printed, ...$lines );

		return array(
			'exit'  => $exit,
			'lines' => $lines,
		);
	}

	/**
	 * Remembers the active data key, so the search can look for it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsHarness $secrets The module, able to open the active key.
	 */
	private function remember( SecretsHarness $secrets ): void {
		$this->keys[] = $secrets->keys->cipher()->encode( $secrets->keys->active()->bytes() );
	}

	/**
	 * Asserts that no line printed in the test holds a stored secret or a data key the site has had.
	 *
	 * @since 0.1.0
	 */
	private function assertPrintedNoSecret(): void {
		$text = implode( "\n", $this->printed );

		$this->assertNotSame( '', $text, 'Nothing was printed, so the search proves nothing.' );
		$this->assertGreaterThan( 1, count( $this->keys ), 'Too few keys were remembered for the search to prove anything.' );

		foreach ( array_merge( array_values( self::SECRETS ), $this->keys ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}
}
