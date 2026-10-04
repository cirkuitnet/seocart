<?php
/**
 * CapabilityMatrix: what a gateway can do, by currency and account country
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * The one declaration of what a gateway can do: rows of (currency, account country) → operations.
 *
 * Owns one fact: which operations a gateway supports where. The plugin refuses, before any call
 * to the provider, an operation the cell of an intent's currency and the account's country does
 * not declare; a gateway is offered at checkout only where its cell declares `authorize`; and the
 * documentation of the gateway's capabilities is generated from the same rows. Pure data.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class CapabilityMatrix {

	/**
	 * Records the rows.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When there is no row, an item is not a row, or two rows are the same cell.
	 *
	 * @param array $rows The rows.
	 *
	 * @phpstan-param list<MatrixRow> $rows
	 */
	public function __construct( public array $rows ) {
		if ( array() === $rows ) {
			throw new \InvalidArgumentException( 'A capability matrix has at least one row.' );
		}

		$cells = array();

		foreach ( $rows as $row ) {
			// @phpstan-ignore instanceof.alwaysTrue (A gateway's declaration is written by hand; a stray value must be refused.)
			if ( ! $row instanceof MatrixRow ) {
				throw new \InvalidArgumentException( 'Every row of a capability matrix is a MatrixRow.' );
			}

			$cell = $row->currency->code() . '/' . $row->accountCountry;

			if ( isset( $cells[ $cell ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'A capability matrix declares the cell %s twice.', $cell ) );
			}

			$cells[ $cell ] = true;
		}
	}

	/**
	 * Tells whether an operation is supported in a currency, for an account of a country.
	 *
	 * The cell is the row of the currency and the account's own country, or else the currency's
	 * `*` row; with no such row, nothing is supported.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $operation      A name of Operations::ALL.
	 * @param Currency    $currency       The currency.
	 * @param string|null $accountCountry The account's country; null when the gateway has none set.
	 * @return bool True when the cell declares the operation.
	 */
	public function allows( string $operation, Currency $currency, ?string $accountCountry ): bool {
		return $this->cell( $currency, $accountCountry )?->supports( $operation ) ?? false;
	}

	/**
	 * Tells whether the matrix lets the gateway take a new payment in a context: its cell declares `authorize`.
	 *
	 * A gateway's own isAvailable() may only narrow this.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True when the cell of the context's currency and account country declares `authorize`.
	 */
	public function available( AvailabilityContext $context ): bool {
		$country = $context->account[ GatewayDescriptor::ACCOUNT_COUNTRY ] ?? null;

		return $this->allows( Operations::AUTHORIZE, $context->currency, is_string( $country ) ? $country : null );
	}

	/**
	 * Returns the currencies the matrix has rows for.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> ISO 4217 codes, each once, in the order the rows name them.
	 */
	public function currencies(): array {
		return array_values( array_unique( array_map( static fn( MatrixRow $row ): string => $row->currency->code(), $this->rows ) ) );
	}

	/**
	 * Returns the cell of a currency and an account's country: the row of that country, or else the currency's `*` row.
	 *
	 * @since 0.2.0
	 *
	 * @param Currency    $currency       The currency.
	 * @param string|null $accountCountry The account's country, or null.
	 * @return MatrixRow|null The row, or null when the matrix has none for them.
	 */
	private function cell( Currency $currency, ?string $accountCountry ): ?MatrixRow {
		$any = null;

		foreach ( $this->rows as $row ) {
			if ( ! $row->covers( $currency, $accountCountry ) ) {
				continue;
			}

			if ( MatrixRow::ANY_COUNTRY !== $row->accountCountry ) {
				return $row;
			}

			$any = $row;
		}

		return $any;
	}
}
