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
 * fee of the calculation names is in the list; the quote must answer each one.
 *
 * @since 0.1.0
 */
final readonly class TaxQuoteRequest {

	/**
	 * Holds the request.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $taxClasses   The classes, each once.
	 * @param Address|null       $destination  Where the order ships, or null when it is not known yet.
	 * @param \DateTimeImmutable $calculatedAt When the calculation was asked for; a quote's expiry counts from it.
	 *
	 * @phpstan-param list<string> $taxClasses
	 */
	public function __construct(
		public array $taxClasses,
		public ?Address $destination,
		public \DateTimeImmutable $calculatedAt
	) {
	}
}
