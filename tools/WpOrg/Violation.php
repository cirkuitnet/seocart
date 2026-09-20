<?php
/**
 * Violation: one broken WordPress.org directory rule
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Names the rule that was broken and explains how.
 *
 * The code is stable, so that a test can assert exactly which rule fired; the message
 * is for the person who has to fix it.
 *
 * @since 0.1.0
 */
final class Violation {

	/**
	 * Stable, kebab-case identifier of the rule, for example 'stable-tag-trunk'.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * What is wrong and what the correct value would be.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    Stable, kebab-case identifier of the rule.
	 * @param string $message What is wrong and what the correct value would be.
	 */
	public function __construct( string $code, string $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
