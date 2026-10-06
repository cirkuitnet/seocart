<?php
/**
 * Tests doctor's secrets check: Site Health's verdict in its words, the payment gateways' credentials counted, and nothing secret printed
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cli;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\SecretsCheck;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Payment\GatewayKernel;

/**
 * With SEOCART_ENCRYPTION_KEY and a payment gateway's sealed credential, the check passes and counts the credential with the plugin's own; without the key it fails with Site Health's critical finding; with a data key being retired it passes, listing the recommendation. Nothing it prints is a credential, plain or sealed.
 *
 * Planted violation, shown red and removed: in Modules::secretsRegister(), build the vault over the
 * plugin's own settings only (Settings::registry()): the gateway's credential is not counted.
 *
 * @since 0.2.0
 */
final class SecretsCheckTest extends DatabaseTestCase {

	/**
	 * Has `second` register through the action, and clears what an earlier test left.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		GatewayKernel::deleteOptions();

		add_action(
			GatewayRegistry::ACTION,
			static function ( GatewayRegistry $registry ): void {
				$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( 'second' ), $registry->context( 'second' ) ) );
			}
		);
	}

	/**
	 * Deletes the data keys and the gateway's document.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests the census: a gateway's sealed credential is counted with the plugin's own, the check passes, and nothing secret is printed.
	 *
	 * @since 0.2.0
	 */
	public function test_the_check_counts_the_gateways_credential_and_passes(): void {
		$kernel = GatewayKernel::over( $this->db, $this->reporter(), new RecordingEventPublisher() );
		$before = $kernel->get( SecretsCheck::class )->run();

		$this->assertTrue( $before->passed, implode( "\n", $before->findings ) );
		$this->assertSame( '0 stored secrets sealed with a data key the site holds, 0 with none it holds, 0 that cannot be read as stored; the canary opens.', $before->summary );

		$this->configure( $kernel );

		$after = GatewayKernel::request( $kernel, $this->db, $this->reporter(), new RecordingEventPublisher() )->get( SecretsCheck::class )->run();

		$this->assertTrue( $after->passed );
		$this->assertSame( '1 stored secret sealed with a data key the site holds, 0 with none it holds, 0 that cannot be read as stored; the canary opens.', $after->summary, 'The gateway\'s credential is counted.' );
		$this->assertSame( array(), $after->findings );

		foreach ( array( 'sk_live_planted', (string) ( GatewayKernel::storedDocument( 'second' )['values'][ GatewayKernel::name( 'second', Mode::Live, 'secret_key' ) ] ?? 'unset' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, self::printed( $after ), 'A credential was printed.' );
		}
	}

	/**
	 * Tests a site without SEOCART_ENCRYPTION_KEY: the check fails with Site Health's own critical finding, in its words.
	 *
	 * @since 0.2.0
	 */
	public function test_without_the_encryption_key_the_check_fails_as_site_health_does(): void {
		$kernel  = GatewayKernel::over( $this->db, $this->reporter(), new RecordingEventPublisher(), array( EncryptionKey::class => static fn(): EncryptionKey => EncryptionKey::fromValue( null ) ) );
		$result  = $kernel->get( SecretsCheck::class )->run();
		$status  = $kernel->get( SecretsStatus::class );
		$finding = SecretsStatus::findings( $status->report() )[0];

		$this->assertFalse( $result->passed );
		$this->assertSame( 'SEOCart keeps the key to its stored secrets in the database', $finding['label'] );
		$this->assertSame( 'Critical: ' . $finding['label'] . '. ' . $finding['detail'], $result->findings[0] );
		$this->assertSame( SecretsStatus::CRITICAL, $status->siteHealthTest()['status'], 'Site Health says the same.' );
	}

	/**
	 * Tests a data key being retired: the check passes, and lists Site Health's recommendation.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_being_retired_passes_with_the_recommendation(): void {
		$kernel = GatewayKernel::over( $this->db, $this->reporter(), new RecordingEventPublisher() );

		$this->configure( $kernel );
		$kernel->get( SecretKeys::class )->rotate();

		$result = GatewayKernel::request( $kernel, $this->db, $this->reporter(), new RecordingEventPublisher() )->get( SecretsCheck::class )->run();

		$this->assertTrue( $result->passed );
		$this->assertCount( 1, $result->findings );
		$this->assertStringStartsWith( 'Recommended: SEOCart is replacing a data key. The data key ', $result->findings[0] );
	}

	/**
	 * Saves `second`'s live settings.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $kernel The container.
	 */
	private function configure( Container $kernel ): void {
		GatewayKernel::writeDocument(
			$kernel,
			DeclaredGateway::descriptor( 'second' ),
			array(
				GatewayKernel::name( 'second', Mode::Live, 'secret_key' ) => 'sk_live_planted',
				GatewayKernel::name( 'second', Mode::Live, GatewayDescriptor::ACCOUNT_COUNTRY ) => 'US',
			)
		);
	}

	/**
	 * Returns everything a check's result prints.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckResult $result The result.
	 * @return string The summary and the findings.
	 */
	private static function printed( CheckResult $result ): string {
		return $result->summary . "\n" . implode( "\n", $result->findings );
	}
}
