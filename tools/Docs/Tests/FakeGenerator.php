<?php
/**
 * FakeGenerator: a configurable generator for the runner's tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use SEOCart\Tools\Docs\GenerationResult;
use SEOCart\Tools\Docs\Generator;

/**
 * Produces fixed content and fixed skips, so that each runner rule can be planted.
 *
 * @since 0.1.0
 */
final class FakeGenerator implements Generator {

	/**
	 * The content generate() returns, or null to make it throw.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $content;

	/**
	 * The items generate() reports as skipped.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $skipped;

	/**
	 * The items expectedSkips() declares.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $expected;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $content  The content to generate, or null to fail with an exception.
	 * @param string[]    $skipped  Optional. The items to report as skipped. Default none.
	 * @param string[]    $expected Optional. The items to declare as expected skips. Default none.
	 */
	public function __construct( ?string $content, array $skipped = array(), array $expected = array() ) {
		$this->content  = $content;
		$this->skipped  = $skipped;
		$this->expected = $expected;
	}

	/**
	 * Returns the generator's identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * Returns the target file.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the test's temporary root.
	 */
	public function target(): string {
		return 'generated.txt';
	}

	/**
	 * Returns the configured content and skips, or throws when no content is configured.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the fake is configured to fail.
	 *
	 * @param string $current The target's current content. Ignored.
	 * @return GenerationResult The configured result.
	 */
	public function generate( string $current ): GenerationResult {
		if ( null === $this->content ) {
			throw new \RuntimeException( 'the source is invalid.' );
		}

		return new GenerationResult( $this->content, $this->skipped );
	}

	/**
	 * Returns the configured expected skips.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The configured list.
	 */
	public function expectedSkips(): array {
		return $this->expected;
	}
}
