<?php
/**
 * TaxQuoteRequest: what a tax provider is asked to quote for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

/**
 * The tax classes the cart needs rates for, where the order goes, and when.
 *
 * Owns one fact: what a tax quoter is given. Every class a line, a shipping rate or a taxable
 * fee of the calculation names is in the list; the quote must answer each one. The origin is the
 * location the order is fulfilled from, when a fulfilment plan was asked for.
 *
 * @since 0.1.0
 * @since 0.2.0 The origin location was added.
 */
final readonly class TaxQuoteRequest {

	/**
	 * Holds the request.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $taxClasses       The classes, each once.
	 * @param Address|null       $destination      Where the order ships, or null when it is not known yet.
	 * @param \DateTimeImmutable $calculatedAt     When the calculation was asked for; a quote's expiry counts from it.
	 * @param int|null           $originLocationId Optional. The location the order ships from. Default null, not planned.
	 *
	 * @phpstan-param list<string> $taxClasses
	 */
	public function __construct(
		public array $taxClasses,
		public ?Address $destination,
		public \DateTimeImmutable $calculatedAt,
		public ?int $originLocationId = null
	) {
	}
}
