<?php
/**
 * SecretsStatus: what the status command, Site Health and the support report say about the secrets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the state of the site's secrets, and judges it for Site Health.
 *
 * This class owns one fact: how healthy the secrets are, and how that is said. report() gathers
 * the cipher, the encryption key's state, every data key with the records it seals, and the canary.
 * siteHealthTest() returns the Site Health test the kernel registers; severity() gives the same
 * verdict to the status command. The verdict is honest in every configuration:
 *
 * - critical when the canary fails; when SEOCART_ENCRYPTION_KEY is not defined, because the data
 *   key then sits unprotected in the database beside the secrets it seals; when the constant is
 *   invalid; when it is defined but a data key made before it is still stored unprotected; when a
 *   stored secret names a key the site no longer has; and when a stored secret cannot be read as
 *   stored;
 * - recommended while a key is being retired;
 * - good otherwise.
 *
 * Nothing it returns holds a key or a secret.
 *
 * @since 0.1.0
 */
final class SecretsStatus {

	/**
	 * The Site Health test's id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TEST = 'seocart_secrets';

	/**
	 * The verdict when nothing is wrong.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GOOD = 'good';

	/**
	 * The verdict when something should be done, and nothing is at risk yet.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RECOMMENDED = 'recommended';

	/**
	 * The verdict when the secrets are unprotected or cannot be opened.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CRITICAL = 'critical';

	/**
	 * The data keys.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretKeys
	 */
	private SecretKeys $keys;

	/**
	 * The records.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretVault
	 */
	private SecretVault $vault;

	/**
	 * The canary check.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretsCanary
	 */
	private SecretsCanary $canary;

	/**
	 * Creates the status.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretKeys    $keys   The data keys.
	 * @param SecretVault   $vault  The records.
	 * @param SecretsCanary $canary The canary check.
	 */
	public function __construct( SecretKeys $keys, SecretVault $vault, SecretsCanary $canary ) {
		$this->keys   = $keys;
		$this->vault  = $vault;
		$this->canary = $canary;
	}

	/**
	 * Gathers the state of the secrets. Writes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return SecretsReport The state.
	 */
	public function report(): SecretsReport {
		try {
			$library = $this->keys->cipher()->library();
		} catch ( CodedException ) {
			$library = null;
		}

		try {
			$wrapped = $this->keys->wrapped();
		} catch ( CodedException ) {
			$wrapped = array();
		}

		$counts   = $this->vault->counts();
		$keys     = array();
		$orphaned = 0;

		foreach ( $this->keys->registered() as $key ) {
			$key['wrapped'] = $wrapped[ $key['key_id'] ] ?? null;
			$key['records'] = $counts[ $key['key_id'] ] ?? 0;
			$keys[]         = $key;
		}

		foreach ( $counts as $key_id => $count ) {
			if ( ! isset( $wrapped[ $key_id ] ) ) {
				$orphaned += $count;
			}
		}

		return new SecretsReport( $library, $this->keys->encryptionKey()->state(), $keys, $orphaned, count( $this->vault->damaged() ), $this->canary->check() );
	}

	/**
	 * Judges a report.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsReport $report The report.
	 * @return string self::GOOD, self::RECOMMENDED or self::CRITICAL.
	 */
	public static function severity( SecretsReport $report ): string {
		$findings = self::findings( $report );

		return array() === $findings ? self::GOOD : $findings[0]['status'];
	}

