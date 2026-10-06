<?php
/**
 * Tests the payment module's statements, read from their constants: what they name and what they change
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every payment statement is a constant, so these facts are read from the constants alone.
 *
 * - No statement names a table outside the payment module, as a token or as a bare name: the
 *   order is reached through the order module's service, never by SQL.
 * - The ledger, the refunds and what each refund claim asked are appended to only: no statement
 *   updates or deletes a row of a payment table declared append-only, which are
 *   `payment_transactions`, `refunds`, `refund_lines`, `refund_components` and
 *   `refund_claim_lines`.
 * - An intent's state changes only through the statements the intent state machine compiles: each
 *   assigns `status` with `status IN ({list})` in its WHERE clause.
 * - An intent's authorized, captured and refunded amounts are each written by the one statement
 *   that applies that operation.
 *
 * The statements are found, not listed: every SQL constant of every class under src/Payment.
 *
 * Planted violations, each shown red and removed:
 * - add `public const PURGE = 'DELETE FROM {payment_transactions} WHERE id = %d';` to
 *   MysqlPaymentRepository: the append-only scan fails;
 * - add `public const RELINE = 'UPDATE {refund_claim_lines} SET quantity = %d WHERE id = %d';` to
 *   MysqlRefundRepository: the append-only scan fails;
 * - add `public const FORCE = "UPDATE {payment_intents} SET status = 'captured' WHERE id = %d";`:
 *   the state scan fails;
 * - join `{orders}` in MysqlPaymentRepository::ORDER_SUMS: the table scan fails.
 *
 * @since 0.1.0
 */
final class StatementsTest extends TestCase {

