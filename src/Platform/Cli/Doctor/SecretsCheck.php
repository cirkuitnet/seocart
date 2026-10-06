<?php
/**
 * SecretsCheck: doctor's report on the stored secrets, Site Health's own verdict
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Secrets\SecretsStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the state of the stored secrets as Site Health's `seocart_secrets` test judges it.
 *
 * Owns one fact: what doctor says of the secrets. The verdict and its lines are SecretsStatus's,
 * word for word: it fails on a critical finding (a canary that does not open, a data key stored
 * unprotected, secrets sealed with a key the site no longer has or that cannot be read as stored),
 * and passes on a recommended one (a key being retired), listing it. The summary is the vault's
 * census, the payment gateways' credentials counted with the plugin's own. Nothing it prints is a
 * key or a secret: key ids, counts and states only.
 *
 * @since 0.2.0
 */
final class SecretsCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'secrets';

	/**
	 * Creates the check. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SecretsStatus $status The secrets' status, which Site Health's test reads too.
	 */
	public function __construct( private SecretsStatus $status ) {
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `secrets`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Judges the stored secrets as Site Health does.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Failed with a `Critical:` line per critical finding; passed otherwise, with a `Recommended:` line per recommendation.
	 */
	public function run(): CheckResult {
		$report   = $this->status->report();
		$findings = SecretsStatus::findings( $report );
		$lines    = array_map( static fn( array $finding ): string => ( SecretsStatus::CRITICAL === $finding['status'] ? 'Critical: ' : 'Recommended: ' ) . $finding['label'] . '. ' . $finding['detail'], $findings );
		$sealed   = array_sum( array_column( $report->keys, 'records' ) );
		$summary  = sprintf(
			'%1$d stored %2$s sealed with a data key the site holds, %3$d with none it holds, %4$d that cannot be read as stored; the canary %5$s.',
			$sealed,
			1 === $sealed ? 'secret' : 'secrets',
			$report->orphaned,
			$report->damaged,
			$report->canary->ok() ? 'opens' : 'fails'
		);

		if ( SecretsStatus::CRITICAL === SecretsStatus::severity( $report ) ) {
			return CheckResult::fail( self::NAME, $summary, $lines );
		}

		return CheckResult::pass( self::NAME, $summary, $lines );
	}
}
