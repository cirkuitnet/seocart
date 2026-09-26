<?php
/**
 * NewOrder: the document an order is placed from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Everything an order is written from: who placed it, where, at which rate, what it sells and what it totals.
 *
 * Owns one fact: the shape of an order at the moment it is placed. Its caller fills it from the
 * calculation's result and the cart; the order module copies it into its tables and computes
 * nothing, because only the calculation produces totals. It is made of plain values, so the
 * order module depends on no other module to be placed.
 *
 * The conversion context decides the two currencies: its quote currency is the order's, its base
 * currency the store's. Every amount of the document must be in the one or the other, as its
 * place says, and a document that mixes them is refused before anything is written.
 *
 * @since 0.1.0
 */
final readonly class NewOrder {

	/**
	 * A UUID in its canonical, lower-case form.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/**
	 * Records the document, refusing one that could not be an order.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When there is no line, the e-mail address is empty, an id is below 1, the hold
	 *                                   group is not a UUID, two lines share a key, or an adjustment names a line the
	 *                                   document does not have.
	 * @throws CurrencyMismatchException When an amount is not in the currency its place requires.
	 *
	 * @param OrderChannel         $channel           Where the order comes from.
	 * @param string               $email             Where its messages go.
	 * @param int|null             $customerId        The WordPress user it belongs to, or null for a guest order.
	 * @param Locale               $locale            The locale it is placed in, which its snapshots are written in.
	 * @param int|null             $marketId          The market it is placed in, or null when the store has none.
	 * @param ConversionContext    $conversionContext The rate it is placed at: quote currency = the order's, base currency = the store's.
	 * @param TotalsSnapshot       $totals            The totals the calculation produced.
	 * @param NewOrderLine[]       $lines             What it sells, in the order it is shown; at least one.
	 * @param NewOrderAdjustment[] $adjustments       Its discounts, shipping and fees, in the order they are shown.
	 * @param Address              $billingAddress    The billing address.
	 * @param Address|null         $shippingAddress   Optional. The shipping address, or null when nothing is shipped. Default null.
	 * @param string|null          $holdGroup         Optional. The stock hold placement took for it, or null when nothing was held. Default null.
	 * @param string|null          $clientIp          Optional. The IP address it was placed from, or null. Default null.
	 * @param string|null          $userAgent         Optional. The browser it was placed from, or null. Default null.
	 */
	public function __construct(
		public OrderChannel $channel,
		public string $email,
		public ?int $customerId,
		public Locale $locale,
		public ?int $marketId,
		public ConversionContext $conversionContext,
		public TotalsSnapshot $totals,
		public array $lines,
		public array $adjustments,
		public Address $billingAddress,
		public ?Address $shippingAddress = null,
		public ?string $holdGroup = null,
		public ?string $clientIp = null,
		public ?string $userAgent = null
	) {
		$this->checkHeader();
		$this->checkReferences();
		$this->checkCurrencies();
	}

	/**
	 * Returns the order's currency: the context's quote currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency of every amount the order owns.
	 */
	public function currency(): Currency {
		return $this->conversionContext->quoteCurrency();
	}

	/**
	 * Returns the base currency: the context's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency of every base_ amount the order owns.
	 */
	public function baseCurrency(): Currency {
		return $this->conversionContext->baseCurrency();
	}

	/**
	 * Checks the e-mail address, the ids and the hold group.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When one could not be an order's.
	 */
	private function checkHeader(): void {
		if ( '' === trim( $this->email ) ) {
			throw new \InvalidArgumentException( 'An order needs the e-mail address its messages go to.' );
		}

		foreach ( array( $this->customerId, $this->marketId ) as $id ) {
			if ( null !== $id && $id < 1 ) {
				throw new \InvalidArgumentException( 'A customer or market id is 1 or more, or null.' );
			}
		}

		if ( null !== $this->holdGroup && 1 !== preg_match( self::UUID_PATTERN, $this->holdGroup ) ) {
			throw new \InvalidArgumentException( 'A hold group is a UUID in its canonical, lower-case form.' );
		}
	}

	/**
	 * Checks the lines and adjustments, and that each adjustment's line is one of the document's.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the document is not well formed.
	 */
	private function checkReferences(): void {
		if ( array() === $this->lines ) {
			throw new \InvalidArgumentException( 'An order sells at least one line.' );
		}

		$keys = array();

		foreach ( $this->lines as $line ) {
			if ( isset( $keys[ $line->key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Two order lines share the key %s.', $line->key ) );
			}

			$keys[ $line->key ] = true;
		}

		foreach ( $this->adjustments as $adjustment ) {
			if ( null !== $adjustment->lineKey && ! isset( $keys[ $adjustment->lineKey ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Adjustment %s names the line %s, which the order does not have.', $adjustment->source, $adjustment->lineKey ) );
			}
		}
	}

	/**
	 * Checks that every amount is in the currency its place requires.
	 *
	 * @since 0.1.0
	 *
	 * @throws CurrencyMismatchException When one is not.
	 */
	private function checkCurrencies(): void {
		$amounts = $this->totals->amounts();
		$base    = $this->totals->baseAmounts();

		foreach ( $this->lines as $line ) {
			$amounts = array_merge( $amounts, $line->amounts() );
			$base    = array_merge( $base, $line->baseAmounts() );
		}

		foreach ( $this->adjustments as $adjustment ) {
			$amounts = array_merge( $amounts, $adjustment->amounts() );
			$base    = array_merge( $base, $adjustment->baseAmounts() );
		}

		foreach ( $amounts as $amount ) {
			CurrencyMismatchException::assertSameCurrency( $this->currency(), $amount->currency() );
		}

		foreach ( $base as $amount ) {
			CurrencyMismatchException::assertSameCurrency( $this->baseCurrency(), $amount->currency() );
		}
	}
}