	/**
	 * The statements that change an intent's state, each the only writer of the state it enters.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const STATE_WRITERS = array(
		'MysqlPaymentRepository::APPLY_AUTHORIZE',
		'MysqlPaymentRepository::APPLY_CAPTURE',
		'MysqlPaymentRepository::APPLY_REFUND',
		'MysqlPaymentRepository::APPLY_DECLINE',
		'MysqlPaymentRepository::REQUIRE_ACTION',
		'MysqlPaymentRepository::MARK_PROCESSING',
	);

	/**
	 * Tests that every statement names only payment tables, and names no other module's table bare.
	 *
	 * @since 0.1.0
	 */
	public function test_no_statement_names_a_table_outside_the_payment_module(): void {
		$ours   = PaymentTables::moduleNames();
		$others = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Payment' !== $table->module() ) {
				$others[] = $table->name();
			}
		}

		$this->assertContains( 'orders', $others, 'The scan must know the order module\'s tables, or it proves nothing.' );

		$named   = array();
		$foreign = array();

		foreach ( self::statements() as $name => $statement ) {
			preg_match_all( '/\{([a-z_]+)\}/', $statement, $tokens );

			foreach ( $tokens[1] as $token ) {
				if ( 'list' === $token ) {
					continue;
				}

				$named[ $token ] = true;

				if ( ! in_array( $token, $ours, true ) ) {
					$foreign[] = "{$name} names {{$token}}";
				}
			}

			foreach ( array_merge( $others, array( 'customers', 'carts', 'promotions' ) ) as $table ) {
				if ( 1 === preg_match( '/(?<![\w{])' . preg_quote( $table, '/' ) . '(?![\w}])/', $statement ) ) {
					$foreign[] = "{$name} names {$table}";
				}
			}
		}

		$this->assertSame( array(), $foreign, 'A payment statement reaches only the payment tables.' );
		$this->assertSame( array(), array_values( array_diff( $ours, array_keys( $named ) ) ), 'Every payment table is named by some statement, so the scan saw them all.' );
	}

	/**
	 * Tests that no statement updates or deletes a ledger or refund row, and that the declarations agree that those tables are append-only.
	 *
	 * @since 0.1.0
	 */
	public function test_no_statement_changes_a_ledger_or_refund_row(): void {
		$appendOnly = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Payment' === $table->module() && MutationPattern::AppendOnly === $table->mutationPattern() ) {
				$appendOnly[] = $table->name();
			}
		}

		$this->assertEqualsCanonicalizing( array_merge( array( PaymentTables::TRANSACTIONS, RefundClaimTables::CLAIM_LINES ), RefundTables::names() ), $appendOnly, 'The ledger, the refunds and what each claim asked are declared append-only.' );

		$changes = array();

		foreach ( self::statements() as $name => $statement ) {
			$changesRows = 1 === preg_match( '/^\s*(UPDATE|DELETE)\b/i', $statement ) || str_contains( $statement, 'ON DUPLICATE KEY UPDATE' );

			foreach ( $appendOnly as $table ) {
				if ( $changesRows && str_contains( $statement, '{' . $table . '}' ) ) {
					$changes[] = $name;
				}
			}
		}

		$this->assertSame( array(), $changes, 'The ledger and the refunds are only appended to.' );
	}

	/**
	 * Tests that only the state machine's statements change an intent's state, each from the states its IN list names.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_state_machines_statements_change_an_intents_state(): void {
		$writers  = array();
		$unguared = array();

		foreach ( self::statements() as $name => $statement ) {
			if ( 1 !== preg_match( '/^\s*UPDATE\s.*?\sSET\s(.*?)\sWHERE\s(.*)$/is', $statement, $parts ) ) {
				continue;
			}

			if ( 1 === preg_match( '/(?<![\w.`])status\s*=/', $parts[1] ) ) {
				$writers[] = $name;

				if ( ! str_contains( $parts[2], 'status IN ({list})' ) ) {
					$unguared[] = $name;
				}
			}
		}

		$this->assertSame( self::STATE_WRITERS, $writers, 'An intent\'s state changes only through the statements the transition table compiles.' );
		$this->assertSame( array(), $unguared, 'Each lists in its WHERE clause the states its target may be entered from.' );
	}

	/**
	 * Tests that each of an intent's amounts is written by the one statement that applies its operation.
	 *
	 * @since 0.1.0
	 */
	public function test_each_intent_amount_has_one_writer(): void {
		$writers = array(
			'authorized_minor' => array(),
			'captured_minor'   => array(),
			'refunded_minor'   => array(),
		);

		foreach ( self::statements() as $name => $statement ) {
			if ( 1 !== preg_match( '/^\s*UPDATE\s.*?\sSET\s(.*?)\sWHERE\s/is', $statement, $set ) ) {
				continue;
			}

			foreach ( array_keys( $writers ) as $column ) {
				if ( 1 === preg_match( '/(?<![\w.`])(?:base_)?' . $column . '\s*=/', $set[1] ) ) {
					$writers[ $column ][] = $name;
				}
			}
		}

		$this->assertSame(
			array(
				'authorized_minor' => array( 'MysqlPaymentRepository::APPLY_AUTHORIZE' ),
				'captured_minor'   => array( 'MysqlPaymentRepository::APPLY_CAPTURE' ),
				'refunded_minor'   => array( 'MysqlPaymentRepository::APPLY_REFUND' ),
			),
			$writers
		);
	}

	/**
	 * Returns every statement the payment module can send: every SQL constant of every class under src/Payment.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Each statement, by `Class::CONSTANT`.
	 */
	private static function statements(): array {
		$statements = array();

		foreach ( PhpSource::files( 'src/Payment' ) as $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				$reflection = new \ReflectionClass( $class );

				foreach ( $reflection->getReflectionConstants() as $constant ) {
					$value = $constant->getValue();

					if ( is_string( $value ) && 1 === preg_match( '/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $value ) ) {
						$statements[ $reflection->getShortName() . '::' . $constant->getName() ] = $value;
					}
				}
			}
		}

		self::assertGreaterThan( 15, count( $statements ), 'The scan found too few statements to prove anything.' );

		return $statements;
	}
}