	/**
	 * Returns the Site Health test, as the `site_status_tests` filter expects a direct test's result.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsReport|null $report Optional. The report to judge. Default a new one.
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	public function siteHealthTest( ?SecretsReport $report = null ): array {
		$findings = self::findings( $report ?? $this->report() );

		if ( array() === $findings ) {
			$findings[] = array(
				'status' => self::GOOD,
				'label'  => __( 'SEOCart\'s stored secrets are protected', 'seocart' ),
				'detail' => __( 'SEOCart seals payment credentials and other secrets with a data key, which SEOCART_ENCRYPTION_KEY in wp-config.php protects, and the secrets canary opens as expected.', 'seocart' ),
			);
		}

		$description = '';

		foreach ( $findings as $finding ) {
			$description .= '<p>' . esc_html( $finding['detail'] ) . '</p>';
		}

		return array(
			'label'       => $findings[0]['label'],
			'status'      => $findings[0]['status'],
			'badge'       => array(
				'label' => __( 'Security', 'seocart' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => self::TEST,
		);
	}

	/**
	 * Lists what is wrong, the most severe first.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsReport $report The report.
	 * @return list<array{status: string, label: string, detail: string}> The findings; none when all is well.
	 */
	private static function findings( SecretsReport $report ): array {
		$critical = array();
		$failure  = $report->canary->failure;

		if ( null !== $failure ) {
			$critical[] = array( __( 'SEOCart cannot open its stored secrets', 'seocart' ), (string) $report->canary->cause() );
		}

		if ( EncryptionKeyState::Absent === $report->encryptionKey && CanaryFailure::EncryptionKeyAbsent !== $failure ) {
			$critical[] = array(
				__( 'SEOCart keeps the key to its stored secrets in the database', 'seocart' ),
				__( 'SEOCart seals payment credentials and other secrets with a data key. SEOCART_ENCRYPTION_KEY is not defined in wp-config.php, so the data key is stored unprotected in the database, next to the secrets it seals: anyone with a copy of the database can read them. They are still kept out of logs, exports and screens. To protect them, define SEOCART_ENCRYPTION_KEY in wp-config.php as the base64 encoding of 32 random bytes, then run wp seocart secrets rotate and wp seocart secrets rekey.', 'seocart' ),
			);
		}

		if ( EncryptionKeyState::Invalid === $report->encryptionKey && CanaryFailure::EncryptionKeyInvalid !== $failure ) {
			$critical[] = array(
				__( 'SEOCART_ENCRYPTION_KEY is not a valid key', 'seocart' ),
				__( 'SEOCART_ENCRYPTION_KEY is defined in wp-config.php, but it is not the base64 encoding of 32 bytes, so SEOCart cannot use it to protect its data key. Replace it with the base64 encoding of 32 random bytes.', 'seocart' ),
			);
		}

		foreach ( $report->keys as $key ) {
			if ( EncryptionKeyState::Present === $report->encryptionKey && false === $key['wrapped'] ) {
				$critical[] = array(
					__( 'A SEOCart data key is stored unprotected', 'seocart' ),
					sprintf(
						/* translators: %s: The id of a data key, sixteen hexadecimal digits. */
						__( 'SEOCART_ENCRYPTION_KEY is defined, but the data key %s was created before it was and is still stored unprotected in the database. Run wp seocart secrets rotate, then wp seocart secrets rekey, to replace it with a protected key.', 'seocart' ),
						$key['key_id']
					),
				);
			}
		}

		if ( $report->orphaned > 0 ) {
			$critical[] = array(
				__( 'Some of SEOCart\'s stored secrets cannot be opened', 'seocart' ),
				sprintf(
					/* translators: %d: A number of stored secrets. */
					_n( '%d stored secret was sealed with a data key this site no longer has, or cannot be read as stored. Enter it again.', '%d stored secrets were sealed with a data key this site no longer has, or cannot be read as stored. Enter them again.', $report->orphaned, 'seocart' ),
					$report->orphaned
				),
			);
		}

		if ( $report->damaged > 0 ) {
			$critical[] = array(
				__( 'Some of SEOCart\'s stored secrets cannot be read as stored', 'seocart' ),
				sprintf(
					/* translators: %d: A number of stored secrets. */
					_n( '%d stored secret cannot be read, because its option or another setting stored beside it is damaged. Repair it; until then no data key is retired.', '%d stored secrets cannot be read, because their options or other settings stored beside them are damaged. Repair them; until then no data key is retired.', $report->damaged, 'seocart' ),
					$report->damaged
				),
			);
		}

		$findings = array();

		foreach ( $critical as $finding ) {
			$findings[] = array(
				'status' => self::CRITICAL,
				'label'  => $finding[0],
				'detail' => $finding[1],
			);
		}

		$retiring = $report->key( SecretKeysTable::RETIRING );

		if ( null !== $retiring ) {
			$findings[] = array(
				'status' => self::RECOMMENDED,
				'label'  => __( 'SEOCart is replacing a data key', 'seocart' ),
				'detail' => sprintf(
					/* translators: 1: The id of a data key, sixteen hexadecimal digits. 2: A number of stored secrets. */
					_n( 'The data key %1$s is being retired, and %2$d stored secret is still sealed with it. Run wp seocart secrets rekey.', 'The data key %1$s is being retired, and %2$d stored secrets are still sealed with it. Run wp seocart secrets rekey.', $retiring['records'], 'seocart' ),
					$retiring['key_id'],
					$retiring['records']
				),
			);
		}

		return $findings;
	}
}
