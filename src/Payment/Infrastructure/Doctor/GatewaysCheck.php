<?php
/**
 * GatewaysCheck: doctor's and Site Health's check that every gateway with open payments can be asked about them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Doctor;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Application\GatewayStatuses;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

/**
 * Reports the gateways whose open payments nothing can settle now: a gateway not registered, one that no longer has the payments' mode, one whose credentials for it are missing or unreadable, and live payments while Safe Mode is on.
 *
 * Owns one fact: when doctor and Site Health call the store's gateways unfit for the payments they
 * hold. The open payments (created, waiting for the customer or the gateway, or authorized) are
 * counted per gateway and mode with one statement; for each, the registry says why it would refuse
 * to ask the gateway about them (Gateways::unavailableFor()), without opening a credential. Each
 * reason is a warning; it is critical when one of those payments has waited for its gateway's
 * answer, unchanged, longer than WAITED_SECONDS, since nothing settles it until a person acts, but
 * for Safe Mode, which a person ends. A gateway an operator switched off is no finding: its open
 * payments go on. It prints gateway ids, modes and counts, never a setting's value.
 *
 * @since 0.2.0
 */
final class GatewaysCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'gateways';

	/**
	 * The id of the Site Health test: the kernel's SiteHealth::GATEWAYS_TEST.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST = 'seocart_gateways';

	/**
	 * How long a payment may wait for its gateway's answer, unchanged, before a gateway that cannot be asked about it is critical: a day, as doctor's check of the checkout waits.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const WAITED_SECONDS = 86400;

	/**
	 * A finding a person must act on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CRITICAL = 'critical';

	/**
	 * A finding a person should act on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RECOMMENDED = 'recommended';

	/**
	 * Creates the check. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayStatuses $statuses The gateways' statuses, with the open payments per gateway and mode.
	 * @param Gateways        $gateways The registry, which says why a gateway cannot be asked.
	 */
	public function __construct( private GatewayStatuses $statuses, private Gateways $gateways ) {
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `gateways`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Checks that every gateway with open payments can be asked about them.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed when each can; failed with a `Critical:` or `Warning:` line per gateway and mode that cannot.
	 */
	public function run(): CheckResult {
		$findings = $this->findings();

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, $this->summary() );
		}

		$open = 0;

		foreach ( $findings as $finding ) {
			$open += $finding['open'];
		}

		return CheckResult::fail(
			self::NAME,
			sprintf( '%d of the gateways\' open payments cannot be settled now.', $open ),
			array_map( static fn( array $finding ): string => ( self::CRITICAL === $finding['status'] ? 'Critical: ' : 'Warning: ' ) . $finding['detail'], $findings )
		);
	}

	/**
	 * Returns the same findings as Site Health's direct test, in the shape the `site_status_tests` filter expects of one.
	 *
	 * @since 0.2.0
	 *
	 * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string} The result.
	 */
	public function siteHealthTest(): array {
		$findings = $this->findings();

		if ( array() === $findings ) {
			$status      = 'good';
			$label       = __( 'SEOCart can ask each gateway about its open payments', 'seocart' );
			$description = '<p>' . esc_html( $this->summary() ) . '</p>';
		} else {
			$status      = $findings[0]['status'];
			$label       = self::CRITICAL === $status ? __( 'Payments are waiting for a gateway SEOCart cannot ask', 'seocart' ) : __( 'Some open payments wait for a gateway SEOCart cannot ask', 'seocart' );
			$description = implode( '', array_map( static fn( array $finding ): string => '<p>' . esc_html( $finding['detail'] ) . '</p>', $findings ) );
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'SEOCart', 'seocart' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => self::TEST,
		);
	}

	/**
	 * Lists the gateways and modes whose open payments cannot be settled now, the critical ones first.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{status: string, open: int, detail: string}> The findings; none when every gateway can be asked.
	 */
	private function findings(): array {
		$critical = array();
		$warnings = array();
		$refused  = $this->statuses->refusalReasons();

		foreach ( $this->statuses->openIntents() as $row ) {
			$reason = $this->gateways->unavailableFor( $row['gateway_id'], $row['mode'] );

			if ( null === $reason ) {
				continue;
			}

			$stuck   = 'safe_mode' !== $reason && null !== $row['waited_seconds'] && $row['waited_seconds'] > self::WAITED_SECONDS;
			$finding = array(
				'status' => $stuck ? self::CRITICAL : self::RECOMMENDED,
				'open'   => $row['open'],
				'detail' => self::detail( $reason, $row['gateway_id'], $row['mode'], $row['open'], $refused[ $row['gateway_id'] ] ?? null ) . ( $stuck ? ' ' . __( 'One of them has waited for its gateway\'s answer for more than a day.', 'seocart' ) : '' ),
			);

			if ( $stuck ) {
				$critical[] = $finding;
			} else {
				$warnings[] = $finding;
			}
		}

		return array_merge( $critical, $warnings );
	}

	/**
	 * Says why a gateway's open payments in a mode cannot be settled now, and what to do.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $reason    Why the registry would refuse to ask the gateway, as Gateways::unavailableFor() says it.
	 * @param string      $gatewayId The gateway.
	 * @param Mode        $mode      The payments' mode.
	 * @param int         $open      How many of them are open.
	 * @param string|null $refusal   Why the registry refused the gateway's registration in this request, if it did.
	 * @return string One sentence or two, translated.
	 */
	private static function detail( string $reason, string $gatewayId, Mode $mode, int $open, ?string $refusal ): string {
		if ( 'not_registered' === $reason && null !== $refusal ) {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. 4: Why SEOCart refused the gateway's registration, a word such as incompatible. */
			$format = _n( '%1$s is not registered: SEOCart refused its registration (%4$s), and %2$d of its %3$s payments is open: it cannot be captured, voided, refunded or reconciled until the registration is accepted. wp seocart gateway status says why.', '%1$s is not registered: SEOCart refused its registration (%4$s), and %2$d of its %3$s payments are open: they cannot be captured, voided, refunded or reconciled until the registration is accepted. wp seocart gateway status says why.', $open, 'seocart' );
		} elseif ( 'not_registered' === $reason ) {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. */
			$format = _n( '%1$s is not registered, and %2$d of its %3$s payments is open: it cannot be captured, voided, refunded or reconciled until the gateway\'s plugin is active again.', '%1$s is not registered, and %2$d of its %3$s payments are open: they cannot be captured, voided, refunded or reconciled until the gateway\'s plugin is active again.', $open, 'seocart' );
		} elseif ( 'safe_mode' === $reason ) {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, live. */
			$format = _n( 'Safe Mode is on, and %2$d of %1$s\'s %3$s payments is open: SEOCart makes no live call, so it waits until Safe Mode ends.', 'Safe Mode is on, and %2$d of %1$s\'s %3$s payments are open: SEOCart makes no live call, so they wait until Safe Mode ends.', $open, 'seocart' );
		} elseif ( 'credentials_missing' === $reason ) {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. */
			$format = _n( '%1$s has no saved %3$s settings, and %2$d of its %3$s payments is open: it cannot be captured, refunded or reconciled until they are saved again with wp seocart gateway configure.', '%1$s has no saved %3$s settings, and %2$d of its %3$s payments are open: they cannot be captured, refunded or reconciled until they are saved again with wp seocart gateway configure.', $open, 'seocart' );
		} elseif ( 'credentials_unreadable' === $reason ) {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. */
			$format = _n( '%1$s\'s %3$s credentials cannot be read, and %2$d of its %3$s payments is open: it cannot be captured, refunded or reconciled until they are entered again with wp seocart gateway configure.', '%1$s\'s %3$s credentials cannot be read, and %2$d of its %3$s payments are open: they cannot be captured, refunded or reconciled until they are entered again with wp seocart gateway configure.', $open, 'seocart' );
		} else {
			/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. */
			$format = _n( '%1$s no longer has a %3$s mode, and %2$d of its %3$s payments is open: it cannot be captured, voided, refunded or reconciled through it.', '%1$s no longer has a %3$s mode, and %2$d of its %3$s payments are open: they cannot be captured, voided, refunded or reconciled through it.', $open, 'seocart' );
		}

		return sprintf( $format, $gatewayId, $open, $mode->value, $refusal );
	}

	/**
	 * Says, for a check that passed, which gateways hold open payments, and each one's state.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence, translated.
	 */
	private function summary(): string {
		$parts = array();

		foreach ( $this->statuses->openIntents() as $row ) {
			$parts[] = sprintf(
				/* translators: 1: A payment gateway's id, such as stripe. 2: A number of payments. 3: A mode, test or live. 4: enabled, or switched off. */
				__( '%1$s (%2$d open, %3$s, %4$s)', 'seocart' ),
				$row['gateway_id'],
				$row['open'],
				$row['mode']->value,
				$this->gateways->isEnabled( $row['gateway_id'] ) ? __( 'enabled', 'seocart' ) : __( 'switched off', 'seocart' )
			);
		}

		if ( array() === $parts ) {
			return __( 'No payment is open, so no gateway needs to be asked about one.', 'seocart' );
		}

		/* translators: %s: The gateways with open payments, separated by commas, such as "stripe (3 open, live, enabled)". */
		return sprintf( __( 'Every gateway with open payments can be asked about them: %s.', 'seocart' ), implode( ', ', $parts ) );
	}
}
