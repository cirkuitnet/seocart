<?php
/**
 * GatewayCommandRecorder: runs `wp seocart gateway` through the production wiring, recording everything it prints
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Payment\Application\GatewayConfiguration;
use SEOCart\Payment\Application\GatewayStatuses;
use SEOCart\Payment\Application\GatewaySwitches;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Payment\Infrastructure\Cli\GatewayCommand;
use SEOCart\Platform\Kernel\Container;

/**
 * The gateway command built from a container's services, as the kernel builds it, with recorders in place of WP-CLI's output.
 *
 * Owns one fact: what a test of the command reads back. Every line and every item it printed is
 * kept, so a test can scan all of it for a value that must never be printed.
 *
 * @since 0.2.0
 */
final class GatewayCommandRecorder {

	/**
	 * Every line printed, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public array $lines = array();

	/**
	 * Every item printed, in order: a gateway's status, by field.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array<string, string|int>>
	 */
	public array $items = array();

	/**
	 * Records what the command prints.
	 *
	 * @since 0.2.0
	 *
	 * @param Container $kernel The container whose services the command is built from.
	 */
	public function __construct( private Container $kernel ) {
	}

	/**
	 * Runs the command, as the user WP-CLI would run as, and returns its exit code.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]                   $args      The positional arguments.
	 * @param array<string, string|bool> $assocArgs Optional. The options. Default none.
	 * @param int                        $user      Optional. The user, as `--user` gives it; 0 for none. Default 0.
	 * @return int The exit code.
	 *
	 * @phpstan-param list<string> $args
	 */
	public function run( array $args, array $assocArgs = array(), int $user = 0 ): int {
		$before = get_current_user_id();

		wp_set_current_user( $user );

		try {
			return $this->command()->run( $args, $assocArgs );
		} finally {
			wp_set_current_user( $before );
		}
	}

	/**
	 * Returns the last line printed.
	 *
	 * @since 0.2.0
	 *
	 * @return string The line; empty when none was.
	 */
	public function last(): string {
		return array() === $this->lines ? '' : $this->lines[ count( $this->lines ) - 1 ];
	}

	/**
	 * Returns everything printed, as one text.
	 *
	 * @since 0.2.0
	 *
	 * @return string The lines and the items.
	 */
	public function printed(): string {
		return implode( "\n", $this->lines ) . "\n" . (string) wp_json_encode( $this->items );
	}

	/**
	 * Builds the command from the container's services, with a new reader of the statuses, as each run of the command in its own request has.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayCommand The command.
	 */
	private function command(): GatewayCommand {
		return new GatewayCommand(
			new GatewayStatuses( $this->kernel->get( Gateways::class ), $this->kernel->get( PaymentRepository::class ) ),
			$this->kernel->get( GatewaySwitches::class ),
			$this->kernel->get( GatewayConfiguration::class ),
			function ( string $line ): void {
				$this->lines[] = $line;
			},
			function ( array $item ): void {
				$this->items[] = $item;
			}
		);
	}
}
